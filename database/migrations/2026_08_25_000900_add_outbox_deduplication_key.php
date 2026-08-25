<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->char('deduplication_key', 64)->nullable()->after('message_id');
            $table->unique('deduplication_key');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->dropUnique(['deduplication_key']);
            $table->dropColumn('deduplication_key');
        });
    }
};
