<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F2 (2026-09-21). Corrects the Kraepelin randomization mode to match the
 * 2026-09-21 owner decision ("docs: Kraepelin numbers are fixed, not
 * seeded"): Kraepelin's answer numbers come from the official sheet,
 * identical for every participant, not generated per session. The old
 * schema still required `randomization = 'seeded'` for Kraepelin in two
 * places, each with a different actual defect:
 *
 * - `assessment_session_definitions` (the catalog/template table): the
 *   Kraepelin branch already required `seed` to be JSON null unconditionally
 *   (a check shared by every instrument, above the per-instrument branch) --
 *   only the string label was stale ('seeded' instead of 'fixed'). This
 *   table's contract was already internally consistent with "no seed",
 *   just misnamed.
 * - `test_sessions.session_definition_*` (the per-session snapshot table,
 *   S3): here the Kraepelin branch required `seed` to be a non-blank
 *   canonical string -- a real, load-bearing contradiction with the fixed-
 *   numbers decision, not a label typo. This is the defect that actually
 *   blocked a real Kraepelin session from ever being created.
 *
 * Both triggers are corrected the same way: `randomization` must equal
 * `'fixed'` and `seed` must be JSON null for Kraepelin, exactly like the
 * existing ist/papi/rmib branch already requires. The `generator` block
 * (columns=50, seconds_per_column=15, numbers_per_column=28,
 * answer_slots_per_column=27) is untouched -- that's structural grid
 * shape/timing metadata, not a randomization seed, and stays required.
 *
 * PostgreSQL: both guards are single trigger functions
 * (`app_private.guard_assessment_session_definitions()` and
 * `app_private.guard_test_session_definition_snapshot()`), so this
 * migration uses `CREATE OR REPLACE FUNCTION` -- the existing triggers,
 * grants, and RLS policies are untouched, since they reference the
 * function by name and the function's OID doesn't change under REPLACE.
 *
 * SQLite has no `CREATE OR REPLACE TRIGGER`, so the affected triggers are
 * dropped and recreated with the corrected WHEN-clause expression.
 *
 * Safety: before installing the corrected guards, this migration counts
 * any existing Kraepelin row in either table that would violate the new
 * rule (`randomization = 'seeded'` OR `seed` is not null) and aborts with
 * a clear message if any exist, rather than silently rewriting or skipping
 * them -- this migration runs in environments this session cannot see
 * (staging, other local databases), so "no real Kraepelin session could
 * exist yet" is a reasoned expectation, not a fact this migration is
 * allowed to assume unchecked.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE assessment_session_definitions IN ACCESS EXCLUSIVE MODE');
                DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
            }

            $this->assertNoRowsViolatingTheFixedContract($driver);

            if ($driver === 'pgsql') {
                $this->installPostgresCatalogFunction($this->postgresCatalogFunctionBody(fixed: true));
                $this->installPostgresSnapshotFunction($this->postgresSnapshotFunctionBody(fixed: true));
            } else {
                $this->installSqliteCatalogTrigger($this->sqliteCatalogValidExpression(fixed: true));
                $this->installSqliteSnapshotTriggers($this->sqliteSnapshotValidExpression(fixed: true));
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE assessment_session_definitions IN ACCESS EXCLUSIVE MODE');
                DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
            }

            if ($driver === 'pgsql') {
                $this->installPostgresCatalogFunction($this->postgresCatalogFunctionBody(fixed: false));
                $this->installPostgresSnapshotFunction($this->postgresSnapshotFunctionBody(fixed: false));
            } else {
                $this->installSqliteCatalogTrigger($this->sqliteCatalogValidExpression(fixed: false));
                $this->installSqliteSnapshotTriggers($this->sqliteSnapshotValidExpression(fixed: false));
            }
        });
    }

    private function assertNoRowsViolatingTheFixedContract(string $driver): void
    {
        if ($driver === 'pgsql') {
            $catalogOffenders = (int) DB::table('assessment_session_definitions')
                ->where('instrument', 'kraepelin')
                ->whereRaw("(template_payload->>'randomization' = 'seeded' OR template_payload->'seed' IS DISTINCT FROM 'null'::jsonb)")
                ->count();
            $snapshotOffenders = (int) DB::table('test_sessions')
                ->where('test_type', 'kraepelin')
                ->whereNotNull('session_definition_payload')
                ->whereRaw("(session_definition_payload->>'randomization' = 'seeded' OR session_definition_payload->'seed' IS DISTINCT FROM 'null'::jsonb)")
                ->count();
        } else {
            $catalogOffenders = (int) DB::table('assessment_session_definitions')
                ->where('instrument', 'kraepelin')
                ->whereRaw("(json_extract(template_payload, '$.randomization') = 'seeded' OR json_type(template_payload, '$.seed') <> 'null')")
                ->count();
            $snapshotOffenders = (int) DB::table('test_sessions')
                ->where('test_type', 'kraepelin')
                ->whereNotNull('session_definition_payload')
                ->whereRaw("(json_extract(session_definition_payload, '$.randomization') = 'seeded' OR json_type(session_definition_payload, '$.seed') <> 'null')")
                ->count();
        }

        if ($catalogOffenders > 0 || $snapshotOffenders > 0) {
            throw new RuntimeException(
                'Kraepelin randomization mode migration aborted: found '
                .$catalogOffenders.' assessment_session_definitions row(s) and '
                .$snapshotOffenders.' test_sessions row(s) still using the old '
                ."'seeded' contract (randomization='seeded' or a non-null seed). "
                .'These must be reviewed and corrected by hand -- this migration '
                .'never rewrites or skips existing Kraepelin definition rows.',
            );
        }
    }

    // -- PostgreSQL: CREATE OR REPLACE FUNCTION only -- existing triggers, --
    // -- grants, and RLS policies stay attached to the function by name.  --

    private function installPostgresCatalogFunction(string $body): void
    {
        $this->execute($body);
    }

    private function installPostgresSnapshotFunction(string $body): void
    {
        $this->execute($body);
    }

    private function postgresCatalogFunctionBody(bool $fixed): string
    {
        $randomizationLiteral = $fixed ? 'fixed' : 'seeded';

        return <<<SQL
            CREATE OR REPLACE FUNCTION app_private.guard_assessment_session_definitions() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS \$guard\$
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
                identity_value text;
                identity_position integer;
                forbidden_codepoints int4multirange :=
                    '{$this->postgresForbiddenCodepointMultirange()}'::int4multirange;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'assessment session definition history cannot be deleted' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF ROW(NEW.id, NEW.instrument, NEW.version, NEW.provenance, NEW.template_checksum,
                        NEW.template_payload, NEW.activated_at)
                        IS DISTINCT FROM ROW(OLD.id, OLD.instrument, OLD.version, OLD.provenance,
                        OLD.template_checksum, OLD.template_payload, OLD.activated_at)
                        OR NOT OLD.is_active OR NEW.is_active
                        OR OLD.deactivated_at IS NOT NULL OR NEW.deactivated_at IS NULL
                        OR NEW.deactivated_at < OLD.activated_at THEN
                        RAISE EXCEPTION 'assessment session definitions only permit one-way deactivation'
                            USING ERRCODE = 'P0001';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NOT NEW.is_active OR NEW.deactivated_at IS NOT NULL THEN
                    RAISE EXCEPTION 'assessment session definitions must be inserted active'
                        USING ERRCODE = 'P0001';
                END IF;

                FOREACH identity_value IN ARRAY ARRAY[NEW.version, NEW.provenance] LOOP
                    FOR identity_position IN 1..char_length(identity_value) LOOP
                        IF ascii(substr(identity_value, identity_position, 1))
                            <@ forbidden_codepoints THEN
                            RAISE EXCEPTION 'assessment session definition identity is not canonical'
                                USING ERRCODE = '23514';
                        END IF;
                    END LOOP;
                END LOOP;

                definition := NEW.template_payload;
                IF (SELECT count(*) FROM jsonb_object_keys(definition)) <> 8
                    OR NOT definition ?& ARRAY[
                        'instrument','version','provenance','total_duration_seconds',
                        'subtests','randomization','seed','generator'
                    ]
                    OR jsonb_typeof(definition->'instrument') <> 'string'
                    OR jsonb_typeof(definition->'version') <> 'string'
                    OR jsonb_typeof(definition->'provenance') <> 'string'
                    OR jsonb_typeof(definition->'total_duration_seconds') <> 'number'
                    OR definition->>'instrument' IS DISTINCT FROM NEW.instrument
                    OR definition->>'version' IS DISTINCT FROM NEW.version
                    OR definition->>'provenance' IS DISTINCT FROM NEW.provenance
                    OR definition->>'total_duration_seconds' !~ '^[1-9][0-9]*\$'
                    OR jsonb_typeof(definition->'subtests') <> 'array'
                    OR jsonb_array_length(definition->'subtests') = 0
                    OR jsonb_typeof(definition->'randomization') <> 'string'
                    OR jsonb_typeof(definition->'seed') <> 'null' THEN
                    RAISE EXCEPTION 'assessment session definition template has an invalid shape'
                        USING ERRCODE = '23514';
                END IF;

                FOR subtest IN SELECT value FROM jsonb_array_elements(definition->'subtests') LOOP
                    IF jsonb_typeof(subtest) <> 'object'
                        OR (SELECT count(*) FROM jsonb_object_keys(subtest)) <> 3
                        OR NOT subtest ?& ARRAY['code','duration_seconds','item_count']
                        OR jsonb_typeof(subtest->'code') <> 'string'
                        OR jsonb_typeof(subtest->'duration_seconds') <> 'number'
                        OR jsonb_typeof(subtest->'item_count') <> 'number' THEN
                        RAISE EXCEPTION 'assessment session definition subtest has an invalid shape'
                            USING ERRCODE = '23514';
                    END IF;
                    code_text := subtest->>'code';
                    duration_text := subtest->>'duration_seconds';
                    item_text := subtest->>'item_count';
                    IF code_text = '' OR code_text <> btrim(code_text)
                        OR code_text ~ '[[:space:][:cntrl:]]'
                        OR duration_text !~ '^[1-9][0-9]*\$'
                        OR item_text !~ '^[1-9][0-9]*\$'
                        OR code_text = ANY(seen_codes) THEN
                        RAISE EXCEPTION 'assessment session definition subtest values are invalid'
                            USING ERRCODE = '23514';
                    END IF;
                    FOR identity_position IN 1..char_length(code_text) LOOP
                        IF ascii(substr(code_text, identity_position, 1))
                            <@ forbidden_codepoints THEN
                            RAISE EXCEPTION 'assessment session definition subtest code is not canonical'
                                USING ERRCODE = '23514';
                        END IF;
                    END LOOP;
                    seen_codes := array_append(seen_codes, code_text);
                    duration_sum := duration_sum + duration_text::numeric;
                    item_sum := item_sum + item_text::numeric;
                END LOOP;
                IF duration_sum <> (definition->>'total_duration_seconds')::numeric THEN
                    RAISE EXCEPTION 'assessment session definition durations are inconsistent'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.instrument IN ('ist','papi','rmib') THEN
                    IF definition->>'randomization' <> 'fixed'
                        OR jsonb_typeof(definition->'generator') <> 'null' THEN
                        RAISE EXCEPTION 'fixed assessment definitions cannot use seeds or generators'
                            USING ERRCODE = '23514';
                    END IF;
                ELSE
                    generator := definition->'generator';
                    IF definition->>'randomization' <> '{$randomizationLiteral}'
                        OR (definition->>'total_duration_seconds')::numeric <> 750
                        OR item_sum <> 1350
                        OR jsonb_typeof(generator) <> 'object'
                        OR (SELECT count(*) FROM jsonb_object_keys(generator)) <> 6
                        OR NOT generator ?& ARRAY[
                            'algorithm','version','columns','seconds_per_column',
                            'numbers_per_column','answer_slots_per_column'
                        ]
                        OR jsonb_typeof(generator->'algorithm') <> 'string'
                        OR jsonb_typeof(generator->'version') <> 'string'
                        OR generator->>'algorithm' = ''
                        OR generator->>'version' = ''
                        OR generator->>'algorithm' <> btrim(generator->>'algorithm')
                        OR generator->>'version' <> btrim(generator->>'version')
                        OR generator->>'algorithm' ~ '[[:space:][:cntrl:]]'
                        OR generator->>'version' ~ '[[:space:][:cntrl:]]'
                        OR jsonb_typeof(generator->'columns') <> 'number'
                        OR jsonb_typeof(generator->'seconds_per_column') <> 'number'
                        OR jsonb_typeof(generator->'numbers_per_column') <> 'number'
                        OR jsonb_typeof(generator->'answer_slots_per_column') <> 'number'
                        OR generator->>'columns' <> '50'
                        OR generator->>'seconds_per_column' <> '15'
                        OR generator->>'numbers_per_column' <> '28'
                        OR generator->>'answer_slots_per_column' <> '27' THEN
                        RAISE EXCEPTION 'kraepelin definition template is invalid'
                            USING ERRCODE = '23514';
                    END IF;
                    FOREACH identity_value IN ARRAY ARRAY[
                        generator->>'algorithm', generator->>'version'
                    ] LOOP
                        FOR identity_position IN 1..char_length(identity_value) LOOP
                            IF ascii(substr(identity_value, identity_position, 1))
                                <@ forbidden_codepoints THEN
                                RAISE EXCEPTION 'kraepelin generator identity is not canonical'
                                    USING ERRCODE = '23514';
                            END IF;
                        END LOOP;
                    END LOOP;
                END IF;
                RETURN NEW;
            END;
            \$guard\$;
            SQL;
    }

    private function postgresSnapshotFunctionBody(bool $fixed): string
    {
        $kraepelinBranch = $fixed
            ? <<<'SQL'
                ELSIF NEW.test_type = 'kraepelin' THEN
                                    generator := definition->'generator';
                                    IF jsonb_typeof(definition->'randomization') <> 'string'
                                        OR definition->>'randomization' <> 'fixed'
                                        OR jsonb_typeof(definition->'seed') <> 'null'
                                        OR jsonb_typeof(generator) <> 'object' THEN
                                        RAISE EXCEPTION 'Kraepelin session definition configuration is invalid' USING ERRCODE = '23514';
                                    END IF;
                SQL
            : <<<'SQL'
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
                SQL;

        return <<<SQL
            CREATE OR REPLACE FUNCTION app_private.guard_test_session_definition_snapshot() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS \$guard\$
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
                    OR definition->>'total_duration_seconds' !~ '^[1-9][0-9]*\$'
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
                    OR NEW.session_definition_checksum !~ '^[0-9a-f]{64}\$' THEN
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
                        OR duration_text !~ '^[1-9][0-9]*\$'
                        OR item_text !~ '^[1-9][0-9]*\$'
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
                {$kraepelinBranch}            IF (SELECT count(*) FROM jsonb_object_keys(generator)) <> 6
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
            \$guard\$;
            SQL;
    }

    // -- SQLite: no CREATE OR REPLACE TRIGGER, drop + recreate. --

    private function installSqliteCatalogTrigger(string $valid): void
    {
        $this->execute('DROP TRIGGER IF EXISTS assessment_session_definitions_insert_guard');
        $this->execute("CREATE TRIGGER assessment_session_definitions_insert_guard
            BEFORE INSERT ON assessment_session_definitions FOR EACH ROW
            WHEN COALESCE(({$valid}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'assessment session definition template is invalid'); END");
    }

    /** @param array{insert: string, update: string} $valid */
    private function installSqliteSnapshotTriggers(array $valid): void
    {
        $this->execute('DROP TRIGGER IF EXISTS test_sessions_definition_snapshot_insert_guard');
        $this->execute('DROP TRIGGER IF EXISTS test_sessions_definition_snapshot_update_guard');
        $this->execute("CREATE TRIGGER test_sessions_definition_snapshot_insert_guard
            BEFORE INSERT ON test_sessions FOR EACH ROW
            WHEN COALESCE(({$valid['insert']}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'test session definition snapshot is invalid'); END");
        $this->execute("CREATE TRIGGER test_sessions_definition_snapshot_update_guard
            BEFORE UPDATE ON test_sessions FOR EACH ROW
            WHEN NEW.session_definition_version IS NOT OLD.session_definition_version
              OR NEW.session_definition_provenance IS NOT OLD.session_definition_provenance
              OR NEW.session_definition_checksum IS NOT OLD.session_definition_checksum
              OR NEW.session_definition_payload IS NOT OLD.session_definition_payload
              OR COALESCE(({$valid['update']}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'test session definition snapshot is immutable or invalid'); END");
    }

    private function sqliteCatalogValidExpression(bool $fixed): string
    {
        $randomizationLiteral = $fixed ? 'fixed' : 'seeded';

        $valid = <<<SQL
            NEW.instrument IN ('ist','papi','rmib','kraepelin')
            AND length(NEW.version) BETWEEN 1 AND 100
            AND length(NEW.provenance) BETWEEN 1 AND 255
            AND NEW.version = trim(NEW.version)
            AND NEW.provenance = trim(NEW.provenance)
            AND instr(NEW.version, ' ') = 0
            AND instr(NEW.provenance, ' ') = 0
            AND instr(NEW.version, char(9)) = 0
            AND instr(NEW.version, char(10)) = 0
            AND instr(NEW.version, char(13)) = 0
            AND instr(NEW.provenance, char(9)) = 0
            AND instr(NEW.provenance, char(10)) = 0
            AND instr(NEW.provenance, char(13)) = 0
            AND instr(NEW.version, char(160)) = 0
            AND instr(NEW.version, char(8203)) = 0
            AND instr(NEW.version, char(8232)) = 0
            AND instr(NEW.version, char(8233)) = 0
            AND instr(NEW.version, char(65279)) = 0
            AND instr(NEW.provenance, char(160)) = 0
            AND instr(NEW.provenance, char(8203)) = 0
            AND instr(NEW.provenance, char(8232)) = 0
            AND instr(NEW.provenance, char(8233)) = 0
            AND instr(NEW.provenance, char(65279)) = 0
            AND length(NEW.template_checksum) = 64
            AND NEW.template_checksum NOT GLOB '*[^0-9a-f]*'
            AND json_valid(NEW.template_payload)
            AND json_type(NEW.template_payload) = 'object'
            AND length(CAST(NEW.template_payload AS BLOB)) <= 65536
            AND (SELECT COUNT(*) FROM json_each(NEW.template_payload)) = 8
            AND NOT EXISTS (
                SELECT 1 FROM json_each(NEW.template_payload) field
                WHERE field.key NOT IN (
                    'instrument','version','provenance','total_duration_seconds',
                    'subtests','randomization','seed','generator'
                )
            )
            AND json_extract(NEW.template_payload, '\$.instrument') = NEW.instrument
            AND json_extract(NEW.template_payload, '\$.version') = NEW.version
            AND json_extract(NEW.template_payload, '\$.provenance') = NEW.provenance
            AND json_type(NEW.template_payload, '\$.total_duration_seconds') = 'integer'
            AND json_extract(NEW.template_payload, '\$.total_duration_seconds') > 0
            AND json_type(NEW.template_payload, '\$.subtests') = 'array'
            AND json_array_length(NEW.template_payload, '\$.subtests') > 0
            AND NOT EXISTS (
                SELECT 1 FROM json_each(NEW.template_payload, '\$.subtests') subtest
                WHERE json_type(subtest.value) <> 'object'
                    OR (SELECT COUNT(*) FROM json_each(subtest.value)) <> 3
                    OR EXISTS (
                        SELECT 1 FROM json_each(subtest.value) field
                        WHERE field.key NOT IN ('code','duration_seconds','item_count')
                    )
                    OR json_type(subtest.value, '\$.code') <> 'text'
                    OR json_extract(subtest.value, '\$.code') = ''
                    OR json_extract(subtest.value, '\$.code') <> trim(json_extract(subtest.value, '\$.code'))
                    OR instr(json_extract(subtest.value, '\$.code'), ' ') > 0
                    OR instr(json_extract(subtest.value, '\$.code'), char(9)) > 0
                    OR instr(json_extract(subtest.value, '\$.code'), char(10)) > 0
                    OR instr(json_extract(subtest.value, '\$.code'), char(13)) > 0
                    OR instr(json_extract(subtest.value, '\$.code'), char(160)) > 0
                    OR instr(json_extract(subtest.value, '\$.code'), char(8203)) > 0
                    OR instr(json_extract(subtest.value, '\$.code'), char(8232)) > 0
                    OR instr(json_extract(subtest.value, '\$.code'), char(8233)) > 0
                    OR instr(json_extract(subtest.value, '\$.code'), char(65279)) > 0
                    OR json_type(subtest.value, '\$.duration_seconds') <> 'integer'
                    OR json_extract(subtest.value, '\$.duration_seconds') < 1
                    OR json_type(subtest.value, '\$.item_count') <> 'integer'
                    OR json_extract(subtest.value, '\$.item_count') < 1
            )
            AND (
                SELECT COUNT(*) = COUNT(DISTINCT json_extract(subtest.value, '\$.code'))
                FROM json_each(NEW.template_payload, '\$.subtests') subtest
            )
            AND (
                SELECT SUM(json_extract(subtest.value, '\$.duration_seconds'))
                FROM json_each(NEW.template_payload, '\$.subtests') subtest
            ) = json_extract(NEW.template_payload, '\$.total_duration_seconds')
            AND json_type(NEW.template_payload, '\$.seed') = 'null'
            AND (
                (NEW.instrument IN ('ist','papi','rmib')
                    AND json_extract(NEW.template_payload, '\$.randomization') = 'fixed'
                    AND json_type(NEW.template_payload, '\$.generator') = 'null')
                OR (NEW.instrument = 'kraepelin'
                    AND json_extract(NEW.template_payload, '\$.randomization') = '{$randomizationLiteral}'
                    AND json_extract(NEW.template_payload, '\$.total_duration_seconds') = 750
                    AND json_type(NEW.template_payload, '\$.generator') = 'object'
                    AND (SELECT COUNT(*) FROM json_each(
                        json_extract(NEW.template_payload, '\$.generator')
                    )) = 6
                    AND NOT EXISTS (
                        SELECT 1 FROM json_each(
                            json_extract(NEW.template_payload, '\$.generator')
                        ) field
                        WHERE field.key NOT IN (
                            'algorithm','version','columns','seconds_per_column',
                            'numbers_per_column','answer_slots_per_column'
                        )
                    )
                    AND json_type(NEW.template_payload, '\$.generator.algorithm') = 'text'
                    AND json_extract(NEW.template_payload, '\$.generator.algorithm') <> ''
                    AND json_extract(NEW.template_payload, '\$.generator.algorithm')
                        = trim(json_extract(NEW.template_payload, '\$.generator.algorithm'))
                    AND instr(json_extract(NEW.template_payload, '\$.generator.algorithm'), ' ') = 0
                    AND json_type(NEW.template_payload, '\$.generator.version') = 'text'
                    AND json_extract(NEW.template_payload, '\$.generator.version') <> ''
                    AND json_extract(NEW.template_payload, '\$.generator.version')
                        = trim(json_extract(NEW.template_payload, '\$.generator.version'))
                    AND instr(json_extract(NEW.template_payload, '\$.generator.version'), ' ') = 0
                    AND json_type(NEW.template_payload, '\$.generator.columns') = 'integer'
                    AND json_extract(NEW.template_payload, '\$.generator.columns') = 50
                    AND json_type(NEW.template_payload, '\$.generator.seconds_per_column') = 'integer'
                    AND json_extract(NEW.template_payload, '\$.generator.seconds_per_column') = 15
                    AND json_type(NEW.template_payload, '\$.generator.numbers_per_column') = 'integer'
                    AND json_extract(NEW.template_payload, '\$.generator.numbers_per_column') = 28
                    AND json_type(NEW.template_payload, '\$.generator.answer_slots_per_column') = 'integer'
                    AND json_extract(NEW.template_payload, '\$.generator.answer_slots_per_column') = 27
                    AND (
                        SELECT SUM(json_extract(subtest.value, '\$.item_count'))
                        FROM json_each(NEW.template_payload, '\$.subtests') subtest
                    ) = 1350)
            )
            AND NOT EXISTS (
                WITH RECURSIVE identities(value) AS (
                    SELECT CAST(NEW.version AS TEXT)
                    UNION ALL SELECT CAST(NEW.provenance AS TEXT)
                    UNION ALL
                    SELECT CASE WHEN subtest.type = 'object'
                        THEN CAST(json_extract(subtest.value, '\$.code') AS TEXT)
                        ELSE NULL END
                    FROM json_each(NEW.template_payload, '\$.subtests') subtest
                    UNION ALL
                    SELECT CAST(json_extract(
                        NEW.template_payload, '\$.generator.algorithm'
                    ) AS TEXT)
                    UNION ALL
                    SELECT CAST(json_extract(
                        NEW.template_payload, '\$.generator.version'
                    ) AS TEXT)
                ), identity_characters(value, position, codepoint) AS (
                    SELECT value, 1, unicode(substr(value, 1, 1))
                    FROM identities WHERE value IS NOT NULL AND value <> ''
                    UNION ALL
                    SELECT value, position + 1, unicode(substr(value, position + 1, 1))
                    FROM identity_characters WHERE position < length(value)
                ), payload_characters(position, backslash_run) AS (
                    SELECT 1,
                        CASE WHEN substr(CAST(NEW.template_payload AS TEXT), 1, 1) = char(92)
                            THEN 1 ELSE 0 END
                    UNION ALL
                    SELECT position + 1,
                        CASE WHEN substr(
                            CAST(NEW.template_payload AS TEXT), position + 1, 1
                        ) = char(92) THEN backslash_run + 1 ELSE 0 END
                    FROM payload_characters
                    WHERE position < length(CAST(NEW.template_payload AS TEXT))
                ), forbidden_codepoints(start_codepoint, end_codepoint) AS (
                    SELECT json_extract(range.value, '\$[0]'), json_extract(range.value, '\$[1]')
                    FROM json_each('__FORBIDDEN_CODEPOINT_RANGES_JSON__') range
                )
                SELECT 1 FROM identities
                WHERE value IS NOT NULL
                    AND instr(CAST(value AS BLOB), X'00') > 0
                UNION ALL
                SELECT 1 FROM payload_characters
                WHERE backslash_run % 2 = 1
                    AND substr(CAST(NEW.template_payload AS TEXT), position, 6)
                        = char(92) || 'u0000'
                UNION ALL
                SELECT 1 FROM identity_characters character
                INNER JOIN forbidden_codepoints forbidden
                    ON character.codepoint BETWEEN forbidden.start_codepoint
                        AND forbidden.end_codepoint
            )
            AND NEW.is_active = 1
            AND NEW.deactivated_at IS NULL
            SQL;

        return str_replace(
            '__FORBIDDEN_CODEPOINT_RANGES_JSON__',
            $this->sqliteForbiddenCodepointRangesJson(),
            $valid,
        );
    }

    /** @return array{insert: string, update: string} */
    private function sqliteSnapshotValidExpression(bool $fixed): array
    {
        $payload = "CASE WHEN json_valid(NEW.session_definition_payload) THEN NEW.session_definition_payload ELSE '{}' END";
        $version = "json_extract({$payload}, '\$.version')";
        $provenance = "json_extract({$payload}, '\$.provenance')";
        $checksum = "json_extract({$payload}, '\$.checksum')";
        $seed = "json_extract({$payload}, '\$.seed')";
        $generator = "json_extract({$payload}, '\$.generator')";
        $canonicalVersion = $this->sqliteCanonicalIdentity($version, 100);
        $canonicalProvenance = $this->sqliteCanonicalIdentity($provenance, 255);
        $canonicalAlgorithm = $this->sqliteCanonicalIdentity("json_extract({$payload}, '\$.generator.algorithm')");
        $canonicalGeneratorVersion = $this->sqliteCanonicalIdentity("json_extract({$payload}, '\$.generator.version')");

        $kraepelinClause = $fixed
            ? <<<SQL
                OR (NEW.test_type = 'kraepelin'
                                    AND NEW.duration_seconds = 750
                                    AND json_type({$payload}, '\$.randomization') = 'text'
                                    AND json_extract({$payload}, '\$.randomization') = 'fixed'
                                    AND json_type({$payload}, '\$.seed') = 'null'
                                    AND json_type({$payload}, '\$.generator') = 'object'
                SQL
            : <<<SQL
                OR (NEW.test_type = 'kraepelin'
                                    AND NEW.duration_seconds = 750
                                    AND json_type({$payload}, '\$.randomization') = 'text'
                                    AND json_extract({$payload}, '\$.randomization') = 'seeded'
                                    AND json_type({$payload}, '\$.seed') = 'text'
                                    AND {$this->sqliteCanonicalIdentity($seed)}
                                    AND json_type({$payload}, '\$.generator') = 'object'
                SQL;

        $valid = <<<SQL
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
                    AND json_type({$payload}, '\$.instrument') = 'text'
                    AND json_extract({$payload}, '\$.instrument') = NEW.test_type
                    AND json_type({$payload}, '\$.version') = 'text'
                    AND {$version} = NEW.session_definition_version
                    AND {$canonicalVersion}
                    AND json_type({$payload}, '\$.provenance') = 'text'
                    AND {$provenance} = NEW.session_definition_provenance
                    AND {$canonicalProvenance}
                    AND json_type({$payload}, '\$.checksum') = 'text'
                    AND {$checksum} = NEW.session_definition_checksum
                    AND length(NEW.session_definition_checksum) = 64
                    AND NEW.session_definition_checksum NOT GLOB '*[^0-9a-f]*'
                    AND json_type({$payload}, '\$.total_duration_seconds') = 'integer'
                    AND json_extract({$payload}, '\$.total_duration_seconds') = NEW.duration_seconds
                    AND json_type({$payload}, '\$.subtests') = 'array'
                    AND json_array_length({$payload}, '\$.subtests') > 0
                    AND NOT EXISTS (
                        SELECT 1 FROM json_each({$payload}, '\$.subtests') subtest
                        WHERE json_type(subtest.value) <> 'object'
                          OR (SELECT COUNT(*) FROM json_each(subtest.value)) <> 3
                          OR EXISTS (
                              SELECT 1 FROM json_each(subtest.value) field
                              WHERE field.key NOT IN ('code','duration_seconds','item_count')
                          )
                          OR json_type(subtest.value, '\$.code') <> 'text'
                          OR NOT ({$this->sqliteCanonicalIdentity("json_extract(subtest.value, '\$.code')")})
                          OR json_type(subtest.value, '\$.duration_seconds') <> 'integer'
                          OR json_extract(subtest.value, '\$.duration_seconds') < 1
                          OR json_type(subtest.value, '\$.item_count') <> 'integer'
                          OR json_extract(subtest.value, '\$.item_count') < 1
                    )
                    AND (
                        SELECT COUNT(*) FROM json_each({$payload}, '\$.subtests')
                    ) = (
                        SELECT COUNT(DISTINCT json_extract(subtest.value, '\$.code'))
                        FROM json_each({$payload}, '\$.subtests') subtest
                    )
                    AND (
                        SELECT SUM(json_extract(subtest.value, '\$.duration_seconds'))
                        FROM json_each({$payload}, '\$.subtests') subtest
                    ) = NEW.duration_seconds
                    AND (
                        (NEW.test_type IN ('ist','papi','rmib')
                            AND json_type({$payload}, '\$.randomization') = 'text'
                            AND json_extract({$payload}, '\$.randomization') = 'fixed'
                            AND json_type({$payload}, '\$.seed') = 'null'
                            AND json_type({$payload}, '\$.generator') = 'null')
                        {$kraepelinClause}
                            AND (SELECT COUNT(*) FROM json_each({$generator})) = 6
                            AND NOT EXISTS (
                                SELECT 1 FROM json_each({$generator}) field
                                WHERE field.key NOT IN (
                                    'algorithm','version','columns','seconds_per_column',
                                    'numbers_per_column','answer_slots_per_column'
                                )
                            )
                            AND json_type({$payload}, '\$.generator.algorithm') = 'text'
                            AND {$canonicalAlgorithm}
                            AND json_type({$payload}, '\$.generator.version') = 'text'
                            AND {$canonicalGeneratorVersion}
                            AND json_type({$payload}, '\$.generator.columns') = 'integer'
                            AND json_extract({$payload}, '\$.generator.columns') = 50
                            AND json_type({$payload}, '\$.generator.seconds_per_column') = 'integer'
                            AND json_extract({$payload}, '\$.generator.seconds_per_column') = 15
                            AND json_type({$payload}, '\$.generator.numbers_per_column') = 'integer'
                            AND json_extract({$payload}, '\$.generator.numbers_per_column') = 28
                            AND json_type({$payload}, '\$.generator.answer_slots_per_column') = 'integer'
                            AND json_extract({$payload}, '\$.generator.answer_slots_per_column') = 27
                            AND (
                                SELECT SUM(json_extract(subtest.value, '\$.item_count'))
                                FROM json_each({$payload}, '\$.subtests') subtest
                            ) = 1350)
                    )
                )
            )
            SQL;

        return ['insert' => $valid, 'update' => $valid];
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

    private function postgresForbiddenCodepointMultirange(): string
    {
        return '{'.implode(',', array_map(
            static fn (array $range): string => sprintf('[%d,%d)', $range[0], $range[1] + 1),
            $this->forbiddenCodepointRanges(),
        )).'}';
    }

    private function sqliteForbiddenCodepointRangesJson(): string
    {
        return json_encode($this->forbiddenCodepointRanges(), JSON_THROW_ON_ERROR);
    }

    /** @return list<array{int, int}> */
    private function forbiddenCodepointRanges(): array
    {
        /** @var list<array{int, int}>|null $cached */
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $ranges = [];
        $start = null;
        $last = null;
        for ($codepoint = 1; $codepoint <= 0x10FFFF; $codepoint++) {
            $forbidden = $codepoint >= 0xD800 && $codepoint <= 0xDFFF;
            if (! $forbidden) {
                $match = preg_match('/[\p{C}\p{Z}\s]/u', $this->utf8Character($codepoint));
                if ($match === false) {
                    throw new RuntimeException('Unable to compile the canonical identity predicate.');
                }
                $forbidden = $match === 1;
            }

            if ($forbidden) {
                $start ??= $codepoint;
                $last = $codepoint;
            } elseif ($start !== null && $last !== null) {
                $ranges[] = [$start, $last];
                $start = null;
                $last = null;
            }
        }
        if ($start !== null && $last !== null) {
            $ranges[] = [$start, $last];
        }

        return $cached = $ranges;
    }

    private function utf8Character(int $codepoint): string
    {
        if ($codepoint <= 0x7F) {
            return chr($codepoint);
        }
        if ($codepoint <= 0x7FF) {
            return chr(0xC0 | ($codepoint >> 6))
                .chr(0x80 | ($codepoint & 0x3F));
        }
        if ($codepoint <= 0xFFFF) {
            return chr(0xE0 | ($codepoint >> 12))
                .chr(0x80 | (($codepoint >> 6) & 0x3F))
                .chr(0x80 | ($codepoint & 0x3F));
        }

        return chr(0xF0 | ($codepoint >> 18))
            .chr(0x80 | (($codepoint >> 12) & 0x3F))
            .chr(0x80 | (($codepoint >> 6) & 0x3F))
            .chr(0x80 | ($codepoint & 0x3F));
    }

    private function execute(string $sql): void
    {
        if (DB::connection()->getPdo()->exec($sql) === false) {
            throw new RuntimeException('Unable to install the corrected Kraepelin randomization mode contract.');
        }
    }
};
