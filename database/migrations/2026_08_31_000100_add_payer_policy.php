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
        // Add without defaults first: legacy rows must remain unconfigured.
        Schema::table('branches', function (Blueprint $table): void {
            $table->json('allowed_payer_types')->nullable();
        });
        Schema::table('integration_sources', function (Blueprint $table): void {
            $table->json('allowed_payer_types')->nullable();
            $table->string('locked_payer_type', 24)->nullable();
        });

        // Defaults apply only to future inserts, including non-Eloquent writers.
        Schema::table('branches', function (Blueprint $table): void {
            $table->json('allowed_payer_types')->nullable()->default('["self"]')->change();
        });
        Schema::table('integration_sources', function (Blueprint $table): void {
            $table->json('allowed_payer_types')->nullable()->default('[]')->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['branches', 'integration_sources'] as $table) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_payer_types_check CHECK (
                    allowed_payer_types IS NULL OR (
                        jsonb_typeof(allowed_payer_types::jsonb) = 'array'
                        AND allowed_payer_types::jsonb <@ '[\"self\",\"organization\"]'::jsonb
                    )
                )");
            }
            DB::statement("ALTER TABLE integration_sources ADD CONSTRAINT integration_sources_locked_payer_check CHECK (
                locked_payer_type IS NULL OR (
                    locked_payer_type IN ('self', 'organization')
                    AND allowed_payer_types IS NOT NULL
                    AND jsonb_exists(allowed_payer_types::jsonb, locked_payer_type)
                )
            )");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE integration_sources DROP CONSTRAINT integration_sources_locked_payer_check');
            DB::statement('ALTER TABLE integration_sources DROP CONSTRAINT integration_sources_payer_types_check');
            DB::statement('ALTER TABLE branches DROP CONSTRAINT branches_payer_types_check');
        }
        Schema::table('integration_sources', function (Blueprint $table): void {
            $table->dropColumn(['allowed_payer_types', 'locked_payer_type']);
        });
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('allowed_payer_types');
        });
    }
};
