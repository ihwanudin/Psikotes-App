<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class BranchSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('branches')->insertOrIgnore([
            'code' => 'LSI-CENTRAL',
            'name' => 'LSI Pusat',
            'ref_code' => 'LSI-PUSAT',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
