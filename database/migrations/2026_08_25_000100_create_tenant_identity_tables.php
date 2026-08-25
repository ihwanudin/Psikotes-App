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
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 160);
            $table->string('ref_code', 64)->unique();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('email', 255)->unique();
            $table->string('password');
            $table->string('role', 32);
            $table->boolean('can_verify_payments')->default(false);
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['branch_id', 'role']);
        });

        Schema::create('participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('referral_branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('referral_source', 16);
            $table->string('full_name', 200);
            $table->string('gender', 16);
            $table->date('birth_date');
            $table->string('education_level', 64);
            $table->string('intended_field', 24);
            $table->string('phone', 32);
            $table->string('email', 255)->nullable();
            $table->string('test_number', 32)->nullable()->unique();
            $table->timestampTz('purge_after')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['branch_id', 'created_at']);
            $table->index(['referral_branch_id', 'created_at']);
        });

        Schema::create('referral_visits', function (Blueprint $table): void {
            $table->id();
            $table->string('ref_code', 64);
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index(['ref_code', 'first_seen_at']);
            $table->index('participant_id');
            $table->index('expires_at');
        });

        Schema::create('consent_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->string('consent_type', 16);
            $table->string('status', 16);
            $table->string('document_version', 64);
            $table->char('document_hash', 64);
            $table->timestampTz('consented_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampsTz();

            $table->unique(['participant_id', 'consent_type', 'document_version']);
            $table->index(['participant_id', 'consent_type', 'status']);
        });

        $this->addPostgresConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('referral_visits');
        Schema::dropIfExists('participants');
        Schema::dropIfExists('admins');
        Schema::dropIfExists('branches');
    }

    private function addPostgresConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX branches_one_default_idx ON branches (is_default) WHERE is_default = true');
        DB::statement("ALTER TABLE admins ADD CONSTRAINT admins_role_check CHECK (role IN ('super_admin', 'branch_admin', 'staff', 'psychologist'))");
        DB::statement("ALTER TABLE participants ADD CONSTRAINT participants_referral_source_check CHECK (referral_source IN ('link', 'manual', 'default'))");
        DB::statement("ALTER TABLE participants ADD CONSTRAINT participants_gender_check CHECK (gender IN ('female', 'male'))");
        DB::statement("ALTER TABLE participants ADD CONSTRAINT participants_intended_field_check CHECK (intended_field IN ('KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM'))");
        DB::statement("ALTER TABLE consent_records ADD CONSTRAINT consent_records_type_check CHECK (consent_type IN ('psychotest', 'dass'))");
        DB::statement("ALTER TABLE consent_records ADD CONSTRAINT consent_records_status_check CHECK (status IN ('accepted', 'declined', 'withdrawn'))");
        DB::statement("ALTER TABLE consent_records ADD CONSTRAINT consent_records_timestamp_check CHECK ((status = 'accepted' AND consented_at IS NOT NULL) OR status <> 'accepted')");
    }
};
