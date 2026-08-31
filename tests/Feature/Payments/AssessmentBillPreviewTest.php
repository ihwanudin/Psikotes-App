<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\PreviewAssessmentBill;
use App\Enums\PayerType;
use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentBillingFixture;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class AssessmentBillPreviewTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = Fixture::create();
    }

    private function preview(?array $selection = null, PayerType $payer = PayerType::Organization, ?int $participant = null): array
    {
        return app(RlsContextRunner::class)->runAsService(fn () => app(PreviewAssessmentBill::class)->execute(
            $this->fixture['organization'], $selection ?? [Fixture::selection($this->fixture)], $payer, $participant));
    }

    public function test_preview_reads_dynamic_prices_and_is_read_only(): void
    {
        $first = $this->preview([Fixture::selection($this->fixture, true)]);
        $this->assertSame(130, $first['totalAmount']);
        $this->assertTrue($first['canReserve']);
        DB::table('packages')->where('id', $this->fixture['package'])->update(['amount' => 200, 'consultation_amount' => 40]);
        $second = $this->preview([Fixture::selection($this->fixture, true)]);
        $this->assertSame(240, $second['totalAmount']);
        $this->assertNotSame($first['selectionHash'], $second['selectionHash']);
        foreach (['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements', 'orders', 'entitlements', 'outbox_messages'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_free_items_are_separate_and_hash_is_order_independent(): void
    {
        $free = Fixture::create(['organization' => $this->fixture['organization']], 0);
        $list = [Fixture::selection($free), Fixture::selection($this->fixture)];
        $result = $this->preview($list);
        $this->assertSame(100, $result['totalAmount']);
        $this->assertSame(1, $result['freeCount']);
        $this->assertSame(1, $result['paidCount']);
        $this->assertSame($result['selectionHash'], $this->preview(array_reverse($list))['selectionHash']);
        $onlyFree = $this->preview([Fixture::selection($free)]);
        $this->assertSame(0, $onlyFree['totalAmount']);
        $this->assertFalse($onlyFree['canReserve']);
    }

    public function test_foreign_and_missing_attempts_are_indistinguishable_and_no_partial_total(): void
    {
        $foreign = Fixture::create();
        $foreignResult = $this->preview([Fixture::selection($foreign)]);
        $missing = $this->preview([['assessmentParticipantId' => 999999, 'consultationRequested' => false]]);
        $this->assertSame($missing['items'][0]['reason'], $foreignResult['items'][0]['reason']);
        $this->assertNull($foreignResult['items'][0]['snapshot']);
        $mixed = $this->preview([Fixture::selection($this->fixture), Fixture::selection($foreign)]);
        $this->assertNull($mixed['totalAmount']);
        $this->assertNull($mixed['selectionHash']);
        $this->assertFalse($mixed['canReserve']);
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_selection_rejected(array $selection): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->preview($selection);
    }

    public static function invalidSelections(): iterable
    {
        yield [[]];
        yield [[['assessmentParticipantId' => '1', 'consultationRequested' => false]]];
        yield [[['assessmentParticipantId' => 1, 'consultationRequested' => 1]]];
        yield [[['assessmentParticipantId' => 1, 'consultationRequested' => false, 'amount' => 1]]];
        yield [[['assessmentParticipantId' => 0, 'consultationRequested' => false]]];
        yield [[['assessmentParticipantId' => 1]]];
        yield [[['assessmentParticipantId' => 1, 'consultationRequested' => false], ['assessmentParticipantId' => 1, 'consultationRequested' => true]]];
    }

    public function test_configured_limit_and_limit_plus_one(): void
    {
        config()->set('assessment_billing.max_items', 2);
        $other = Fixture::create(['organization' => $this->fixture['organization']]);
        $selection = [Fixture::selection($this->fixture), Fixture::selection($other)];
        $this->assertSame(200, $this->preview($selection)['totalAmount']);
        $this->expectException(InvalidArgumentException::class);
        $this->preview([...$selection, ['assessmentParticipantId' => 999999, 'consultationRequested' => false]]);
    }

    public function test_ten_attempts_have_one_preview_total_without_creating_bill(): void
    {
        $selection = [Fixture::selection($this->fixture)];
        for ($index = 1; $index < 10; $index++) {
            $selection[] = Fixture::selection(Fixture::create(['organization' => $this->fixture['organization']]));
        }
        $this->assertSame(1000, $this->preview($selection)['totalAmount']);
        $this->assertDatabaseCount('assessment_bills', 0);
    }

    public function test_policy_off_and_legacy_attempt_are_not_billable(): void
    {
        DB::table('integration_sources')->where('id', $this->fixture['source'])->update(['allowed_payer_types' => '["self"]']);
        $this->assertSame('PAYER_NOT_ALLOWED', $this->preview()['items'][0]['reason']);
        DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->update(['metadata' => null]);
        $this->assertSame('CHECKOUT_ATTEMPT_REQUIRED', $this->preview()['items'][0]['reason']);
    }

    public function test_self_payer_requires_matching_participant(): void
    {
        $this->assertTrue($this->preview(null, PayerType::SelfPay, $this->fixture['participant'])['canReserve']);
        $this->assertSame('ASSESSMENT_NOT_AVAILABLE', $this->preview(null, PayerType::SelfPay, 999999)['items'][0]['reason']);
    }

    public function test_stored_snapshot_is_used_without_repricing(): void
    {
        $charge = $this->charge();
        DB::table('packages')->where('id', $this->fixture['package'])->update(['amount' => 999]);
        $this->assertSame(100, $this->preview()['totalAmount']);
        $snapshot = $charge->price_snapshot;
        $snapshot['baseAmount'] = $snapshot['amount'] = 0;
        $charge->update(['base_amount' => 0, 'amount' => 0, 'price_snapshot' => $snapshot, 'free_settled_at' => now()]);
        $this->assertSame('CHARGE_ALREADY_SETTLED', $this->preview()['items'][0]['reason']);
    }

    public function test_total_overflow_rejects_whole_preview(): void
    {
        DB::table('packages')->where('id', $this->fixture['package'])->update(['amount' => PHP_INT_MAX]);
        $other = Fixture::create(['organization' => $this->fixture['organization']], 1);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('TOTAL_OVERFLOW');
        $this->preview([Fixture::selection($this->fixture), Fixture::selection($other)]);
    }

    public function test_service_context_is_required(): void
    {
        $this->expectException(LogicException::class);
        app(PreviewAssessmentBill::class)->execute($this->fixture['organization'], [Fixture::selection($this->fixture)], PayerType::Organization);
    }

    private function charge(): AssessmentCharge
    {
        $snapshot = app(AssessmentPriceSnapshot::class)->capture(TestPackage::with('items')->findOrFail($this->fixture['package']), false);

        return AssessmentCharge::create(['assessment_participant_id' => $this->fixture['attempt'], 'organization_id' => $this->fixture['organization'],
            'participant_id' => $this->fixture['participant'], 'package_id' => $this->fixture['package'], 'payer_type' => 'organization',
            'base_amount' => 100, 'consultation_amount' => 0, 'consultation_requested' => false, 'amount' => 100, 'currency' => 'IDR',
            'price_snapshot' => $snapshot, 'policy_snapshot' => ['payerType' => 'organization']]);
    }

    public function test_claimed_charge_is_not_available_even_when_bill_expired(): void
    {
        $charge = $this->charge();
        $bill = AssessmentBillingFixture::create('organization', $this->fixture);
        DB::table('assessment_bill_items')->insert(['bill_id' => $bill['bill'], 'charge_id' => $charge->id,
            'organization_id' => $this->fixture['organization'], 'participant_id' => $this->fixture['participant'],
            'payer_type' => 'organization', 'amount' => 100, 'currency' => 'IDR']);
        DB::table('assessment_bills')->where('id', $bill['bill'])->update(['status' => 'expired']);
        $this->assertSame('CHARGE_ALREADY_BILLED', $this->preview()['items'][0]['reason']);
    }

    public function test_invalid_existing_snapshot_and_consultation_change_fail_closed(): void
    {
        $charge = $this->charge();
        $this->assertSame('CONSULTATION_LOCKED', $this->preview([Fixture::selection($this->fixture, true)])['items'][0]['reason']);
        $charge->update(['price_snapshot' => ['version' => 99]]);
        $this->assertSame('PRICE_SNAPSHOT_INVALID', $this->preview()['items'][0]['reason']);
    }

    public function test_revoked_attempt_is_not_billable(): void
    {
        DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->update(['revoked_at' => now()]);
        $this->assertSame('ASSESSMENT_NOT_BILLABLE', $this->preview()['items'][0]['reason']);
    }

    public function test_invalid_limit_fails_closed(): void
    {
        config()->set('assessment_billing.max_items', 0);
        $this->expectException(LogicException::class);
        $this->preview();
    }

    public function test_soft_deleted_participant_is_not_available(): void
    {
        DB::table('participants')->where('id', $this->fixture['participant'])->update(['deleted_at' => now()]);
        $this->assertSame('ASSESSMENT_NOT_AVAILABLE', $this->preview()['items'][0]['reason']);
    }
}
