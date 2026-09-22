<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F2 timed-segments stage 3b (2026-09-22). Extends
 * app_private.guard_test_session_definition_snapshot() (Postgres) and its
 * SQLite trigger mirror (installed by 2026_09_10_000100_add_test_session_
 * definition_snapshots.php, most recently replaced whole by
 * 2026_09_21_000100_fix_kraepelin_randomization_mode.php) to accept the
 * three optional subtest fields SessionDefinition's PHP layer already
 * accepts (stage 2): reading_cap_seconds, allow_early_finish, and a nested
 * segments list. Before this migration, that guard independently enforced
 * "exactly 3 keys: code, duration_seconds, item_count" per subtest at the
 * DATABASE level -- a real, separate guard from SessionDefinition's own PHP
 * validation (deliberately: this payload is schedule- and checksum-bearing,
 * so the project's own established pattern is two independent layers, not
 * one). Any subtest carrying the new fields would be rejected here
 * regardless of what the PHP layer already accepts.
 *
 * Lead's confirmed semantics (2026-09-22) before this was written: the three
 * optional fields validate INDEPENDENTLY of each other -- a subtest may
 * carry any one, any combination, or none, exactly matching stage 2's PHP
 * design (each defaults to 0/false/absent when its own key is absent, never
 * defaulted into anything checksummed).
 *
 * What changes, precisely:
 * 1. A subtest may now have 3-6 keys: the original 3, required, plus
 *    reading_cap_seconds (integer >= 0), allow_early_finish (boolean), and
 *    segments (non-empty array) -- each validated for shape/type only when
 *    actually present; no unknown keys beyond this set.
 * 2. A `segments` entry must have exactly 4 keys (code, duration_seconds,
 *    reading_cap_seconds, allow_early_finish) with the same canonical-
 *    identity rules as a subtest's own code, and a subtest's `segments`
 *    duration_seconds must sum to exactly that subtest's own
 *    duration_seconds (mirrors the existing subtest-sum-equals-total
 *    invariant one level down).
 * 3. Every code (subtest AND segment, session-wide) must be globally
 *    unique -- extends the existing per-table subtest-code-uniqueness check
 *    to include segment codes in the same registry, matching
 *    SessionDefinition::assertUniqueCode()'s single shared $codes array.
 * 4. The three places that previously required
 *    `total_duration_seconds == NEW.duration_seconds` (a direct top-level
 *    equality, the subtest-duration-sum equality, and Kraepelin's
 *    hardcoded `NEW.duration_seconds = 750`) now allow
 *    `NEW.duration_seconds` to exceed that by exactly the SUM of every
 *    segment's reading_cap_seconds (a subtest with explicit `segments` uses
 *    each segment's own value; a subtest without uses its own
 *    reading_cap_seconds if present, else 0) -- revision 1 of
 *    tasks/handoffs/f2/timed-segments-plan.md, mirroring
 *    AllocateAndStartAssessmentSession::sessionDurationSeconds(). The
 *    subtest-duration-sum-equals-total_duration_seconds invariant itself
 *    (unrelated to reading caps) is untouched.
 *
 * Confirmed NOT affected by this change (Lead explicitly asked): item_count
 * sums (the Kraepelin 1350 check), instrument/version/provenance/checksum
 * identity checks, randomization/seed/generator validation, and the
 * append-only/immutability guard on UPDATE -- none of those read
 * duration_seconds or the new optional fields at all.
 *
 * assessment_session_definitions.template_payload (the CATALOG/template
 * table, guarded by the separate app_private.guard_assessment_session_
 * definitions() function) is NOT touched here -- nothing in this PR writes
 * a template row with the new fields (real reading-cap catalog data is
 * out of scope per CLAUDE.md's tools/extract/-only data governance and the
 * plan doc's own "explicitly not designed here"). That guard will need the
 * identical extension whenever real catalog data eventually carries a
 * reading cap -- flagged to Lead as a tracked follow-up, not done here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
                DB::unprepared($this->postgresSnapshotFunctionBody(timedSegments: true));
            } elseif ($driver === 'sqlite') {
                $this->installSqliteSnapshotTriggers($this->sqliteSnapshotValidExpression(timedSegments: true));
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
                DB::unprepared($this->postgresSnapshotFunctionBody(timedSegments: false));
            } elseif ($driver === 'sqlite') {
                $this->installSqliteSnapshotTriggers($this->sqliteSnapshotValidExpression(timedSegments: false));
            }
        });
    }

    private function postgresSnapshotFunctionBody(bool $timedSegments): string
    {
        $subtestShape = $timedSegments
            ? <<<'SQL'
                    optional_key_count := (SELECT count(*) FROM jsonb_object_keys(subtest)) - 3;
                    IF NOT subtest ?& ARRAY['code','duration_seconds','item_count']
                        OR optional_key_count < 0
                        OR optional_key_count > 3
                        OR EXISTS (
                            SELECT 1 FROM jsonb_object_keys(subtest) key
                            WHERE key NOT IN ('code','duration_seconds','item_count',
                                'reading_cap_seconds','allow_early_finish','segments')
                        )
                        OR jsonb_typeof(subtest->'code') <> 'string'
                        OR jsonb_typeof(subtest->'duration_seconds') <> 'number'
                        OR jsonb_typeof(subtest->'item_count') <> 'number' THEN
                        RAISE EXCEPTION 'test session definition subtest has an invalid shape' USING ERRCODE = '23514';
                    END IF;
                SQL
            : <<<'SQL'
                    IF (SELECT count(*) FROM jsonb_object_keys(subtest)) <> 3
                        OR NOT subtest ?& ARRAY['code','duration_seconds','item_count']
                        OR jsonb_typeof(subtest->'code') <> 'string'
                        OR jsonb_typeof(subtest->'duration_seconds') <> 'number'
                        OR jsonb_typeof(subtest->'item_count') <> 'number' THEN
                        RAISE EXCEPTION 'test session definition subtest has an invalid shape' USING ERRCODE = '23514';
                    END IF;
                SQL;

        $optionalFieldsAndSegments = $timedSegments
            ? <<<'SQL'

                    IF subtest ? 'reading_cap_seconds' AND (
                        jsonb_typeof(subtest->'reading_cap_seconds') <> 'number'
                        OR (subtest->>'reading_cap_seconds') !~ '^(0|[1-9][0-9]*)$'
                    ) THEN
                        RAISE EXCEPTION 'test session definition subtest reading cap is invalid' USING ERRCODE = '23514';
                    END IF;
                    IF subtest ? 'allow_early_finish' AND jsonb_typeof(subtest->'allow_early_finish') <> 'boolean' THEN
                        RAISE EXCEPTION 'test session definition subtest allow_early_finish is invalid' USING ERRCODE = '23514';
                    END IF;

                    IF subtest ? 'segments' THEN
                        IF jsonb_typeof(subtest->'segments') <> 'array'
                            OR jsonb_array_length(subtest->'segments') = 0 THEN
                            RAISE EXCEPTION 'test session definition subtest segments must be a non-empty array' USING ERRCODE = '23514';
                        END IF;
                        segment_duration_sum := 0;
                        FOR segment IN SELECT value FROM jsonb_array_elements(subtest->'segments') LOOP
                            IF jsonb_typeof(segment) <> 'object'
                                OR (SELECT count(*) FROM jsonb_object_keys(segment)) <> 4
                                OR NOT segment ?& ARRAY['code','duration_seconds','reading_cap_seconds','allow_early_finish']
                                OR jsonb_typeof(segment->'code') <> 'string'
                                OR jsonb_typeof(segment->'duration_seconds') <> 'number'
                                OR jsonb_typeof(segment->'reading_cap_seconds') <> 'number'
                                OR jsonb_typeof(segment->'allow_early_finish') <> 'boolean' THEN
                                RAISE EXCEPTION 'test session definition segment has an invalid shape' USING ERRCODE = '23514';
                            END IF;
                            segment_code_text := segment->>'code';
                            segment_duration_text := segment->>'duration_seconds';
                            segment_reading_cap_text := segment->>'reading_cap_seconds';
                            IF segment_code_text = '' OR segment_code_text <> btrim(segment_code_text)
                                OR segment_code_text ~ '[[:space:][:cntrl:]]'
                                OR position(chr(160) in segment_code_text) > 0
                                OR position(chr(8203) in segment_code_text) > 0
                                OR position(chr(8232) in segment_code_text) > 0
                                OR position(chr(8233) in segment_code_text) > 0
                                OR position(chr(65279) in segment_code_text) > 0
                                OR segment_duration_text !~ '^[1-9][0-9]*$'
                                OR segment_reading_cap_text !~ '^(0|[1-9][0-9]*)$'
                                OR segment_code_text = ANY(seen_codes) THEN
                                RAISE EXCEPTION 'test session definition segment values are invalid' USING ERRCODE = '23514';
                            END IF;
                            seen_codes := array_append(seen_codes, segment_code_text);
                            segment_duration_sum := segment_duration_sum + segment_duration_text::numeric;
                            reading_cap_sum := reading_cap_sum + segment_reading_cap_text::numeric;
                        END LOOP;
                        IF segment_duration_sum <> duration_text::numeric THEN
                            RAISE EXCEPTION 'test session definition segment durations disagree with their subtest' USING ERRCODE = '23514';
                        END IF;
                    ELSE
                        subtest_reading_cap := CASE WHEN subtest ? 'reading_cap_seconds'
                            THEN (subtest->>'reading_cap_seconds')::numeric ELSE 0 END;
                        reading_cap_sum := reading_cap_sum + subtest_reading_cap;
                    END IF;
                SQL
            : '';

        $totalCheck = $timedSegments
            ? "duration_sum <> (definition->>'total_duration_seconds')::numeric\n                    OR duration_sum + reading_cap_sum <> NEW.duration_seconds"
            : 'duration_sum <> NEW.duration_seconds';

        $kraepelinDurationCheck = $timedSegments
            ? 'NEW.duration_seconds <> 750 + reading_cap_sum'
            : 'NEW.duration_seconds <> 750';

        $declarations = $timedSegments
            ? "                segment jsonb;\n                segment_code_text text;\n                segment_duration_text text;\n                segment_reading_cap_text text;\n                segment_duration_sum numeric;\n                reading_cap_sum numeric := 0;\n                subtest_reading_cap numeric;\n                optional_key_count integer;\n"
            : '';

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
            {$declarations}BEGIN
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
            {$subtestShape}            code_text := subtest->>'code';
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
            {$optionalFieldsAndSegments}        END LOOP;
                IF {$totalCheck} THEN
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
                        OR definition->>'randomization' <> 'fixed'
                        OR jsonb_typeof(definition->'seed') <> 'null'
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
                        OR {$kraepelinDurationCheck}
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

    /** @param array{insert: string, update: string} $valid */
    private function installSqliteSnapshotTriggers(array $valid): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_definition_snapshot_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_definition_snapshot_update_guard');
        DB::unprepared("CREATE TRIGGER test_sessions_definition_snapshot_insert_guard
            BEFORE INSERT ON test_sessions FOR EACH ROW
            WHEN COALESCE(({$valid['insert']}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'test session definition snapshot is invalid'); END");
        DB::unprepared("CREATE TRIGGER test_sessions_definition_snapshot_update_guard
            BEFORE UPDATE ON test_sessions FOR EACH ROW
            WHEN NEW.session_definition_version IS NOT OLD.session_definition_version
              OR NEW.session_definition_provenance IS NOT OLD.session_definition_provenance
              OR NEW.session_definition_checksum IS NOT OLD.session_definition_checksum
              OR NEW.session_definition_payload IS NOT OLD.session_definition_payload
              OR COALESCE(({$valid['update']}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'test session definition snapshot is immutable or invalid'); END");
    }

    /** @return array{insert: string, update: string} */
    private function sqliteSnapshotValidExpression(bool $timedSegments): array
    {
        $payload = "CASE WHEN json_valid(NEW.session_definition_payload) THEN NEW.session_definition_payload ELSE '{}' END";
        $version = "json_extract({$payload}, '\$.version')";
        $provenance = "json_extract({$payload}, '\$.provenance')";
        $checksum = "json_extract({$payload}, '\$.checksum')";
        $generator = "json_extract({$payload}, '\$.generator')";
        $canonicalVersion = $this->sqliteCanonicalIdentity($version, 100);
        $canonicalProvenance = $this->sqliteCanonicalIdentity($provenance, 255);
        $canonicalAlgorithm = $this->sqliteCanonicalIdentity("json_extract({$payload}, '\$.generator.algorithm')");
        $canonicalGeneratorVersion = $this->sqliteCanonicalIdentity("json_extract({$payload}, '\$.generator.version')");

        $readingCapSumExpr = $timedSegments
            ? <<<SQL
                (
                        SELECT SUM(
                            CASE WHEN json_type(subtest.value, '\$.segments') = 'array'
                                THEN (
                                    SELECT SUM(json_extract(segment.value, '\$.reading_cap_seconds'))
                                    FROM json_each(subtest.value, '\$.segments') segment
                                )
                                ELSE COALESCE(json_extract(subtest.value, '\$.reading_cap_seconds'), 0)
                            END
                        )
                        FROM json_each({$payload}, '\$.subtests') subtest
                    )
                SQL
            : '0';

        $subtestKeyCountCheck = $timedSegments
            ? <<<'SQL'
                (SELECT COUNT(*) FROM json_each(subtest.value)) < 3
                          OR (SELECT COUNT(*) FROM json_each(subtest.value)) > 6
                          OR NOT EXISTS (SELECT 1 FROM json_each(subtest.value) k WHERE k.key = 'code')
                          OR NOT EXISTS (SELECT 1 FROM json_each(subtest.value) k WHERE k.key = 'duration_seconds')
                          OR NOT EXISTS (SELECT 1 FROM json_each(subtest.value) k WHERE k.key = 'item_count')
                SQL
            : '(SELECT COUNT(*) FROM json_each(subtest.value)) <> 3';

        $subtestAllowedKeys = $timedSegments
            ? "'code','duration_seconds','item_count','reading_cap_seconds','allow_early_finish','segments'"
            : "'code','duration_seconds','item_count'";

        $subtestOptionalFieldChecks = $timedSegments
            ? <<<'SQL'

                          OR (
                              json_type(subtest.value, '$.reading_cap_seconds') IS NOT NULL
                              AND (
                                  json_type(subtest.value, '$.reading_cap_seconds') <> 'integer'
                                  OR json_extract(subtest.value, '$.reading_cap_seconds') < 0
                              )
                          )
                          OR (
                              json_type(subtest.value, '$.allow_early_finish') IS NOT NULL
                              AND json_type(subtest.value, '$.allow_early_finish') NOT IN ('true','false')
                          )
                          OR (
                              json_type(subtest.value, '$.segments') IS NOT NULL
                              AND (
                                  json_type(subtest.value, '$.segments') <> 'array'
                                  OR json_array_length(subtest.value, '$.segments') = 0
                              )
                          )
                SQL
            : '';

        $codeUniquenessCheck = $timedSegments
            ? <<<SQL
                (
                        WITH all_codes(code) AS (
                            SELECT json_extract(subtest.value, '\$.code')
                            FROM json_each({$payload}, '\$.subtests') subtest
                            UNION ALL
                            SELECT json_extract(segment.value, '\$.code')
                            FROM json_each({$payload}, '\$.subtests') subtest,
                                 json_each(subtest.value, '\$.segments') segment
                            WHERE json_type(subtest.value, '\$.segments') = 'array'
                        )
                        SELECT COUNT(*) = COUNT(DISTINCT code) FROM all_codes
                    )
                SQL
            : <<<SQL
                (
                        SELECT COUNT(*) FROM json_each({$payload}, '\$.subtests')
                    ) = (
                        SELECT COUNT(DISTINCT json_extract(subtest.value, '\$.code'))
                        FROM json_each({$payload}, '\$.subtests') subtest
                    )
                SQL;

        $segmentShapeAndSumChecks = $timedSegments
            ? <<<SQL

                    AND NOT EXISTS (
                        SELECT 1 FROM json_each({$payload}, '\$.subtests') subtest,
                                      json_each(subtest.value, '\$.segments') segment
                        WHERE json_type(subtest.value, '\$.segments') = 'array'
                            AND (
                                json_type(segment.value) <> 'object'
                                OR (SELECT COUNT(*) FROM json_each(segment.value)) <> 4
                                OR EXISTS (
                                    SELECT 1 FROM json_each(segment.value) field
                                    WHERE field.key NOT IN ('code','duration_seconds','reading_cap_seconds','allow_early_finish')
                                )
                                OR json_type(segment.value, '\$.code') <> 'text'
                                OR NOT ({$this->sqliteCanonicalIdentity("json_extract(segment.value, '\$.code')")})
                                OR json_type(segment.value, '\$.duration_seconds') <> 'integer'
                                OR json_extract(segment.value, '\$.duration_seconds') < 1
                                OR json_type(segment.value, '\$.reading_cap_seconds') <> 'integer'
                                OR json_extract(segment.value, '\$.reading_cap_seconds') < 0
                                OR json_type(segment.value, '\$.allow_early_finish') NOT IN ('true','false')
                            )
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM json_each({$payload}, '\$.subtests') subtest
                        WHERE json_type(subtest.value, '\$.segments') = 'array'
                            AND (
                                SELECT SUM(json_extract(segment.value, '\$.duration_seconds'))
                                FROM json_each(subtest.value, '\$.segments') segment
                            ) <> json_extract(subtest.value, '\$.duration_seconds')
                    )
                SQL
            : '';

        $kraepelinClause = $timedSegments
            ? <<<SQL
                OR (NEW.test_type = 'kraepelin'
                                    AND NEW.duration_seconds = 750 + {$readingCapSumExpr}
                                    AND json_type({$payload}, '\$.randomization') = 'text'
                                    AND json_extract({$payload}, '\$.randomization') = 'fixed'
                                    AND json_type({$payload}, '\$.seed') = 'null'
                                    AND json_type({$payload}, '\$.generator') = 'object'
                SQL
            : <<<SQL
                OR (NEW.test_type = 'kraepelin'
                                    AND NEW.duration_seconds = 750
                                    AND json_type({$payload}, '\$.randomization') = 'text'
                                    AND json_extract({$payload}, '\$.randomization') = 'fixed'
                                    AND json_type({$payload}, '\$.seed') = 'null'
                                    AND json_type({$payload}, '\$.generator') = 'object'
                SQL;

        $totalDurationCheck = $timedSegments
            ? <<<SQL
                (
                        SELECT SUM(json_extract(subtest.value, '\$.duration_seconds'))
                        FROM json_each({$payload}, '\$.subtests') subtest
                    ) = json_extract({$payload}, '\$.total_duration_seconds')
                    AND (
                        SELECT SUM(json_extract(subtest.value, '\$.duration_seconds'))
                        FROM json_each({$payload}, '\$.subtests') subtest
                    ) + {$readingCapSumExpr} = NEW.duration_seconds
                SQL
            : <<<SQL
                json_extract({$payload}, '\$.total_duration_seconds') = NEW.duration_seconds
                    AND (
                        SELECT SUM(json_extract(subtest.value, '\$.duration_seconds'))
                        FROM json_each({$payload}, '\$.subtests') subtest
                    ) = NEW.duration_seconds
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
                    AND json_type({$payload}, '\$.subtests') = 'array'
                    AND json_array_length({$payload}, '\$.subtests') > 0
                    AND NOT EXISTS (
                        SELECT 1 FROM json_each({$payload}, '\$.subtests') subtest
                        WHERE json_type(subtest.value) <> 'object'
                          OR {$subtestKeyCountCheck}
                          OR EXISTS (
                              SELECT 1 FROM json_each(subtest.value) field
                              WHERE field.key NOT IN ({$subtestAllowedKeys})
                          )
                          OR json_type(subtest.value, '\$.code') <> 'text'
                          OR NOT ({$this->sqliteCanonicalIdentity("json_extract(subtest.value, '\$.code')")})
                          OR json_type(subtest.value, '\$.duration_seconds') <> 'integer'
                          OR json_extract(subtest.value, '\$.duration_seconds') < 1
                          OR json_type(subtest.value, '\$.item_count') <> 'integer'
                          OR json_extract(subtest.value, '\$.item_count') < 1{$subtestOptionalFieldChecks}
                    )
                    AND {$codeUniquenessCheck}
                    {$segmentShapeAndSumChecks}
                    AND {$totalDurationCheck}
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
};
