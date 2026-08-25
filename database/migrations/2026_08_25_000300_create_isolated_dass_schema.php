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
        if ($this->usesPostgres()) {
            DB::statement('CREATE SCHEMA IF NOT EXISTS dass');
        }

        $assessments = $this->table('assessments');
        $responses = $this->table('responses');
        $results = $this->table('results');

        Schema::create($assessments, function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consent_record_id')->nullable()->constrained('consent_records')->nullOnDelete();
            $table->string('status', 24)->default('not_started');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index(['participant_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create($responses, function (Blueprint $table) use ($assessments): void {
            $table->id();
            $table->foreignId('assessment_id')->constrained($assessments)->cascadeOnDelete();
            $table->unsignedTinyInteger('item_number');
            $table->unsignedTinyInteger('response_value');
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestampTz('answered_at');
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['assessment_id', 'item_number']);
            $table->index('expires_at');
        });

        Schema::create($results, function (Blueprint $table) use ($assessments): void {
            $table->id();
            $table->foreignId('assessment_id')->unique()->constrained($assessments)->cascadeOnDelete();
            $table->unsignedTinyInteger('depression_raw');
            $table->unsignedTinyInteger('anxiety_raw');
            $table->unsignedTinyInteger('stress_raw');
            $table->unsignedTinyInteger('depression_score');
            $table->unsignedTinyInteger('anxiety_score');
            $table->unsignedTinyInteger('stress_score');
            $table->string('depression_category', 24);
            $table->string('anxiety_category', 24);
            $table->string('stress_category', 24);
            $table->string('overall_category', 24);
            $table->string('follow_up', 32)->nullable();
            $table->json('validity_flags')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index('expires_at');
        });

        $this->addPostgresConstraints($assessments, $responses);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('results'));
        Schema::dropIfExists($this->table('responses'));
        Schema::dropIfExists($this->table('assessments'));

        if ($this->usesPostgres()) {
            DB::statement('DROP SCHEMA IF EXISTS dass');
        }
    }

    private function table(string $name): string
    {
        return $this->usesPostgres() ? "dass.{$name}" : "dass_{$name}";
    }

    private function usesPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    private function addPostgresConstraints(string $assessments, string $responses): void
    {
        if (! $this->usesPostgres()) {
            return;
        }

        DB::statement("ALTER TABLE {$assessments} ADD CONSTRAINT dass_assessments_status_check CHECK (status IN ('not_started', 'in_progress', 'completed', 'declined', 'withdrawn'))");
        DB::statement("ALTER TABLE {$responses} ADD CONSTRAINT dass_responses_item_check CHECK (item_number BETWEEN 1 AND 21)");
        DB::statement("ALTER TABLE {$responses} ADD CONSTRAINT dass_responses_value_check CHECK (response_value BETWEEN 0 AND 3)");
    }
};
