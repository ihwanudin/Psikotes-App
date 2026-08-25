<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TestPackageSeeder extends Seeder
{
    /** @var array<string, array{name: string, test_type: string}> */
    private const PACKAGES = [
        'IST' => ['name' => 'Tes Inteligensi (IST)', 'test_type' => 'ist'],
        'PAPI' => ['name' => 'Tes Kepribadian (PAPI Kostick)', 'test_type' => 'papi'],
        'RMIB' => ['name' => 'Tes Minat (RMIB)', 'test_type' => 'rmib'],
        'KRAEPELIN' => ['name' => 'Tes Ketahanan Kerja (Kraepelin)', 'test_type' => 'kraepelin'],
        'DASS21' => ['name' => 'Skrining DASS-21', 'test_type' => 'dass21'],
    ];

    public function run(): void
    {
        foreach (self::PACKAGES as $code => $definition) {
            DB::table('packages')->insertOrIgnore([
                'code' => $code,
                'name' => $definition['name'],
                'amount' => null,
                'currency' => 'IDR',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $packageId = DB::table('packages')->where('code', $code)->value('id')
                ?? throw new LogicException("Package template [{$code}] could not be resolved.");

            DB::table('package_items')->insertOrIgnore([
                'package_id' => $packageId,
                'test_type' => $definition['test_type'],
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
