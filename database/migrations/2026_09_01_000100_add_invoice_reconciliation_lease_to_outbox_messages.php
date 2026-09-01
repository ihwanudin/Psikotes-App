<?php

declare(strict_types=1);

use App\Models\AssessmentBill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'outbox_invoice_reconciliation_discovery_idx';

    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->uuid('reconciliation_lease_token')->nullable();
            $table->timestampTz('reconciliation_lease_expires_at')->nullable();
            $table->timestampTz('reconciliation_next_at')->nullable();
            $table->unsignedSmallInteger('reconciliation_lookup_attempts')->default(0);
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresContract();

            return;
        }

        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->index([
                'topic', 'status', 'attempts', 'processed_at', 'reconciliation_next_at',
                'reconciliation_lease_expires_at', 'id',
            ], self::INDEX);
        });
    }

    public function down(): void
    {
        $hasMetadata = DB::table('outbox_messages')->where(function ($query): void {
            $query->whereNotNull('reconciliation_lease_token')
                ->orWhereNotNull('reconciliation_lease_expires_at')
                ->orWhereNotNull('reconciliation_next_at')
                ->orWhere('reconciliation_lookup_attempts', '<>', 0);
        })->exists();
        if ($hasMetadata) {
            throw new RuntimeException('Invoice reconciliation metadata prevents rollback; drain leases and cooldown state explicitly.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX '.self::INDEX);
            foreach (['outbox_reconciliation_active_lease_check', 'outbox_reconciliation_topic_isolation_check',
                'outbox_reconciliation_lookup_attempts_check', 'outbox_reconciliation_lease_pair_check'] as $constraint) {
                DB::statement('ALTER TABLE outbox_messages DROP CONSTRAINT '.$constraint);
            }
        } else {
            Schema::table('outbox_messages', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }

        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->dropColumn([
                'reconciliation_lease_token', 'reconciliation_lease_expires_at',
                'reconciliation_next_at', 'reconciliation_lookup_attempts',
            ]);
        });
    }

    private function addPostgresContract(): void
    {
        $topic = 'assessment.bill.invoice-issuance';
        $aggregate = AssessmentBill::class;
        DB::statement('ALTER TABLE outbox_messages ADD CONSTRAINT outbox_reconciliation_lease_pair_check CHECK (
            (reconciliation_lease_token IS NULL) = (reconciliation_lease_expires_at IS NULL)
        )');
        DB::statement('ALTER TABLE outbox_messages ADD CONSTRAINT outbox_reconciliation_lookup_attempts_check CHECK (
            reconciliation_lookup_attempts >= 0 AND reconciliation_lookup_attempts <= 100
        )');
        DB::statement("ALTER TABLE outbox_messages ADD CONSTRAINT outbox_reconciliation_topic_isolation_check CHECK (
            topic = '{$topic}' OR (
                reconciliation_lease_token IS NULL AND reconciliation_lease_expires_at IS NULL
                AND reconciliation_next_at IS NULL AND reconciliation_lookup_attempts = 0
            )
        )");
        DB::statement("ALTER TABLE outbox_messages ADD CONSTRAINT outbox_reconciliation_active_lease_check CHECK (
            reconciliation_lease_token IS NULL OR (
                topic = '{$topic}' AND aggregate_type = '{$aggregate}' AND attempts = 1
                AND processed_at IS NULL AND (
                    (status = 'processing' AND last_error IS NULL)
                    OR (status = 'failed' AND last_error = 'INVOICE_OUTCOME_UNKNOWN')
                )
            )
        )");
        DB::statement('CREATE INDEX '.self::INDEX." ON outbox_messages
            (reconciliation_next_at, reconciliation_lease_expires_at, id)
            WHERE topic = '{$topic}' AND aggregate_type = '{$aggregate}' AND attempts = 1
                AND processed_at IS NULL AND (
                    (status = 'processing' AND last_error IS NULL)
                    OR (status = 'failed' AND last_error = 'INVOICE_OUTCOME_UNKNOWN')
                )");
    }
};
