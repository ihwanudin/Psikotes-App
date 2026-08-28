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
        Schema::create('selection_participants', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 100);
            $table->string('external_candidate_id', 64);
            $table->string('selection_round_id', 64);
            $table->string('registration_id', 100);
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 200);
            $table->char('request_hash', 64);
            $table->timestampsTz();

            $table->unique(['client_id', 'external_candidate_id']);
            $table->unique(['client_id', 'idempotency_key']);
            $table->unique('participant_id');
            $table->index(['selection_round_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE selection_participants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE selection_participants FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY selection_participants_service ON selection_participants
            FOR ALL TO psikotes_runtime
            USING (app_private.app_role() = 'service')
            WITH CHECK (app_private.app_role() = 'service')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('selection_participants');
    }
};
