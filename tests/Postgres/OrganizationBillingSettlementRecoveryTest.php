<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\FinalizeAssessmentBill;
use App\Actions\Payments\ReviewAssessmentBillTransfer;
use App\Data\Payments\AssessmentBillManualReview;
use App\Data\Payments\PaymentEvent;
use App\Enums\AdminRole;
use App\Enums\AssessmentBillManualDecision;
use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\AssessmentBillItem;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentAccessFixture;

/** PostgreSQL-authoritative rollback and retry evidence for settlement writers. */
final class OrganizationBillingSettlementRecoveryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $bill;

    /** @var list<int> */
    private array $attempts = [];

    /** @var list<int> */
    private array $participants = [];

    /** @var list<int> */
    private array $packages = [];

    private ?int $reviewer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        Date::setTestNow(now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        AssessmentBillItem::flushEventListeners();
        if (isset($this->bill)) {
            app(RlsContextRunner::class)->runAsService(function (): void {
                DB::table('outbox_messages')->where('topic', 'assessment.activation')
                    ->whereIn('aggregate_id', array_map(strval(...), $this->attempts))->delete();
                DB::table('audit_logs')->where('branch_id', $this->bill['organization'])->delete();
                foreach (['assessment_entitlements', 'assessment_bill_items', 'assessment_bills', 'assessment_charges',
                    'assessment_participants', 'integration_clients'] as $table) {
                    DB::table($table)->where('organization_id', $this->bill['organization'])->delete();
                }
                foreach (['consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
                    DB::table($table)->whereIn('participant_id', $this->participants)->delete();
                }
                DB::table('participants')->whereIn('id', $this->participants)->delete();
                DB::table('package_items')->whereIn('package_id', $this->packages)->delete();
                DB::table('packages')->whereIn('id', $this->packages)->delete();
                if ($this->reviewer !== null) {
                    DB::table('admins')->where('id', $this->reviewer)->delete();
                }
                DB::table('payment_methods')->where('id', $this->bill['paymentMethod'])->delete();
                DB::table('branches')->where('id', $this->bill['organization'])->delete();
            });
        }
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_webhook_fifth_allocation_crash_rolls_back_and_retry_settles_everything(): void
    {
        $this->bill = $this->pendingAttempt();
        for ($i = 1; $i < 10; $i++) {
            $this->appendAttempt();
        }

        $this->crashOnFifthAllocation('synthetic webhook fifth allocation crash', fn (): array => $this->finalize());
        $this->assertFullyRolledBack(10);

        $this->assertSame([
            'decision' => 'settled',
            'allocationCount' => 10,
            'activatedAttemptCount' => 10,
        ], $this->finalize());
        $this->assertFullySettled(10, actor: 'system', activated: 10);
    }

    public function test_manual_fifth_allocation_crash_rolls_back_and_retry_settles_everything(): void
    {
        $this->bill = $this->pendingAttempt(manual: true);
        for ($i = 1; $i < 10; $i++) {
            $this->appendAttempt(manual: true);
        }
        $this->reviewer = app(RlsContextRunner::class)->runAsService(fn (): int => Admin::query()->insertGetId([
            'name' => 'Synthetic recovery reviewer',
            'email' => uniqid().'@example.test',
            'password' => 'not-a-real-password',
            'role' => AdminRole::SuperAdmin->value,
            'can_verify_payments' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->crashOnFifthAllocation('synthetic manual fifth allocation crash', fn (): array => $this->review());
        $this->assertFullyRolledBack(10);

        $this->assertSame([
            'decision' => 'settled',
            'allocationCount' => 10,
            'activatedAttemptCount' => 10,
        ], $this->review());
        $this->assertFullySettled(10, actor: 'admin', activated: 10);
    }

    /** @return array<string, mixed> */
    private function pendingAttempt(bool $manual = false): array
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($manual): array {
            $appending = isset($this->bill);
            $identity = $appending ? ['organization' => $this->bill['organization']] : null;
            $fixture = AssessmentAccessFixture::create(identity: $identity);
            $this->attempts[] = $fixture['attempt'];
            $this->participants[] = $fixture['participant'];
            $this->packages[] = $fixture['package'];
            DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'assessment_status' => 'PROVISIONED',
                'funding_mode' => 'INVOICED_TO_ORGANIZATION',
                'metadata' => json_encode([
                    'checkout_contract_version' => 'checkout-v2',
                    'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION',
                ], JSON_THROW_ON_ERROR),
            ]);
            DB::table('assessment_entitlements')->where('id', $fixture['entitlement'])
                ->update(['status' => 'locked', 'ready_at' => null]);
            DB::table('assessment_bill_items')->where('id', $fixture['item'])->update(['settled_at' => null]);
            $method = (int) DB::table('assessment_bills')->where('id', $fixture['bill'])->value('payment_method_id');
            if (isset($this->bill)) {
                DB::table('assessment_bills')->where('id', $fixture['bill'])
                    ->update(['payment_method_id' => $this->bill['paymentMethod']]);
                DB::table('payment_methods')->where('id', $method)->delete();
                $method = $this->bill['paymentMethod'];
            } elseif ($manual) {
                DB::table('payment_methods')->where('id', $method)
                    ->update(['code' => 'manual_transfer', 'is_active' => false]);
            }
            $attributes = $manual ? [
                'gateway_ref' => null,
                'invoice_url' => null,
                'proof_object_key' => 'assessment-bills/ab/'.str_repeat('c', 62).'.jpg',
                'proof_checksum_sha256' => str_repeat('a', 64),
                'proof_mime_type' => 'image/jpeg',
                'proof_size_bytes' => 100,
                'proof_uploaded_at' => '2026-09-01T00:00:00.000000Z',
            ] : ['gateway_ref' => $appending ? null : 'xendit-invoice-reference'];
            DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
                'status' => 'pending',
                'paid_at' => null,
                'verified_at' => null,
                'verified_by_admin_id' => null,
                'rejection_reason' => null,
                ...$attributes,
            ]);
            $bill = DB::table('assessment_bills')->where('id', $fixture['bill'])->sole();

            return [
                ...$fixture,
                'reference' => $bill->public_reference,
                'paymentMethod' => $method,
                'fingerprint' => $manual ? hash('sha256', implode("\0", [
                    $bill->proof_object_key,
                    $bill->proof_checksum_sha256,
                    $bill->proof_mime_type,
                    (string) $bill->proof_size_bytes,
                    CarbonImmutable::parse($bill->proof_uploaded_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
                ])) : null,
            ];
        });
    }

    private function appendAttempt(bool $manual = false): void
    {
        $other = $this->pendingAttempt($manual);
        app(RlsContextRunner::class)->runAsService(function () use ($other): void {
            DB::table('assessment_bill_items')->where('id', $other['item'])->update(['bill_id' => $this->bill['bill']]);
            DB::table('assessment_bills')->where('id', $other['bill'])->delete();
            DB::table('assessment_bills')->where('id', $this->bill['bill'])->update([
                'amount' => DB::raw('amount + 100'),
                'item_count' => DB::raw('item_count + 1'),
            ]);
        });
    }

    private function crashOnFifthAllocation(string $message, callable $operation): void
    {
        $saved = 0;
        AssessmentBillItem::updating(function () use (&$saved, $message): void {
            if (++$saved === 5) {
                throw new RuntimeException($message);
            }
        });
        try {
            $operation();
            $this->fail('Expected synthetic fifth-allocation crash.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        } finally {
            AssessmentBillItem::flushEventListeners();
        }
        $this->assertSame(5, $saved);
    }

    private function assertFullyRolledBack(int $items): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($items): void {
            $bill = DB::table('assessment_bills')->where('id', $this->bill['bill'])->sole();
            $this->assertSame('pending', $bill->status);
            $this->assertNull($bill->paid_at);
            $this->assertNull($bill->verified_at);
            $this->assertNull($bill->verified_by_admin_id);
            $this->assertNull($bill->rejection_reason);
            $this->assertSame($items, DB::table('assessment_bill_items')->where('bill_id', $bill->id)->count());
            $this->assertSame($items, DB::table('assessment_bill_items')->where('bill_id', $bill->id)
                ->whereNull('settled_at')->count());
            $this->assertSame($items, DB::table('assessment_participants')->whereIn('id', $this->attempts)
                ->where('assessment_status', 'PROVISIONED')->count());
            $this->assertSame($items, DB::table('assessment_entitlements')->whereIn('assessment_participant_id', $this->attempts)
                ->where('status', 'locked')->count());
            $this->assertSame(0, DB::table('audit_logs')->where('branch_id', $this->bill['organization'])->count());
            $this->assertSame(0, DB::table('outbox_messages')->where('topic', 'assessment.activation')
                ->whereIn('aggregate_id', array_map(strval(...), $this->attempts))->count());
        });
    }

    private function assertFullySettled(int $items, string $actor, int $activated): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($items, $actor, $activated): void {
            $bill = DB::table('assessment_bills')->where('id', $this->bill['bill'])->sole();
            $this->assertSame('paid', $bill->status);
            $this->assertNotNull($bill->paid_at);
            $settledAt = DB::table('assessment_bill_items')->where('bill_id', $bill->id)->pluck('settled_at');
            $this->assertCount($items, $settledAt);
            $this->assertCount(1, $settledAt->unique());
            $this->assertTrue(CarbonImmutable::parse($bill->paid_at)
                ->equalTo(CarbonImmutable::parse($settledAt->first())));
            if ($actor === 'admin') {
                $this->assertNotNull($bill->verified_at);
                $this->assertSame($this->reviewer, (int) $bill->verified_by_admin_id);
            } else {
                $this->assertNull($bill->verified_at);
                $this->assertNull($bill->verified_by_admin_id);
            }
            $this->assertSame($activated, DB::table('assessment_participants')->whereIn('id', $this->attempts)
                ->where('assessment_status', 'READY')->count());
            $this->assertSame($items - $activated, DB::table('assessment_participants')->whereIn('id', $this->attempts)
                ->where('assessment_status', 'PROVISIONED')->count());
            $this->assertSame($activated, DB::table('assessment_entitlements')->whereIn('assessment_participant_id', $this->attempts)
                ->where('status', 'ready')->count());
            $this->assertSame($items - $activated, DB::table('assessment_entitlements')
                ->whereIn('assessment_participant_id', $this->attempts)->where('status', 'locked')->count());
            $this->assertSame(1, DB::table('audit_logs')->where('branch_id', $this->bill['organization'])
                ->where('action', 'assessment_bill.paid')->where('actor_type', $actor)->count());
            $this->assertSame($activated, DB::table('audit_logs')->where('branch_id', $this->bill['organization'])
                ->where('action', 'assessment.activated')->count());
            $this->assertSame(1 + $activated,
                DB::table('audit_logs')->where('branch_id', $this->bill['organization'])->count());
            $this->assertSame($activated, DB::table('outbox_messages')->where('topic', 'assessment.activation')
                ->whereIn('aggregate_id', array_map(strval(...), $this->attempts))->count());
        });
    }

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    private function finalize(): array
    {
        return app(FinalizeAssessmentBill::class)->execute(new PaymentEvent(
            eventId: 'synthetic-recovery-paid-event',
            providerReference: 'xendit-invoice-reference',
            merchantReference: $this->bill['reference'],
            status: PaymentStatus::Paid,
            occurredAt: now(),
            amount: 1000,
            currency: 'IDR',
        ));
    }

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    private function review(): array
    {
        return app(ReviewAssessmentBillTransfer::class)->execute(new AssessmentBillManualReview(
            actorAdminId: $this->reviewer ?? throw new RuntimeException('Reviewer is missing.'),
            billReference: $this->bill['reference'],
            expectedProofFingerprint: $this->bill['fingerprint'],
            decision: AssessmentBillManualDecision::Approve,
            rejectionCode: null,
        ));
    }
}
