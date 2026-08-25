<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DassIsolationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_dass_data_uses_separate_tables_with_retention_columns(): void
    {
        foreach (['dass_assessments', 'dass_responses', 'dass_results'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing isolated DASS table: {$table}");
            $this->assertTrue(Schema::hasColumn($table, 'expires_at'));
        }

        $this->assertTrue(Schema::hasColumns('dass_responses', [
            'assessment_id',
            'item_number',
            'response_value',
            'answered_at',
        ]));
        $this->assertTrue(Schema::hasColumns('dass_results', [
            'assessment_id',
            'depression_raw',
            'anxiety_raw',
            'stress_raw',
            'overall_category',
        ]));
    }

    public function test_each_dass_item_can_only_be_recorded_once_per_assessment(): void
    {
        $assessmentId = $this->seedAssessment();
        $response = [
            'assessment_id' => $assessmentId,
            'item_number' => 1,
            'response_value' => 0,
            'answered_at' => now(),
            'expires_at' => now()->addYears(2),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('dass_responses')->insert($response);

        $this->expectException(QueryException::class);
        DB::table('dass_responses')->insert($response);
    }

    private function seedAssessment(): int
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

        return DB::table('dass_assessments')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participantId,
            'status' => 'in_progress',
            'started_at' => now(),
            'expires_at' => now()->addYears(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
