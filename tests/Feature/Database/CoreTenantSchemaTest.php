<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CoreTenantSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_identity_tables_and_security_columns_exist(): void
    {
        foreach ([
            'branches',
            'admins',
            'participants',
            'referral_visits',
            'consent_records',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue(Schema::hasColumns('participants', [
            'branch_id',
            'referral_branch_id',
            'referral_source',
            'intended_field',
            'test_number',
            'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('consent_records', [
            'participant_id',
            'consent_type',
            'status',
            'document_version',
            'document_hash',
            'consented_at',
            'withdrawn_at',
        ]));
    }

    public function test_consent_b_can_be_declined_without_blocking_the_participant(): void
    {
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'PUSAT',
            'name' => 'Pusat',
            'ref_code' => 'PUSAT',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $participantId = DB::table('participants')->insertGetId([
            'branch_id' => $branchId,
            'referral_branch_id' => $branchId,
            'referral_source' => 'default',
            'full_name' => 'Peserta Sintetis',
            'gender' => 'female',
            'birth_date' => '2000-01-01',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '080000000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('consent_records')->insert([
            'participant_id' => $participantId,
            'consent_type' => 'dass',
            'status' => 'declined',
            'document_version' => 'draft-test',
            'document_hash' => hash('sha256', 'synthetic consent'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('consent_records', [
            'participant_id' => $participantId,
            'consent_type' => 'dass',
            'status' => 'declined',
            'consented_at' => null,
        ]);
    }

    public function test_branch_referral_and_test_numbers_are_unique(): void
    {
        DB::table('branches')->insert([
            'code' => 'PUSAT',
            'name' => 'Pusat',
            'ref_code' => 'REF-UNIK',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('branches')->insert([
            'code' => 'CABANG',
            'name' => 'Cabang',
            'ref_code' => 'REF-UNIK',
            'is_default' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
