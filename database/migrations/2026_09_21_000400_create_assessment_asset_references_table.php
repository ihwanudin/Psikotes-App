<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off). Maps an opaque
 * `asset_id` (issued once, stable across re-syncs) to the private-disk
 * object a reader's `/items` response is allowed to name only indirectly --
 * item payloads carry `asset_id`, never a disk path. Populated by
 * `assets:sync-ist` (App\Actions\AssessmentAssets\SyncIstAssets), never
 * hand-edited. Same "no RLS" posture as `instrument_versions`: this is
 * versioned reference/catalog content, not participant-scoped data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_asset_references', function (Blueprint $table): void {
            $table->ulid('asset_id')->primary();
            $table->string('instrument', 32);
            $table->string('disk', 32);
            $table->string('object_key', 255);
            $table->char('checksum_sha256', 64);
            $table->timestampsTz();

            $table->unique(['instrument', 'object_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_asset_references');
    }
};
