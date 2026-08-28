<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertDatabaseCount('packages', 6);
        $this->assertDatabaseCount('payment_methods', 2);
        $this->assertDatabaseCount('users', 0);
    }
}
