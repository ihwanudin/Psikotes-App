<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ReviewAssessmentBillTransfer;
use App\Data\Payments\AssessmentBillManualReview;
use App\Enums\AdminRole;
use App\Enums\AssessmentBillManualDecision;
use App\Enums\AssessmentBillManualRejectionCode;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentParticipant;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class AssessmentBillManualReviewTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private array $bill;

    private Admin $reviewer;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        $this->freezeTime();
        $this->bill = $this->pendingManualAttempt();
        $this->reviewer = $this->admin(AdminRole::SuperAdmin);
    }

    #[DataProvider('invalidReviews')]
    public function test_typed_review_rejects_invalid_shapes(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssessmentBillManualReview(...$values);
    }

    public static function invalidReviews(): iterable
    {
        $valid = [
            'actorAdminId' => 1,
            'billReference' => 'AB_01K3H9M5YXB62D9QK7E5V2G8Z9',
            'expectedProofFingerprint' => str_repeat('a', 64),
            'decision' => AssessmentBillManualDecision::Approve,
            'rejectionCode' => null,
        ];

        yield 'non-positive actor' => [[...$valid, 'actorAdminId' => 0]];
        yield 'foreign reference namespace' => [[...$valid, 'billReference' => 'ORDER_01K3H9M5YXB62D9QK7E5V2G8Z9']];
        yield 'uppercase fingerprint' => [[...$valid, 'expectedProofFingerprint' => str_repeat('A', 64)]];
        yield 'approve with reason' => [[...$valid, 'rejectionCode' => AssessmentBillManualRejectionCode::UnreadableProof]];
        yield 'reject without reason' => [[...$valid, 'decision' => AssessmentBillManualDecision::Reject]];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_unauthorized_actor_is_rejected_before_bill_lookup(AdminRole $role): void
    {
        $admin = $this->admin($role, true);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            app(ReviewAssessmentBillTransfer::class)->execute($this->review($admin->id));
            $this->fail('Unauthorized reviewer was accepted.');
        } catch (\DomainException $exception) {
            $this->assertSame('ASSESSMENT_BILL_REVIEW_NOT_FOUND', $exception->getMessage());
        }

        $this->assertNotEmpty(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"admins"')));
        $this->assertSame([], array_values(array_filter($queries,
            static fn (string $sql): bool => str_contains($sql, '"assessment_bills"'))));
    }

    public static function unauthorizedRoles(): iterable
    {
        yield 'branch owner flag true' => [AdminRole::BranchAdmin];
        yield 'staff flag true' => [AdminRole::Staff];
        yield 'psychologist' => [AdminRole::Psychologist];
    }

    public function test_foreign_branch_admin_and_missing_actor_are_denied_before_bill_lookup(): void
    {
        $foreign = AssessmentAccessFixture::create();
        $admin = $this->admin(AdminRole::BranchAdmin, true);
        DB::table('admins')->where('id', $admin->id)->update(['branch_id' => $foreign['organization']]);
        foreach ([$admin->id, PHP_INT_MAX] as $actorId) {
            $queries = [];
            DB::listen(static function ($query) use (&$queries): void {
                $queries[] = $query->sql;
            });
            $this->assertReviewError($this->review($actorId), 'ASSESSMENT_BILL_REVIEW_NOT_FOUND');
            $this->assertSame([], array_values(array_filter($queries,
                static fn (string $sql): bool => str_contains($sql, '"assessment_bills"'))));
        }
    }

    #[DataProvider('itemCounts')]
    public function test_approve_settles_all_items_and_activates_only_complete_attempts(int $itemCount): void
    {
        $incomplete = null;
        for ($i = 1; $i < $itemCount; $i++) {
            $incomplete = $this->appendAttempt();
        }
        if ($incomplete !== null) {
            DB::table('consent_records')->where('participant_id', $incomplete['participant'])
                ->where('consent_type', 'psychotest')->update(['status' => 'declined', 'consented_at' => null]);
        }

        $result = $this->execute($this->review($this->reviewer->id));

        $this->assertSame('settled', $result['decision']);
        $this->assertSame($itemCount, $result['allocationCount']);
        $this->assertDatabaseHas('assessment_bills', [
            'id' => $this->bill['bill'],
            'status' => 'paid',
            'paid_at' => now(),
            'verified_at' => now(),
            'verified_by_admin_id' => $this->reviewer->id,
            'rejection_reason' => null,
        ]);
        $this->assertSame(0, DB::table('assessment_bill_items')->where('bill_id', $this->bill['bill'])
            ->whereNull('settled_at')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $audit = json_decode((string) DB::table('audit_logs')->where('action', 'assessment_bill.paid')->value('context'), true,
            512, JSON_THROW_ON_ERROR);
        $this->assertSame('manual_transfer', $audit['source']);
        $this->assertSame($this->bill['fingerprint'], $audit['proofFingerprint']);
        $this->assertArrayNotHasKey('proofObjectKey', $audit);
        $this->assertArrayNotHasKey('organizationId', $audit);
        if ($incomplete !== null) {
            $this->assertDatabaseHas('assessment_participants', [
                'id' => $incomplete['attempt'], 'assessment_status' => 'PROVISIONED',
            ]);
            $this->assertDatabaseHas('assessment_entitlements', [
                'assessment_participant_id' => $incomplete['attempt'], 'status' => 'locked',
            ]);
        }
    }

    #[DataProvider('itemCounts')]
    public function test_reject_records_bounded_reason_without_settlement_or_activation(int $itemCount): void
    {
        for ($i = 1; $i < $itemCount; $i++) {
            $this->appendAttempt();
        }
        $review = $this->review($this->reviewer->id, AssessmentBillManualDecision::Reject,
            AssessmentBillManualRejectionCode::WrongBeneficiary);

        $result = $this->execute($review);

        $this->assertSame(['decision' => 'rejected', 'allocationCount' => $itemCount, 'activatedAttemptCount' => 0], $result);
        $this->assertDatabaseHas('assessment_bills', [
            'id' => $this->bill['bill'],
            'status' => 'rejected',
            'paid_at' => null,
            'verified_at' => now(),
            'verified_by_admin_id' => $this->reviewer->id,
            'rejection_reason' => 'WRONG_BENEFICIARY',
        ]);
        $this->assertSame($itemCount, DB::table('assessment_bill_items')->where('bill_id', $this->bill['bill'])
            ->whereNull('settled_at')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.rejected')->count());
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public static function itemCounts(): iterable
    {
        yield 'one item' => [1];
        yield 'ten items' => [10];
    }

    public function test_exact_replay_is_noop_and_identity_or_opposite_decision_conflicts(): void
    {
        $approve = $this->review($this->reviewer->id);
        $this->assertSame('settled', $this->execute($approve)['decision']);
        $this->travel(5)->minutes();
        $this->assertSame('replayed', $this->execute($approve)['decision']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->assertDatabaseCount('outbox_messages', 1);

        $other = $this->admin(AdminRole::SuperAdmin);
        foreach ([
            $this->review($other->id),
            $this->review($this->reviewer->id, AssessmentBillManualDecision::Reject,
                AssessmentBillManualRejectionCode::UnreadableProof),
        ] as $conflict) {
            $this->assertReviewError($conflict, 'ASSESSMENT_BILL_REVIEW_CONFLICT');
        }
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_reject_replay_requires_same_actor_proof_decision_and_reason(): void
    {
        $review = $this->review($this->reviewer->id, AssessmentBillManualDecision::Reject,
            AssessmentBillManualRejectionCode::UnreadableProof);
        $this->assertSame('rejected', $this->execute($review)['decision']);
        $this->assertSame('replayed', $this->execute($review)['decision']);
        $this->assertReviewError(
            $this->review($this->reviewer->id, AssessmentBillManualDecision::Reject,
                AssessmentBillManualRejectionCode::DuplicateProof),
            'ASSESSMENT_BILL_REVIEW_CONFLICT',
        );
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.rejected')->count());
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_stale_proof_channel_and_noncanonical_states_fail_without_mutation(): void
    {
        $review = $this->review($this->reviewer->id);
        DB::table('assessment_bills')->where('id', $this->bill['bill'])
            ->update(['proof_checksum_sha256' => str_repeat('b', 64)]);
        $this->assertReviewError($review, 'ASSESSMENT_BILL_REVIEW_CONFLICT');
        DB::table('assessment_bills')->where('id', $this->bill['bill'])->update([
            'proof_checksum_sha256' => str_repeat('a', 64), 'gateway_ref' => 'forged-provider',
        ]);
        $this->assertReviewError($review, 'ASSESSMENT_BILL_REVIEW_CHANNEL_INVALID');
        DB::table('assessment_bills')->where('id', $this->bill['bill'])->update([
            'gateway_ref' => null, 'status' => 'unknown',
        ]);
        $this->assertReviewError($review, 'ASSESSMENT_BILL_REVIEW_STATE_INVALID');

        $this->assertSame(0, DB::table('assessment_bill_items')->where('bill_id', $this->bill['bill'])
            ->whereNotNull('settled_at')->count());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_corrupt_proof_scope_snapshot_money_and_channel_fail_closed(): void
    {
        $review = $this->review($this->reviewer->id);
        $mutations = [
            ['assessment_bills', $this->bill['bill'], ['proof_mime_type' => null], 'ASSESSMENT_BILL_REVIEW_PROOF_INVALID'],
            ['assessment_bills', $this->bill['bill'], ['invoice_url' => 'https://forbidden.test'], 'ASSESSMENT_BILL_REVIEW_CHANNEL_INVALID'],
            ['assessment_bill_items', $this->bill['item'], ['settled_at' => now()], 'ASSESSMENT_BILL_REVIEW_SCOPE_INVALID'],
            ['assessment_charges', $this->bill['charge'], ['price_snapshot' => '{}'], 'ASSESSMENT_BILL_REVIEW_SCOPE_INVALID'],
            ['assessment_bills', $this->bill['bill'], ['amount' => 101], 'ASSESSMENT_BILL_REVIEW_SCOPE_INVALID'],
        ];
        foreach ($mutations as [$table, $id, $values, $error]) {
            $before = (array) DB::table($table)->where('id', $id)->first();
            DB::table($table)->where('id', $id)->update($values);
            $this->assertReviewError($review, $error);
            DB::table($table)->where('id', $id)->update($before);
        }
        DB::table('payment_methods')->where('id', $this->bill['paymentMethod'])->update(['code' => 'xendit']);
        $this->assertReviewError($review, 'ASSESSMENT_BILL_REVIEW_CHANNEL_INVALID');

        $this->assertDatabaseHas('assessment_bills', ['id' => $this->bill['bill'], 'status' => 'pending', 'paid_at' => null]);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_deleted_or_role_changed_super_admin_fails_before_bill_lookup(): void
    {
        foreach (['deleted', 'role-changed'] as $case) {
            $admin = $this->admin(AdminRole::SuperAdmin);
            if ($case === 'deleted') {
                $admin->delete();
            } else {
                DB::table('admins')->where('id', $admin->id)->update(['role' => AdminRole::Staff->value]);
            }
            $queries = [];
            DB::listen(static function ($query) use (&$queries): void {
                $queries[] = $query->sql;
            });
            $this->assertReviewError($this->review($admin->id), 'ASSESSMENT_BILL_REVIEW_NOT_FOUND');
            $this->assertSame([], array_values(array_filter($queries,
                static fn (string $sql): bool => str_contains($sql, '"assessment_bills"'))));
        }
    }

    public function test_fifth_item_failure_rolls_back_bill_verifier_allocations_audit_activation_and_outbox(): void
    {
        for ($i = 1; $i < 10; $i++) {
            $this->appendAttempt();
        }
        $saved = 0;
        AssessmentBillItem::updating(function () use (&$saved): void {
            if (++$saved === 5) {
                throw new RuntimeException('synthetic manual fifth item crash');
            }
        });
        try {
            $this->execute($this->review($this->reviewer->id));
            $this->fail('Expected synthetic fifth item crash.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic manual fifth item crash', $exception->getMessage());
        } finally {
            AssessmentBillItem::flushEventListeners();
        }

        $this->assertDatabaseHas('assessment_bills', [
            'id' => $this->bill['bill'], 'status' => 'pending', 'paid_at' => null,
            'verified_at' => null, 'verified_by_admin_id' => null,
        ]);
        $this->assertSame(10, DB::table('assessment_bill_items')->where('bill_id', $this->bill['bill'])
            ->whereNull('settled_at')->count());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(0, DB::table('assessment_entitlements')->where('status', 'ready')->count());
    }

    public function test_activation_failure_rolls_back_manual_payment_audit_and_allocations(): void
    {
        AssessmentParticipant::updating(static function (AssessmentParticipant $attempt): void {
            if ($attempt->assessment_status === 'READY') {
                throw new RuntimeException('synthetic manual activation crash');
            }
        });
        try {
            $this->execute($this->review($this->reviewer->id));
            $this->fail('Expected synthetic activation crash.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic manual activation crash', $exception->getMessage());
        } finally {
            AssessmentParticipant::flushEventListeners();
        }

        $this->assertManualRollbackState();
    }

    public function test_outbox_failure_rolls_back_manual_payment_activation_and_audits(): void
    {
        $failed = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$failed): void {
            if (! $failed && str_starts_with(strtolower(ltrim($query)), 'insert')
                && str_contains($query, 'outbox_messages')) {
                $failed = true;
                throw new RuntimeException('synthetic manual outbox crash');
            }
        });
        try {
            $this->execute($this->review($this->reviewer->id));
            $this->fail('Expected synthetic outbox crash.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic manual outbox crash', $exception->getMessage());
        }

        $this->assertManualRollbackState();
    }

    public function test_ambient_context_or_transaction_is_rejected(): void
    {
        try {
            DB::transaction(fn () => $this->execute($this->review($this->reviewer->id)));
            $this->fail('Ambient transaction accepted.');
        } catch (LogicException) {
            $this->assertDatabaseCount('audit_logs', 0);
        }
        $this->expectException(LogicException::class);
        app(RlsContextRunner::class)->run(new RlsContext('service'),
            fn () => $this->execute($this->review($this->reviewer->id)));
    }

    private function review(int $adminId, AssessmentBillManualDecision $decision = AssessmentBillManualDecision::Approve,
        ?AssessmentBillManualRejectionCode $reason = null): AssessmentBillManualReview
    {
        return new AssessmentBillManualReview(
            actorAdminId: $adminId,
            billReference: $this->bill['reference'],
            expectedProofFingerprint: $this->bill['fingerprint'],
            decision: $decision,
            rejectionCode: $reason,
        );
    }

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    private function execute(AssessmentBillManualReview $review): array
    {
        return app(ReviewAssessmentBillTransfer::class)->execute($review);
    }

    private function assertReviewError(AssessmentBillManualReview $review, string $code): void
    {
        try {
            $this->execute($review);
            $this->fail('Expected manual review to fail closed.');
        } catch (\DomainException $exception) {
            $this->assertSame($code, $exception->getMessage());
        }
    }

    private function assertManualRollbackState(): void
    {
        $this->assertDatabaseHas('assessment_bills', [
            'id' => $this->bill['bill'], 'status' => 'pending', 'paid_at' => null,
            'verified_at' => null, 'verified_by_admin_id' => null,
        ]);
        $this->assertDatabaseHas('assessment_bill_items', ['id' => $this->bill['item'], 'settled_at' => null]);
        $this->assertDatabaseHas('assessment_participants', [
            'id' => $this->bill['attempt'], 'assessment_status' => 'PROVISIONED',
        ]);
        $this->assertDatabaseHas('assessment_entitlements', [
            'id' => $this->bill['entitlement'], 'status' => 'locked',
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    private function pendingManualAttempt(?array $identity = null, ?int $paymentMethodId = null): array
    {
        $fixture = AssessmentAccessFixture::create(identity: $identity);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'assessment_status' => 'PROVISIONED',
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION',
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('assessment_entitlements')->where('assessment_participant_id', $fixture['attempt'])
            ->update(['status' => 'locked', 'ready_at' => null]);
        DB::table('assessment_bill_items')->where('id', $fixture['item'])->update(['settled_at' => null]);
        $method = (int) DB::table('assessment_bills')->where('id', $fixture['bill'])->value('payment_method_id');
        if ($paymentMethodId === null) {
            DB::table('payment_methods')->where('id', $method)->update(['code' => 'manual_transfer', 'is_active' => false]);
            $paymentMethodId = $method;
        } else {
            DB::table('assessment_bills')->where('id', $fixture['bill'])->update(['payment_method_id' => $paymentMethodId]);
            DB::table('payment_methods')->where('id', $method)->delete();
        }
        $proof = [
            'proof_object_key' => 'assessment-bills/ab/'.str_repeat('c', 62).'.jpg',
            'proof_checksum_sha256' => str_repeat('a', 64),
            'proof_mime_type' => 'image/jpeg',
            'proof_size_bytes' => 100,
            'proof_uploaded_at' => '2026-09-01T00:00:00.000000Z',
        ];
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'status' => 'pending', 'paid_at' => null, 'verified_at' => null,
            'verified_by_admin_id' => null, 'rejection_reason' => null,
            'gateway_ref' => null, 'invoice_url' => null, ...$proof,
        ]);
        $bill = AssessmentBill::query()->findOrFail($fixture['bill']);
        $fingerprint = hash('sha256', implode("\0", [
            $bill->proof_object_key, $bill->proof_checksum_sha256, $bill->proof_mime_type,
            (string) $bill->proof_size_bytes, $bill->proof_uploaded_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ]));

        return [...$fixture, 'reference' => $bill->public_reference, 'fingerprint' => $fingerprint,
            'paymentMethod' => $paymentMethodId];
    }

    private function appendAttempt(): array
    {
        $other = $this->pendingManualAttempt(
            ['organization' => $this->bill['organization']],
            $this->bill['paymentMethod'],
        );
        DB::table('assessment_bill_items')->where('id', $other['item'])->update(['bill_id' => $this->bill['bill']]);
        DB::table('assessment_bills')->where('id', $other['bill'])->delete();
        DB::table('assessment_bills')->where('id', $this->bill['bill'])->update([
            'amount' => DB::raw('amount + 100'), 'item_count' => DB::raw('item_count + 1'),
        ]);

        return $other;
    }

    private function admin(AdminRole $role, bool $canVerify = false): Admin
    {
        return Admin::query()->create([
            'name' => 'Synthetic reviewer',
            'email' => $role->value.'-'.uniqid().'@example.test',
            'password' => 'not-a-real-password',
            'role' => $role,
            'can_verify_payments' => $canVerify,
            'branch_id' => in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true)
                ? $this->bill['organization'] : null,
        ]);
    }
}
