<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$now = now();

DB::transaction(function () use ($now): void {
    DB::table('branches')->updateOrInsert(
        ['code' => 'E2E-A'],
        [
            'name' => 'E2E Branch A',
            'ref_code' => 'E2EA',
            'organization_code' => 'E2E-A',
            'organization_type' => 'INTERNAL_BRANCH',
            'display_name' => 'E2E Branch A',
            'status' => 'ACTIVE',
            'is_default' => true,
            'is_active' => true,
            'allowed_funding_modes' => json_encode(['COMMERCIAL_SELF_PAY'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    );
    DB::table('branches')->updateOrInsert(
        ['code' => 'E2E-B'],
        [
            'name' => 'E2E Branch B',
            'ref_code' => 'E2EB',
            'organization_code' => 'E2E-B',
            'organization_type' => 'INTERNAL_BRANCH',
            'display_name' => 'E2E Branch B',
            'status' => 'ACTIVE',
            'is_default' => false,
            'is_active' => true,
            'allowed_funding_modes' => json_encode(['COMMERCIAL_SELF_PAY'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    );

    $branchA = (int) DB::table('branches')->where('code', 'E2E-A')->value('id');
    $branchB = (int) DB::table('branches')->where('code', 'E2E-B')->value('id');

    foreach ([
        ['e2e.psychologist@example.test', 'E2E Psychologist', 'psychologist', null, false],
        ['e2e.super-admin@example.test', 'E2E Super Admin', 'super_admin', null, true],
        ['e2e.branch-admin@example.test', 'E2E Branch Admin', 'branch_admin', $branchA, true],
        ['e2e.staff@example.test', 'E2E Staff', 'staff', $branchA, true],
    ] as [$email, $name, $role, $branchId, $canVerify]) {
        DB::table('admins')->updateOrInsert(
            ['email' => $email],
            [
                'branch_id' => $branchId,
                'name' => $name,
                'password' => Hash::make('Password123!'),
                'role' => $role,
                'can_verify_payments' => $canVerify,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    $superAdmin = (int) DB::table('admins')->where('email', 'e2e.super-admin@example.test')->value('id');
    $branchAdmin = (int) DB::table('admins')->where('email', 'e2e.branch-admin@example.test')->value('id');

    DB::table('packages')->updateOrInsert(
        ['code' => 'E2E_MAIN'],
        [
            'name' => 'E2E Paket Psikotes Utama',
            'description' => 'Synthetic main package for browser E2E; DASS-21 is mandatory.',
            'amount' => 100000,
            'consultation_amount' => 25000,
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    );
    $package = (int) DB::table('packages')->where('code', 'E2E_MAIN')->value('id');
    DB::table('package_items')->where('package_id', $package)->delete();
    foreach (['ist', 'papi', 'rmib', 'kraepelin', 'dass21'] as $sort => $testType) {
        DB::table('package_items')->insert([
            'package_id' => $package,
            'test_type' => $testType,
            'sort_order' => $sort + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    DB::table('payment_methods')->whereIn('code', ['manual_transfer', 'xendit'])->update([
        'is_active' => true,
        'updated_at' => $now,
    ]);

    $participantA = upsertParticipant($branchA, $package, 'E2E Branch A Participant', '628111111111', 'E2E-A-001');
    $participantB = upsertParticipant($branchB, $package, 'E2E Branch B Participant', '628000000000', 'E2E-B-001');

    $casePublicId = '01K000000000000000000E2E2A';
    DB::table('assessment_cases')->updateOrInsert(
        ['public_id' => $casePublicId],
        [
            'participant_id' => $participantA,
            'organization_id' => $branchA,
            'package_id' => $package,
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => 'KAIGO',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    );
    $caseId = (int) DB::table('assessment_cases')->where('public_id', $casePublicId)->value('id');

    $ruleA = upsertFeeRule($branchA, $superAdmin);
    $ruleB = upsertFeeRule($branchB, $superAdmin);
    upsertCommission($branchA, $participantA, $ruleA, 'direct_order', 900001, 'E2E-A');
    upsertCommission($branchB, $participantB, $ruleB, 'direct_order', 900002, 'E2E-B');
    upsertWithdrawal($branchA, $branchAdmin, 'E2E-WD-A', 5000);
    upsertWithdrawal($branchB, $branchAdmin, 'E2E-WD-B', 7000);

    DB::table('integration_clients')->updateOrInsert(
        ['client_id' => 'e2e-client'],
        [
            'organization_id' => $branchA,
            'credential_reference' => 'e2e-credential',
            'result_delivery_mode' => 'NONE',
            'enabled' => true,
            'rate_limit_policy' => json_encode(['requestsPerMinute' => 120], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    );
    $client = (int) DB::table('integration_clients')->where('client_id', 'e2e-client')->value('id');
    DB::table('assessment_participants')->updateOrInsert(
        ['integration_client_id' => $client, 'idempotency_key' => 'e2e-case-a'],
        [
            'organization_id' => $branchA,
            'participant_id' => $participantA,
            'package_id' => $package,
            'assessment_attempt_id' => '01K000000000000000000E2E2B',
            'source_system' => 'E2E_SOURCE',
            'external_candidate_id' => 'E2E-A-001',
            'external_process_id' => 'E2E-PROCESS-A',
            'external_registration_id' => 'E2E-REG-A',
            'assessment_round_id' => 'E2E-ROUND-A',
            'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'READY',
            'recommendation' => null,
            'result_version' => 0,
            'finalized_at' => null,
            'revoked_at' => null,
            'request_hash' => hash('sha256', 'e2e-case-a'),
            'logical_assessment_key' => hash('sha256', 'e2e-logical-a'),
            'assessment_case_id' => $caseId,
            'metadata' => json_encode(['e2e' => true], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    );
    DB::table('integration_sources')->updateOrInsert(
        ['integration_client_id' => $client, 'source_system' => 'E2E_SOURCE', 'contract_version' => 'v1'],
        [
            'authentication_mode' => 'HMAC_SHA256',
            'allowed_assessment_packages' => json_encode(['E2E_MAIN'], JSON_THROW_ON_ERROR),
            'participant_provisioning_mode' => 'API',
            'commercial_mode' => 'SELF_PAY',
            'allowed_funding_modes' => json_encode(['COMMERCIAL_SELF_PAY'], JSON_THROW_ON_ERROR),
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    );
});

function upsertParticipant(int $branchId, int $packageId, string $name, string $phone, string $testNumber): int
{
    DB::table('participants')->updateOrInsert(
        ['test_number' => $testNumber],
        [
            'package_id' => $packageId,
            'source_system' => 'DIRECT_PUBLIC',
            'attribution_source' => 'e2e',
            'branch_id' => $branchId,
            'referral_branch_id' => $branchId,
            'referral_source' => 'default',
            'full_name' => $name,
            'gender' => 'female',
            'birth_date' => '1999-01-01',
            'education_level' => 'SMA',
            'intended_field' => 'KAIGO',
            'phone' => $phone,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    return (int) DB::table('participants')->where('test_number', $testNumber)->value('id');
}

function upsertFeeRule(int $branchId, int $adminId): int
{
    DB::table('branch_fee_rules')->updateOrInsert(
        ['branch_id' => $branchId, 'effective_until' => null],
        [
            'rate_basis' => 'base_amount',
            'rate_type' => 'percentage',
            'percentage_bps' => 500,
            'fixed_amount' => null,
            'currency' => 'IDR',
            'rounding_mode' => 'floor',
            'effective_from' => '2026-01-01 00:00:00',
            'created_by_admin_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    return (int) DB::table('branch_fee_rules')->where('branch_id', $branchId)->value('id');
}

function upsertCommission(int $branchId, int $participantId, int $ruleId, string $sourceType, int $sourceId, string $label): void
{
    DB::table('commission_entries')->updateOrInsert(
        ['source_type' => $sourceType, 'source_id' => $sourceId],
        [
            'branch_id' => $branchId,
            'participant_id' => $participantId,
            'period_month' => '2026-09-01',
            'paid_at' => '2026-09-20 09:00:00',
            'fee_rule_id' => $ruleId,
            'rate_basis' => 'base_amount',
            'rate_basis_amount' => 100000,
            'gross_amount' => 100000,
            'commission_amount' => 5000,
            'currency' => 'IDR',
            'calculation_snapshot' => json_encode(['e2eLabel' => $label], JSON_THROW_ON_ERROR),
            'status' => 'accrued',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

function upsertWithdrawal(int $branchId, int $adminId, string $reference, int $amount): void
{
    DB::table('withdrawal_requests')->updateOrInsert(
        ['public_reference' => $reference],
        [
            'branch_id' => $branchId,
            'period_month' => '2026-09-01',
            'status' => 'submitted',
            'requested_amount' => $amount,
            'approved_amount' => null,
            'requested_by_admin_id' => $adminId,
            'submitted_at' => '2026-09-20 10:00:00',
            'metadata' => json_encode(['e2e' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}
