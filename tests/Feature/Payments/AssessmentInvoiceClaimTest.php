<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentCharge;
use App\Models\OutboxMessage;
use App\Models\Participant;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\DispatchIntegrationOutbox;
use App\Services\Notifications\DispatchNotificationOutbox;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class AssessmentInvoiceClaimTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $f;

    private array $second;

    private AssessmentBill $bill;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-01T00:00:00Z');
        Http::preventStrayRequests();
        Http::fake([]);
        Bus::fake();
        Queue::fake();
        config()->set('assessment_integration.checkout.enabled', true);
        $this->f = Fixture::create();
        $this->second = Fixture::create(['organization' => $this->f['organization']]);
        foreach ([$this->f['package'], $this->second['package']] as $packageId) {
            DB::table('package_items')->insert([
                'package_id' => $packageId, 'test_type' => 'dass21', 'sort_order' => 2,
            ]);
        }
        DB::table('assessment_participants')->update(['funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}']);
        $admin = Admin::create(['branch_id' => $this->f['organization'], 'name' => 'Synthetic claim',
            'email' => 'claim@example.test', 'password' => 'synthetic', 'role' => AdminRole::BranchAdmin]);
        $method = DB::table('payment_methods')->insertGetId(['code' => 'xendit', 'display_name' => 'Synthetic', 'is_active' => true]);
        $selection = [Fixture::selection($this->f), Fixture::selection($this->second)];
        $this->bill = app(RlsContextRunner::class)->runAsService(function () use ($admin, $method, $selection) {
            $preview = app(PreviewAssessmentBill::class)->execute($this->f['organization'], $selection, PayerType::Organization);

            return app(ReserveAssessmentBill::class)->execute($admin, $selection, $method, $preview['selectionHash'], 'claim-test');
        });
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        $this->assertNull(app(RlsContextRunner::class)->current());
        parent::tearDown();
    }

    public function test_claim_is_atomic_and_replay_does_not_extend_or_dispatch(): void
    {
        Date::setTestNow('2024-02-29T10:15:00+07:00');
        $first = $this->claim();
        $message = OutboxMessage::sole();
        $this->assertSame(['decision' => 'claimed', 'messageId' => $message->message_id], $first);
        $this->assertSame('issuing', $this->bill->refresh()->status);
        $this->assertSame('assessment.bill.invoice-issuance', $message->topic);
        $this->assertSame('pending', $message->status);
        $this->assertSame(0, $message->attempts);
        $payload = $message->payload;
        $this->assertSame(1, $payload['version']);
        $this->assertSame($this->bill->public_reference, $payload['snapshot']['publicReference']);
        $this->assertSame(200, $payload['snapshot']['amount']);
        $this->assertCount(2, $payload['snapshot']['items']);
        $this->assertSame('2024-03-01T03:15:00Z', $payload['requestedExpiresAt']);
        $this->assertTrue($message->available_at->equalTo('2024-02-29T03:15:00Z'));
        $this->assertTrue($message->created_at->equalTo('2024-02-29T03:15:00Z'));
        $this->assertTrue($message->updated_at->equalTo('2024-02-29T03:15:00Z'));
        $this->assertTrue($message->expires_at->equalTo('2026-02-28T03:15:00Z'));
        $audit = DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->sole();
        $this->assertSame('2024-02-29 03:15:00', $audit->occurred_at);
        $this->assertSame('2029-02-28 03:15:00', $audit->expires_at);
        $this->assertSame([
            'messageId' => $message->message_id,
            'reference' => $this->bill->public_reference,
            'snapshotHash' => $payload['snapshotHash'],
        ], json_decode((string) $audit->context, true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('"snapshot":', (string) $audit->context);
        $this->assertStringNotContainsString('requestedExpiresAt', (string) $audit->context);
        $before = $message->getAttributes();
        Date::setTestNow('2024-03-02T03:15:00Z');
        config()->set('assessment_billing.invoice_duration_hours', 48);
        DB::table('packages')->where('id', $this->f['package'])->update(['amount' => 999]);
        $this->assertSame(['decision' => 'replayed', 'messageId' => $message->message_id], $this->claim());
        $this->assertSame($before, $message->fresh()->getAttributes());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
        $this->assertDatabaseCount('outbox_messages', 1);
        foreach (['gateway_ref', 'invoice_url', 'expires_at', 'paid_at'] as $field) {
            $this->assertNull($this->bill->getAttribute($field));
        }
        foreach (['orders', 'entitlements', 'assessment_entitlements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, DB::table('assessment_bill_items')->whereNotNull('settled_at')->count());
        $this->assertSame(0, DB::table('assessment_charges')->whereNotNull('free_settled_at')->count());
        $this->assertSame('{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}',
            DB::table('assessment_participants')->where('id', $this->f['attempt'])->value('metadata'));
        $this->assertSame(0, app(DispatchNotificationOutbox::class)->handle());
        $this->assertSame(0, app(DispatchIntegrationOutbox::class)->handle());
    }

    #[DataProvider('roles')]
    public function test_only_explicit_service_context_can_claim(?string $role): void
    {
        $this->expectException(LogicException::class);
        $run = fn () => app(ClaimAssessmentBillInvoice::class)->execute($this->f['organization'], $this->bill->id);
        $role === null ? $run() : app(RlsContextRunner::class)->run(new RlsContext($role, $this->f['organization'], $this->f['participant']), $run);
    }

    public static function roles(): iterable
    {
        foreach ([null, 'participant', 'branch_admin', 'super_admin', 'staff', 'psychologist'] as $role) {
            yield [$role];
        }
    }

    #[DataProvider('invalidDurations')]
    public function test_invalid_duration_is_rejected_without_claim(mixed $hours): void
    {
        config()->set('assessment_billing.invoice_duration_hours', $hours);
        try {
            $this->claim();
            $this->fail('Invalid duration must not claim.');
        } catch (LogicException) {
            $this->assertUnclaimed();
        }
    }

    public static function invalidDurations(): iterable
    {
        foreach ([0, -1, null, '24', 1.5, true, PHP_INT_MAX] as $hours) {
            yield [$hours];
        }
    }

    #[DataProvider('corruptBills')]
    public function test_corrupt_or_ineligible_bill_is_rejected_without_writes(string $case): void
    {
        $bill = DB::table('assessment_bills')->where('id', $this->bill->id);
        match ($case) {
            'sum' => $bill->update(['amount' => 201]),
            'count' => $bill->update(['item_count' => 3]),
            'zero' => $bill->update(['amount' => 0]),
            'reference' => $bill->update(['public_reference' => 'NOT_AB']),
            'key' => $bill->update(['idempotency_key' => '']),
            'paid' => $bill->update(['status' => 'paid', 'paid_at' => now()]),
            'expired' => $bill->update(['status' => 'expired']),
            'rejected' => $bill->update(['status' => 'rejected']),
            'missing-intent' => $bill->update(['status' => 'issuing']),
            'gateway' => $bill->update(['gateway_ref' => 'unexpected']),
            'settled-item' => DB::table('assessment_bill_items')->update(['settled_at' => now()]),
            'snapshot' => DB::table('assessment_charges')->update(['price_snapshot' => '{}']),
            'policy-snapshot' => DB::table('assessment_charges')->update(['policy_snapshot' => '{}']),
            'initial-funding' => DB::table('assessment_participants')->update(['metadata' => '{"checkout_contract_version":"checkout-v2"}']),
            'funding' => DB::table('assessment_participants')->update(['funding_mode' => 'COMMERCIAL_SELF_PAY']),
            'revoked' => DB::table('assessment_participants')->update(['revoked_at' => now()]),
            'method-off' => DB::table('payment_methods')->where('id', $this->bill->payment_method_id)->update(['is_active' => false]),
            'client-off' => DB::table('integration_clients')->where('id', $this->f['client'])->update(['enabled' => false]),
            'policy-off' => DB::table('branches')->where('id', $this->f['organization'])->update(['allowed_payer_types' => '["self"]']),
            'opt-in-off' => config()->set('assessment_integration.checkout.enabled', false),
        };
        $before = DB::table('assessment_bills')->where('id', $this->bill->id)->first();
        try {
            $this->claim();
            $this->fail('Invalid bill must not claim.');
        } catch (DomainException) {
            $this->assertEquals($before, DB::table('assessment_bills')->where('id', $this->bill->id)->first());
            $this->assertDatabaseCount('outbox_messages', 0);
            $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
        }
    }

    public static function corruptBills(): iterable
    {
        foreach (['sum', 'count', 'zero', 'reference', 'key', 'paid', 'expired', 'rejected',
            'missing-intent', 'gateway', 'settled-item', 'snapshot', 'policy-snapshot',
            'initial-funding', 'funding', 'revoked', 'method-off', 'client-off', 'policy-off', 'opt-in-off'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('corruptIntents')]
    public function test_corrupt_replay_never_backfills_or_rearms(string $case): void
    {
        $this->claim();
        $message = OutboxMessage::sole();
        $payload = $message->payload;
        match ($case) {
            'amount' => $payload['snapshot']['amount']++,
            'extra' => $payload['extra'] = true,
            'expiry' => $payload['requestedExpiresAt'] = 'tomorrow',
            'extended-expiry' => $payload['requestedExpiresAt'] = '2026-09-03T00:00:00Z',
            'hash' => $payload['snapshotHash'] = str_repeat('0', 64),
            'identity' => $payload['snapshot']['organizationId']++,
            'initial-funding' => DB::table('assessment_participants')->where('id', $this->f['attempt'])
                ->update(['metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":"INVOICED_TO_ORGANIZATION"}']),
            'counter' => $message->attempts = 1,
            'topic' => $message->topic = 'participant.activation',
            'dedup-key' => $message->deduplication_key = str_repeat('0', 64),
            'outbox-expiry' => $message->expires_at = now()->addDay(),
            'bill-key' => $this->bill->update(['idempotency_key' => 'changed-key']),
            'version' => $payload['version'] = 2,
            'missing-expiry' => $payload['requestedExpiresAt'] = null,
            'invalid-date' => $payload['claimedAt'] = '2026-99-99T00:00:00Z',
            'invalid-hours' => $payload['invoiceDurationHours'] = PHP_INT_MAX,
        };
        $message->payload = $payload;
        $message->save();
        $before = $message->getAttributes();
        try {
            $this->claim();
            $this->fail('Corrupt replay must fail closed.');
        } catch (DomainException) {
            $this->assertSame($before, $message->fresh()->getAttributes());
            $this->assertSame('issuing', $this->bill->fresh()->status);
            $this->assertDatabaseCount('outbox_messages', 1);
        }
    }

    public static function corruptIntents(): iterable
    {
        foreach (['amount', 'extra', 'expiry', 'extended-expiry', 'hash', 'identity', 'initial-funding',
            'counter', 'topic', 'dedup-key', 'outbox-expiry', 'bill-key', 'version', 'missing-expiry', 'invalid-date', 'invalid-hours'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_unknown_and_in_flight_are_not_claimed_again(): void
    {
        $this->claim();
        $message = OutboxMessage::sole();
        foreach ([['issuing', 'processing'], ['unknown', 'failed']] as [$status, $delivery]) {
            $this->bill->update(['status' => $status]);
            $message->forceFill(['status' => $delivery, 'attempts' => 1])->save();
            $before = $message->getAttributes();
            $this->assertSame(['decision' => 'recovery_required', 'messageId' => $message->message_id], $this->claim());
            $this->assertSame($before, $message->fresh()->getAttributes());
        }
    }

    public function test_manual_transfer_is_not_applicable(): void
    {
        DB::table('payment_methods')->where('id', $this->bill->payment_method_id)->update(['code' => 'manual_transfer']);
        $this->assertSame(['decision' => 'not_applicable', 'messageId' => null], $this->claim());
        $this->assertUnclaimed();
    }

    public function test_foreign_scope_is_denied(): void
    {
        $this->expectException(DomainException::class);
        app(RlsContextRunner::class)->runAsService(fn () => app(ClaimAssessmentBillInvoice::class)->execute($this->second['organization'] + 999, $this->bill->id));
    }

    #[DataProvider('failurePoints')]
    public function test_failed_outbox_or_audit_insert_rolls_back_claim(string $table): void
    {
        $once = true;
        DB::listen(function (QueryExecuted $query) use ($table, &$once): void {
            if ($once && str_starts_with($query->sql, 'insert') && str_contains($query->sql, $table)) {
                $once = false;
                throw new RuntimeException('synthetic-claim-failure');
            }
        });
        try {
            $this->claim();
            $this->fail('Injection did not execute.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic-claim-failure', $e->getMessage());
            $this->assertFalse($once);
            $this->assertUnclaimed();
        }
    }

    public static function failurePoints(): iterable
    {
        yield ['outbox_messages'];
        yield ['audit_logs'];
    }

    private function claim(): array
    {
        return app(RlsContextRunner::class)->runAsService(fn () => app(ClaimAssessmentBillInvoice::class)->execute($this->f['organization'], $this->bill->id));
    }

    public function test_database_rejects_linkage_and_currency_corruption_without_disabling_constraints(): void
    {
        foreach (['participant_id' => $this->f['participant'], 'currency' => 'USD'] as $field => $value) {
            try {
                DB::table('assessment_bill_items')->where('bill_id', $this->bill->id)->update([$field => $value]);
                $this->fail('Composite linkage must reject corruption.');
            } catch (QueryException $e) {
                $this->assertSame('23000', $e->getCode());
                $this->assertUnclaimed();
            }
        }
    }

    public function test_valid_self_payer_claim_uses_only_its_own_attempt(): void
    {
        $f = Fixture::create(['organization' => $this->f['organization']]);
        DB::table('package_items')->insert([
            'package_id' => $f['package'], 'test_type' => 'dass21', 'sort_order' => 2,
        ]);
        DB::table('assessment_participants')->where('id', $f['attempt'])->update([
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":"COMMERCIAL_SELF_PAY"}']);
        app(RlsContextRunner::class)->runAsService(function () use ($f): void {
            $participant = Participant::findOrFail($f['participant']);
            $selection = [Fixture::selection($f)];
            $preview = app(PreviewAssessmentBill::class)->execute($f['organization'], $selection, PayerType::SelfPay, $participant->id);
            $bill = app(ReserveAssessmentBill::class)->execute($participant, $selection, $this->bill->payment_method_id, $preview['selectionHash'], 'self');
            $result = app(ClaimAssessmentBillInvoice::class)->execute($f['organization'], $bill->id);
            $this->assertSame('claimed', $result['decision']);
            $snapshot = OutboxMessage::sole()->payload['snapshot'];
            $this->assertSame('self', $snapshot['payerType']);
            $this->assertSame($participant->id, $snapshot['payerParticipantId']);
            $this->assertCount(1, $snapshot['items']);
            $this->assertSame('COMMERCIAL_SELF_PAY', $snapshot['items'][0]['initialFundingMode']);
        });
    }

    public function test_positive_item_amounts_that_overflow_total_are_rejected(): void
    {
        // Rebuild valid individual FK links; do not turn off constraints to fabricate the total.
        $item = DB::table('assessment_bill_items')->where('bill_id', $this->bill->id)->first();
        DB::table('assessment_bill_items')->where('id', $item->id)->delete();
        $charge = AssessmentCharge::findOrFail($item->charge_id);
        $price = $charge->price_snapshot;
        $price['baseAmount'] = $price['amount'] = PHP_INT_MAX;
        $charge->update(['base_amount' => PHP_INT_MAX, 'amount' => PHP_INT_MAX, 'price_snapshot' => $price]);
        DB::table('assessment_bill_items')->insert(array_replace((array) $item, ['amount' => PHP_INT_MAX]));
        $this->bill->update(['amount' => PHP_INT_MAX]);
        try {
            $this->claim();
            $this->fail('Overflow must not claim.');
        } catch (DomainException $e) {
            $this->assertSame('INVOICE_TOTAL_OVERFLOW', $e->getMessage());
            $this->assertUnclaimed();
        }
    }

    public function test_outbox_retention_date_must_be_representable(): void
    {
        Date::setTestNow('9998-09-01T00:00:00Z');
        try {
            $this->claim();
            $this->fail('Unrepresentable retention horizon must fail.');
        } catch (LogicException) {
            $this->assertUnclaimed();
        }
    }

    public function test_deleted_intent_after_claim_never_gets_recreated(): void
    {
        $this->claim();
        OutboxMessage::query()->delete();
        try {
            $this->claim();
            $this->fail('Deleted intent must not be replaced.');
        } catch (DomainException) {
            $this->assertSame('issuing', $this->bill->refresh()->status);
            $this->assertDatabaseCount('outbox_messages', 0);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
        }
    }

    private function assertUnclaimed(): void
    {
        $this->assertSame('reserved', $this->bill->refresh()->status);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
    }
}
