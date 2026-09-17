<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ActivateSettledAssessment;
use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\AssessmentSettlementReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;
use Tests\Support\AssessmentBillingFixture;

final class AssessmentSettlementReaderTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->f = Fixture::create();
    }

    private function settled(?array $f = null): bool
    {
        $f ??= $this->f;

        return app(RlsContextRunner::class)->runAsService(fn (): bool => app(AssessmentSettlementReader::class)
            ->isSettled(AssessmentCharge::findOrFail($f['charge'])));
    }

    public function test_collective_own_allocation_requires_every_member_settled_and_preserves_business_rows(): void
    {
        $other = Fixture::create(identity: ['organization' => $this->f['organization']]);
        DB::table('assessment_bill_items')->where('id', $other['item'])->update(['bill_id' => $this->f['bill']]);
        DB::table('assessment_bills')->where('id', $this->f['bill'])->update(['amount' => 200, 'item_count' => 2]);
        $before = $this->businessRows();
        $this->assertTrue($this->settled());
        $this->assertTrue($this->settled($other));
        $this->assertSame($before, $this->businessRows());
        DB::table('assessment_bill_items')->where('id', $other['item'])->update(['settled_at' => null]);
        $this->assertFalse($this->settled());
        $this->assertFalse($this->settled($other));
        DB::table('assessment_bill_items')->where('id', $other['item'])->update(['settled_at' => now()->addSecond()]);
        $this->assertFalse($this->settled());
    }

    public function test_self_requires_matching_payer_participant_on_both_bill_and_item(): void
    {
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->where('id', $this->f['charge'])->update(['payer_type' => 'self']);
        DB::table('assessment_bills')->update(['payer_type' => 'self', 'payer_participant_id' => $this->f['participant']]);
        $item = AssessmentBillingFixture::item([...$this->f, 'payer' => 'self']);
        DB::table('assessment_bill_items')->insert([...$item, 'settled_at' => now()]);
        $this->assertTrue($this->settled());
        $other = Fixture::create(identity: ['organization' => $this->f['organization']]);
        DB::table('assessment_bill_items')->where('charge_id', $this->f['charge'])->delete();
        DB::table('assessment_bills')->where('id', $this->f['bill'])->update(['payer_participant_id' => $other['participant']]);
        DB::table('assessment_bill_items')->insert([...$item, 'payer_participant_id' => $other['participant'], 'settled_at' => now()]);
        $this->assertFalse($this->settled());
        DB::table('assessment_bill_items')->where('charge_id', $this->f['charge'])->delete();
        DB::table('assessment_bills')->where('id', $this->f['bill'])->update(['payer_participant_id' => $this->f['participant']]);
        DB::table('assessment_bill_items')->insert([...$item, 'payer_participant_id' => null, 'settled_at' => now()]);
        $this->assertFalse($this->settled());
        DB::table('assessment_bill_items')->where('charge_id', $this->f['charge'])->update(['payer_participant_id' => $this->f['participant']]);
        $this->assertTrue($this->settled());
    }

    #[DataProvider('unsettledStates')]
    public function test_incomplete_or_mismatched_evidence_is_not_settled(string $table, array $values): void
    {
        DB::table($table)->update($values);
        $this->assertFalse($this->settled());
    }

    public static function unsettledStates(): iterable
    {
        yield 'pending' => ['assessment_bills', ['status' => 'pending', 'paid_at' => null]];
        yield 'missing paid timestamp' => ['assessment_bills', ['paid_at' => null]];
        yield 'future paid timestamp' => ['assessment_bills', ['paid_at' => '2099-01-01']];
        yield 'missing allocation timestamp' => ['assessment_bill_items', ['settled_at' => null]];
        yield 'future allocation timestamp' => ['assessment_bill_items', ['settled_at' => '2099-01-01']];
        yield 'wrong count' => ['assessment_bills', ['item_count' => 2]];
        yield 'wrong total' => ['assessment_bills', ['amount' => 101]];
    }

    public function test_zero_needs_nonfuture_free_marker_and_no_bill_item(): void
    {
        DB::table('assessment_bill_items')->delete();
        DB::table('packages')->where('id', $this->f['package'])->update(['amount' => 0]);
        $snapshot = app(AssessmentPriceSnapshot::class)->capture(TestPackage::with('items')->findOrFail($this->f['package']), false);
        $charge = AssessmentCharge::findOrFail($this->f['charge']);
        $charge->update(['amount' => 0, 'base_amount' => 0, 'price_snapshot' => $snapshot]);
        $this->assertFalse($this->settled());
        $charge->update(['free_settled_at' => now()->addSecond()]);
        $this->assertFalse($this->settled());
        $charge->update(['free_settled_at' => now()]);
        $this->assertTrue($this->settled());
        // Even an inconsistent allocation blocks the free branch; preserve FK amount linkage.
        DB::table('assessment_bill_items')->insert([
            'bill_id' => $this->f['bill'], 'charge_id' => $charge->id, 'organization_id' => $this->f['organization'],
            'participant_id' => $this->f['participant'], 'payer_type' => 'organization', 'amount' => 0, 'currency' => 'IDR',
        ]);
        $this->assertFalse($this->settled());
    }

    public function test_historical_paid_evidence_does_not_gain_a_current_policy_guard(): void
    {
        DB::table('branches')->update(['allowed_payer_types' => '[]']);
        DB::table('integration_clients')->update(['enabled' => false]);
        $this->assertTrue($this->settled());
        app(RlsContextRunner::class)->runAsService(fn () => $this->assertSame($this->f['entitlement'],
            app(AssessmentEntitlementGate::class)->assertReady(new AssessmentPrincipal(
                $this->f['participant'], $this->f['organization'], $this->f['attempt']), 'ist')->id));
    }

    #[DataProvider('missingPrerequisites')]
    public function test_paid_without_prerequisites_is_still_settled_but_gate_and_activation_deny_access(string $table, array $values): void
    {
        DB::table($table)->update($values);
        $this->assertTrue($this->settled());
        $principal = new AssessmentPrincipal($this->f['participant'], $this->f['organization'], $this->f['attempt']);
        app(RlsContextRunner::class)->runAsService(function () use ($principal): void {
            try {
                app(AssessmentEntitlementGate::class)->assertReady($principal, 'ist');
                $this->fail('Prerequisites must remain independent access requirements.');
            } catch (EntitlementLocked) {
                $this->assertTrue(true);
            }
            DB::table('assessment_entitlements')->update(['status' => 'locked', 'ready_at' => null]);
            DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED']);
            $this->assertSame([], app(ActivateSettledAssessment::class)->execute($principal));
        });
        $this->assertTrue($this->settled());
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function missingPrerequisites(): iterable
    {
        yield 'consent' => ['consent_records', ['status' => 'declined']];
        yield 'identity' => ['identity_verifications', ['outcome' => 'pending']];
        yield 'profile' => ['participants', ['full_name' => null]];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_reader_requires_service_context(?string $role): void
    {
        $read = fn (): bool => app(AssessmentSettlementReader::class)->isSettled(AssessmentCharge::findOrFail($this->f['charge']));
        $this->expectException(LogicException::class);
        if ($role === null) {
            $read();
        } else {
            app(RlsContextRunner::class)->run(new RlsContext($role, $this->f['organization'], $this->f['participant']), $read);
        }
    }

    public static function unauthorizedRoles(): iterable
    {
        yield 'no context' => [null];
        yield 'admin' => ['super_admin'];
        yield 'participant' => ['participant'];
    }

    private function businessRows(): array
    {
        $rows = [];
        foreach (['assessment_bills', 'assessment_bill_items', 'assessment_charges', 'assessment_entitlements',
            'assessment_participants', 'outbox_messages', 'audit_logs'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
