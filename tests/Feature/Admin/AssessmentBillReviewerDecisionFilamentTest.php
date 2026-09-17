<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Payments\ReviewAssessmentBillFromProofAccess;
use App\Enums\AdminRole;
use App\Enums\AssessmentBillManualDecision;
use App\Enums\AssessmentBillManualRejectionCode;
use App\Filament\Resources\AssessmentBillReviews\Pages\ViewAssessmentBillReview;
use App\Models\Admin;
use App\Models\AssessmentBill;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;
use Throwable;

final class AssessmentBillReviewerDecisionFilamentTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private array $bill;

    private Admin $reviewer;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        $this->freezeTime();
        Storage::fake('payment-proofs');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->bill = $this->manualBillWithProof();
        $this->reviewer = $this->admin(AdminRole::SuperAdmin, null, false);
    }

    public function test_decision_without_successful_open_is_rejected_without_mutation(): void
    {
        $this->actingAs($this->reviewer, 'admin');

        Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])
            ->callAction(TestAction::make('approve'))
            ->assertNotified('Keputusan tidak dapat diproses. Buka bukti lalu coba kembali.');

        $this->assertPendingWithoutDecisionAudit();
    }

    public function test_open_then_approve_settles_once_and_refreshes_safe_record(): void
    {
        $this->actingAs($this->reviewer, 'admin');
        $component = Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']]);
        $this->openThroughUi($component);

        $component->callAction(TestAction::make('approve'))
            ->assertNotified('Keputusan tersimpan.');

        $this->assertSame('paid', DB::table('assessment_bills')->where('id', $this->bill['bill'])->value('status'));
        $this->assertNotNull(DB::table('assessment_bill_items')->where('id', $this->bill['item'])->value('settled_at'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'assessment_bill.proof_temporary_url_issued')->count());
        $this->assertSafeComponentState($component->instance());
    }

    public function test_open_then_reject_requires_bounded_code_and_never_settles(): void
    {
        $this->actingAs($this->reviewer, 'admin');
        $component = Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']]);
        $this->openThroughUi($component);

        $component->callAction(TestAction::make('reject'), data: ['rejection_code' => 'free text'])
            ->assertHasActionErrors(['rejection_code']);
        $component->unmountAction();
        $component->callAction(TestAction::make('reject'), data: [
            'rejection_code' => AssessmentBillManualRejectionCode::UnreadableProof->value,
        ])->assertHasNoActionErrors()->assertNotified('Keputusan tersimpan.');

        $bill = DB::table('assessment_bills')->where('id', $this->bill['bill'])->first();
        $this->assertSame('rejected', $bill->status);
        $this->assertSame('UNREADABLE_PROOF', $bill->rejection_reason);
        $this->assertNull(DB::table('assessment_bill_items')->where('id', $this->bill['item'])->value('settled_at'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.rejected')->count());
    }

    public function test_exact_internal_replay_uses_same_access_context_without_duplicate_audit(): void
    {
        $this->insertAccessAudit($this->reviewer, $this->bill);
        $action = app(ReviewAssessmentBillFromProofAccess::class);

        $first = $action->execute($this->reviewer, $this->bill['reference'],
            AssessmentBillManualDecision::Approve, null);
        $second = $action->execute($this->reviewer, $this->bill['reference'],
            AssessmentBillManualDecision::Approve, null);

        $this->assertSame('settled', $first['decision']);
        $this->assertSame('replayed', $second['decision']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->assertSame(1, DB::table('outbox_messages')->where('topic', 'assessment.activation')->count());
    }

    #[DataProvider('proofChanges')]
    public function test_proof_changed_after_open_conflicts_without_decision_mutation(array $changes): void
    {
        $this->insertAccessAudit($this->reviewer, $this->bill);
        DB::table('assessment_bills')->where('id', $this->bill['bill'])->update($changes);

        try {
            app(ReviewAssessmentBillFromProofAccess::class)->execute(
                $this->reviewer,
                $this->bill['reference'],
                AssessmentBillManualDecision::Approve,
                null,
            );
            $this->fail('Changed proof must not be approved from old access context.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->assertPendingWithoutDecisionAudit();
    }

    public static function proofChanges(): iterable
    {
        yield 'replacement' => [[
            'proof_object_key' => 'assessment-bills/zz/'.str_repeat('a', 62).'.pdf',
            'proof_checksum_sha256' => str_repeat('a', 64),
            'proof_uploaded_at' => '2026-09-01 10:59:30',
        ]];
        yield 'cleared' => [[
            'proof_object_key' => null, 'proof_checksum_sha256' => null, 'proof_mime_type' => null,
            'proof_size_bytes' => null, 'proof_uploaded_at' => null,
        ]];
    }

    #[DataProvider('invalidAccessAudits')]
    public function test_invalid_access_audit_context_fails_closed(string $mutation): void
    {
        $other = $this->manualBillWithProof('bb');
        $actor = $this->reviewer;
        $bill = $this->bill;
        $context = $this->accessContext($bill);
        $occurredAt = now();

        match ($mutation) {
            'expired' => $context['url_expires_at'] = now()->subSecond()->toIso8601String(),
            'malformed' => $context = ['version' => 1, 'source' => 'assessment_bill_manual_review'],
            'extra key' => $context['object_key'] = 'must-not-be-accepted',
            'wrong source' => $context['source'] = 'legacy_manual_review',
            'wrong actor' => $actor = $this->admin(AdminRole::SuperAdmin, null, false),
            'wrong bill' => $bill = $other,
            'future occurred' => $occurredAt = now()->addMinute(),
            'non utc occurred' => $occurredAt = now()->setTimezone('+07:00')->format('Y-m-d H:i:sP'),
            'invalid calendar' => $occurredAt = '2026-02-30 11:00:00',
            default => null,
        };
        $this->insertAccessAudit($actor, $bill, $context, $occurredAt);

        $this->expectException(DomainException::class);
        app(ReviewAssessmentBillFromProofAccess::class)->execute(
            $this->reviewer,
            $this->bill['reference'],
            AssessmentBillManualDecision::Approve,
            null,
        );
    }

    public static function invalidAccessAudits(): iterable
    {
        yield 'expired URL' => ['expired'];
        yield 'malformed context' => ['malformed'];
        yield 'unexpected context key' => ['extra key'];
        yield 'wrong context source' => ['wrong source'];
        yield 'different reviewer' => ['wrong actor'];
        yield 'different bill' => ['wrong bill'];
        yield 'future occurred at' => ['future occurred'];
        yield 'non-UTC occurred at' => ['non utc occurred'];
        yield 'invalid calendar occurred at' => ['invalid calendar'];
    }

    public function test_ambiguous_latest_access_audits_with_different_fingerprints_fail_closed(): void
    {
        $this->insertAccessAudit($this->reviewer, $this->bill);
        $context = $this->accessContext($this->bill);
        $context['proof_fingerprint'] = str_repeat('a', 64);
        $this->insertAccessAudit($this->reviewer, $this->bill, $context);

        $this->expectException(DomainException::class);
        app(ReviewAssessmentBillFromProofAccess::class)->execute(
            $this->reviewer,
            $this->bill['reference'],
            AssessmentBillManualDecision::Approve,
            null,
        );
    }

    public function test_repeated_equivalent_access_audits_are_not_ambiguous(): void
    {
        $this->insertAccessAudit($this->reviewer, $this->bill);
        $this->insertAccessAudit($this->reviewer, $this->bill);

        $result = app(ReviewAssessmentBillFromProofAccess::class)->execute(
            $this->reviewer,
            $this->bill['reference'],
            AssessmentBillManualDecision::Reject,
            AssessmentBillManualRejectionCode::DuplicateProof,
        );

        $this->assertSame('rejected', $result['decision']);
        $this->assertSame(2, DB::table('audit_logs')
            ->where('action', 'assessment_bill.proof_temporary_url_issued')->count());
    }

    #[DataProvider('revocations')]
    public function test_reviewer_revoked_after_open_and_modal_mount_cannot_decide(array $changes): void
    {
        $this->insertAccessAudit($this->reviewer, $this->bill);
        $this->actingAs($this->reviewer, 'admin');
        $component = Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])
            ->mountAction('approve')
            ->assertActionMounted('approve');
        DB::table('admins')->where('id', $this->reviewer->id)->update($changes);

        $component->call('callMountedAction')->assertNotFound();

        $this->assertPendingWithoutDecisionAudit();
    }

    public static function revocations(): iterable
    {
        yield 'role changed' => [['role' => AdminRole::Staff->value]];
        yield 'deleted' => [['deleted_at' => '2026-09-01 10:59:30']];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_non_reviewer_cannot_use_internal_or_mounted_decision_action(?AdminRole $role): void
    {
        if ($role === null) {
            Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])->assertNotFound();

            return;
        }
        $branch = in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true)
            ? $this->bill['organization'] : null;
        $actor = $this->admin($role, $branch, true);
        $this->insertAccessAudit($actor, $this->bill);
        $this->actingAs($actor, 'admin');
        Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])->assertNotFound();

        try {
            app(ReviewAssessmentBillFromProofAccess::class)->execute(
                $actor,
                $this->bill['reference'],
                AssessmentBillManualDecision::Approve,
                null,
            );
            $this->fail('Non-reviewer decision must fail closed.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
        $this->assertPendingWithoutDecisionAudit();
    }

    public static function unauthorizedRoles(): iterable
    {
        yield 'guest' => [null];
        yield 'branch admin payer' => [AdminRole::BranchAdmin];
        yield 'staff legacy flag' => [AdminRole::Staff];
        yield 'psychologist' => [AdminRole::Psychologist];
    }

    public function test_decision_actions_and_mounted_reject_form_never_expose_proof_identity(): void
    {
        $this->actingAs($this->reviewer, 'admin');
        $component = Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])
            ->assertActionVisible('approve')
            ->assertActionVisible('reject')
            ->mountAction('reject')
            ->assertMountedActionModalSee('Alasan penolakan');

        $this->assertSafeComponentState($component->instance());
        $html = $component->html();
        foreach ($this->sensitiveValues() as $value) {
            $this->assertStringNotContainsString($value, $html);
        }
    }

    public function test_ambient_context_or_transaction_is_rejected_before_audit_lookup(): void
    {
        $this->insertAccessAudit($this->reviewer, $this->bill);
        $action = app(ReviewAssessmentBillFromProofAccess::class);

        try {
            DB::transaction(fn () => $action->execute(
                $this->reviewer,
                $this->bill['reference'],
                AssessmentBillManualDecision::Approve,
                null,
            ));
            $this->fail('Ambient transaction must be rejected.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }

        $this->assertPendingWithoutDecisionAudit();
    }

    public function test_unexpected_database_failure_propagates_and_rolls_back_ui_decision(): void
    {
        $this->insertAccessAudit($this->reviewer, $this->bill);
        DB::statement(<<<'SQL'
            CREATE TRIGGER reject_reviewer_decision_audit BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'assessment_bill.paid'
            BEGIN SELECT RAISE(ABORT, 'synthetic reviewer audit failure'); END
            SQL);
        $this->actingAs($this->reviewer, 'admin');

        try {
            Livewire::test(ViewAssessmentBillReview::class, ['record' => $this->bill['bill']])
                ->callAction(TestAction::make('approve'));
            $this->fail('Unexpected database failure must propagate.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('synthetic reviewer audit failure', $exception->getMessage());
        }

        $this->assertPendingWithoutDecisionAudit();
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    private function openThroughUi($component): void
    {
        $disk = Storage::disk('payment-proofs');
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('exists')->once()->andReturnUsing(fn (string $key): bool => $disk->exists($key));
        $mock->shouldReceive('temporaryUrl')->once()->andReturn('https://proof.example.test/opaque-token');
        Storage::shouldReceive('disk')->with('payment-proofs')->andReturn($mock);
        $component->callAction(TestAction::make('openProof'))
            ->assertRedirect('https://proof.example.test/opaque-token');
    }

    private function insertAccessAudit(Admin $actor, array $bill, ?array $context = null, mixed $occurredAt = null): void
    {
        DB::table('audit_logs')->insert([
            'branch_id' => $bill['organization'], 'actor_type' => 'admin',
            'actor_id' => (string) $actor->id, 'action' => 'assessment_bill.proof_temporary_url_issued',
            'subject_type' => AssessmentBill::class, 'subject_id' => (string) $bill['bill'],
            'context' => json_encode($context ?? $this->accessContext($bill), JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt ?? now(), 'expires_at' => now()->addYearsNoOverflow(2),
        ]);
    }

    private function accessContext(array $bill): array
    {
        return [
            'version' => 1,
            'source' => 'assessment_bill_manual_review',
            'proof_fingerprint' => $bill['fingerprint'],
            'url_expires_at' => now()->addMinutes(15)->toIso8601String(),
        ];
    }

    private function assertPendingWithoutDecisionAudit(): void
    {
        $this->assertSame('pending', DB::table('assessment_bills')->where('id', $this->bill['bill'])->value('status'));
        $this->assertNull(DB::table('assessment_bill_items')->where('id', $this->bill['item'])->value('settled_at'));
        $this->assertSame(0, DB::table('audit_logs')->whereIn('action', [
            'assessment_bill.paid', 'assessment_bill.rejected',
        ])->count());
    }

    private function assertSafeComponentState(object $component): void
    {
        $payload = json_encode($component, JSON_THROW_ON_ERROR);
        foreach ($this->sensitiveValues() as $value) {
            $this->assertStringNotContainsString($value, $payload);
        }
    }

    /** @return list<string> */
    private function sensitiveValues(): array
    {
        return [
            $this->bill['proofKey'], $this->bill['fingerprint'], str_repeat('e', 64),
            'Synthetic Candidate', 'PRIVATE-CANDIDATE-IDENTIFIER', 'opaque-token',
        ];
    }

    private function manualBillWithProof(string $shard = 'aa'): array
    {
        $fixture = AssessmentAccessFixture::create();
        DB::table('branches')->where('id', $fixture['organization'])->update(['name' => 'Synthetic Organization']);
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => 'Synthetic Candidate']);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])
            ->update([
                'external_candidate_id' => 'PRIVATE-CANDIDATE-IDENTIFIER',
                'assessment_status' => 'PROVISIONED',
                'funding_mode' => 'INVOICED_TO_ORGANIZATION',
                'metadata' => json_encode([
                    'checkout_contract_version' => 'checkout-v2',
                    'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION',
                ], JSON_THROW_ON_ERROR),
            ]);
        DB::table('assessment_bill_items')->where('id', $fixture['item'])->update(['settled_at' => null]);
        DB::table('assessment_entitlements')->where('id', $fixture['entitlement'])
            ->update(['status' => 'locked', 'ready_at' => null]);
        $method = DB::table('payment_methods')->where('code', 'manual_transfer')->value('id');
        if (! is_int($method)) {
            $method = DB::table('payment_methods')->insertGetId([
                'code' => 'manual_transfer', 'display_name' => 'Transfer Manual', 'is_active' => false,
            ]);
        }
        $contents = "%PDF-1.4\nprivate proof\n%%EOF";
        $key = "assessment-bills/{$shard}/".str_repeat('f', 62).'.pdf';
        $uploadedAt = now()->subMinute()->toImmutable()->utc()->startOfSecond();
        Storage::disk('payment-proofs')->put($key, $contents, ['visibility' => 'private']);
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'payment_method_id' => $method,
            'status' => 'pending', 'paid_at' => null, 'gateway_ref' => null, 'invoice_url' => null,
            'proof_object_key' => $key, 'proof_checksum_sha256' => str_repeat('e', 64),
            'proof_mime_type' => 'application/pdf', 'proof_size_bytes' => strlen($contents),
            'proof_uploaded_at' => $uploadedAt, 'verified_at' => null,
            'verified_by_admin_id' => null, 'rejection_reason' => null,
        ]);
        $fingerprint = hash('sha256', implode("\0", [
            $key, str_repeat('e', 64), 'application/pdf', (string) strlen($contents),
            $uploadedAt->format('Y-m-d\TH:i:s.u\Z'),
        ]));

        return [...$fixture, 'method' => $method, 'proofKey' => $key, 'fingerprint' => $fingerprint,
            'reference' => (string) DB::table('assessment_bills')->where('id', $fixture['bill'])
                ->value('public_reference')];
    }

    private function admin(AdminRole $role, ?int $branchId, bool $legacyFlag): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branchId, 'name' => 'Synthetic reviewer',
            'email' => uniqid().'@example.test', 'password' => 'not-real',
            'role' => $role, 'can_verify_payments' => $legacyFlag,
        ]);
    }
}
