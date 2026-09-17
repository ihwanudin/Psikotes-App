<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_minimum_operational_catalog_without_demo_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('branches', [
            'code' => 'LSI-CENTRAL',
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('branches', [
            'code' => 'BEASISWA-JEPANG',
            'name' => 'Program Beasiswa Jepang',
            'ref_code' => 'BEASISWA-JEPANG',
            'is_default' => false,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('packages', 6);
        $this->assertDatabaseCount('payment_methods', 2);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_program_branch_seeding_is_idempotent_and_preserves_operational_state(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('branches')
            ->where('code', 'BEASISWA-JEPANG')
            ->update(['is_active' => false]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseHas('branches', [
            'code' => 'BEASISWA-JEPANG',
            'is_active' => false,
        ]);
    }
}
