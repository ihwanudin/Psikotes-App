<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class PaymentMethodSeeder extends Seeder
{
    /** @var array<string, string> */
    private const METHODS = [
        'xendit' => 'Xendit Invoice',
        'manual_transfer' => 'Transfer Manual',
    ];

    public function run(): void
    {
        foreach (self::METHODS as $code => $displayName) {
            DB::table('payment_methods')->insertOrIgnore([
                'code' => $code,
                'display_name' => $displayName,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
