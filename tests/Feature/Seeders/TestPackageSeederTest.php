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

    public function test_each_canonical_test_type_has_an_inactive_idr_package_template(): void
    {
        $this->seed(TestPackageSeeder::class);

        $expected = [
            'IST' => 'ist',
            'PAPI' => 'papi',
            'RMIB' => 'rmib',
            'KRAEPELIN' => 'kraepelin',
            'DASS21' => 'dass21',
        ];

        $this->assertDatabaseCount('packages', count($expected));
        $this->assertDatabaseCount('package_items', count($expected));

        foreach ($expected as $code => $testType) {
            $package = DB::table('packages')->where('code', $code)->sole();

            $this->assertSame('IDR', $package->currency);
            $this->assertSame(0, $package->is_active);
            $this->assertNull($package->amount);
            $this->assertDatabaseHas('package_items', [
                'package_id' => $package->id,
                'test_type' => $testType,
            ]);
        }
    }

    public function test_package_templates_are_idempotent(): void
    {
        $this->seed(TestPackageSeeder::class);
        $this->seed(TestPackageSeeder::class);

        $this->assertDatabaseCount('packages', 5);
        $this->assertDatabaseCount('package_items', 5);
    }
}
