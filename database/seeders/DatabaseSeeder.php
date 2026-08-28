<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(BranchSeeder::class);
        $this->call(InstrumentSeeder::class);
        $this->call(TestPackageSeeder::class);
        $this->call(PaymentMethodSeeder::class);
    }
}
