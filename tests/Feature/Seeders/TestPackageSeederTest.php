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
            'IST' => ['amount' => 99000, 'items' => ['ist', 'dass21']],
            'PAPI' => ['amount' => 99000, 'items' => ['papi', 'dass21']],
            'RMIB' => ['amount' => 99000, 'items' => ['rmib', 'dass21']],
            'KRAEPELIN' => ['amount' => 99000, 'items' => ['kraepelin', 'dass21']],
            'ALL' => ['amount' => 200000, 'items' => ['ist', 'papi', 'rmib', 'kraepelin', 'dass21']],
        ];

        $this->assertDatabaseCount('packages', count($expected));
        $this->assertDatabaseCount('package_items', 13);
        $this->assertDatabaseMissing('packages', ['code' => 'DASS21']);

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

        $this->assertDatabaseCount('packages', 5);
        $this->assertDatabaseCount('package_items', 13);
    }
}
