<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off; corrected same
 * day after Lead's review of PR #78 caught the table shipping with no
 * RLS/GRANT at all -- "no RLS" was a misreading of `instrument_versions`'s
 * OWN base migration; its service-only RLS/GRANT actually live in the
 * later 2026_09_09_000100_harden_instrument_versions_history.php, which
 * this migration follows instead, folded into table creation itself
 * (matching identity_evidence's single-migration shape -- there's no
 * pre-existing unprotected data to migrate around here).
 *
 * Maps an opaque `asset_id` (issued once, stable across re-syncs) to the
 * private-disk object a reader's `/items` response is allowed to name only
 * indirectly -- item payloads carry `asset_id`, never a disk path.
 * Populated only by `assets:sync-ist`
 * (App\Actions\AssessmentAssets\SyncIstAssets), read only by
 * GetAssessmentSessionAssetUrl -- both run exclusively inside
 * RlsContextRunner::runAsService(), so a service-only policy set covers
 * every real caller; nothing else should ever touch this table directly.
 * No DELETE grant: nothing in this codebase deletes a row (SyncIstAssets
 * deliberately doesn't handle source-file removal yet, see its own doc
 * comment).
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

        $this->securePostgresTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_asset_references');
    }

    private function securePostgresTable(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            REVOKE ALL PRIVILEGES ON TABLE assessment_asset_references FROM psikotes_runtime;
            GRANT SELECT, INSERT, UPDATE ON TABLE assessment_asset_references TO psikotes_runtime;

            ALTER TABLE assessment_asset_references ENABLE ROW LEVEL SECURITY;
            ALTER TABLE assessment_asset_references FORCE ROW LEVEL SECURITY;

            CREATE POLICY assessment_asset_references_service_select ON assessment_asset_references
                FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() = 'service');
            CREATE POLICY assessment_asset_references_service_insert ON assessment_asset_references
                FOR INSERT TO psikotes_runtime
                WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY assessment_asset_references_service_update ON assessment_asset_references
                FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() = 'service')
                WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }
};
