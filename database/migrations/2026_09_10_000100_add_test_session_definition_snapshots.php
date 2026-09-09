<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const POSTGRES_SESSION_GUARD_SHA256 = 'c377e31edc6347b691e854eb373c25b690519e6ec5efa3ef21193d13bb302fe9';

    private const POSTGRES_GRANT_GUARD_SHA256 = 'ccc54a9d87a513abe110b5b0496d2069fa6093a4fa54ab4c2dd970206b044f4e';

    private const COLUMNS = [
        'session_definition_version',
        'session_definition_provenance',
        'session_definition_checksum',
        'session_definition_payload',
    ];

    public function up(): void
    {
        try {
            DB::transaction(function (): void {
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') {
                    DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
                    DB::statement('LOCK TABLE test_session_grants IN ACCESS EXCLUSIVE MODE');
                }

                $present = array_values(array_filter(
                    self::COLUMNS,
                    static fn (string $column): bool => Schema::hasColumn('test_sessions', $column),
                ));
                if ($present !== []) {
                    if ($present !== self::COLUMNS) {
                        $this->abort('partial snapshot columns already exist');
                    }
                    $this->assertExactState($driver);

                    return;
                }
                if ($this->enforcementPresent($driver)) {
                    $this->abort('snapshot enforcement exists without its columns');
                }

                Schema::table('test_sessions', function (Blueprint $table) use ($driver): void {
                    $table->string('session_definition_version', 100)->nullable();
                    $table->string('session_definition_provenance', 255)->nullable();
                    $table->char('session_definition_checksum', 64)->nullable();
                    if ($driver === 'pgsql') {
                        $table->jsonb('session_definition_payload')->nullable();
                    } else {
                        $table->json('session_definition_payload')->nullable();
                    }
                });

                if ($driver === 'pgsql') {
                    $this->addPostgresEnforcement();
                } else {
                    $this->addSqliteEnforcement();
                }
                $this->assertExactState($driver);
            });
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException
                && str_starts_with($exception->getMessage(), 'Test session definition snapshot migration aborted:')) {
                throw $exception;
            }
            throw new RuntimeException(
                'Test session definition snapshot migration aborted: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $driver = DB::getDriverName();
            $present = array_values(array_filter(
                self::COLUMNS,
                static fn (string $column): bool => Schema::hasColumn('test_sessions', $column),
            ));
            if ($present === []) {
                if ($this->enforcementPresent($driver)) {
                    throw new RuntimeException('Partial test session definition snapshot schema prevents rollback.');
                }

                return;
            }
            if ($present !== self::COLUMNS) {
                throw new RuntimeException('Partial test session definition snapshot schema prevents rollback.');
            }

            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
                DB::statement('LOCK TABLE test_session_grants IN ACCESS EXCLUSIVE MODE');
                DB::statement("SELECT set_config('app.role', 'service', true)");
            }
            $populated = DB::table('test_sessions')->where(function ($query): void {
                foreach (self::COLUMNS as $column) {
                    $query->orWhereNotNull($column);
                }
            })->exists();
            if ($populated) {
                throw new RuntimeException('Test session definition snapshot history prevents rollback.');
            }

            $this->removeEnforcement($driver);
            Schema::table('test_sessions', function (Blueprint $table): void {
                $table->dropColumn(self::COLUMNS);
            });
        });
    }

    private function addPostgresEnforcement(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE test_sessions ADD CONSTRAINT test_sessions_definition_snapshot_completeness_check CHECK (
                (session_definition_version IS NULL
                    AND session_definition_provenance IS NULL
                    AND session_definition_checksum IS NULL
                    AND session_definition_payload IS NULL)
                OR (session_definition_version IS NOT NULL
                    AND session_definition_provenance IS NOT NULL
                    AND session_definition_checksum IS NOT NULL
                    AND session_definition_payload IS NOT NULL)
            )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app_private.guard_test_session_definition_snapshot() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $guard$
            DECLARE
                definition jsonb;
                subtest jsonb;
                generator jsonb;
                duration_sum numeric := 0;
                item_sum numeric := 0;
                duration_text text;
                item_text text;
                code_text text;
                seen_codes text[] := ARRAY[]::text[];
            BEGIN
                IF TG_OP = 'UPDATE' AND (
                    NEW.session_definition_version IS DISTINCT FROM OLD.session_definition_version
                    OR NEW.session_definition_provenance IS DISTINCT FROM OLD.session_definition_provenance
                    OR NEW.session_definition_checksum IS DISTINCT FROM OLD.session_definition_checksum
                    OR NEW.session_definition_payload IS DISTINCT FROM OLD.session_definition_payload
                ) THEN
                    RAISE EXCEPTION 'test session definition snapshot is immutable' USING ERRCODE = 'P0001';
                END IF;

                IF NEW.session_definition_version IS NULL
                    AND NEW.session_definition_provenance IS NULL
                    AND NEW.session_definition_checksum IS NULL
                    AND NEW.session_definition_payload IS NULL THEN
                    RETURN NEW;
                END IF;
                IF NEW.session_definition_version IS NULL
                    OR NEW.session_definition_provenance IS NULL
                    OR NEW.session_definition_checksum IS NULL
                    OR NEW.session_definition_payload IS NULL THEN
                    RAISE EXCEPTION 'test session definition snapshot must be complete' USING ERRCODE = '23514';
                END IF;

                definition := NEW.session_definition_payload;
                IF jsonb_typeof(definition) <> 'object' THEN
                    RAISE EXCEPTION 'test session definition payload must be an object' USING ERRCODE = '23514';
                END IF;
                IF (SELECT count(*) FROM jsonb_object_keys(definition)) <> 9
                    OR NOT definition ?& ARRAY[
                        'instrument','version','provenance','checksum','total_duration_seconds',
                        'subtests','randomization','seed','generator'
                    ] THEN
                    RAISE EXCEPTION 'test session definition payload has an invalid shape' USING ERRCODE = '23514';
                END IF;
                IF jsonb_typeof(definition->'instrument') <> 'string'
                    OR jsonb_typeof(definition->'version') <> 'string'
                    OR jsonb_typeof(definition->'provenance') <> 'string'
                    OR jsonb_typeof(definition->'checksum') <> 'string'
                    OR jsonb_typeof(definition->'total_duration_seconds') <> 'number' THEN
                    RAISE EXCEPTION 'test session definition identity fields have invalid types' USING ERRCODE = '23514';
                END IF;
                IF definition->>'instrument' IS DISTINCT FROM NEW.test_type
                    OR definition->>'version' IS DISTINCT FROM NEW.session_definition_version
                    OR definition->>'provenance' IS DISTINCT FROM NEW.session_definition_provenance
                    OR definition->>'checksum' IS DISTINCT FROM NEW.session_definition_checksum
                    OR definition->>'total_duration_seconds' !~ '^[1-9][0-9]*$'
                    OR (definition->>'total_duration_seconds')::numeric <> NEW.duration_seconds
                    OR length(NEW.session_definition_version) > 100
                    OR length(NEW.session_definition_provenance) > 255
                    OR NEW.session_definition_version = ''
                    OR NEW.session_definition_provenance = ''
                    OR NEW.session_definition_version <> btrim(NEW.session_definition_version)
                    OR NEW.session_definition_provenance <> btrim(NEW.session_definition_provenance)
                    OR NEW.session_definition_version ~ '[[:space:][:cntrl:]]'
                    OR NEW.session_definition_provenance ~ '[[:space:][:cntrl:]]'
                    OR position(chr(160) in NEW.session_definition_version) > 0
                    OR position(chr(8203) in NEW.session_definition_version) > 0
                    OR position(chr(8232) in NEW.session_definition_version) > 0
                    OR position(chr(8233) in NEW.session_definition_version) > 0
                    OR position(chr(65279) in NEW.session_definition_version) > 0
                    OR position(chr(160) in NEW.session_definition_provenance) > 0
                    OR position(chr(8203) in NEW.session_definition_provenance) > 0
                    OR position(chr(8232) in NEW.session_definition_provenance) > 0
                    OR position(chr(8233) in NEW.session_definition_provenance) > 0
                    OR position(chr(65279) in NEW.session_definition_provenance) > 0
                    OR NEW.session_definition_checksum !~ '^[0-9a-f]{64}$' THEN
                    RAISE EXCEPTION 'test session definition payload disagrees with its session identity' USING ERRCODE = '23514';
                END IF;
                IF jsonb_typeof(definition->'subtests') <> 'array' THEN
                    RAISE EXCEPTION 'test session definition subtests must be an array' USING ERRCODE = '23514';
                END IF;
                IF jsonb_array_length(definition->'subtests') = 0 THEN
                    RAISE EXCEPTION 'test session definition subtests must be a non-empty array' USING ERRCODE = '23514';
                END IF;

                FOR subtest IN SELECT value FROM jsonb_array_elements(definition->'subtests') LOOP
                    IF jsonb_typeof(subtest) <> 'object' THEN
                        RAISE EXCEPTION 'test session definition subtest must be an object' USING ERRCODE = '23514';
                    END IF;
                    IF (SELECT count(*) FROM jsonb_object_keys(subtest)) <> 3
                        OR NOT subtest ?& ARRAY['code','duration_seconds','item_count']
                        OR jsonb_typeof(subtest->'code') <> 'string'
                        OR jsonb_typeof(subtest->'duration_seconds') <> 'number'
                        OR jsonb_typeof(subtest->'item_count') <> 'number' THEN
                        RAISE EXCEPTION 'test session definition subtest has an invalid shape' USING ERRCODE = '23514';
                    END IF;
                    code_text := subtest->>'code';
                    duration_text := subtest->>'duration_seconds';
                    item_text := subtest->>'item_count';
                    IF code_text = '' OR code_text <> btrim(code_text)
                        OR code_text ~ '[[:space:][:cntrl:]]'
                        OR position(chr(160) in code_text) > 0
                        OR position(chr(8203) in code_text) > 0
                        OR position(chr(8232) in code_text) > 0
                        OR position(chr(8233) in code_text) > 0
                        OR position(chr(65279) in code_text) > 0
                        OR duration_text !~ '^[1-9][0-9]*$'
                        OR item_text !~ '^[1-9][0-9]*$'
                        OR code_text = ANY(seen_codes) THEN
                        RAISE EXCEPTION 'test session definition subtest values are invalid' USING ERRCODE = '23514';
                    END IF;
                    seen_codes := array_append(seen_codes, code_text);
                    duration_sum := duration_sum + duration_text::numeric;
                    item_sum := item_sum + item_text::numeric;
                END LOOP;
                IF duration_sum <> NEW.duration_seconds THEN
                    RAISE EXCEPTION 'test session definition subtest durations disagree with the session' USING ERRCODE = '23514';
                END IF;

                IF NEW.test_type IN ('ist','papi','rmib') THEN
                    IF jsonb_typeof(definition->'randomization') <> 'string'
                        OR definition->>'randomization' <> 'fixed'
                        OR jsonb_typeof(definition->'seed') <> 'null'
                        OR jsonb_typeof(definition->'generator') <> 'null' THEN
                        RAISE EXCEPTION 'fixed session definition configuration is invalid' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.test_type = 'kraepelin' THEN
                    generator := definition->'generator';
                    IF jsonb_typeof(definition->'randomization') <> 'string'
                        OR definition->>'randomization' <> 'seeded'
                        OR jsonb_typeof(definition->'seed') <> 'string'
                        OR definition->>'seed' = ''
                        OR definition->>'seed' <> btrim(definition->>'seed')
                        OR definition->>'seed' ~ '[[:space:][:cntrl:]]'
                        OR position(chr(160) in definition->>'seed') > 0
                        OR position(chr(8203) in definition->>'seed') > 0
                        OR position(chr(8232) in definition->>'seed') > 0
                        OR position(chr(8233) in definition->>'seed') > 0
                        OR position(chr(65279) in definition->>'seed') > 0
                        OR jsonb_typeof(generator) <> 'object' THEN
                        RAISE EXCEPTION 'Kraepelin session definition configuration is invalid' USING ERRCODE = '23514';
                    END IF;
                    IF (SELECT count(*) FROM jsonb_object_keys(generator)) <> 6
                        OR NOT generator ?& ARRAY[
                            'algorithm','version','columns','seconds_per_column',
                            'numbers_per_column','answer_slots_per_column'
                        ]
                        OR jsonb_typeof(generator->'algorithm') <> 'string'
                        OR generator->>'algorithm' = ''
                        OR generator->>'algorithm' <> btrim(generator->>'algorithm')
                        OR generator->>'algorithm' ~ '[[:space:][:cntrl:]]'
                        OR position(chr(160) in generator->>'algorithm') > 0
                        OR position(chr(8203) in generator->>'algorithm') > 0
                        OR position(chr(8232) in generator->>'algorithm') > 0
                        OR position(chr(8233) in generator->>'algorithm') > 0
                        OR position(chr(65279) in generator->>'algorithm') > 0
                        OR jsonb_typeof(generator->'version') <> 'string'
                        OR generator->>'version' = ''
                        OR generator->>'version' <> btrim(generator->>'version')
                        OR generator->>'version' ~ '[[:space:][:cntrl:]]'
                        OR position(chr(160) in generator->>'version') > 0
                        OR position(chr(8203) in generator->>'version') > 0
                        OR position(chr(8232) in generator->>'version') > 0
                        OR position(chr(8233) in generator->>'version') > 0
                        OR position(chr(65279) in generator->>'version') > 0
                        OR jsonb_typeof(generator->'columns') <> 'number'
                        OR jsonb_typeof(generator->'seconds_per_column') <> 'number'
                        OR jsonb_typeof(generator->'numbers_per_column') <> 'number'
                        OR jsonb_typeof(generator->'answer_slots_per_column') <> 'number'
                        OR generator->>'columns' <> '50'
                        OR generator->>'seconds_per_column' <> '15'
                        OR generator->>'numbers_per_column' <> '28'
                        OR generator->>'answer_slots_per_column' <> '27'
                        OR NEW.duration_seconds <> 750
                        OR item_sum <> 1350 THEN
                        RAISE EXCEPTION 'Kraepelin session definition configuration is invalid' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    RAISE EXCEPTION 'unsupported session definition instrument' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $guard$;
            REVOKE ALL ON FUNCTION app_private.guard_test_session_definition_snapshot() FROM PUBLIC;
            CREATE TRIGGER test_sessions_definition_snapshot_guard
            BEFORE INSERT OR UPDATE ON test_sessions
            FOR EACH ROW EXECUTE FUNCTION app_private.guard_test_session_definition_snapshot();

            CREATE FUNCTION app_private.guard_test_session_grant_definition_snapshot() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM public.test_sessions session
                    WHERE session.id = NEW.test_session_id
                      AND session.session_definition_version IS NOT NULL
                      AND session.session_definition_provenance IS NOT NULL
                      AND session.session_definition_checksum IS NOT NULL
                      AND session.session_definition_payload IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'test session grant requires a complete definition snapshot' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $guard$;
            REVOKE ALL ON FUNCTION app_private.guard_test_session_grant_definition_snapshot() FROM PUBLIC;
            CREATE TRIGGER test_session_grants_definition_snapshot_guard
            BEFORE INSERT ON test_session_grants
            FOR EACH ROW EXECUTE FUNCTION app_private.guard_test_session_grant_definition_snapshot();
            SQL);
    }

    private function addSqliteEnforcement(): void
    {
        $valid = $this->sqliteValidSnapshotExpression();
        $this->executeSqliteStatement("CREATE TRIGGER test_sessions_definition_snapshot_insert_guard
            BEFORE INSERT ON test_sessions FOR EACH ROW
            WHEN COALESCE(({$valid}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'test session definition snapshot is invalid'); END");
        $this->executeSqliteStatement("CREATE TRIGGER test_sessions_definition_snapshot_update_guard
            BEFORE UPDATE ON test_sessions FOR EACH ROW
            WHEN NEW.session_definition_version IS NOT OLD.session_definition_version
              OR NEW.session_definition_provenance IS NOT OLD.session_definition_provenance
              OR NEW.session_definition_checksum IS NOT OLD.session_definition_checksum
              OR NEW.session_definition_payload IS NOT OLD.session_definition_payload
              OR COALESCE(({$valid}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'test session definition snapshot is immutable or invalid'); END");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_session_grants_definition_snapshot_guard
            BEFORE INSERT ON test_session_grants FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM test_sessions session
                WHERE session.id = NEW.test_session_id
                  AND session.session_definition_version IS NOT NULL
                  AND session.session_definition_provenance IS NOT NULL
                  AND session.session_definition_checksum IS NOT NULL
                  AND session.session_definition_payload IS NOT NULL
            )
            BEGIN SELECT RAISE(ABORT, 'test session grant requires a complete definition snapshot'); END
            SQL);
    }

    private function sqliteValidSnapshotExpression(): string
    {
        $payload = "CASE WHEN json_valid(NEW.session_definition_payload) THEN NEW.session_definition_payload ELSE '{}' END";
        $version = "json_extract({$payload}, '$.version')";
        $provenance = "json_extract({$payload}, '$.provenance')";
        $checksum = "json_extract({$payload}, '$.checksum')";
        $seed = "json_extract({$payload}, '$.seed')";
        $generator = "json_extract({$payload}, '$.generator')";
        $canonicalVersion = $this->sqliteCanonicalIdentity($version, 100);
        $canonicalProvenance = $this->sqliteCanonicalIdentity($provenance, 255);
        $canonicalSeed = $this->sqliteCanonicalIdentity($seed);
        $canonicalAlgorithm = $this->sqliteCanonicalIdentity("json_extract({$payload}, '$.generator.algorithm')");
        $canonicalGeneratorVersion = $this->sqliteCanonicalIdentity("json_extract({$payload}, '$.generator.version')");

        return <<<SQL
            (
                (NEW.session_definition_version IS NULL
                    AND NEW.session_definition_provenance IS NULL
                    AND NEW.session_definition_checksum IS NULL
                    AND NEW.session_definition_payload IS NULL)
                OR (
                    NEW.session_definition_version IS NOT NULL
                    AND NEW.session_definition_provenance IS NOT NULL
                    AND NEW.session_definition_checksum IS NOT NULL
                    AND NEW.session_definition_payload IS NOT NULL
                    AND json_valid(NEW.session_definition_payload)
                    AND json_type({$payload}) = 'object'
                    AND (SELECT COUNT(*) FROM json_each({$payload})) = 9
                    AND NOT EXISTS (
                        SELECT 1 FROM json_each({$payload}) field
                        WHERE field.key NOT IN (
                            'instrument','version','provenance','checksum','total_duration_seconds',
                            'subtests','randomization','seed','generator'
                        )
                    )
                    AND json_type({$payload}, '$.instrument') = 'text'
                    AND json_extract({$payload}, '$.instrument') = NEW.test_type
                    AND json_type({$payload}, '$.version') = 'text'
                    AND {$version} = NEW.session_definition_version
                    AND {$canonicalVersion}
                    AND json_type({$payload}, '$.provenance') = 'text'
                    AND {$provenance} = NEW.session_definition_provenance
                    AND {$canonicalProvenance}
                    AND json_type({$payload}, '$.checksum') = 'text'
                    AND {$checksum} = NEW.session_definition_checksum
                    AND length(NEW.session_definition_checksum) = 64
                    AND NEW.session_definition_checksum NOT GLOB '*[^0-9a-f]*'
                    AND json_type({$payload}, '$.total_duration_seconds') = 'integer'
                    AND json_extract({$payload}, '$.total_duration_seconds') = NEW.duration_seconds
                    AND json_type({$payload}, '$.subtests') = 'array'
                    AND json_array_length({$payload}, '$.subtests') > 0
                    AND NOT EXISTS (
                        SELECT 1 FROM json_each({$payload}, '$.subtests') subtest
                        WHERE json_type(subtest.value) <> 'object'
                          OR (SELECT COUNT(*) FROM json_each(subtest.value)) <> 3
                          OR EXISTS (
                              SELECT 1 FROM json_each(subtest.value) field
                              WHERE field.key NOT IN ('code','duration_seconds','item_count')
                          )
                          OR json_type(subtest.value, '$.code') <> 'text'
                          OR NOT ({$this->sqliteCanonicalIdentity("json_extract(subtest.value, '$.code')")})
                          OR json_type(subtest.value, '$.duration_seconds') <> 'integer'
                          OR json_extract(subtest.value, '$.duration_seconds') < 1
                          OR json_type(subtest.value, '$.item_count') <> 'integer'
                          OR json_extract(subtest.value, '$.item_count') < 1
                    )
                    AND (
                        SELECT COUNT(*) FROM json_each({$payload}, '$.subtests')
                    ) = (
                        SELECT COUNT(DISTINCT json_extract(subtest.value, '$.code'))
                        FROM json_each({$payload}, '$.subtests') subtest
                    )
                    AND (
                        SELECT SUM(json_extract(subtest.value, '$.duration_seconds'))
                        FROM json_each({$payload}, '$.subtests') subtest
                    ) = NEW.duration_seconds
                    AND (
                        (NEW.test_type IN ('ist','papi','rmib')
                            AND json_type({$payload}, '$.randomization') = 'text'
                            AND json_extract({$payload}, '$.randomization') = 'fixed'
                            AND json_type({$payload}, '$.seed') = 'null'
                            AND json_type({$payload}, '$.generator') = 'null')
                        OR (NEW.test_type = 'kraepelin'
                            AND NEW.duration_seconds = 750
                            AND json_type({$payload}, '$.randomization') = 'text'
                            AND json_extract({$payload}, '$.randomization') = 'seeded'
                            AND json_type({$payload}, '$.seed') = 'text'
                            AND {$canonicalSeed}
                            AND json_type({$payload}, '$.generator') = 'object'
                            AND (SELECT COUNT(*) FROM json_each({$generator})) = 6
                            AND NOT EXISTS (
                                SELECT 1 FROM json_each({$generator}) field
                                WHERE field.key NOT IN (
                                    'algorithm','version','columns','seconds_per_column',
                                    'numbers_per_column','answer_slots_per_column'
                                )
                            )
                            AND json_type({$payload}, '$.generator.algorithm') = 'text'
                            AND {$canonicalAlgorithm}
                            AND json_type({$payload}, '$.generator.version') = 'text'
                            AND {$canonicalGeneratorVersion}
                            AND json_type({$payload}, '$.generator.columns') = 'integer'
                            AND json_extract({$payload}, '$.generator.columns') = 50
                            AND json_type({$payload}, '$.generator.seconds_per_column') = 'integer'
                            AND json_extract({$payload}, '$.generator.seconds_per_column') = 15
                            AND json_type({$payload}, '$.generator.numbers_per_column') = 'integer'
                            AND json_extract({$payload}, '$.generator.numbers_per_column') = 28
                            AND json_type({$payload}, '$.generator.answer_slots_per_column') = 'integer'
                            AND json_extract({$payload}, '$.generator.answer_slots_per_column') = 27
                            AND (
                                SELECT SUM(json_extract(subtest.value, '$.item_count'))
                                FROM json_each({$payload}, '$.subtests') subtest
                            ) = 1350)
                    )
                )
            )
            SQL;
    }

    private function sqliteCanonicalIdentity(string $expression, ?int $maxLength = null): string
    {
        $length = $maxLength === null
            ? "length({$expression}) > 0"
            : "length({$expression}) BETWEEN 1 AND {$maxLength}";

        return "{$length} AND {$expression} = trim({$expression})"
            ." AND instr({$expression}, ' ') = 0"
            ." AND instr({$expression}, char(9)) = 0"
            ." AND instr({$expression}, char(10)) = 0"
            ." AND instr({$expression}, char(13)) = 0"
            ." AND instr({$expression}, char(160)) = 0"
            ." AND instr({$expression}, char(8203)) = 0"
            ." AND instr({$expression}, char(8232)) = 0"
            ." AND instr({$expression}, char(8233)) = 0"
            ." AND instr({$expression}, char(65279)) = 0";
    }

    private function assertExactState(string $driver): void
    {
        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('test_sessions', $column)) {
                $this->abort('snapshot schema is incomplete');
            }
        }
        $checksumIndexes = DB::select($driver === 'pgsql'
            ? "SELECT indexname FROM pg_indexes WHERE schemaname='public' AND tablename='test_sessions' AND indexdef ILIKE '%session_definition_checksum%'"
            : "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='test_sessions' AND lower(sql) LIKE '%session_definition_checksum%'");
        if ($checksumIndexes !== []) {
            $this->abort('definition checksum must not be indexed');
        }

        if ($driver === 'pgsql') {
            $columns = DB::select(<<<'SQL'
                SELECT attname, format_type(atttypid, atttypmod) type, attnotnull
                FROM pg_attribute
                WHERE attrelid='test_sessions'::regclass
                  AND attname LIKE 'session_definition_%' AND attnum > 0 AND NOT attisdropped
                ORDER BY attname
                SQL);
            $actual = [];
            foreach ($columns as $column) {
                $data = (array) $column;
                $actual[(string) $data['attname']] = [
                    (string) $data['type'],
                    (bool) $data['attnotnull'],
                ];
            }
            $expected = [
                'session_definition_checksum' => ['character(64)', false],
                'session_definition_payload' => ['jsonb', false],
                'session_definition_provenance' => ['character varying(255)', false],
                'session_definition_version' => ['character varying(100)', false],
            ];
            if ($actual !== $expected || ! $this->postgresEnforcementExact()) {
                $this->abort('partial PostgreSQL snapshot enforcement already exists');
            }

            return;
        }

        $columns = collect(DB::select("PRAGMA table_info('test_sessions')"))->keyBy('name');
        foreach (self::COLUMNS as $column) {
            if ((int) $columns[$column]->notnull !== 0) {
                $this->abort('partial SQLite snapshot enforcement already exists');
            }
        }
        if (! $this->sqliteEnforcementExact()) {
            $this->abort('partial SQLite snapshot enforcement already exists');
        }
    }

    private function postgresEnforcementExact(): bool
    {
        $constraints = DB::select(<<<'SQL'
            SELECT contype, convalidated, pg_get_constraintdef(oid, false) definition
            FROM pg_constraint
            WHERE conrelid='test_sessions'::regclass
              AND conname LIKE '%definition_snapshot%'
            ORDER BY conname
            SQL);
        $constraint = count($constraints) === 1 ? $constraints[0] : null;
        $expectedConstraint = <<<'SQL'
            CHECK (
                (session_definition_version IS NULL
                    AND session_definition_provenance IS NULL
                    AND session_definition_checksum IS NULL
                    AND session_definition_payload IS NULL)
                OR (session_definition_version IS NOT NULL
                    AND session_definition_provenance IS NOT NULL
                    AND session_definition_checksum IS NOT NULL
                    AND session_definition_payload IS NOT NULL)
            )
            SQL;
        if ($constraint === null || $constraint->contype !== 'c' || ! $constraint->convalidated
            || $this->normalize((string) $constraint->definition) !== $this->normalize($expectedConstraint)) {
            return false;
        }

        $triggers = collect(DB::select(<<<'SQL'
            SELECT trigger.tgname, trigger.tgenabled, pg_get_triggerdef(trigger.oid, false) definition,
                namespace.nspname, function.proname
            FROM pg_trigger trigger
            JOIN pg_proc function ON function.oid=trigger.tgfoid
            JOIN pg_namespace namespace ON namespace.oid=function.pronamespace
            WHERE NOT trigger.tgisinternal
              AND trigger.tgrelid IN ('test_sessions'::regclass, 'test_session_grants'::regclass)
              AND (trigger.tgname LIKE '%definition_snapshot%'
                OR (namespace.nspname='app_private' AND function.proname IN (
                    'guard_test_session_definition_snapshot',
                    'guard_test_session_grant_definition_snapshot'
                )))
            ORDER BY trigger.tgname
            SQL))->keyBy('tgname');
        if ($triggers->count() !== 2
            || ! isset(
                $triggers['test_sessions_definition_snapshot_guard'],
                $triggers['test_session_grants_definition_snapshot_guard'],
            )) {
            return false;
        }
        $expectedTriggers = [
            'test_sessions_definition_snapshot_guard' => [
                'guard_test_session_definition_snapshot',
                'CREATE TRIGGER test_sessions_definition_snapshot_guard BEFORE INSERT OR UPDATE ON public.test_sessions FOR EACH ROW EXECUTE FUNCTION app_private.guard_test_session_definition_snapshot()',
            ],
            'test_session_grants_definition_snapshot_guard' => [
                'guard_test_session_grant_definition_snapshot',
                'CREATE TRIGGER test_session_grants_definition_snapshot_guard BEFORE INSERT ON public.test_session_grants FOR EACH ROW EXECUTE FUNCTION app_private.guard_test_session_grant_definition_snapshot()',
            ],
        ];
        foreach ($expectedTriggers as $name => [$function, $definition]) {
            $trigger = $triggers[$name];
            if ($trigger->tgenabled !== 'O' || $trigger->nspname !== 'app_private'
                || $trigger->proname !== $function
                || $this->normalize((string) $trigger->definition) !== $this->normalize($definition)) {
                return false;
            }
        }

        $functions = collect(DB::select(<<<'SQL'
            SELECT proc.proname, proc.prosecdef, proc.proconfig,
              pg_get_userbyid(proc.proowner) owner, proc.proacl, proc.prosrc, language.lanname
            FROM pg_proc proc JOIN pg_namespace namespace ON namespace.oid=proc.pronamespace
            JOIN pg_language language ON language.oid=proc.prolang
            WHERE namespace.nspname='app_private'
              AND proc.proname IN (
                'guard_test_session_definition_snapshot',
                'guard_test_session_grant_definition_snapshot'
              ) ORDER BY proc.proname
            SQL))->keyBy('proname');
        if ($functions->count() !== 2) {
            return false;
        }
        $tableOwner = DB::scalar("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid='test_sessions'::regclass");
        foreach ($functions as $function) {
            if ($function->owner !== $tableOwner
                || $function->lanname !== 'plpgsql'
                || $function->proconfig !== '{"search_path=pg_catalog, public"}'
                || $function->proacl !== "{{$tableOwner}=X/{$tableOwner}}") {
                return false;
            }
        }

        return ! (bool) $functions['guard_test_session_definition_snapshot']->prosecdef
            && (bool) $functions['guard_test_session_grant_definition_snapshot']->prosecdef
            && hash('sha256', $this->normalize((string) $functions['guard_test_session_definition_snapshot']->prosrc)) === self::POSTGRES_SESSION_GUARD_SHA256
            && hash('sha256', $this->normalize((string) $functions['guard_test_session_grant_definition_snapshot']->prosrc)) === self::POSTGRES_GRANT_GUARD_SHA256;
    }

    private function sqliteEnforcementExact(): bool
    {
        $actual = collect(DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master WHERE type='trigger'
              AND name IN (
                'test_sessions_definition_snapshot_insert_guard',
                'test_sessions_definition_snapshot_update_guard',
                'test_session_grants_definition_snapshot_guard'
              ) ORDER BY name
            SQL))->pluck('sql', 'name');
        if ($actual->count() !== 3) {
            return false;
        }
        $valid = $this->sqliteValidSnapshotExpression();
        $expected = [
            'test_sessions_definition_snapshot_insert_guard' => "CREATE TRIGGER test_sessions_definition_snapshot_insert_guard BEFORE INSERT ON test_sessions FOR EACH ROW WHEN COALESCE(({$valid}), 0) = 0 BEGIN SELECT RAISE(ABORT, 'test session definition snapshot is invalid'); END",
            'test_sessions_definition_snapshot_update_guard' => "CREATE TRIGGER test_sessions_definition_snapshot_update_guard BEFORE UPDATE ON test_sessions FOR EACH ROW WHEN NEW.session_definition_version IS NOT OLD.session_definition_version OR NEW.session_definition_provenance IS NOT OLD.session_definition_provenance OR NEW.session_definition_checksum IS NOT OLD.session_definition_checksum OR NEW.session_definition_payload IS NOT OLD.session_definition_payload OR COALESCE(({$valid}), 0) = 0 BEGIN SELECT RAISE(ABORT, 'test session definition snapshot is immutable or invalid'); END",
            'test_session_grants_definition_snapshot_guard' => "CREATE TRIGGER test_session_grants_definition_snapshot_guard BEFORE INSERT ON test_session_grants FOR EACH ROW WHEN NOT EXISTS (SELECT 1 FROM test_sessions session WHERE session.id = NEW.test_session_id AND session.session_definition_version IS NOT NULL AND session.session_definition_provenance IS NOT NULL AND session.session_definition_checksum IS NOT NULL AND session.session_definition_payload IS NOT NULL) BEGIN SELECT RAISE(ABORT, 'test session grant requires a complete definition snapshot'); END",
        ];
        foreach ($expected as $name => $sql) {
            if (! isset($actual[$name]) || $this->normalize((string) $actual[$name]) !== $this->normalize($sql)) {
                return false;
            }
        }

        return true;
    }

    private function enforcementPresent(string $driver): bool
    {
        if ($driver === 'pgsql') {
            return (bool) DB::scalar(<<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname='test_sessions_definition_snapshot_completeness_check'
                    UNION ALL
                    SELECT 1 FROM pg_trigger WHERE tgname IN (
                        'test_sessions_definition_snapshot_guard',
                        'test_session_grants_definition_snapshot_guard'
                    )
                    UNION ALL
                    SELECT 1 FROM pg_proc proc JOIN pg_namespace namespace ON namespace.oid=proc.pronamespace
                    WHERE namespace.nspname='app_private' AND proc.proname IN (
                        'guard_test_session_definition_snapshot',
                        'guard_test_session_grant_definition_snapshot'
                    )
                )
                SQL);
        }

        return (bool) DB::scalar(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM sqlite_master WHERE name IN (
                    'test_sessions_definition_snapshot_insert_guard',
                    'test_sessions_definition_snapshot_update_guard',
                    'test_session_grants_definition_snapshot_guard'
                )
            )
            SQL);
    }

    private function removeEnforcement(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS test_session_grants_definition_snapshot_guard ON test_session_grants');
            DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_definition_snapshot_guard ON test_sessions');
            DB::statement('ALTER TABLE test_sessions DROP CONSTRAINT IF EXISTS test_sessions_definition_snapshot_completeness_check');
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_test_session_grant_definition_snapshot()');
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_test_session_definition_snapshot()');

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS test_session_grants_definition_snapshot_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_definition_snapshot_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_definition_snapshot_insert_guard');
    }

    private function normalize(string $sql): string
    {
        return strtolower((string) preg_replace('/\s+|["()]/', '', $sql));
    }

    private function executeSqliteStatement(string $sql): void
    {
        if (DB::connection()->getPdo()->exec($sql) === false) {
            $this->abort('unable to create SQLite snapshot enforcement');
        }
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('Test session definition snapshot migration aborted: '.$reason.'.');
    }
};
