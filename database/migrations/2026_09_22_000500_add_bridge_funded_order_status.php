<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'paid', 'rejected', 'expired', 'cancelled', 'bridge_funded'))");
        }

        // The synthetic 'bridge_funding' payment_methods row is deliberately
        // NOT seeded here: a bare `migrate` (no seed) is exactly what many
        // existing tests do before asserting on the payment_methods table's
        // exact row count/contents (e.g. blanket UPDATEs assuming a single
        // row). It is created lazily, firstOrCreate, by GrantBridgeFunding
        // itself at grant time, and via PaymentMethodSeeder for local/dev/CI
        // seeding.
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'bridge_funded')->update(['status' => 'pending']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'paid', 'rejected', 'expired', 'cancelled'))");
        }
    }
};
