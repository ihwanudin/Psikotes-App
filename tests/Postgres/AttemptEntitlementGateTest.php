<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentCharge;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class AttemptEntitlementGateTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow(now()->startOfSecond());
        DB::beginTransaction();
        $this->f = app(RlsContextRunner::class)->runAsService(fn () => Fixture::create());
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        Date::setTestNow();
        parent::tearDown();
    }

    private function principal(?array $f = null): AssessmentPrincipal
    {
        $f ??= $this->f;

        return new AssessmentPrincipal($f['participant'], $f['organization'], $f['attempt']);
    }

    public function test_service_gate_uses_runtime_role_and_does_not_write_access(): void
    {
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $entitlement = app(AssessmentEntitlementGate::class)->assertReady($this->principal(), 'ist');
            $this->assertSame($this->f['entitlement'], $entitlement->id);
            $this->assertSame('ready', $entitlement->status);
            $this->assertNull($entitlement->started_at);
            $this->assertSame(0, DB::table('outbox_messages')
                ->where('aggregate_id', (string) $this->f['attempt'])->count());
        });
    }

    public function test_membership_settlement_is_separate_from_other_members_consent(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $other = Fixture::create(identity: ['organization' => $this->f['organization']]);
            DB::table('assessment_bill_items')->where('id', $other['item'])->update(['bill_id' => $this->f['bill']]);
            DB::table('assessment_bills')->where('id', $this->f['bill'])->update(['amount' => 200, 'item_count' => 2]);
            DB::table('consent_records')->where('participant_id', $other['participant'])->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
            $gate = app(AssessmentEntitlementGate::class);
            $this->assertSame($this->f['entitlement'], $gate->assertReady($this->principal(), 'ist')->id);
            $this->expectException(EntitlementLocked::class);
            $gate->assertReady($this->principal($other), 'ist');
        });
    }

    public function test_foreign_attempt_is_rejected_even_with_service_reads(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $foreign = Fixture::create();
            $this->expectException(EntitlementLocked::class);
            app(AssessmentEntitlementGate::class)->assertReady($this->principal([...$this->f, 'attempt' => $foreign['attempt']]), 'ist');
        });
    }

    public function test_paid_bill_without_settled_item_does_not_open_access(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_bill_items')->where('id', $this->f['item'])->update(['settled_at' => null]);
            $this->expectException(EntitlementLocked::class);
            app(AssessmentEntitlementGate::class)->assertReady($this->principal(), 'ist');
        });
    }

    public function test_manual_review_with_postgres_timezone_is_accepted(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $admin = Admin::create(['name' => 'Synthetic', 'email' => 'review-gate@example.test', 'password' => 'synthetic', 'role' => AdminRole::SuperAdmin]);
            DB::table('identity_verifications')->where('participant_id', $this->f['participant'])->update([
                'outcome' => 'mismatch', 'manual_status' => 'accepted', 'reviewed_by_admin_id' => $admin->id, 'reviewed_at' => now()]);
            $this->assertSame($this->f['entitlement'], app(AssessmentEntitlementGate::class)->assertReady($this->principal(), 'ist')->id);
        });
    }

    public function test_zero_amount_without_explicit_settlement_stays_locked(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_bill_items')->where('id', $this->f['item'])->delete();
            $charge = AssessmentCharge::findOrFail($this->f['charge']);
            $snapshot = $charge->price_snapshot;
            $snapshot['amount'] = $snapshot['baseAmount'] = 0;
            $charge->update(['amount' => 0, 'base_amount' => 0, 'price_snapshot' => $snapshot]);
            $gate = app(AssessmentEntitlementGate::class);
            try {
                $gate->assertReady($this->principal(), 'ist');
                $this->fail('Implicit free settlement accepted.');
            } catch (EntitlementLocked) {
                $this->assertNull($charge->free_settled_at);
            }
            $charge->update(['free_settled_at' => now()]);
            $this->assertSame($this->f['entitlement'], $gate->assertReady($this->principal(), 'ist')->id);
        });
    }

    #[DataProvider('roles')]
    public function test_direct_non_service_call_cannot_read_billing_via_gate(string $role): void
    {
        $this->expectException(LogicException::class);
        app(RlsContextRunner::class)->run(new RlsContext($role, $this->f['organization'], $this->f['participant']),
            fn () => app(AssessmentEntitlementGate::class)->assertReady($this->principal(), 'ist'));
    }

    public static function roles(): iterable
    {
        yield ['participant'];
        yield ['branch_admin'];
        yield ['super_admin'];
    }
}
