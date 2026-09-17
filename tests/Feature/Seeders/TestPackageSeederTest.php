<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\TestPackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TestPackageSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_has_data_driven_individual_and_bundle_prices(): void
    {
        $this->seed(TestPackageSeeder::class);

        $expected = [
            'DASS21' => ['amount' => 0, 'items' => ['dass21']],
            'IST' => ['amount' => 99000, 'items' => ['ist', 'dass21']],
            'PAPI' => ['amount' => 99000, 'items' => ['papi', 'dass21']],
            'RMIB' => ['amount' => 99000, 'items' => ['rmib', 'dass21']],
            'KRAEPELIN' => ['amount' => 99000, 'items' => ['kraepelin', 'dass21']],
            'ALL' => ['amount' => 200000, 'items' => ['ist', 'papi', 'rmib', 'kraepelin', 'dass21']],
        ];

        $this->assertDatabaseCount('packages', count($expected));
        $this->assertDatabaseCount('package_items', 14);

        foreach ($expected as $code => $definition) {
            $package = DB::table('packages')->where('code', $code)->sole();

            $this->assertSame('IDR', $package->currency);
            $this->assertSame(0, $package->is_active);
            $this->assertSame($definition['amount'], $package->amount);
            $this->assertSame(50000, $package->consultation_amount);
            $this->assertSame(
                $definition['items'],
                DB::table('package_items')
                    ->where('package_id', $package->id)
                    ->orderBy('sort_order')
                    ->pluck('test_type')
                    ->all(),
            );
        }
    }

    public function test_package_templates_are_idempotent(): void
    {
        $this->seed(TestPackageSeeder::class);
        $this->seed(TestPackageSeeder::class);

        $this->assertDatabaseCount('packages', 6);
        $this->assertDatabaseCount('package_items', 14);
    }

    public function test_reseeding_removes_old_bundle_copy_without_overwriting_admin_price_or_activation(): void
    {
        $this->seed(TestPackageSeeder::class);
        DB::table('packages')->where('code', 'IST')->update([
            'name' => 'Tes Inteligensi (IST) + DASS-21',
            'amount' => 150000,
            'is_active' => true,
        ]);

        $this->seed(TestPackageSeeder::class);

        $this->assertDatabaseHas('packages', [
            'code' => 'IST',
            'name' => 'Tes Inteligensi (IST)',
            'amount' => 150000,
            'is_active' => true,
        ]);
    }
}
