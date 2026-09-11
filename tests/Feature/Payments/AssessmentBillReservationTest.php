<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class AssessmentBillReservationTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $fixture;

    private Admin $admin;

    private int $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = $this->createFixture();
        $this->admin = Admin::create(['branch_id' => $this->fixture['organization'], 'name' => 'Synthetic',
            'email' => 'reservation@example.test', 'password' => 'synthetic-password', 'role' => AdminRole::BranchAdmin]);
        $this->method = DB::table('payment_methods')->insertGetId(['code' => 'manual_transfer', 'display_name' => 'Synthetic', 'is_active' => true]);
    }

    /**
     * @param  array<string, int>|null  $identity
     * @return array<string, int>
     */
    private function createFixture(?array $identity = null, int $amount = 100): array
    {
        $fixture = Fixture::create($identity, $amount);
        DB::table('package_items')->insert([
            'package_id' => $fixture['package'],
            'test_type' => 'dass21',
            'sort_order' => 2,
        ]);

        return $fixture;
    }

    private function preview(array $selection, ?Participant $participant = null): array
    {
        return app(RlsContextRunner::class)->runAsService(fn () => app(PreviewAssessmentBill::class)->execute(
            $this->fixture['organization'], $selection, $participant === null ? PayerType::Organization : PayerType::SelfPay, $participant?->id));
    }

    private function reserve(array $selection, string $hash, string $key = 'intent-one', Admin|Participant|null $principal = null): AssessmentBill
    {
        return app(RlsContextRunner::class)->runAsService(fn () => app(ReserveAssessmentBill::class)->execute(
            $principal ?? $this->admin, $selection, $this->method, $hash, $key));
    }

    public function test_ten_attempts_create_one_reserved_bill_and_no_access_or_external_effects(): void
    {
        $selection = [Fixture::selection($this->fixture, true)];
        for ($i = 1; $i < 10; $i++) {
            $selection[] = Fixture::selection($this->createFixture(['organization' => $this->fixture['organization']]));
        }
        $preview = $this->preview($selection);
        $bill = $this->reserve($selection, $preview['selectionHash']);
        $this->assertSame('reserved', $bill->status);
        $this->assertSame(1030, $bill->amount);
        $this->assertSame('IDR', $bill->currency);
        $this->assertSame('organization', $bill->payer_type);
        $this->assertNull($bill->payer_participant_id);
        $this->assertSame($this->method, $bill->payment_method_id);
        $this->assertSame(10, $bill->item_count);
        $this->assertMatchesRegularExpression('/^AB_[0-9A-HJKMNP-TV-Z]{26}$/', $bill->public_reference);
        $this->assertNull($bill->gateway_ref);
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 10);
        $this->assertDatabaseCount('assessment_bill_items', 10);
        $this->assertSame(1030, (int) DB::table('assessment_bill_items')->sum('amount'));
        $this->assertSame(0, DB::table('assessment_bill_items')->whereNotNull('settled_at')->count());
        $audit = DB::table('audit_logs')->where('action', 'assessment_bill.reserved')->sole();
        $anchor = CarbonImmutable::parse((string) $audit->occurred_at)->utc();
        $this->assertSame(
            app(RetentionPolicy::class)->expiresAt(RetentionDataClass::Audit, $anchor)->format('Y-m-d H:i:s.uP'),
            CarbonImmutable::parse((string) $audit->expires_at)->utc()->format('Y-m-d H:i:s.uP'),
        );
        $this->assertSame(
            '2029-02-28 03:15:00.000000+00:00',
            app(RetentionPolicy::class)->expiresAt(
                RetentionDataClass::Audit,
                CarbonImmutable::parse('2024-02-29 10:15:00+07:00')->utc(),
            )->format('Y-m-d H:i:s.uP'),
        );
        $this->assertSame([
            'reference' => $bill->public_reference,
            'selectionHash' => $bill->selection_hash,
            'amount' => 1030,
            'currency' => 'IDR',
            'itemCount' => 10,
            'payerType' => 'organization',
            'paymentMethodId' => $this->method,
        ], json_decode((string) $audit->context, true, flags: JSON_THROW_ON_ERROR));
        foreach (['assessment_entitlements', 'orders', 'entitlements', 'outbox_messages'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_same_intent_replays_even_after_policy_and_method_are_disabled(): void
    {
        $selection = [Fixture::selection($this->fixture)];
        $hash = $this->preview($selection)['selectionHash'];
        $bill = $this->reserve($selection, $hash);
        DB::table('branches')->where('id', $this->fixture['organization'])->update(['allowed_payer_types' => '[]']);
        DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]);
        $this->assertSame($bill->id, $this->reserve($selection, $hash)->id);
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.reserved')->count());
        $this->expectExceptionMessage('IDEMPOTENCY_CONFLICT');
        $this->expectException(DomainException::class);
        $this->reserve([Fixture::selection($this->fixture, true)], $hash);
    }

    public function test_changed_price_rejects_the_entire_selection(): void
    {
        $selection = [Fixture::selection($this->fixture)];
        $hash = $this->preview($selection)['selectionHash'];
        DB::table('packages')->where('id', $this->fixture['package'])->update(['amount' => 200]);
        try {
            $this->reserve($selection, $hash);
            $this->fail('Stale preview accepted.');
        } catch (DomainException $e) {
            $this->assertSame('PREVIEW_CHANGED', $e->getMessage());
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
    }

    public function test_self_reservation_derives_payer_from_persisted_participant(): void
    {
        $participant = Participant::findOrFail($this->fixture['participant']);
        $selection = [Fixture::selection($this->fixture)];
        $bill = $this->reserve($selection, $this->preview($selection, $participant)['selectionHash'], principal: $participant);
        $this->assertSame('self', $bill->payer_type);
        $this->assertSame($participant->id, $bill->payer_participant_id);
        $this->assertSame($participant->id, DB::table('assessment_bill_items')->value('payer_participant_id'));
    }

    public function test_free_items_are_not_claimed_or_settled_and_all_free_requires_separate_flow(): void
    {
        $free = $this->createFixture(['organization' => $this->fixture['organization']], 0);
        $selection = [Fixture::selection($free), Fixture::selection($this->fixture)];
        $bill = $this->reserve($selection, $this->preview($selection)['selectionHash']);
        $this->assertSame(100, $bill->amount);
        $this->assertSame(1, $bill->item_count);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseMissing('assessment_charges', ['assessment_participant_id' => $free['attempt']]);
        $onlyFree = [Fixture::selection($free)];
        $this->expectExceptionMessage('FREE_CHECKOUT_REQUIRED');
        $this->expectException(DomainException::class);
        $this->reserve($onlyFree, $this->preview($onlyFree)['selectionHash'], 'free-intent');
    }

    public function test_existing_snapshot_is_preserved_including_original_policy(): void
    {
        $snapshot = app(AssessmentPriceSnapshot::class)->capture(TestPackage::with('items')->findOrFail($this->fixture['package']), false);
        $charge = AssessmentCharge::create(['assessment_participant_id' => $this->fixture['attempt'],
            'organization_id' => $this->fixture['organization'], 'participant_id' => $this->fixture['participant'],
            'package_id' => $this->fixture['package'], 'payer_type' => 'organization', 'base_amount' => 100,
            'consultation_amount' => 0, 'amount' => 100, 'currency' => 'IDR',
            'price_snapshot' => $snapshot, 'policy_snapshot' => ['original' => true]]);
        DB::table('packages')->where('id', $this->fixture['package'])->update(['amount' => 999]);
        $selection = [Fixture::selection($this->fixture)];
        $bill = $this->reserve($selection, $this->preview($selection)['selectionHash']);
        $this->assertSame(100, $bill->amount);
        $this->assertSame($snapshot, $charge->refresh()->price_snapshot);
        $this->assertSame(['original' => true], $charge->policy_snapshot);
        $this->assertSame($charge->id, DB::table('assessment_bill_items')->value('charge_id'));
    }

    #[DataProvider('invalidatingChanges')]
    public function test_changed_prerequisite_rejects_without_partial_writes(string $table, string $fixtureKey, array $values): void
    {
        $selection = [Fixture::selection($this->fixture)];
        $hash = $this->preview($selection)['selectionHash'];
        DB::table($table)->where('id', $this->fixture[$fixtureKey])->update($values);
        try {
            $this->reserve($selection, $hash);
            $this->fail('Changed prerequisite accepted.');
        } catch (DomainException $e) {
            $this->assertSame('PREVIEW_CHANGED', $e->getMessage());
        }
        foreach (['assessment_bills', 'assessment_bill_items', 'assessment_charges'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public static function invalidatingChanges(): iterable
    {
        yield 'policy OFF' => ['branches', 'organization', ['allowed_payer_types' => '[]']];
        yield 'source OFF' => ['integration_sources', 'source', ['status' => 'INACTIVE']];
        yield 'client OFF' => ['integration_clients', 'client', ['enabled' => false]];
        yield 'revoked' => ['assessment_participants', 'attempt', ['revoked_at' => '2026-08-31 00:00:00']];
        yield 'legacy' => ['assessment_participants', 'attempt', ['metadata' => null]];
        yield 'deleted participant' => ['participants', 'participant', ['deleted_at' => '2026-08-31 00:00:00']];
        yield 'consultation' => ['packages', 'package', ['amount' => 0]];
    }

    public function test_injected_failure_on_fifth_item_rolls_back_bill_all_charges_items_and_audit(): void
    {
        $selection = [Fixture::selection($this->fixture)];
        for ($i = 1; $i < 10; $i++) {
            $selection[] = Fixture::selection($this->createFixture(['organization' => $this->fixture['organization']]));
        }
        $hash = $this->preview($selection)['selectionHash'];
        $count = 0;
        $event = 'eloquent.creating: '.AssessmentBillItem::class;
        Event::listen($event, function () use (&$count): void {
            if (++$count === 5) {
                throw new RuntimeException('synthetic-fifth-item-failure');
            }
        });
        try {
            $this->reserve($selection, $hash);
            $this->fail('Failure injection did not run.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic-fifth-item-failure', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame(5, $count);
        foreach (['assessment_bills', 'assessment_bill_items', 'assessment_charges', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(10, $this->reserve($selection, $hash)->item_count);
    }

    public function test_reordered_items_and_object_keys_replay_same_bill(): void
    {
        $other = $this->createFixture(['organization' => $this->fixture['organization']]);
        $selection = [Fixture::selection($this->fixture), Fixture::selection($other, true)];
        $hash = $this->preview($selection)['selectionHash'];
        $bill = $this->reserve($selection, $hash);
        $reordered = array_map(fn ($item) => array_reverse($item, true), array_reverse($selection));
        $this->assertSame($bill->id, $this->reserve($reordered, $hash)->id);
    }

    #[DataProvider('disallowedRoles')]
    public function test_persisted_admin_role_is_required_even_for_replay(AdminRole $role): void
    {
        $selection = [Fixture::selection($this->fixture)];
        $hash = $this->preview($selection)['selectionHash'];
        $this->reserve($selection, $hash);
        DB::table('admins')->where('id', $this->admin->id)->update(['role' => $role->value]);
        $this->expectException(AuthorizationException::class);
        $this->reserve($selection, $hash);
    }

    public static function disallowedRoles(): iterable
    {
        yield [AdminRole::Staff];
        yield [AdminRole::Psychologist];
        yield [AdminRole::SuperAdmin];
    }

    public function test_foreign_selection_and_self_identity_spoof_are_rejected(): void
    {
        $foreign = $this->createFixture();
        $hash = str_repeat('a', 64);
        $participant = Participant::findOrFail($this->fixture['participant']);
        $participant->branch_id = $foreign['organization'];
        try {
            $this->reserve([Fixture::selection($this->fixture), Fixture::selection($foreign)], $hash);
            $this->fail('Cross organization selection accepted.');
        } catch (DomainException $e) {
            $this->assertSame('PREVIEW_CHANGED', $e->getMessage());
        }
        try {
            $this->reserve([Fixture::selection($foreign)], $hash, principal: $participant);
            $this->fail('Forged participant branch accepted.');
        } catch (DomainException $e) {
            $this->assertSame('PREVIEW_CHANGED', $e->getMessage());
        }
        $this->assertDatabaseCount('assessment_bills', 0);
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_bill_never_releases_the_claim(string $status): void
    {
        $selection = [Fixture::selection($this->fixture)];
        $hash = $this->preview($selection)['selectionHash'];
        $bill = $this->reserve($selection, $hash);
        $bill->update(['status' => $status, 'paid_at' => $status === 'paid' ? now() : null]);
        $this->assertSame($bill->id, $this->reserve($selection, $hash)->id);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PREVIEW_CHANGED');
        $this->reserve($selection, $hash, 'different-intent');
    }

    public static function terminalStatuses(): iterable
    {
        yield ['paid'];
        yield ['expired'];
        yield ['rejected'];
    }

    public function test_method_change_with_same_key_is_conflict_and_inactive_new_method_is_rejected(): void
    {
        $selection = [Fixture::selection($this->fixture)];
        $hash = $this->preview($selection)['selectionHash'];
        $this->reserve($selection, $hash);
        $this->method = DB::table('payment_methods')->insertGetId(['code' => 'xendit', 'display_name' => 'Synthetic', 'is_active' => false]);
        foreach (['intent-one' => 'IDEMPOTENCY_CONFLICT', 'intent-new' => 'PAYMENT_METHOD_NOT_AVAILABLE'] as $key => $reason) {
            try {
                $this->reserve($selection, $hash, $key);
                $this->fail('Invalid method accepted.');
            } catch (DomainException $e) {
                $this->assertSame($reason, $e->getMessage());
            }
        }
    }

    public function test_service_context_required_and_invalid_request_rejected(): void
    {
        try {
            app(ReserveAssessmentBill::class)->execute($this->admin, [], $this->method, str_repeat('a', 64), 'key');
            $this->fail('Non-service reservation accepted.');
        } catch (LogicException $e) {
            $this->assertSame('Reservation requires service RLS context.', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->reserve([Fixture::selection($this->fixture)], 'invalid-hash');
    }

    public function test_configured_limit_is_enforced_before_any_write(): void
    {
        config()->set('assessment_billing.max_items', 2);
        $other = $this->createFixture(['organization' => $this->fixture['organization']]);
        $selection = [Fixture::selection($this->fixture), Fixture::selection($other)];
        $this->assertSame(2, $this->reserve($selection, $this->preview($selection)['selectionHash'])->item_count);
        $this->expectException(InvalidArgumentException::class);
        $this->reserve([...$selection, ['assessmentParticipantId' => 99999, 'consultationRequested' => false]], str_repeat('a', 64), 'too-large');
    }

    public function test_default_limit_accepts_one_hundred_attempts_and_rejects_one_hundred_one(): void
    {
        $this->assertSame(100, config('assessment_billing.max_items'));
        $selection = [Fixture::selection($this->fixture)];
        for ($i = 1; $i < 100; $i++) {
            $selection[] = Fixture::selection($this->createFixture(['organization' => $this->fixture['organization']]));
        }
        $bill = $this->reserve($selection, $this->preview($selection)['selectionHash']);
        $this->assertSame(100, $bill->item_count);
        $this->assertSame(10000, $bill->amount);
        $this->expectException(InvalidArgumentException::class);
        $this->reserve([...$selection, ['assessmentParticipantId' => 999999, 'consultationRequested' => false]], str_repeat('a', 64), 'over-default-limit');
    }

    public function test_invalid_selections_and_keys_are_rejected_without_persistence(): void
    {
        foreach (AssessmentBillPreviewTest::invalidSelections() as [$selection]) {
            try {
                $this->reserve($selection, str_repeat('a', 64));
                $this->fail('Malformed selection accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('INVALID_BILL_SELECTION', $e->getMessage());
            }
        }
        foreach (['', ' ', str_repeat('a', 129), "key\n"] as $key) {
            try {
                $this->reserve([Fixture::selection($this->fixture)], str_repeat('a', 64), $key);
                $this->fail('Malformed idempotency key accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('INVALID_BILL_REQUEST', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
    }

    public function test_total_overflow_is_rejected_without_any_writes(): void
    {
        DB::table('packages')->where('id', $this->fixture['package'])->update(['amount' => PHP_INT_MAX]);
        $other = $this->createFixture(['organization' => $this->fixture['organization']], 1);
        try {
            $this->reserve([Fixture::selection($this->fixture), Fixture::selection($other)], str_repeat('a', 64));
            $this->fail('Overflow accepted.');
        } catch (DomainException $e) {
            $this->assertSame('TOTAL_OVERFLOW', $e->getMessage());
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
    }

    public function test_deleted_principal_cannot_replay(): void
    {
        $selection = [Fixture::selection($this->fixture)];
        $hash = $this->preview($selection)['selectionHash'];
        $this->reserve($selection, $hash);
        DB::table('admins')->where('id', $this->admin->id)->update(['deleted_at' => now()]);
        $this->expectException(AuthorizationException::class);
        $this->reserve($selection, $hash);
    }
}
