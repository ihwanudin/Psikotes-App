<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->unsignedBigInteger('consultation_amount')->nullable()->after('amount');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('payment_method_id')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE packages DROP CONSTRAINT packages_amount_check');
            DB::statement('ALTER TABLE packages DROP CONSTRAINT packages_activation_check');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_amount_check');
            DB::statement('ALTER TABLE packages ADD CONSTRAINT packages_amount_check CHECK (amount IS NULL OR amount >= 0)');
            DB::statement('ALTER TABLE packages ADD CONSTRAINT packages_consultation_amount_check CHECK (consultation_amount IS NULL OR consultation_amount > 0)');
            DB::statement('ALTER TABLE packages ADD CONSTRAINT packages_activation_check CHECK (is_active = false OR amount IS NOT NULL)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_check CHECK (amount >= 0)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_payment_requirement_check CHECK ((amount = 0 AND payment_method_id IS NULL) OR (amount > 0 AND payment_method_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_payment_requirement_check');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_amount_check');
            DB::statement('ALTER TABLE packages DROP CONSTRAINT packages_activation_check');
            DB::statement('ALTER TABLE packages DROP CONSTRAINT packages_consultation_amount_check');
            DB::statement('ALTER TABLE packages DROP CONSTRAINT packages_amount_check');
            DB::statement('ALTER TABLE packages ADD CONSTRAINT packages_amount_check CHECK (amount IS NULL OR amount > 0)');
            DB::statement('ALTER TABLE packages ADD CONSTRAINT packages_activation_check CHECK (is_active = false OR (amount IS NOT NULL AND amount > 0))');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_check CHECK (amount > 0)');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('payment_method_id')->nullable(false)->change();
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn('consultation_amount');
        });
    }
};
