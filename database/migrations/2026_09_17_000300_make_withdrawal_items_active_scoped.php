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
        Schema::table('withdrawal_request_items', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('currency');
            $table->dropUnique('withdrawal_request_items_commission_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX withdrawal_request_items_active_commission_unique
                ON withdrawal_request_items (commission_entry_id)
                WHERE is_active = true',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS withdrawal_request_items_active_commission_unique');

        Schema::table('withdrawal_request_items', function (Blueprint $table): void {
            $table->dropColumn('is_active');
            $table->unique('commission_entry_id', 'withdrawal_request_items_commission_unique');
        });
    }
};
