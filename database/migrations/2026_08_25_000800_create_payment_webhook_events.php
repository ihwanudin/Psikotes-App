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
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('provider', 32);
            $table->string('event_id', 160);
            $table->string('provider_reference', 160);
            $table->string('merchant_reference', 64);
            $table->string('status', 24);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->timestampTz('occurred_at');
            $table->char('intent_hash', 64);
            $table->string('outcome', 24)->default('processing');
            $table->string('error_code', 48)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'event_id']);
            $table->index(['provider', 'provider_reference']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_status_check CHECK (status IN ('pending', 'paid', 'expired', 'cancelled'))");
        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_currency_check CHECK (currency = 'IDR')");
        DB::statement('ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_outcome_check CHECK (outcome IN ('processing', 'applied', 'ignored', 'rejected'))");
        DB::statement('ALTER TABLE payment_webhook_events ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payment_webhook_events FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY payment_webhook_events_read ON payment_webhook_events
            FOR SELECT TO psikotes_runtime
            USING (app_private.app_role() IN ('service', 'super_admin'))
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY payment_webhook_events_write ON payment_webhook_events
            FOR ALL TO psikotes_runtime
            USING (app_private.app_role() = 'service')
            WITH CHECK (app_private.app_role() = 'service')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
