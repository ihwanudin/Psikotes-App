<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\AssessmentBill;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\TestPackage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;

final class AssessmentBillingSchemaTest extends OrganizationPaymentTestCase
{
    private AssessmentParticipant $attempt;

    private int $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $organization = Branch::create(['code' => 'BILL', 'ref_code' => 'BILL', 'name' => 'Synthetic',
            'organization_code' => 'BILL', 'display_name' => 'Synthetic']);
        $client = IntegrationClient::create(['organization_id' => $organization->id,
            'client_id' => 'billing-test', 'credential_reference' => 'synthetic-only']);
        $package = TestPackage::create(['code' => 'BILL', 'name' => 'Synthetic', 'amount' => 120000,
            'consultation_amount' => 30000, 'currency' => 'IDR', 'is_active' => true]);
        $participant = Participant::create(['branch_id' => $organization->id, 'referral_branch_id' => $organization->id,
            'referral_source' => 'default', 'full_name' => 'Synthetic', 'gender' => 'male',
            'birth_date' => '2000-01-01', 'education_level' => 'SMA_SMK', 'intended_field' => 'UMUM', 'phone' => '620000000000']);
        $this->attempt = AssessmentParticipant::create(['integration_client_id' => $client->id,
            'organization_id' => $organization->id, 'participant_id' => $participant->id, 'package_id' => $package->id,
            'assessment_attempt_id' => (string) Str::ulid(), 'source_system' => 'BILL_TEST', 'external_candidate_id' => 'C-1',
            'funding_mode' => 'COMMERCIAL_SELF_PAY', 'assessment_status' => 'PROVISIONED',
            'idempotency_key' => 'attempt:1', 'request_hash' => str_repeat('a', 64), 'logical_assessment_key' => str_repeat('b', 64)]);
        $this->method = DB::table('payment_methods')->insertGetId(['code' => 'test_manual', 'display_name' => 'Synthetic', 'is_active' => false]);
    }

    public function test_models_preserve_integer_snapshots_and_have_no_payment_side_effects(): void
    {
        $charge = AssessmentCharge::create($this->chargeData())->refresh();
        $bill = AssessmentBill::create($this->billData())->refresh();
        $this->assertSame(150000, $charge->amount);
        $this->assertSame(['packageCode' => 'BILL'], $charge->price_snapshot);
        $this->assertSame($this->attempt->id, $charge->assessmentParticipant->id);
        $this->assertSame($this->attempt->participant_id, $charge->participant->id);
        $this->assertSame($this->attempt->organization_id, $bill->organization->id);
        $this->assertSame('reserved', $bill->status);
        $this->assertNull($bill->paid_at);
        $this->assertNull($bill->payer_participant_id);
        $this->assertNull($charge->free_settled_at);
        $this->attempt->package->update(['amount' => 999000, 'consultation_amount' => 90000]);
        $this->assertSame(150000, $charge->refresh()->amount);
        $this->assertSame(150000, $bill->refresh()->amount);
        foreach (['orders', 'entitlements', 'outbox_messages'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_one_charge_per_attempt_is_enforced(): void
    {
        AssessmentCharge::create($this->chargeData());
        $this->expectException(QueryException::class);
        AssessmentCharge::create($this->chargeData());
    }

    #[DataProvider('scopeFields')]
    public function test_charge_cannot_mismatch_attempt_scope(string $field): void
    {
        $this->expectException(QueryException::class);
        AssessmentCharge::create([...$this->chargeData(), $field => 999999]);
    }

    public static function scopeFields(): iterable
    {
        foreach (['organization_id', 'participant_id', 'package_id', 'assessment_participant_id'] as $field) {
            yield [$field];
        }
    }

    public function test_organization_idempotency_rejects_duplicate_even_with_null_participant(): void
    {
        AssessmentBill::create($this->billData());
        $this->expectException(QueryException::class);
        AssessmentBill::create([...$this->billData(), 'public_reference' => 'AB_'.Str::ulid()]);
    }

    public function test_self_idempotency_rejects_duplicate_and_does_not_collide_with_organization(): void
    {
        AssessmentBill::create($this->billData());
        $self = [...$this->billData(), 'payer_type' => 'self', 'payer_participant_id' => $this->attempt->participant_id];
        AssessmentBill::create($self);
        $this->assertDatabaseCount('assessment_bills', 2);
        $this->expectException(QueryException::class);
        AssessmentBill::create([...$self, 'public_reference' => 'AB_'.Str::ulid()]);
    }

    public function test_public_reference_is_unique(): void
    {
        $data = $this->billData();
        AssessmentBill::create($data);
        $this->expectException(QueryException::class);
        AssessmentBill::create([...$data, 'idempotency_key' => 'other']);
    }

    public function test_populated_rollback_preserves_legacy_rows_and_can_upgrade_again(): void
    {
        $legacyId = DB::table('orders')->insertGetId(['public_id' => (string) Str::ulid(),
            'participant_id' => $this->attempt->participant_id, 'payment_method_id' => $this->method,
            'amount' => 150000, 'currency' => 'IDR', 'status' => 'pending']);
        $legacy = DB::table('orders')->where('id', $legacyId)->first();
        AssessmentCharge::create($this->chargeData());
        AssessmentBill::create($this->billData());
        $entitlements = require database_path('migrations/2026_08_31_000400_create_assessment_entitlements.php');
        $items = require database_path('migrations/2026_08_31_000300_create_assessment_bill_items.php');
        // Reverse dependency order, as a real migration rollback would use.
        $entitlements->down();
        $items->down();
        $migration = require database_path('migrations/2026_08_31_000200_create_assessment_billing.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('assessment_bills'));
        $this->assertFalse(Schema::hasTable('assessment_charges'));
        $this->assertEquals($legacy, DB::table('orders')->where('id', $legacyId)->first());
        $this->assertDatabaseHas('assessment_participants', ['id' => $this->attempt->id, 'assessment_status' => 'PROVISIONED']);
        $migration->up();
        $items->up();
        $entitlements->up();
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        AssessmentCharge::create($this->chargeData());
        $this->assertDatabaseCount('assessment_charges', 1);
    }

    private function chargeData(): array
    {
        return ['assessment_participant_id' => $this->attempt->id, 'organization_id' => $this->attempt->organization_id,
            'participant_id' => $this->attempt->participant_id, 'package_id' => $this->attempt->package_id,
            'payer_type' => 'organization', 'base_amount' => 120000, 'consultation_amount' => 30000,
            'consultation_requested' => true, 'amount' => 150000, 'currency' => 'IDR',
            'price_snapshot' => ['packageCode' => 'BILL'], 'policy_snapshot' => ['payerType' => 'organization']];
    }

    private function billData(): array
    {
        return ['organization_id' => $this->attempt->organization_id, 'payer_type' => 'organization',
            'public_reference' => 'AB_'.Str::ulid(), 'amount' => 150000, 'currency' => 'IDR', 'item_count' => 1,
            'selection_hash' => str_repeat('c', 64), 'request_hash' => str_repeat('d', 64),
            'idempotency_key' => 'billing:1', 'payment_method_id' => $this->method];
    }
}
