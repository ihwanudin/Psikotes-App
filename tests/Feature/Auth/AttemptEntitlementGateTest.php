<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentCharge;
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;
use Tests\Support\AssessmentEntitlementBlockedStates;

final class AttemptEntitlementGateTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = Fixture::create();
    }

    private function gate(?array $f = null, string $type = 'ist'): int
    {
        $f ??= $this->f;

        return app(RlsContextRunner::class)->runAsService(fn () => app(AssessmentEntitlementGate::class)->assertReady(
            new AssessmentPrincipal($f['participant'], $f['organization'], $f['attempt']), $type)->id);
    }

    public function test_settled_owned_attempt_with_current_prerequisites_can_start(): void
    {
        $this->assertSame($this->f['entitlement'], $this->gate());
        $this->assertSame('ready', DB::table('assessment_entitlements')->value('status'));
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_paid_old_attempt_cannot_open_new_unpaid_attempt(): void
    {
        $next = Fixture::create(identity: $this->f);
        DB::table('assessment_bills')->where('id', $next['bill'])->update(['status' => 'pending', 'paid_at' => null]);
        DB::table('assessment_bill_items')->where('id', $next['item'])->update(['settled_at' => null]);
        $this->assertSame($this->f['entitlement'], $this->gate());
        $this->expectException(EntitlementLocked::class);
        $this->gate($next);
    }

    public function test_bill_paid_without_individual_allocation_is_locked(): void
    {
        DB::table('assessment_bill_items')->where('id', $this->f['item'])->update(['settled_at' => null]);
        $this->expectException(EntitlementLocked::class);
        $this->gate();
    }

    public function test_paid_without_consent_does_not_open_access(): void
    {
        DB::table('consent_records')->where('consent_type', 'psychotest')->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $this->expectException(EntitlementLocked::class);
        $this->gate();
    }

    #[DataProvider('blockedStates')]
    public function test_incomplete_or_changed_state_is_locked(string $table, array $values): void
    {
        DB::table($table)->update($values);
        $this->expectException(EntitlementLocked::class);
        $this->gate();
    }

    public static function blockedStates(): iterable
    {
        return AssessmentEntitlementBlockedStates::cases();
    }

    public function test_dass_decline_blocks_only_dass_not_main_tests(): void
    {
        $dass = Fixture::create('dass21', $this->f);
        DB::table('consent_records')->where('consent_type', 'dass')->update(['status' => 'declined', 'consented_at' => null]);
        $this->assertSame($this->f['entitlement'], $this->gate());
        $this->expectException(EntitlementLocked::class);
        $this->gate($dass, 'dass21');
    }

    public function test_dass_requires_current_version_and_hash_and_can_start_when_accepted(): void
    {
        $dass = Fixture::create('dass21', $this->f);
        $this->assertSame($dass['entitlement'], $this->gate($dass, 'dass21'));
        config()->set('consent.documents.dass.text', ConsentDocument::for('dass')->text.' Updated');
        $this->expectException(EntitlementLocked::class);
        $this->gate($dass, 'dass21');
    }

    public function test_explicit_free_settlement_is_required_even_for_zero_charge(): void
    {
        DB::table('assessment_bill_items')->where('id', $this->f['item'])->delete();
        $charge = AssessmentCharge::findOrFail($this->f['charge']);
        $snapshot = $charge->price_snapshot;
        $snapshot['amount'] = $snapshot['baseAmount'] = 0;
        $charge->update(['amount' => 0, 'base_amount' => 0, 'price_snapshot' => $snapshot]);
        try {
            $this->gate();
            $this->fail('Implicit zero settlement accepted.');
        } catch (EntitlementLocked) {
            $this->assertNull($charge->free_settled_at);
        }
        $charge->update(['free_settled_at' => now()]);
        $this->assertSame($this->f['entitlement'], $this->gate());
        DB::table('consent_records')->where('consent_type', 'psychotest')->delete();
        $this->expectException(EntitlementLocked::class);
        $this->gate();
    }

    public function test_legacy_entitlement_cannot_substitute_for_attempt_entitlement(): void
    {
        DB::table('assessment_entitlements')->delete();
        $this->createCaseScopedGenericEntitlement();
        $this->expectException(EntitlementLocked::class);
        $this->gate();
    }

    public function test_other_participant_and_other_organization_are_locked(): void
    {
        $other = Fixture::create();
        foreach ([['participant' => $other['participant']], ['organization' => $other['organization']], ['attempt' => $other['attempt']]] as $forged) {
            try {
                $this->gate([...$this->f, ...$forged]);
                $this->fail('Foreign principal accepted.');
            } catch (EntitlementLocked) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_test_type_must_belong_to_paid_snapshot_not_current_catalogue(): void
    {
        DB::table('package_items')->where('package_id', $this->f['package'])->where('test_type', 'ist')
            ->update(['test_type' => 'papi']);
        DB::table('packages')->where('id', $this->f['package'])->update(['amount' => 999, 'is_active' => false]);
        DB::table('branches')->where('id', $this->f['organization'])->update(['allowed_payer_types' => '[]']);
        $this->assertSame($this->f['entitlement'], $this->gate());
        DB::table('assessment_entitlements')->where('id', $this->f['entitlement'])->update(['test_type' => 'papi']);
        $this->expectException(EntitlementLocked::class);
        $this->gate(type: 'papi');
    }

    public function test_manual_review_acceptance_requires_reviewer_and_current_timestamps(): void
    {
        $reviewer = Admin::create(['name' => 'Synthetic', 'email' => 'reviewer@example.test', 'password' => 'synthetic', 'role' => AdminRole::SuperAdmin]);
        DB::table('identity_verifications')->update(['outcome' => 'mismatch', 'manual_status' => 'accepted',
            'reviewed_by_admin_id' => $reviewer->id, 'reviewed_at' => now()]);
        $this->assertSame($this->f['entitlement'], $this->gate());
        DB::table('identity_verifications')->update(['reviewed_at' => '2099-01-01 00:00:00']);
        $this->expectException(EntitlementLocked::class);
        $this->gate();
    }

    public function test_missing_identity_evidence_is_locked(): void
    {
        DB::table('identity_evidence')->where('type', 'initial_selfie')->delete();
        $this->expectException(EntitlementLocked::class);
        $this->gate();
    }

    public function test_manual_review_timestamp_compares_instants_not_offset_strings(): void
    {
        $this->travelTo(now()->startOfSecond());
        $reviewer = Admin::create(['name' => 'Synthetic', 'email' => 'timezone@example.test', 'password' => 'synthetic', 'role' => AdminRole::SuperAdmin]);
        DB::table('identity_verifications')->update(['outcome' => 'mismatch', 'manual_status' => 'accepted',
            'reviewed_by_admin_id' => $reviewer->id, 'reviewed_at' => now()->format('Y-m-d H:i:sP')]);
        $this->assertSame($this->f['entitlement'], $this->gate());
    }

    public function test_gate_requires_service_context(): void
    {
        $this->expectException(LogicException::class);
        app(AssessmentEntitlementGate::class)->assertReady(new AssessmentPrincipal($this->f['participant'], $this->f['organization'], $this->f['attempt']), 'ist');
    }

    private function createCaseScopedGenericEntitlement(): void
    {
        DB::table('participants')->where('id', $this->f['participant'])->update([
            'package_id' => $this->f['package'],
            'source_system' => 'P6B_TEST',
        ]);
        DB::table('entitlements')->insert([
            'participant_id' => $this->f['participant'],
            'assessment_case_id' => $this->f['case'],
            'test_type' => 'ist',
            'status' => 'ready',
            'ready_at' => now(),
        ]);
    }
}
