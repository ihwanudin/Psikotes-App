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
        Schema::create('generic_assessment_result_callback_schedules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('outbox_id')->unique();
            $table->string('state', 24);
            $table->unsignedSmallInteger('broker_attempts');
            $table->timestampTz('next_dispatch_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('last_failure_code', 64)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('outbox_id')->references('id')->on('generic_assessment_result_outbox')->restrictOnDelete();
            $table->index(['state', 'next_dispatch_at', 'outbox_id'], 'generic_result_callback_schedule_due_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresGuard();
        } elseif (DB::getDriverName() === 'sqlite') {
            $this->addSqliteGuards();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS guard_generic_result_callback_schedule() CASCADE');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS generic_result_callback_schedule_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS generic_result_callback_schedule_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS generic_result_callback_schedule_delete_guard');
        }

        Schema::dropIfExists('generic_assessment_result_callback_schedules');
    }

    private function addSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER generic_result_callback_schedule_insert_guard
            BEFORE INSERT ON generic_assessment_result_callback_schedules
            BEGIN
                SELECT CASE WHEN
                    NEW.state <> 'PENDING'
                    OR NEW.broker_attempts <> 1
                    OR NEW.next_dispatch_at IS NULL
                    OR NEW.next_dispatch_at < NEW.created_at
                    OR NEW.queued_at IS NOT NULL
                    OR NEW.completed_at IS NOT NULL
                    OR NEW.last_failure_code IS NOT NULL
                THEN RAISE(ABORT, 'generic result callback schedule invariant violation') END;
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER generic_result_callback_schedule_update_guard
            BEFORE UPDATE ON generic_assessment_result_callback_schedules
            BEGIN
                SELECT CASE WHEN
                    NEW.id IS NOT OLD.id
                    OR NEW.outbox_id IS NOT OLD.outbox_id
                    OR NEW.created_at IS NOT OLD.created_at
                    OR NEW.broker_attempts < OLD.broker_attempts
                    OR NEW.broker_attempts > OLD.broker_attempts + 1
                    OR NEW.broker_attempts < 1
                    OR NEW.broker_attempts > 4
                    OR NEW.state NOT IN ('PENDING','QUEUED','RUNNING','RETRY_WAIT','COMPLETED','BROKER_FAILED','WORKER_FAILED','BROKER_EXHAUSTED')
                    OR (NEW.state = 'BROKER_EXHAUSTED' AND NEW.broker_attempts <> 4)
                    OR NOT (
                        (NEW.broker_attempts = OLD.broker_attempts + 1
                            AND NEW.state = 'PENDING'
                            AND OLD.state IN ('PENDING','QUEUED','RUNNING','RETRY_WAIT','BROKER_FAILED','WORKER_FAILED'))
                        OR (NEW.broker_attempts = OLD.broker_attempts AND (
                            (OLD.state = 'PENDING' AND NEW.state IN ('QUEUED','RUNNING','BROKER_FAILED','BROKER_EXHAUSTED','WORKER_FAILED'))
                            OR (OLD.state = 'QUEUED' AND NEW.state IN ('RUNNING','WORKER_FAILED'))
                            OR (OLD.state = 'BROKER_FAILED' AND NEW.state = 'RUNNING')
                            OR (OLD.state = 'RUNNING' AND NEW.state IN ('RETRY_WAIT','COMPLETED','WORKER_FAILED'))
                            OR (OLD.state IN ('QUEUED','RUNNING','RETRY_WAIT','BROKER_FAILED','WORKER_FAILED') AND NEW.state = 'BROKER_EXHAUSTED')
                        ))
                    )
                    OR (NEW.state IN ('PENDING','QUEUED','RUNNING','RETRY_WAIT','BROKER_FAILED','WORKER_FAILED') AND NEW.next_dispatch_at IS NULL)
                    OR (NEW.state IN ('COMPLETED','BROKER_EXHAUSTED') AND NEW.next_dispatch_at IS NOT NULL)
                    OR (NEW.state = 'QUEUED' AND NEW.queued_at IS NULL)
                    OR (NEW.state = 'COMPLETED' AND NEW.completed_at IS NULL)
                    OR (NEW.state <> 'COMPLETED' AND NEW.completed_at IS NOT NULL)
                    OR (NEW.state NOT IN ('BROKER_FAILED','WORKER_FAILED','BROKER_EXHAUSTED') AND NEW.last_failure_code IS NOT NULL)
                    OR (NEW.state IN ('BROKER_FAILED','BROKER_EXHAUSTED') AND NEW.last_failure_code <> 'BROKER_DISPATCH_FAILED')
                    OR (NEW.state = 'WORKER_FAILED' AND NEW.last_failure_code <> 'WORKER_EXECUTION_FAILED')
                THEN RAISE(ABORT, 'generic result callback schedule update invalid') END;
            END
        SQL);
        DB::unprepared("CREATE TRIGGER generic_result_callback_schedule_delete_guard BEFORE DELETE ON generic_assessment_result_callback_schedules BEGIN SELECT RAISE(ABORT, 'generic result callback schedules are durable'); END");
    }

    private function addPostgresGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_generic_result_callback_schedule()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'generic result callback schedules are durable';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.state <> 'PENDING' OR NEW.broker_attempts <> 1
                        OR NEW.next_dispatch_at IS NULL OR NEW.next_dispatch_at < NEW.created_at
                        OR NEW.queued_at IS NOT NULL OR NEW.completed_at IS NOT NULL OR NEW.last_failure_code IS NOT NULL
                    THEN RAISE EXCEPTION 'generic result callback schedule invariant violation'; END IF;
                    RETURN NEW;
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.outbox_id IS DISTINCT FROM OLD.outbox_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR NEW.broker_attempts < OLD.broker_attempts OR NEW.broker_attempts > OLD.broker_attempts + 1
                    OR NEW.broker_attempts < 1 OR NEW.broker_attempts > 4
                    OR NEW.state NOT IN ('PENDING','QUEUED','RUNNING','RETRY_WAIT','COMPLETED','BROKER_FAILED','WORKER_FAILED','BROKER_EXHAUSTED')
                    OR (NEW.state = 'BROKER_EXHAUSTED' AND NEW.broker_attempts <> 4)
                    OR NOT (
                        (NEW.broker_attempts = OLD.broker_attempts + 1
                            AND NEW.state = 'PENDING'
                            AND OLD.state IN ('PENDING','QUEUED','RUNNING','RETRY_WAIT','BROKER_FAILED','WORKER_FAILED'))
                        OR (NEW.broker_attempts = OLD.broker_attempts AND (
                            (OLD.state = 'PENDING' AND NEW.state IN ('QUEUED','RUNNING','BROKER_FAILED','BROKER_EXHAUSTED','WORKER_FAILED'))
                            OR (OLD.state = 'QUEUED' AND NEW.state IN ('RUNNING','WORKER_FAILED'))
                            OR (OLD.state = 'BROKER_FAILED' AND NEW.state = 'RUNNING')
                            OR (OLD.state = 'RUNNING' AND NEW.state IN ('RETRY_WAIT','COMPLETED','WORKER_FAILED'))
                            OR (OLD.state IN ('QUEUED','RUNNING','RETRY_WAIT','BROKER_FAILED','WORKER_FAILED') AND NEW.state = 'BROKER_EXHAUSTED')
                        ))
                    )
                    OR (NEW.state IN ('PENDING','QUEUED','RUNNING','RETRY_WAIT','BROKER_FAILED','WORKER_FAILED') AND NEW.next_dispatch_at IS NULL)
                    OR (NEW.state IN ('COMPLETED','BROKER_EXHAUSTED') AND NEW.next_dispatch_at IS NOT NULL)
                    OR (NEW.state = 'QUEUED' AND NEW.queued_at IS NULL)
                    OR (NEW.state = 'COMPLETED' AND NEW.completed_at IS NULL)
                    OR (NEW.state <> 'COMPLETED' AND NEW.completed_at IS NOT NULL)
                    OR (NEW.state NOT IN ('BROKER_FAILED','WORKER_FAILED','BROKER_EXHAUSTED') AND NEW.last_failure_code IS NOT NULL)
                    OR (NEW.state IN ('BROKER_FAILED','BROKER_EXHAUSTED') AND NEW.last_failure_code <> 'BROKER_DISPATCH_FAILED')
                    OR (NEW.state = 'WORKER_FAILED' AND NEW.last_failure_code <> 'WORKER_EXECUTION_FAILED')
                THEN RAISE EXCEPTION 'generic result callback schedule update invalid'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER generic_result_callback_schedule_guard
            BEFORE INSERT OR UPDATE OR DELETE ON generic_assessment_result_callback_schedules
            FOR EACH ROW EXECUTE FUNCTION guard_generic_result_callback_schedule()
        SQL);
    }
};
