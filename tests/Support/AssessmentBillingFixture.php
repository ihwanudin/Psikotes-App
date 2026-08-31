<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic rows only. PostgreSQL callers must already own a service context. */
final class AssessmentBillingFixture
{
    public static function create(string $payer = 'organization', ?array $identity = null): array
    {
        $key = (string) Str::ulid();
        $organization = $identity['organization'] ?? DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key, 'display_name' => 'Synthetic']);
        $participant = $identity['participant'] ?? DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'gender' => 'male', 'birth_date' => '2000-01-01',
            'education_level' => 'SMA_SMK', 'intended_field' => 'UMUM', 'phone' => '620000000000']);
        $client = DB::table('integration_clients')->insertGetId(['organization_id' => $organization,
            'client_id' => $key, 'credential_reference' => 'synthetic-only']);
        $package = DB::table('packages')->insertGetId(['code' => $key, 'name' => 'Synthetic', 'amount' => 100, 'currency' => 'IDR']);
        $attempt = DB::table('assessment_participants')->insertGetId(['organization_id' => $organization,
            'integration_client_id' => $client, 'participant_id' => $participant, 'package_id' => $package,
            'assessment_attempt_id' => $key, 'source_system' => 'P6B_TEST', 'external_candidate_id' => $key,
            'funding_mode' => 'COMMERCIAL_SELF_PAY', 'assessment_status' => 'PROVISIONED',
            'idempotency_key' => $key, 'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key)]);
        $method = DB::table('payment_methods')->insertGetId(['code' => $key, 'display_name' => 'Synthetic', 'is_active' => false]);
        $charge = DB::table('assessment_charges')->insertGetId(['assessment_participant_id' => $attempt,
            'organization_id' => $organization, 'participant_id' => $participant, 'package_id' => $package,
            'payer_type' => $payer, 'base_amount' => 100, 'consultation_amount' => 0, 'amount' => 100,
            'currency' => 'IDR', 'price_snapshot' => '{}', 'policy_snapshot' => '{}']);
        $bill = DB::table('assessment_bills')->insertGetId(['organization_id' => $organization, 'payer_type' => $payer,
            'payer_participant_id' => $payer === 'self' ? $participant : null, 'public_reference' => 'AB_'.$key,
            'amount' => 100, 'currency' => 'IDR', 'item_count' => 1, 'selection_hash' => hash('sha256', $key),
            'idempotency_key' => $key, 'request_hash' => hash('sha256', $key), 'payment_method_id' => $method]);

        return compact('organization', 'participant', 'package', 'attempt', 'charge', 'bill', 'payer');
    }

    public static function item(array $fixture): array
    {
        return ['bill_id' => $fixture['bill'], 'charge_id' => $fixture['charge'],
            'organization_id' => $fixture['organization'], 'participant_id' => $fixture['participant'],
            'payer_type' => $fixture['payer'], 'payer_participant_id' => $fixture['payer'] === 'self' ? $fixture['participant'] : null,
            'amount' => 100, 'currency' => 'IDR'];
    }

    public static function entitlement(array $fixture): array
    {
        return ['charge_id' => $fixture['charge'], 'assessment_participant_id' => $fixture['attempt'],
            'organization_id' => $fixture['organization'], 'participant_id' => $fixture['participant'], 'test_type' => 'ist'];
    }
}
