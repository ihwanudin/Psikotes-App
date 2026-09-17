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
        Schema::create('identity_evidence', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('disk', 32);
            $table->string('object_key', 255)->unique();
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('checksum_sha256', 64);
            $table->timestampsTz();

            $table->unique(['participant_id', 'type']);
            $table->index(['participant_id', 'created_at']);
        });

        Schema::create('identity_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('matcher', 64);
            $table->string('outcome', 24);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('marker', 120)->nullable();
            $table->string('manual_status', 24)->default('pending');
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('checked_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->index(['outcome', 'manual_status']);
        });

        $this->securePostgresTables();
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
        Schema::dropIfExists('identity_evidence');
    }

    private function securePostgresTables(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE identity_evidence ENABLE ROW LEVEL SECURITY;
            ALTER TABLE identity_evidence FORCE ROW LEVEL SECURITY;
            ALTER TABLE identity_verifications ENABLE ROW LEVEL SECURITY;
            ALTER TABLE identity_verifications FORCE ROW LEVEL SECURITY;

            GRANT SELECT, INSERT, UPDATE, DELETE ON identity_evidence, identity_verifications TO psikotes_runtime;
            GRANT USAGE, SELECT ON SEQUENCE identity_evidence_id_seq, identity_verifications_id_seq TO psikotes_runtime;

            ALTER TABLE identity_evidence
                ADD CONSTRAINT identity_evidence_type_check
                CHECK (type IN ('identity_document', 'initial_selfie'));
            ALTER TABLE identity_verifications
                ADD CONSTRAINT identity_verifications_outcome_check
                CHECK (outcome IN ('pending', 'match', 'mismatch', 'error')),
                ADD CONSTRAINT identity_verifications_confidence_check
                CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1)),
                ADD CONSTRAINT identity_verifications_manual_status_check
                CHECK (manual_status IN ('pending', 'accepted', 'rejected'));

            CREATE POLICY identity_evidence_read ON identity_evidence FOR SELECT TO psikotes_runtime
            USING (
                app_private.app_role() IN ('service', 'super_admin', 'psychologist')
                OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
                OR (
                    app_private.app_role() IN ('branch_admin', 'staff')
                    AND EXISTS (
                        SELECT 1 FROM participants
                        WHERE participants.id = identity_evidence.participant_id
                          AND participants.branch_id = app_private.app_branch_id()
                    )
                )
            );
            CREATE POLICY identity_evidence_write ON identity_evidence FOR ALL TO psikotes_runtime
            USING (app_private.app_role() = 'service')
            WITH CHECK (app_private.app_role() = 'service');

            CREATE POLICY identity_verifications_read ON identity_verifications FOR SELECT TO psikotes_runtime
            USING (
                app_private.app_role() IN ('service', 'super_admin', 'psychologist')
                OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
                OR (
                    app_private.app_role() IN ('branch_admin', 'staff')
                    AND EXISTS (
                        SELECT 1 FROM participants
                        WHERE participants.id = identity_verifications.participant_id
                          AND participants.branch_id = app_private.app_branch_id()
                    )
                )
            );
            CREATE POLICY identity_verifications_write ON identity_verifications FOR ALL TO psikotes_runtime
            USING (app_private.app_role() = 'service')
            WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }
};
