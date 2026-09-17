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
        Schema::create('integration_callback_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbox_message_id')->constrained('outbox_messages')->cascadeOnDelete();
            $table->foreignId('integration_client_id')->constrained()->restrictOnDelete();
            $table->ulid('event_id')->unique();
            $table->string('status', 24)->default('PENDING');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->unique('outbox_message_id');
            $table->index(['status', 'updated_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE integration_callback_deliveries ADD CONSTRAINT integration_callback_deliveries_status_check CHECK (status IN ('PENDING','SENDING','UNKNOWN','FAILED','DELIVERED'))");
            DB::statement('ALTER TABLE integration_callback_deliveries ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE integration_callback_deliveries FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY integration_callback_deliveries_service ON integration_callback_deliveries FOR ALL TO psikotes_runtime USING (app_private.app_role() = 'service') WITH CHECK (app_private.app_role() = 'service')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_callback_deliveries');
    }
};
