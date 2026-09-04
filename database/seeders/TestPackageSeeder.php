<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use LogicException;

final class TestPackageSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('packages')->where('code', 'DASS21')->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        $catalog = json_decode(
            File::get(database_path('seeders/data/service-catalog.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($catalog['packages'] as $definition) {
            DB::table('packages')->insertOrIgnore([
                'code' => $definition['code'],
                'name' => $definition['name'],
                'amount' => $definition['amount'],
                'consultation_amount' => $catalog['consultation_amount'],
                'currency' => $catalog['currency'],
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('packages')
                ->where('code', $definition['code'])
                ->whereNull('amount')
                ->update([
                    'amount' => $definition['amount'],
                    'consultation_amount' => $catalog['consultation_amount'],
                    'updated_at' => now(),
                ]);

            DB::table('packages')
                ->where('code', $definition['code'])
                ->whereNull('consultation_amount')
                ->update([
                    'consultation_amount' => $catalog['consultation_amount'],
                    'updated_at' => now(),
                ]);

            $packageId = DB::table('packages')->where('code', $definition['code'])->value('id')
                ?? throw new LogicException("Package template [{$definition['code']}] could not be resolved.");

            foreach ($definition['test_types'] as $sortOrder => $testType) {
                DB::table('package_items')->insertOrIgnore([
                    'package_id' => $packageId,
                    'test_type' => $testType,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
