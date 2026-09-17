<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array COLUMNS = [
        'proof_checksum_sha256', 'proof_mime_type', 'proof_size_bytes', 'proof_uploaded_at',
    ];

    private const array CONSTRAINTS = [
        'assessment_bill_proof_identity_pair_check',
        'assessment_bill_proof_checksum_check',
        'assessment_bill_proof_mime_check',
        'assessment_bill_proof_size_check',
        'assessment_bill_proof_key_check',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            if ($this->hasAnyTargetColumn()
                || DB::table('assessment_bills')->whereNotNull('proof_object_key')->exists()) {
                throw new RuntimeException('Assessment bill proof identity cannot be migrated safely.');
            }

            Schema::table('assessment_bills', function (Blueprint $table): void {
                $table->char('proof_checksum_sha256', 64)->nullable();
                $table->string('proof_mime_type', 32)->nullable();
                $table->unsignedBigInteger('proof_size_bytes')->nullable();
                $table->timestampTz('proof_uploaded_at')->nullable();
            });

            if (DB::getDriverName() === 'pgsql') {
                $this->addPostgresContract();
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $hasMetadata = DB::table('assessment_bills')->where(function ($query): void {
                foreach (self::COLUMNS as $column) {
                    $query->orWhereNotNull($column);
                }
            })->exists();
            if ($hasMetadata) {
                throw new RuntimeException('Assessment bill proof identity prevents migration rollback.');
            }

            if (DB::getDriverName() === 'pgsql') {
                foreach (array_reverse(self::CONSTRAINTS) as $constraint) {
                    DB::statement('ALTER TABLE assessment_bills DROP CONSTRAINT '.$constraint);
                }
            }

            Schema::table('assessment_bills', function (Blueprint $table): void {
                $table->dropColumn(self::COLUMNS);
            });
        });
    }

    private function hasAnyTargetColumn(): bool
    {
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('assessment_bills', $column)) {
                return true;
            }
        }

        return false;
    }

    private function addPostgresContract(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE assessment_bills ADD CONSTRAINT assessment_bill_proof_identity_pair_check CHECK (
                (proof_object_key IS NULL AND proof_checksum_sha256 IS NULL AND proof_mime_type IS NULL
                    AND proof_size_bytes IS NULL AND proof_uploaded_at IS NULL)
                OR
                (proof_object_key IS NOT NULL AND proof_checksum_sha256 IS NOT NULL AND proof_mime_type IS NOT NULL
                    AND proof_size_bytes IS NOT NULL AND proof_uploaded_at IS NOT NULL)
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE assessment_bills ADD CONSTRAINT assessment_bill_proof_checksum_check CHECK (
                proof_checksum_sha256 IS NULL OR proof_checksum_sha256 ~ '^[0-9a-f]{64}$'
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE assessment_bills ADD CONSTRAINT assessment_bill_proof_mime_check CHECK (
                proof_mime_type IS NULL OR proof_mime_type IN ('image/jpeg', 'image/png', 'application/pdf')
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE assessment_bills ADD CONSTRAINT assessment_bill_proof_size_check CHECK (
                proof_size_bytes IS NULL OR proof_size_bytes BETWEEN 1 AND 5120000
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE assessment_bills ADD CONSTRAINT assessment_bill_proof_key_check CHECK (
                proof_object_key IS NULL
                OR proof_object_key ~ '^assessment-bills/[a-z0-9]{2}/[a-z0-9]{62}\.(jpg|png|pdf)$'
            )
            SQL);
    }
};
