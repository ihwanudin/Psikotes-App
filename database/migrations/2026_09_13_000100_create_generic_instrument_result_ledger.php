<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PARENT = 'generic_instrument_results';

    private const CHILD = 'generic_instrument_result_sources';

    private const INSTRUMENT_SCOPE = 'instrument_versions_result_scope_unique';

    /** @var array<string,string> */
    private const SQLITE_DEFINITION_HASHES = [
        'index:instrument_versions_result_scope_unique' => '6b43464d3dcfa053fbd327edf2d2a4a62e0379b7b52249019d3fc6f50f09ecbc',
        'table:generic_instrument_result_sources' => '716e0c623e39563e1b3f25f257566963a361a9bc441101e669f84389bb296245',
        'table:generic_instrument_results' => '44c80ae421760c03f07da0d88305ea7c7d98605c2723ad676d38cd5bc5739049',
        'trigger:generic_instrument_result_sources_guard_delete' => '44ec41f46fb9dfd0e059e502bb285b49ffbbcb24108754cd3e028abeefebd0ba',
        'trigger:generic_instrument_result_sources_guard_update' => 'f90fdc105f3e75e800ae696597d5935112d39df3e6de299fcf3756304fadd14a',
        'trigger:generic_instrument_results_guard_delete' => '40b8a19a1f04a41ec69fbacdf5d75395328f28159c8fe418931d348d15229f0e',
        'trigger:generic_instrument_results_guard_insert' => '311c21c7fded6ab2d1e31a16f922b61373a7562543a1746fae8eb0c83d42004e',
        'trigger:generic_instrument_results_guard_update' => 'ed5bfbed0e4980ba4ca356a05504c59b63c7887b1da6312cf6f3624e8b10889e',
    ];

    public function up(): void
    {
        $this->transactional(function (): void {
            $driver = $this->driver();
            $parent = Schema::hasTable(self::PARENT);
            $child = Schema::hasTable(self::CHILD);
            $support = $this->supportIndexExists($driver);

            if ($parent && $child && $support) {
                $this->assertExactState($driver);

                return;
            }
            if ($parent || $child || $support) {
                throw new RuntimeException('Partial generic instrument result ledger schema prevents migration.');
            }
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE test_sessions, instrument_versions IN SHARE ROW EXCLUSIVE MODE');
            }

            DB::statement('CREATE UNIQUE INDEX '.self::INSTRUMENT_SCOPE
                .' ON instrument_versions (id, code, version, source_file, checksum)');
            $this->createTables($driver);
            $driver === 'pgsql' ? $this->addPostgresContract() : $this->addSqliteContract();
            $this->assertExactState($driver);
        });
    }

    public function down(): void
    {
        $this->transactional(function (): void {
            $driver = $this->driver();
            $parent = Schema::hasTable(self::PARENT);
            $child = Schema::hasTable(self::CHILD);
            $support = $this->supportIndexExists($driver);

            if (! $parent && ! $child && ! $support) {
                return;
            }
            if (! $parent || ! $child || ! $support) {
                throw new RuntimeException('Partial generic instrument result ledger schema prevents rollback.');
            }
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE '.self::PARENT.', '.self::CHILD.' IN ACCESS EXCLUSIVE MODE');
                DB::statement("SELECT set_config('app.role', 'service', true)");
            }
            if (DB::table(self::PARENT)->exists() || DB::table(self::CHILD)->exists()) {
                throw new RuntimeException('Generic instrument result history prevents rollback.');
            }

            Schema::drop(self::CHILD);
            Schema::drop(self::PARENT);
            if ($driver === 'pgsql') {
                DB::unprepared('DROP FUNCTION app_private.guard_generic_instrument_result_source()');
                DB::unprepared('DROP FUNCTION app_private.guard_generic_instrument_result()');
            }
            DB::statement('DROP INDEX '.self::INSTRUMENT_SCOPE);
        });
    }

    private function createTables(string $driver): void
    {
        Schema::create(self::PARENT, function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->ulid('public_id');
            $table->unsignedBigInteger('assessment_case_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('participant_id');
            $table->ulid('session_public_id');
            $table->string('instrument_code', 24);
            $table->unsignedInteger('attempt_no');
            $table->timestampTz('submitted_at', 6);
            $table->unsignedInteger('answers_revision');
            $table->char('sealed_source_checksum', 64);
            $table->string('session_definition_version', 100);
            $table->string('session_definition_provenance', 255);
            $table->char('session_definition_checksum', 64);
            $driver === 'pgsql'
                ? $table->jsonb('session_definition_payload')
                : $table->json('session_definition_payload');
            $table->unsignedBigInteger('instrument_version_id');
            $table->string('instrument_version', 64);
            $table->string('instrument_source_file', 128);
            $table->char('instrument_checksum', 64);
            $table->string('result_contract_version', 100);
            $driver === 'pgsql' ? $table->jsonb('result_payload') : $table->json('result_payload');
            $table->char('result_checksum', 64);
            $table->timestampTz('created_at', 6);

            $table->unique('public_id', 'generic_instrument_results_public_id_unique');
            $table->unique('session_id', 'generic_instrument_results_session_unique');
            $table->foreign(
                ['session_id', 'assessment_case_id', 'participant_id', 'instrument_code'],
                'generic_instrument_results_session_scope_fk',
            )->references(['id', 'assessment_case_id', 'participant_id', 'test_type'])
                ->on('test_sessions')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign(
                ['instrument_version_id', 'instrument_code', 'instrument_version',
                    'instrument_source_file', 'instrument_checksum'],
                'generic_instrument_results_instrument_version_fk',
            )->references(['id', 'code', 'version', 'source_file', 'checksum'])
                ->on('instrument_versions')->restrictOnUpdate()->restrictOnDelete();
        });

        Schema::create(self::CHILD, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('result_id');
            $table->unsignedInteger('ordinal');
            $table->string('source_code', 32);
            $table->integer('raw_score');
            $table->integer('standard_score');
            $table->integer('source_score');
            $table->unsignedSmallInteger('level');
            $table->string('category', 100);
            $table->integer('band_low')->nullable();
            $table->integer('band_high')->nullable();
            $table->timestampTz('created_at', 6);

            $table->unique(['result_id', 'ordinal'], 'generic_instrument_result_sources_order_unique');
            $table->unique(['result_id', 'source_code'], 'generic_instrument_result_sources_code_unique');
            $table->foreign('result_id', 'generic_instrument_result_sources_parent_fk')
                ->references('id')->on(self::PARENT)->restrictOnUpdate()->restrictOnDelete();
        });
    }

    private function addPostgresContract(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE generic_instrument_results
                ADD CONSTRAINT generic_instrument_results_contract_check CHECK (
                    public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND session_public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND instrument_code IN ('ist','papi','rmib','kraepelin')
                    AND attempt_no > 0 AND answers_revision > 0
                    AND sealed_source_checksum ~ '^[0-9a-f]{64}$'
                    AND session_definition_checksum ~ '^[0-9a-f]{64}$'
                    AND instrument_checksum ~ '^[0-9a-f]{64}$'
                    AND result_checksum ~ '^[0-9a-f]{64}$'
                    AND length(session_definition_version) BETWEEN 1 AND 100
                    AND session_definition_version = btrim(session_definition_version)
                    AND session_definition_version !~ '[[:space:][:cntrl:]]'
                    AND length(session_definition_provenance) BETWEEN 1 AND 255
                    AND session_definition_provenance = btrim(session_definition_provenance)
                    AND session_definition_provenance !~ '[[:space:][:cntrl:]]'
                    AND length(result_contract_version) BETWEEN 1 AND 100
                    AND result_contract_version = btrim(result_contract_version)
                    AND result_contract_version !~ '[[:space:][:cntrl:]]'
                    AND jsonb_typeof(session_definition_payload) = 'object'
                    AND jsonb_typeof(result_payload) = 'object'
                );
            ALTER TABLE generic_instrument_result_sources
                ADD CONSTRAINT generic_instrument_result_sources_contract_check CHECK (
                    ordinal > 0 AND raw_score >= 0 AND level BETWEEN 1 AND 5
                    AND length(source_code) BETWEEN 1 AND 32
                    AND source_code = btrim(source_code) AND source_code !~ '[[:space:][:cntrl:]]'
                    AND length(btrim(category)) > 0
                    AND (band_low IS NOT NULL OR band_high IS NOT NULL)
                    AND (band_low IS NULL OR band_high IS NULL OR band_low <= band_high)
                );

            CREATE FUNCTION app_private.guard_generic_instrument_result() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'generic instrument result history is append-only' USING ERRCODE = 'P0001';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM public.test_sessions session
                    WHERE session.id = NEW.session_id
                      AND session.assessment_case_id = NEW.assessment_case_id
                      AND session.participant_id = NEW.participant_id
                      AND session.public_id = NEW.session_public_id
                      AND session.test_type = NEW.instrument_code
                      AND session.attempt_no = NEW.attempt_no
                      AND session.status IN ('submitted','scored')
                      AND session.submitted_at = NEW.submitted_at
                      AND session.answers_revision = NEW.answers_revision
                      AND session.session_definition_version = NEW.session_definition_version
                      AND session.session_definition_provenance = NEW.session_definition_provenance
                      AND session.session_definition_checksum = NEW.session_definition_checksum
                      AND session.session_definition_payload::jsonb = NEW.session_definition_payload::jsonb
                ) THEN
                    RAISE EXCEPTION 'generic instrument result requires its exact submitted session source'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER generic_instrument_results_guard
                BEFORE INSERT OR UPDATE OR DELETE ON generic_instrument_results
                FOR EACH ROW EXECUTE FUNCTION app_private.guard_generic_instrument_result();

            CREATE FUNCTION app_private.guard_generic_instrument_result_source() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $guard$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'generic instrument result source history is append-only' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER generic_instrument_result_sources_guard
                BEFORE UPDATE OR DELETE ON generic_instrument_result_sources
                FOR EACH ROW EXECUTE FUNCTION app_private.guard_generic_instrument_result_source();

            REVOKE ALL PRIVILEGES ON generic_instrument_results,
                generic_instrument_result_sources FROM psikotes_runtime;
            REVOKE ALL PRIVILEGES ON SEQUENCE generic_instrument_results_id_seq,
                generic_instrument_result_sources_id_seq FROM psikotes_runtime;
            GRANT SELECT, INSERT ON generic_instrument_results,
                generic_instrument_result_sources TO psikotes_runtime;
            GRANT USAGE, SELECT ON SEQUENCE generic_instrument_results_id_seq,
                generic_instrument_result_sources_id_seq TO psikotes_runtime;

            ALTER TABLE generic_instrument_results ENABLE ROW LEVEL SECURITY;
            ALTER TABLE generic_instrument_results FORCE ROW LEVEL SECURITY;
            ALTER TABLE generic_instrument_result_sources ENABLE ROW LEVEL SECURITY;
            ALTER TABLE generic_instrument_result_sources FORCE ROW LEVEL SECURITY;
            CREATE POLICY generic_instrument_results_service_select ON generic_instrument_results
                FOR SELECT TO psikotes_runtime USING (app_private.app_role() = 'service');
            CREATE POLICY generic_instrument_results_service_insert ON generic_instrument_results
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY generic_instrument_result_sources_service_select ON generic_instrument_result_sources
                FOR SELECT TO psikotes_runtime USING (app_private.app_role() = 'service');
            CREATE POLICY generic_instrument_result_sources_service_insert ON generic_instrument_result_sources
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');

            REVOKE ALL ON FUNCTION app_private.guard_generic_instrument_result() FROM PUBLIC;
            REVOKE ALL ON FUNCTION app_private.guard_generic_instrument_result_source() FROM PUBLIC;
            SQL);
    }

    private function addSqliteContract(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER generic_instrument_results_guard_insert
            BEFORE INSERT ON generic_instrument_results FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM test_sessions session
                WHERE session.id = NEW.session_id
                  AND session.assessment_case_id = NEW.assessment_case_id
                  AND session.participant_id = NEW.participant_id
                  AND session.public_id = NEW.session_public_id
                  AND session.test_type = NEW.instrument_code
                  AND session.attempt_no = NEW.attempt_no
                  AND session.status IN ('submitted','scored')
                  AND session.submitted_at = NEW.submitted_at
                  AND session.answers_revision = NEW.answers_revision
                  AND session.session_definition_version = NEW.session_definition_version
                  AND session.session_definition_provenance = NEW.session_definition_provenance
                  AND session.session_definition_checksum = NEW.session_definition_checksum
                  AND session.session_definition_payload = NEW.session_definition_payload
            )
            BEGIN SELECT RAISE(ABORT, 'generic instrument result requires its exact submitted session source'); END;
            CREATE TRIGGER generic_instrument_results_guard_update
            BEFORE UPDATE ON generic_instrument_results FOR EACH ROW
            BEGIN SELECT RAISE(ABORT, 'generic instrument result history is append-only'); END;
            CREATE TRIGGER generic_instrument_results_guard_delete
            BEFORE DELETE ON generic_instrument_results FOR EACH ROW
            BEGIN SELECT RAISE(ABORT, 'generic instrument result history is append-only'); END;
            CREATE TRIGGER generic_instrument_result_sources_guard_update
            BEFORE UPDATE ON generic_instrument_result_sources FOR EACH ROW
            BEGIN SELECT RAISE(ABORT, 'generic instrument result source history is append-only'); END;
            CREATE TRIGGER generic_instrument_result_sources_guard_delete
            BEFORE DELETE ON generic_instrument_result_sources FOR EACH ROW
            BEGIN SELECT RAISE(ABORT, 'generic instrument result source history is append-only'); END;
            SQL);
    }

    private function assertExactState(string $driver): void
    {
        foreach ([self::PARENT, self::CHILD] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Generic instrument result ledger schema is incomplete.');
            }
        }
        if (! $this->supportIndexExists($driver)) {
            throw new RuntimeException('Generic instrument result ledger instrument scope is incomplete.');
        }

        $driver === 'pgsql' ? $this->assertPostgresState() : $this->assertSqliteState();
    }

    private function assertPostgresState(): void
    {
        $expectedColumns = [
            self::PARENT => [
                'id' => ['bigint', true], 'public_id' => ['character(26)', true],
                'assessment_case_id' => ['bigint', true], 'session_id' => ['bigint', true],
                'participant_id' => ['bigint', true], 'session_public_id' => ['character(26)', true],
                'instrument_code' => ['character varying(24)', true], 'attempt_no' => ['integer', true],
                'submitted_at' => ['timestamp(6) with time zone', true], 'answers_revision' => ['integer', true],
                'sealed_source_checksum' => ['character(64)', true],
                'session_definition_version' => ['character varying(100)', true],
                'session_definition_provenance' => ['character varying(255)', true],
                'session_definition_checksum' => ['character(64)', true],
                'session_definition_payload' => ['jsonb', true], 'instrument_version_id' => ['bigint', true],
                'instrument_version' => ['character varying(64)', true],
                'instrument_source_file' => ['character varying(128)', true],
                'instrument_checksum' => ['character(64)', true],
                'result_contract_version' => ['character varying(100)', true],
                'result_payload' => ['jsonb', true], 'result_checksum' => ['character(64)', true],
                'created_at' => ['timestamp(6) with time zone', true],
            ],
            self::CHILD => [
                'id' => ['bigint', true], 'result_id' => ['bigint', true],
                'ordinal' => ['integer', true], 'source_code' => ['character varying(32)', true],
                'raw_score' => ['integer', true], 'standard_score' => ['integer', true],
                'source_score' => ['integer', true], 'level' => ['smallint', true],
                'category' => ['character varying(100)', true], 'band_low' => ['integer', false],
                'band_high' => ['integer', false], 'created_at' => ['timestamp(6) with time zone', true],
            ],
        ];
        foreach ($expectedColumns as $table => $expected) {
            $actual = [];
            foreach (DB::select(<<<SQL
                SELECT attname,format_type(atttypid,atttypmod) type,attnotnull
                FROM pg_attribute WHERE attrelid='{$table}'::regclass
                  AND attnum>0 AND NOT attisdropped ORDER BY attnum
                SQL) as $column) {
                $actual[$column->attname] = [$column->type, (bool) $column->attnotnull];
            }
            if ($actual !== $expected) {
                throw new RuntimeException("Generic instrument result ledger {$table} columns are not exact.");
            }
        }

        $constraintNames = collect(DB::select(<<<'SQL'
            SELECT conrelid::regclass::text relation,conname
            FROM pg_constraint
            WHERE conrelid IN (
                'generic_instrument_results'::regclass,
                'generic_instrument_result_sources'::regclass
            ) ORDER BY relation,conname
            SQL))->groupBy('relation')->map(fn ($rows) => $rows->pluck('conname')->all())->all();
        $expectedConstraints = [
            self::PARENT => [
                'generic_instrument_results_contract_check',
                'generic_instrument_results_instrument_version_fk',
                'generic_instrument_results_pkey',
                'generic_instrument_results_public_id_unique',
                'generic_instrument_results_session_scope_fk',
                'generic_instrument_results_session_unique',
            ],
            self::CHILD => [
                'generic_instrument_result_sources_code_unique',
                'generic_instrument_result_sources_contract_check',
                'generic_instrument_result_sources_order_unique',
                'generic_instrument_result_sources_parent_fk',
                'generic_instrument_result_sources_pkey',
            ],
        ];
        foreach ($expectedConstraints as &$names) {
            sort($names);
        }
        unset($names);
        foreach ($expectedConstraints as $table => $expected) {
            if (($constraintNames[$table] ?? null) !== $expected) {
                throw new RuntimeException("Generic instrument result ledger {$table} constraints are not exact.");
            }
        }

        $constraintShapes = [];
        foreach (DB::select(<<<'SQL'
            SELECT constraint_row.conname,constraint_row.contype,
                constraint_row.condeferrable,constraint_row.condeferred,
                string_agg(attribute.attname,',' ORDER BY key.ordinality) columns
            FROM pg_constraint constraint_row
            JOIN unnest(constraint_row.conkey) WITH ORDINALITY key(attnum,ordinality) ON true
            JOIN pg_attribute attribute
              ON attribute.attrelid=constraint_row.conrelid AND attribute.attnum=key.attnum
            WHERE constraint_row.conrelid IN (
                'generic_instrument_results'::regclass,
                'generic_instrument_result_sources'::regclass
            ) GROUP BY constraint_row.conname,constraint_row.contype,
                constraint_row.condeferrable,constraint_row.condeferred
            ORDER BY constraint_row.conname
            SQL) as $row) {
            $shape = (array) $row;
            $constraintShapes[(string) $shape['conname']] = [
                (string) $shape['contype'], (string) $shape['columns'],
                (bool) $shape['condeferrable'], (bool) $shape['condeferred'],
            ];
        }
        $expectedShapes = [
            'generic_instrument_result_sources_code_unique' => ['u', 'result_id,source_code', false, false],
            'generic_instrument_result_sources_order_unique' => ['u', 'result_id,ordinal', false, false],
            'generic_instrument_result_sources_parent_fk' => ['f', 'result_id', false, false],
            'generic_instrument_result_sources_pkey' => ['p', 'id', false, false],
            'generic_instrument_results_instrument_version_fk' => ['f', 'instrument_version_id,instrument_code,instrument_version,instrument_source_file,instrument_checksum', false, false],
            'generic_instrument_results_pkey' => ['p', 'id', false, false],
            'generic_instrument_results_public_id_unique' => ['u', 'public_id', false, false],
            'generic_instrument_results_session_scope_fk' => ['f', 'session_id,assessment_case_id,participant_id,instrument_code', false, false],
            'generic_instrument_results_session_unique' => ['u', 'session_id', false, false],
        ];
        foreach ($expectedShapes as $name => $shape) {
            if (($constraintShapes[$name] ?? null) !== $shape) {
                throw new RuntimeException("Generic instrument result ledger {$name} shape is not exact.");
            }
        }
        $checkDefinitions = collect(DB::select(<<<'SQL'
            SELECT conname,pg_get_constraintdef(oid,false) definition
            FROM pg_constraint WHERE conname IN (
                'generic_instrument_results_contract_check',
                'generic_instrument_result_sources_contract_check'
            ) ORDER BY conname
            SQL))->pluck('definition', 'conname')->all();
        $expectedChecks = [
            'generic_instrument_result_sources_contract_check' => <<<'SQL'
                CHECK (((ordinal > 0) AND (raw_score >= 0) AND ((level >= 1) AND (level <= 5)) AND ((length((source_code)::text) >= 1) AND (length((source_code)::text) <= 32)) AND ((source_code)::text = btrim((source_code)::text)) AND ((source_code)::text !~ '[[:space:][:cntrl:]]'::text) AND (length(btrim((category)::text)) > 0) AND ((band_low IS NOT NULL) OR (band_high IS NOT NULL)) AND ((band_low IS NULL) OR (band_high IS NULL) OR (band_low <= band_high))))
                SQL,
            'generic_instrument_results_contract_check' => <<<'SQL'
                CHECK (((public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'::text) AND (session_public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'::text) AND ((instrument_code)::text = ANY ((ARRAY['ist'::character varying, 'papi'::character varying, 'rmib'::character varying, 'kraepelin'::character varying])::text[])) AND (attempt_no > 0) AND (answers_revision > 0) AND (sealed_source_checksum ~ '^[0-9a-f]{64}$'::text) AND (session_definition_checksum ~ '^[0-9a-f]{64}$'::text) AND (instrument_checksum ~ '^[0-9a-f]{64}$'::text) AND (result_checksum ~ '^[0-9a-f]{64}$'::text) AND ((length((session_definition_version)::text) >= 1) AND (length((session_definition_version)::text) <= 100)) AND ((session_definition_version)::text = btrim((session_definition_version)::text)) AND ((session_definition_version)::text !~ '[[:space:][:cntrl:]]'::text) AND ((length((session_definition_provenance)::text) >= 1) AND (length((session_definition_provenance)::text) <= 255)) AND ((session_definition_provenance)::text = btrim((session_definition_provenance)::text)) AND ((session_definition_provenance)::text !~ '[[:space:][:cntrl:]]'::text) AND ((length((result_contract_version)::text) >= 1) AND (length((result_contract_version)::text) <= 100)) AND ((result_contract_version)::text = btrim((result_contract_version)::text)) AND ((result_contract_version)::text !~ '[[:space:][:cntrl:]]'::text) AND (jsonb_typeof(session_definition_payload) = 'object'::text) AND (jsonb_typeof(result_payload) = 'object'::text)))
                SQL,
        ];
        foreach ($expectedChecks as $name => $expected) {
            if (! isset($checkDefinitions[$name])
                || $this->normalize($checkDefinitions[$name]) !== $this->normalize($expected)) {
                throw new RuntimeException("Generic instrument result ledger {$name} check constraint is not exact.");
            }
        }

        $foreignDefinitions = collect(DB::select(<<<'SQL'
            SELECT conname,pg_get_constraintdef(oid,false) definition
            FROM pg_constraint
            WHERE conname IN (
                'generic_instrument_results_session_scope_fk',
                'generic_instrument_results_instrument_version_fk',
                'generic_instrument_result_sources_parent_fk'
            ) ORDER BY conname
            SQL))->pluck('definition', 'conname')->all();
        $expectedForeignFragments = [
            'generic_instrument_results_session_scope_fk' => 'FOREIGN KEY (session_id, assessment_case_id, participant_id, instrument_code) REFERENCES test_sessions(id, assessment_case_id, participant_id, test_type) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'generic_instrument_results_instrument_version_fk' => 'FOREIGN KEY (instrument_version_id, instrument_code, instrument_version, instrument_source_file, instrument_checksum) REFERENCES instrument_versions(id, code, version, source_file, checksum) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'generic_instrument_result_sources_parent_fk' => 'FOREIGN KEY (result_id) REFERENCES generic_instrument_results(id) ON UPDATE RESTRICT ON DELETE RESTRICT',
        ];
        foreach ($expectedForeignFragments as $name => $expected) {
            if (! isset($foreignDefinitions[$name])
                || $this->normalize($foreignDefinitions[$name]) !== $this->normalize($expected)) {
                throw new RuntimeException("Generic instrument result ledger {$name} is not exact.");
            }
        }

        $support = DB::selectOne(<<<'SQL'
            SELECT index.indisunique,index.indisvalid,index.indisready,
                string_agg(attribute.attname,',' ORDER BY key.ordinality) columns
            FROM pg_index index
            JOIN pg_class class ON class.oid=index.indexrelid
            JOIN unnest(index.indkey) WITH ORDINALITY key(attnum,ordinality) ON true
            JOIN pg_attribute attribute ON attribute.attrelid=index.indrelid AND attribute.attnum=key.attnum
            WHERE class.relname='instrument_versions_result_scope_unique'
            GROUP BY index.indisunique,index.indisvalid,index.indisready
            SQL);
        if ($support === null || ! $support->indisunique || ! $support->indisvalid || ! $support->indisready
            || $support->columns !== 'id,code,version,source_file,checksum') {
            throw new RuntimeException('Generic instrument result ledger instrument scope index is not exact.');
        }

        $tables = collect(DB::select(<<<'SQL'
            SELECT relname,relrowsecurity,relforcerowsecurity,pg_get_userbyid(relowner) owner
            FROM pg_class WHERE relname IN (
                'generic_instrument_results','generic_instrument_result_sources'
            ) ORDER BY relname
            SQL))->keyBy('relname');
        foreach ([self::PARENT, self::CHILD] as $table) {
            if (! isset($tables[$table]) || ! $tables[$table]->relrowsecurity
                || ! $tables[$table]->relforcerowsecurity
                || $tables[$table]->owner === 'psikotes_runtime') {
                throw new RuntimeException("Generic instrument result ledger {$table} RLS is not exact.");
            }
            foreach (['SELECT', 'INSERT'] as $privilege) {
                if (! DB::scalar("SELECT has_table_privilege('psikotes_runtime', ?, ?)", [$table, $privilege])) {
                    throw new RuntimeException("Generic instrument result ledger {$table} grant is incomplete.");
                }
            }
            foreach (['UPDATE', 'DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
                if (DB::scalar("SELECT has_table_privilege('psikotes_runtime', ?, ?)", [$table, $privilege])) {
                    throw new RuntimeException("Generic instrument result ledger {$table} grant is excessive.");
                }
            }
        }

        $aclRows = collect(DB::select(<<<'SQL'
            SELECT class.relname,pg_get_userbyid(class.relowner) owner,
                pg_get_userbyid(acl.grantor) grantor,
                CASE WHEN acl.grantee=0 THEN 'PUBLIC' ELSE pg_get_userbyid(acl.grantee) END grantee,
                acl.privilege_type,acl.is_grantable
            FROM pg_class class
            CROSS JOIN LATERAL aclexplode(COALESCE(
                class.relacl,
                acldefault(CASE WHEN class.relkind='S' THEN 'S'::"char" ELSE 'r'::"char" END, class.relowner)
            )) acl
            WHERE class.oid IN (
                'public.generic_instrument_results'::regclass,
                'public.generic_instrument_result_sources'::regclass,
                'public.generic_instrument_results_id_seq'::regclass,
                'public.generic_instrument_result_sources_id_seq'::regclass
            ) AND acl.grantee <> class.relowner
            ORDER BY class.relname,grantee,acl.privilege_type
            SQL));
        if ($aclRows->isEmpty()
            || $aclRows->contains(static function (object $row): bool {
                $state = (array) $row;

                return $state['grantor'] !== $state['owner'];
            })) {
            throw new RuntimeException('Generic instrument result ledger ACL grantor is not its relation owner.');
        }
        $acl = $aclRows->map(static function (object $row): array {
            $state = (array) $row;

            return [$state['relname'], $state['grantor'], $state['grantee'],
                $state['privilege_type'], (bool) $state['is_grantable']];
        })->all();
        $firstAcl = (array) $aclRows->first();
        $owner = (string) $firstAcl['owner'];
        if ($acl !== [
            ['generic_instrument_result_sources', $owner, 'psikotes_runtime', 'INSERT', false],
            ['generic_instrument_result_sources', $owner, 'psikotes_runtime', 'SELECT', false],
            ['generic_instrument_result_sources_id_seq', $owner, 'psikotes_runtime', 'SELECT', false],
            ['generic_instrument_result_sources_id_seq', $owner, 'psikotes_runtime', 'USAGE', false],
            ['generic_instrument_results', $owner, 'psikotes_runtime', 'INSERT', false],
            ['generic_instrument_results', $owner, 'psikotes_runtime', 'SELECT', false],
            ['generic_instrument_results_id_seq', $owner, 'psikotes_runtime', 'SELECT', false],
            ['generic_instrument_results_id_seq', $owner, 'psikotes_runtime', 'USAGE', false],
        ]) {
            throw new RuntimeException('Generic instrument result ledger ACL topology is not exact.');
        }

        $policies = collect(DB::select(<<<'SQL'
            SELECT tablename,policyname,permissive,cmd,roles,qual,with_check
            FROM pg_policies WHERE tablename IN (
                'generic_instrument_results','generic_instrument_result_sources'
            ) ORDER BY tablename,policyname
            SQL));
        if ($policies->pluck('policyname')->all() !== [
            'generic_instrument_result_sources_service_insert',
            'generic_instrument_result_sources_service_select',
            'generic_instrument_results_service_insert',
            'generic_instrument_results_service_select',
        ]) {
            throw new RuntimeException('Generic instrument result ledger policies are not exact.');
        }
        $expectedPolicies = [
            'generic_instrument_result_sources_service_insert' => ['PERMISSIVE', 'INSERT', '{psikotes_runtime}', null, "(app_private.app_role() = 'service'::text)"],
            'generic_instrument_result_sources_service_select' => ['PERMISSIVE', 'SELECT', '{psikotes_runtime}', "(app_private.app_role() = 'service'::text)", null],
            'generic_instrument_results_service_insert' => ['PERMISSIVE', 'INSERT', '{psikotes_runtime}', null, "(app_private.app_role() = 'service'::text)"],
            'generic_instrument_results_service_select' => ['PERMISSIVE', 'SELECT', '{psikotes_runtime}', "(app_private.app_role() = 'service'::text)", null],
        ];
        foreach ($policies as $policy) {
            $state = (array) $policy;
            if (! isset($expectedPolicies[(string) $state['policyname']])
                || [$state['permissive'], $state['cmd'], $state['roles'], $state['qual'], $state['with_check']]
                    !== $expectedPolicies[(string) $state['policyname']]) {
                throw new RuntimeException('Generic instrument result ledger policy predicate is not exact.');
            }
        }

        $sequences = DB::select(<<<'SQL'
            SELECT sequence.relname,pg_get_userbyid(sequence.relowner) owner,
                pg_get_userbyid(table_row.relowner) table_owner,attribute.attname,
                pg_get_expr(default_value.adbin,default_value.adrelid) default_value,
                has_sequence_privilege('psikotes_runtime', sequence.oid, 'USAGE') runtime_usage,
                has_sequence_privilege('psikotes_runtime', sequence.oid, 'SELECT') runtime_select,
                has_sequence_privilege('psikotes_runtime', sequence.oid, 'UPDATE') runtime_update
            FROM pg_class sequence
            JOIN pg_depend dependency ON dependency.classid='pg_class'::regclass
              AND dependency.objid=sequence.oid AND dependency.deptype='a'
            JOIN pg_class table_row ON table_row.oid=dependency.refobjid
            JOIN pg_attribute attribute ON attribute.attrelid=table_row.oid
              AND attribute.attnum=dependency.refobjsubid
            JOIN pg_attrdef default_value ON default_value.adrelid=table_row.oid
              AND default_value.adnum=attribute.attnum
            WHERE sequence.relkind='S' AND sequence.relname IN (
                'generic_instrument_results_id_seq',
                'generic_instrument_result_sources_id_seq'
            ) ORDER BY sequence.relname
            SQL);
        $expectedSequenceDefaults = [
            'generic_instrument_result_sources_id_seq' => "nextval('generic_instrument_result_sources_id_seq'::regclass)",
            'generic_instrument_results_id_seq' => "nextval('generic_instrument_results_id_seq'::regclass)",
        ];
        if (count($sequences) !== 2) {
            throw new RuntimeException('Generic instrument result ledger sequence ownership is incomplete.');
        }
        foreach ($sequences as $sequence) {
            if ($sequence->owner !== $sequence->table_owner || $sequence->attname !== 'id'
                || ($expectedSequenceDefaults[$sequence->relname] ?? null) !== $sequence->default_value
                || ! $sequence->runtime_usage || ! $sequence->runtime_select || $sequence->runtime_update) {
                throw new RuntimeException('Generic instrument result ledger sequence state is not exact.');
            }
        }

        $triggers = collect(DB::select(<<<'SQL'
            SELECT trigger.tgname,trigger.tgtype,namespace.nspname,function.proname,
                function.prosecdef,function.proconfig,function.proacl,
                function.prosrc,pg_get_triggerdef(trigger.oid,false) definition,
                pg_get_userbyid(function.proowner) function_owner,
                pg_get_userbyid(class.relowner) table_owner
            FROM pg_trigger trigger
            JOIN pg_class class ON class.oid=trigger.tgrelid
            JOIN pg_proc function ON function.oid=trigger.tgfoid
            JOIN pg_namespace namespace ON namespace.oid=function.pronamespace
            WHERE NOT trigger.tgisinternal AND trigger.tgrelid IN (
                'generic_instrument_results'::regclass,
                'generic_instrument_result_sources'::regclass
            ) ORDER BY trigger.tgname
            SQL))->keyBy('tgname');
        if ($triggers->keys()->all() !== [
            'generic_instrument_result_sources_guard', 'generic_instrument_results_guard',
        ]) {
            throw new RuntimeException('Generic instrument result ledger triggers are not exact.');
        }
        foreach ($triggers as $trigger) {
            if ($trigger->nspname !== 'app_private'
                || $trigger->function_owner !== $trigger->table_owner
                || $trigger->proconfig !== '{"search_path=pg_catalog, public"}'
                || $trigger->proacl !== "{{$trigger->table_owner}=X/{$trigger->table_owner}}") {
                throw new RuntimeException('Generic instrument result ledger guard ownership is not exact.');
            }
        }
        if (! $triggers['generic_instrument_results_guard']->prosecdef
            || $triggers['generic_instrument_result_sources_guard']->prosecdef) {
            throw new RuntimeException('Generic instrument result ledger guard security is not exact.');
        }
        if ((int) $triggers['generic_instrument_result_sources_guard']->tgtype !== 27
            || (int) $triggers['generic_instrument_results_guard']->tgtype !== 31) {
            throw new RuntimeException('Generic instrument result ledger guard events are not exact.');
        }
        $parentBody = <<<'SQL'
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'generic instrument result history is append-only' USING ERRCODE = 'P0001';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM public.test_sessions session
                    WHERE session.id = NEW.session_id
                      AND session.assessment_case_id = NEW.assessment_case_id
                      AND session.participant_id = NEW.participant_id
                      AND session.public_id = NEW.session_public_id
                      AND session.test_type = NEW.instrument_code
                      AND session.attempt_no = NEW.attempt_no
                      AND session.status IN ('submitted','scored')
                      AND session.submitted_at = NEW.submitted_at
                      AND session.answers_revision = NEW.answers_revision
                      AND session.session_definition_version = NEW.session_definition_version
                      AND session.session_definition_provenance = NEW.session_definition_provenance
                      AND session.session_definition_checksum = NEW.session_definition_checksum
                      AND session.session_definition_payload::jsonb = NEW.session_definition_payload::jsonb
                ) THEN
                    RAISE EXCEPTION 'generic instrument result requires its exact submitted session source'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            SQL;
        $childBody = <<<'SQL'
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'generic instrument result source history is append-only' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END;
            SQL;
        if ($this->normalize($triggers['generic_instrument_results_guard']->prosrc)
                !== $this->normalize($parentBody)
            || $this->normalize($triggers['generic_instrument_result_sources_guard']->prosrc)
                !== $this->normalize($childBody)) {
            throw new RuntimeException('Generic instrument result ledger guard body is not exact.');
        }
    }

    private function assertSqliteState(): void
    {
        $actual = [];
        foreach (DB::select(<<<'SQL'
            SELECT type,name,sql FROM sqlite_master
            WHERE (name IN (
                'generic_instrument_results','generic_instrument_result_sources',
                'instrument_versions_result_scope_unique'
            ) OR name LIKE 'generic_instrument_result%guard%')
              AND sql IS NOT NULL ORDER BY type,name
            SQL) as $definition) {
            $state = (array) $definition;
            $actual[(string) $state['type'].':'.(string) $state['name']] = hash(
                'sha256', $this->normalize((string) $state['sql']),
            );
        }
        if ($actual !== self::SQLITE_DEFINITION_HASHES) {
            throw new RuntimeException('Generic instrument result ledger SQLite definition is not exact.');
        }
    }

    private function supportIndexExists(string $driver): bool
    {
        return (bool) DB::scalar($driver === 'pgsql'
            ? "SELECT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname='public' AND indexname='".self::INSTRUMENT_SCOPE."')"
            : "SELECT EXISTS (SELECT 1 FROM sqlite_master WHERE type='index' AND name='".self::INSTRUMENT_SCOPE."')");
    }

    private function normalize(string $sql): string
    {
        return strtolower((string) preg_replace('/\s+|["()]/', '', $sql));
    }

    private function driver(): string
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Generic instrument result ledger requires PostgreSQL or SQLite.');
        }

        return $driver;
    }

    private function transactional(Closure $operation): void
    {
        DB::transaction(fn (): mixed => $operation());
    }
};
