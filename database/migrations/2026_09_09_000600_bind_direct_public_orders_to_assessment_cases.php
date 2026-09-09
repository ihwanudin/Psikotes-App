<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PARENT_UNIQUE = 'assessment_cases_order_scope_unique';

    private const ORDER_UNIQUE = 'orders_assessment_case_unique';

    private const ORDER_FOREIGN = 'orders_assessment_case_scope_fk';

    private const SUPPORTED = ['dass21', 'ist', 'kraepelin', 'papi', 'rmib'];

    public function up(): void
    {
        try {
            $this->transactional(function (): void {
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') {
                    $this->lockPostgresTables();
                    $this->setPostgresForceRls(false);
                }

                if (Schema::hasColumn('orders', 'assessment_case_id')) {
                    $this->abort('partial order binding schema already exists');
                }
                if (DB::table('assessment_cases')->where('origin', 'DIRECT_PUBLIC')->exists()) {
                    $this->abort('unbound direct case history already exists');
                }
                $this->assertOneOrderPerDirectParticipant();

                if ($driver === 'sqlite') {
                    DB::statement('ALTER TABLE orders ADD COLUMN assessment_case_id INTEGER NULL');
                } else {
                    Schema::table('orders', function (Blueprint $table): void {
                        $table->unsignedBigInteger('assessment_case_id')->nullable();
                    });
                }

                $this->backfill();
                $this->assertComplete();
                $this->enforce($driver);

                if ($driver === 'pgsql') {
                    $this->setPostgresForceRls(true);
                }
            });
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException
                && str_starts_with($exception->getMessage(), 'Direct public order case backfill aborted:')) {
                throw $exception;
            }

            throw new RuntimeException(
                'Direct public order case backfill aborted: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    public function down(): void
    {
        $this->transactional(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                $this->lockPostgresTables();
                $this->setPostgresForceRls(false);
            }

            if (DB::table('orders')->whereNotNull('assessment_case_id')->exists()
                || DB::table('assessment_cases')->where('origin', 'DIRECT_PUBLIC')->exists()) {
                throw new RuntimeException('Direct public order case history prevents rollback.');
            }

            $this->removeEnforcement($driver);

            if ($driver === 'pgsql') {
                $this->setPostgresForceRls(true);
            }
        });
    }

    private function backfill(): void
    {
        $orders = DB::table('orders as orders')
            ->join('participants as participant', 'participant.id', '=', 'orders.participant_id')
            ->orderBy('orders.id')
            ->get([
                'orders.id', 'orders.public_id', 'orders.participant_id', 'orders.created_at',
                'participant.branch_id', 'participant.package_id', 'participant.source_system',
            ]);

        foreach ($orders as $order) {
            if ($order->source_system !== 'DIRECT_PUBLIC') {
                continue;
            }

            if (! is_numeric($order->package_id)
                || ! is_numeric($order->branch_id)
                || ! is_string($order->public_id)
                || ! Str::isUlid($order->public_id)
                || strtoupper($order->public_id) !== $order->public_id
                || $order->created_at === null) {
                $this->abort('historical direct identity is incomplete');
            }

            $packageTypes = $this->packageTypes((int) $order->package_id);
            $entitlementTypes = $this->entitlementTypes((int) $order->id, (int) $order->participant_id);
            if ($packageTypes !== $entitlementTypes) {
                $this->abort('package items and entitlements differ');
            }

            $kind = $this->compositionKind($packageTypes);
            if ($kind === 'dass') {
                continue;
            }

            $caseId = DB::table('assessment_cases')->insertGetId([
                'public_id' => $order->public_id,
                'participant_id' => $order->participant_id,
                'organization_id' => $order->branch_id,
                'package_id' => $order->package_id,
                'origin' => 'DIRECT_PUBLIC',
                'intended_field_snapshot' => null,
                'created_at' => $order->created_at,
                'updated_at' => $order->created_at,
            ]);

            DB::table('orders')->where('id', $order->id)->update(['assessment_case_id' => $caseId]);
        }
    }

    private function assertOneOrderPerDirectParticipant(): void
    {
        $invalid = DB::table('participants as participant')
            ->leftJoin('orders as orders', 'orders.participant_id', '=', 'participant.id')
            ->where('participant.source_system', 'DIRECT_PUBLIC')
            ->groupBy('participant.id')
            ->havingRaw('COUNT(orders.id) <> 1')
            ->limit(1)
            ->first(['participant.id']) !== null;

        if ($invalid) {
            $this->abort('each direct participant must have exactly one order');
        }
    }

    private function assertComplete(): void
    {
        $orders = DB::table('orders as orders')
            ->join('participants as participant', 'participant.id', '=', 'orders.participant_id')
            ->where('participant.source_system', 'DIRECT_PUBLIC')
            ->orderBy('orders.id')
            ->get([
                'orders.id', 'orders.public_id', 'orders.participant_id', 'orders.assessment_case_id',
                'participant.branch_id', 'participant.package_id',
            ]);

        foreach ($orders as $order) {
            if (! is_numeric($order->package_id)) {
                $this->abort('historical direct package is missing');
            }

            $packageTypes = $this->packageTypes((int) $order->package_id);
            $entitlementTypes = $this->entitlementTypes((int) $order->id, (int) $order->participant_id);
            if ($packageTypes !== $entitlementTypes) {
                $this->abort('package items and entitlements differ');
            }

            $kind = $this->compositionKind($packageTypes);
            if ($kind === 'dass') {
                if ($order->assessment_case_id !== null) {
                    $this->abort('DASS-only order was bound to a case');
                }

                continue;
            }

            if (! is_numeric($order->assessment_case_id)) {
                $this->abort('main direct order was not bound');
            }

            $case = DB::table('assessment_cases')->where('id', $order->assessment_case_id)->first();
            if ($case === null
                || $case->public_id !== $order->public_id
                || $case->participant_id !== $order->participant_id
                || $case->organization_id !== $order->branch_id
                || $case->package_id !== $order->package_id
                || $case->origin !== 'DIRECT_PUBLIC') {
                $this->abort('direct order does not match its exact case');
            }
        }
    }

    /** @return list<string> */
    private function packageTypes(int $packageId): array
    {
        $types = DB::table('package_items')->where('package_id', $packageId)
            ->orderBy('test_type')->pluck('test_type')->all();

        return array_values(array_map(static fn (mixed $type): string => (string) $type, $types));
    }

    /** @return list<string> */
    private function entitlementTypes(int $orderId, int $participantId): array
    {
        if (DB::table('entitlements')->where('order_id', $orderId)
            ->where('participant_id', '<>', $participantId)->exists()) {
            $this->abort('order entitlement participant differs');
        }

        $rows = DB::table('entitlements')->where('participant_id', $participantId)
            ->orderBy('test_type')->get(['order_id', 'test_type']);
        foreach ($rows as $row) {
            if ($row->order_id !== $orderId) {
                $this->abort('participant entitlement order differs');
            }
        }

        return array_values($rows->map(static fn (object $row): string => (string) $row->test_type)->all());
    }

    /** @param list<string> $types */
    private function compositionKind(array $types): string
    {
        if ($types === ['dass21']) {
            return 'dass';
        }

        if ($types === []
            || count($types) !== count(array_unique($types))
            || array_diff($types, self::SUPPORTED) !== []
            || ! in_array('dass21', $types, true)
            || count(array_diff($types, ['dass21'])) < 1) {
            $this->abort('direct package composition is invalid');
        }

        return 'main';
    }

    private function enforce(string $driver): void
    {
        Schema::table('assessment_cases', function (Blueprint $table): void {
            $table->unique(['id', 'public_id', 'participant_id'], self::PARENT_UNIQUE);
        });

        if ($driver === 'pgsql') {
            Schema::table('orders', function (Blueprint $table): void {
                $table->unique('assessment_case_id', self::ORDER_UNIQUE);
                $table->foreign(['assessment_case_id', 'public_id', 'participant_id'], self::ORDER_FOREIGN)
                    ->references(['id', 'public_id', 'participant_id'])->on('assessment_cases')->restrictOnDelete();
            });
            $this->addPostgresGuard();

            return;
        }

        $triggers = $this->sqliteReferencingTriggers();
        $indexes = $this->sqliteOrderIndexes();
        $this->withoutSqliteTriggers($triggers, function () use ($indexes): void {
            $this->withoutSqliteIndexes($indexes, function (): void {
                Schema::table('orders', function (Blueprint $table): void {
                    $table->unique('assessment_case_id', self::ORDER_UNIQUE);
                    $table->foreign(
                        ['assessment_case_id', 'public_id', 'participant_id'],
                        self::ORDER_FOREIGN,
                    )->references(['id', 'public_id', 'participant_id'])
                        ->on('assessment_cases')->restrictOnDelete();
                });
            });
        });
        $this->restoreSqliteIndexes($indexes);
        $this->addSqliteGuards();
    }

    private function removeEnforcement(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_direct_case_identity_guard ON orders');
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_direct_order_case_identity()');
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropForeign(self::ORDER_FOREIGN);
                $table->dropUnique(self::ORDER_UNIQUE);
                $table->dropColumn('assessment_case_id');
            });
            Schema::table('assessment_cases', function (Blueprint $table): void {
                $table->dropUnique(self::PARENT_UNIQUE);
            });

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS orders_direct_case_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_direct_case_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_direct_case_delete_guard');
        $triggers = $this->sqliteReferencingTriggers([
            'orders_direct_case_insert_guard',
            'orders_direct_case_update_guard',
            'orders_direct_case_delete_guard',
        ]);
        $indexes = array_values(array_filter(
            $this->sqliteOrderIndexes(),
            static fn (array $index): bool => $index['name'] !== self::ORDER_UNIQUE,
        ));
        $this->withoutSqliteTriggers($triggers, function () use ($indexes): void {
            $this->withoutSqliteIndexes($indexes, function (): void {
                $this->rebuildSqliteOrdersWithoutCase();
            });
        });
        $this->restoreSqliteIndexes($indexes);
        Schema::table('assessment_cases', function (Blueprint $table): void {
            $table->dropUnique(self::PARENT_UNIQUE);
        });
    }

    private function rebuildSqliteOrdersWithoutCase(): void
    {
        Schema::create('orders_without_direct_case_binding', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id');
            $table->foreignId('participant_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('IDR');
            $table->string('gateway_ref', 160)->nullable();
            $table->text('invoice_url')->nullable();
            $table->string('proof_object_key', 512)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();
        });

        $columns = implode(', ', [
            'id', 'public_id', 'participant_id', 'payment_method_id', 'status', 'amount', 'currency',
            'gateway_ref', 'invoice_url', 'proof_object_key', 'expires_at', 'paid_at', 'verified_at',
            'verified_by_admin_id', 'rejection_reason', 'metadata', 'created_at', 'updated_at',
        ]);
        DB::statement("INSERT INTO orders_without_direct_case_binding ({$columns}) SELECT {$columns} FROM orders");
        Schema::drop('orders');
        Schema::rename('orders_without_direct_case_binding', 'orders');
    }

    private function addPostgresGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app_private.guard_direct_order_case_identity()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, public AS $guard$
            DECLARE
                participant_row public.participants%ROWTYPE;
                item_count integer;
                dass_count integer;
                non_dass_count integer;
                unsupported_count integer;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.assessment_case_id IS NOT NULL THEN
                        RAISE EXCEPTION 'direct order case identity is immutable' USING ERRCODE = 'P0001';
                    END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.public_id IS DISTINCT FROM OLD.public_id
                        OR NEW.participant_id IS DISTINCT FROM OLD.participant_id
                        OR NEW.assessment_case_id IS DISTINCT FROM OLD.assessment_case_id
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    THEN
                        RAISE EXCEPTION 'direct order case identity is immutable' USING ERRCODE = 'P0001';
                    END IF;
                    RETURN NEW;
                END IF;

                SELECT * INTO participant_row FROM public.participants WHERE id = NEW.participant_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'order participant is missing' USING ERRCODE = '23514';
                END IF;
                IF participant_row.source_system IS DISTINCT FROM 'DIRECT_PUBLIC' THEN
                    IF NEW.assessment_case_id IS NOT NULL THEN
                        RAISE EXCEPTION 'non-direct order cannot bind a direct case' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF participant_row.package_id IS NULL THEN
                    RAISE EXCEPTION 'direct order package is missing' USING ERRCODE = '23514';
                END IF;

                SELECT COUNT(*), COUNT(*) FILTER (WHERE test_type = 'dass21'),
                    COUNT(*) FILTER (WHERE test_type IN ('ist','papi','rmib','kraepelin')),
                    COUNT(*) FILTER (WHERE test_type NOT IN ('dass21','ist','papi','rmib','kraepelin'))
                INTO item_count, dass_count, non_dass_count, unsupported_count
                FROM public.package_items WHERE package_id = participant_row.package_id;

                IF item_count = 1 AND dass_count = 1 THEN
                    IF NEW.assessment_case_id IS NOT NULL THEN
                        RAISE EXCEPTION 'DASS-only order cannot bind a generic case' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF item_count < 2 OR dass_count <> 1 OR non_dass_count < 1 OR unsupported_count <> 0
                    OR NEW.assessment_case_id IS NULL
                THEN
                    RAISE EXCEPTION 'main direct order requires an exact case' USING ERRCODE = '23514';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM public.assessment_cases assessment_case
                    WHERE assessment_case.id = NEW.assessment_case_id
                      AND assessment_case.public_id = NEW.public_id
                      AND assessment_case.participant_id = NEW.participant_id
                      AND assessment_case.organization_id = participant_row.branch_id
                      AND assessment_case.package_id = participant_row.package_id
                      AND assessment_case.origin = 'DIRECT_PUBLIC'
                ) THEN
                    RAISE EXCEPTION 'direct order requires its exact case' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER orders_direct_case_identity_guard
            BEFORE INSERT OR UPDATE OR DELETE ON orders
            FOR EACH ROW EXECUTE FUNCTION app_private.guard_direct_order_case_identity();
            SQL);
    }

    private function addSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER orders_direct_case_insert_guard
            BEFORE INSERT ON orders FOR EACH ROW
            WHEN NOT (
                EXISTS (
                    SELECT 1 FROM participants participant
                    WHERE participant.id = NEW.participant_id
                      AND participant.source_system IS NOT 'DIRECT_PUBLIC'
                      AND NEW.assessment_case_id IS NULL
                )
                OR EXISTS (
                    SELECT 1 FROM participants participant
                    WHERE participant.id = NEW.participant_id
                      AND participant.source_system = 'DIRECT_PUBLIC'
                      AND participant.package_id IS NOT NULL
                      AND (SELECT COUNT(*) FROM package_items item WHERE item.package_id = participant.package_id) = 1
                      AND (SELECT COUNT(*) FROM package_items item WHERE item.package_id = participant.package_id AND item.test_type = 'dass21') = 1
                      AND NEW.assessment_case_id IS NULL
                )
                OR EXISTS (
                    SELECT 1 FROM participants participant
                    JOIN assessment_cases assessment_case ON assessment_case.id = NEW.assessment_case_id
                    WHERE participant.id = NEW.participant_id
                      AND participant.source_system = 'DIRECT_PUBLIC'
                      AND participant.package_id IS NOT NULL
                      AND (SELECT COUNT(*) FROM package_items item WHERE item.package_id = participant.package_id) >= 2
                      AND (SELECT COUNT(*) FROM package_items item WHERE item.package_id = participant.package_id AND item.test_type = 'dass21') = 1
                      AND (SELECT COUNT(*) FROM package_items item WHERE item.package_id = participant.package_id AND item.test_type IN ('ist','papi','rmib','kraepelin')) >= 1
                      AND (SELECT COUNT(*) FROM package_items item WHERE item.package_id = participant.package_id AND item.test_type NOT IN ('dass21','ist','papi','rmib','kraepelin')) = 0
                      AND assessment_case.public_id = NEW.public_id
                      AND assessment_case.participant_id = NEW.participant_id
                      AND assessment_case.organization_id = participant.branch_id
                      AND assessment_case.package_id = participant.package_id
                      AND assessment_case.origin = 'DIRECT_PUBLIC'
                )
            )
            BEGIN SELECT RAISE(ABORT, 'direct order requires its exact case'); END;
            CREATE TRIGGER orders_direct_case_update_guard
            BEFORE UPDATE ON orders FOR EACH ROW
            WHEN NEW.public_id IS NOT OLD.public_id
              OR NEW.participant_id IS NOT OLD.participant_id
              OR NEW.assessment_case_id IS NOT OLD.assessment_case_id
              OR NEW.created_at IS NOT OLD.created_at
            BEGIN SELECT RAISE(ABORT, 'direct order case identity is immutable'); END;
            CREATE TRIGGER orders_direct_case_delete_guard
            BEFORE DELETE ON orders FOR EACH ROW WHEN OLD.assessment_case_id IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'direct order case identity is immutable'); END;
            SQL);
    }

    private function lockPostgresTables(): void
    {
        DB::statement(<<<'SQL'
            LOCK TABLE participants, packages, package_items, assessment_cases, orders, entitlements
                IN ACCESS EXCLUSIVE MODE
            SQL);
    }

    private function setPostgresForceRls(bool $force): void
    {
        $mode = $force ? 'FORCE' : 'NO FORCE';
        foreach (['packages', 'package_items', 'participants', 'orders', 'entitlements', 'assessment_cases'] as $table) {
            DB::statement("ALTER TABLE {$table} {$mode} ROW LEVEL SECURITY");
        }
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('Direct public order case backfill aborted: '.$reason.'.');
    }

    private function transactional(Closure $operation): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::transaction(fn (): mixed => $operation());

            return;
        }

        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('SQLite direct order case migration requires no surrounding transaction.');
        }
        $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');
        if ($foreignKeys) {
            Schema::disableForeignKeyConstraints();
        }
        try {
            DB::transaction(function () use ($operation): void {
                $operation();
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('Direct order case migration would violate existing foreign keys.');
                }
            });
        } finally {
            if ($foreignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    /** @param list<string> $excluded
     * @return list<array{name:string,sql:string}>
     */
    private function sqliteReferencingTriggers(array $excluded = []): array
    {
        return array_values(collect(DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'trigger' AND sql IS NOT NULL AND lower(sql) LIKE '%orders%'
            ORDER BY name
            SQL))->map(fn (object $trigger): array => (array) $trigger)
            ->reject(fn (array $trigger): bool => in_array((string) $trigger['name'], $excluded, true))
            ->map(fn (array $trigger): array => [
                'name' => (string) $trigger['name'],
                'sql' => (string) $trigger['sql'],
            ])->all());
    }

    /** @return list<array{name:string,sql:string}> */
    private function sqliteOrderIndexes(): array
    {
        return array_values(collect(DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'index' AND tbl_name = 'orders' AND sql IS NOT NULL
            ORDER BY name
            SQL))->map(fn (object $index): array => (array) $index)
            ->map(fn (array $index): array => [
                'name' => (string) $index['name'],
                'sql' => (string) $index['sql'],
            ])->all());
    }

    /** @param list<array{name:string,sql:string}> $triggers */
    private function withoutSqliteTriggers(array $triggers, Closure $operation): void
    {
        foreach ($triggers as $trigger) {
            DB::connection()->getPdo()->exec('DROP TRIGGER IF EXISTS "'.str_replace('"', '""', $trigger['name']).'"');
        }
        try {
            $operation();
        } finally {
            foreach ($triggers as $trigger) {
                if (DB::connection()->getPdo()->exec($trigger['sql']) === false) {
                    throw new RuntimeException('Failed to restore an order-referencing SQLite trigger.');
                }
            }
        }
    }

    /** @param list<array{name:string,sql:string}> $indexes */
    private function withoutSqliteIndexes(array $indexes, Closure $operation): void
    {
        foreach ($indexes as $index) {
            DB::statement('DROP INDEX "'.str_replace('"', '""', $index['name']).'"');
        }
        $operation();
    }

    /** @param list<array{name:string,sql:string}> $indexes */
    private function restoreSqliteIndexes(array $indexes): void
    {
        $existing = collect(DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='orders'"))
            ->pluck('name')->all();
        foreach ($indexes as $index) {
            if (! in_array($index['name'], $existing, true)
                && DB::connection()->getPdo()->exec($index['sql']) === false) {
                throw new RuntimeException('Failed to restore an order index.');
            }
        }
    }
};
