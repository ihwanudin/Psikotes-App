<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'assessment_session_definitions';

    private const ACTIVE_INDEX = 'assessment_session_definitions_one_active_instrument_unique';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('Assessment session definition catalog already exists.');
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('instrument', 24);
            $table->string('version', 100);
            $table->string('provenance', 255);
            $table->char('template_checksum', 64);
            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('template_payload');
            } else {
                $table->json('template_payload');
            }
            $table->boolean('is_active');
            $table->timestampTz('activated_at', 6);
            $table->timestampTz('deactivated_at', 6)->nullable();

            $table->unique(
                ['instrument', 'version'],
                'assessment_session_definitions_instrument_version_unique',
            );
        });
        DB::statement('CREATE UNIQUE INDEX '.self::ACTIVE_INDEX
            .' ON '.self::TABLE.' (instrument) WHERE is_active = TRUE');

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresContract();
        } else {
            $this->addSqliteContract();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
                DB::statement('ALTER TABLE '.self::TABLE.' NO FORCE ROW LEVEL SECURITY');
            }
            if (DB::table(self::TABLE)->exists()) {
                throw new RuntimeException('Assessment session definition history prevents rollback.');
            }

            Schema::drop(self::TABLE);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('DROP FUNCTION IF EXISTS app_private.guard_assessment_session_definitions()');
            }
        });
    }

    private function addPostgresContract(): void
    {
        if (DB::scalar('SHOW server_encoding') !== 'UTF8') {
            throw new RuntimeException('Session definition identity validation requires PostgreSQL UTF8.');
        }

        DB::statement(<<<'SQL'
            ALTER TABLE assessment_session_definitions
                ADD CONSTRAINT assessment_session_definitions_identity_check CHECK (
                    instrument IN ('ist','papi','rmib','kraepelin')
                    AND length(version) BETWEEN 1 AND 100
                    AND length(provenance) BETWEEN 1 AND 255
                    AND version = btrim(version)
                    AND provenance = btrim(provenance)
                    AND version !~ '[[:space:][:cntrl:]]'
                    AND provenance !~ '[[:space:][:cntrl:]]'
                    AND position(chr(160) in version) = 0
                    AND position(chr(8203) in version) = 0
                    AND position(chr(8232) in version) = 0
                    AND position(chr(8233) in version) = 0
                    AND position(chr(65279) in version) = 0
                    AND position(chr(160) in provenance) = 0
                    AND position(chr(8203) in provenance) = 0
                    AND position(chr(8232) in provenance) = 0
                    AND position(chr(8233) in provenance) = 0
                    AND position(chr(65279) in provenance) = 0
                    AND template_checksum ~ '^[0-9a-f]{64}$'
                    AND jsonb_typeof(template_payload) = 'object'
                    AND octet_length(template_payload::text) <= 65536
                ),
                ADD CONSTRAINT assessment_session_definitions_lifecycle_check CHECK (
                    (is_active = TRUE AND deactivated_at IS NULL)
                    OR (is_active = FALSE AND deactivated_at IS NOT NULL
                        AND deactivated_at >= activated_at)
                )
            SQL);
        $guardSql = <<<'SQL'
            CREATE FUNCTION app_private.guard_assessment_session_definitions() RETURNS trigger
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
                identity_value text;
                identity_position integer;
                forbidden_codepoints int4multirange :=
                    '__FORBIDDEN_CODEPOINT_MULTIRANGE__'::int4multirange;
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
                    OR definition->>'total_duration_seconds' !~ '^[1-9][0-9]*$'
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
                        OR duration_text !~ '^[1-9][0-9]*$'
                        OR item_text !~ '^[1-9][0-9]*$'
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
                    IF definition->>'randomization' <> 'seeded'
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
            $guard$;

            REVOKE ALL ON FUNCTION app_private.guard_assessment_session_definitions() FROM PUBLIC;
            CREATE TRIGGER assessment_session_definitions_guard
                BEFORE INSERT OR UPDATE OR DELETE ON assessment_session_definitions
                FOR EACH ROW EXECUTE FUNCTION app_private.guard_assessment_session_definitions();

            REVOKE ALL PRIVILEGES ON TABLE assessment_session_definitions FROM PUBLIC, psikotes_runtime;
            REVOKE ALL PRIVILEGES ON SEQUENCE assessment_session_definitions_id_seq FROM PUBLIC, psikotes_runtime;
            GRANT SELECT, INSERT, UPDATE ON TABLE assessment_session_definitions TO psikotes_runtime;
            GRANT USAGE, SELECT ON SEQUENCE assessment_session_definitions_id_seq TO psikotes_runtime;

            ALTER TABLE assessment_session_definitions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE assessment_session_definitions FORCE ROW LEVEL SECURITY;
            CREATE POLICY assessment_session_definitions_service_select ON assessment_session_definitions
                FOR SELECT TO psikotes_runtime USING (app_private.app_role() = 'service');
            CREATE POLICY assessment_session_definitions_service_insert ON assessment_session_definitions
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY assessment_session_definitions_service_update ON assessment_session_definitions
                FOR UPDATE TO psikotes_runtime USING (app_private.app_role() = 'service')
                WITH CHECK (app_private.app_role() = 'service');
            SQL;
        $this->executeGeneratedContractSql(str_replace(
            '__FORBIDDEN_CODEPOINT_MULTIRANGE__',
            $this->postgresForbiddenCodepointMultirange(),
            $guardSql,
        ));
    }

    private function addSqliteContract(): void
    {
        $valid = <<<'SQL'
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
            AND json_extract(NEW.template_payload, '$.instrument') = NEW.instrument
            AND json_extract(NEW.template_payload, '$.version') = NEW.version
            AND json_extract(NEW.template_payload, '$.provenance') = NEW.provenance
            AND json_type(NEW.template_payload, '$.total_duration_seconds') = 'integer'
            AND json_extract(NEW.template_payload, '$.total_duration_seconds') > 0
            AND json_type(NEW.template_payload, '$.subtests') = 'array'
            AND json_array_length(NEW.template_payload, '$.subtests') > 0
            AND NOT EXISTS (
                SELECT 1 FROM json_each(NEW.template_payload, '$.subtests') subtest
                WHERE json_type(subtest.value) <> 'object'
                    OR (SELECT COUNT(*) FROM json_each(subtest.value)) <> 3
                    OR EXISTS (
                        SELECT 1 FROM json_each(subtest.value) field
                        WHERE field.key NOT IN ('code','duration_seconds','item_count')
                    )
                    OR json_type(subtest.value, '$.code') <> 'text'
                    OR json_extract(subtest.value, '$.code') = ''
                    OR json_extract(subtest.value, '$.code') <> trim(json_extract(subtest.value, '$.code'))
                    OR instr(json_extract(subtest.value, '$.code'), ' ') > 0
                    OR instr(json_extract(subtest.value, '$.code'), char(9)) > 0
                    OR instr(json_extract(subtest.value, '$.code'), char(10)) > 0
                    OR instr(json_extract(subtest.value, '$.code'), char(13)) > 0
                    OR instr(json_extract(subtest.value, '$.code'), char(160)) > 0
                    OR instr(json_extract(subtest.value, '$.code'), char(8203)) > 0
                    OR instr(json_extract(subtest.value, '$.code'), char(8232)) > 0
                    OR instr(json_extract(subtest.value, '$.code'), char(8233)) > 0
                    OR instr(json_extract(subtest.value, '$.code'), char(65279)) > 0
                    OR json_type(subtest.value, '$.duration_seconds') <> 'integer'
                    OR json_extract(subtest.value, '$.duration_seconds') < 1
                    OR json_type(subtest.value, '$.item_count') <> 'integer'
                    OR json_extract(subtest.value, '$.item_count') < 1
            )
            AND (
                SELECT COUNT(*) = COUNT(DISTINCT json_extract(subtest.value, '$.code'))
                FROM json_each(NEW.template_payload, '$.subtests') subtest
            )
            AND (
                SELECT SUM(json_extract(subtest.value, '$.duration_seconds'))
                FROM json_each(NEW.template_payload, '$.subtests') subtest
            ) = json_extract(NEW.template_payload, '$.total_duration_seconds')
            AND json_type(NEW.template_payload, '$.seed') = 'null'
            AND (
                (NEW.instrument IN ('ist','papi','rmib')
                    AND json_extract(NEW.template_payload, '$.randomization') = 'fixed'
                    AND json_type(NEW.template_payload, '$.generator') = 'null')
                OR (NEW.instrument = 'kraepelin'
                    AND json_extract(NEW.template_payload, '$.randomization') = 'seeded'
                    AND json_extract(NEW.template_payload, '$.total_duration_seconds') = 750
                    AND json_type(NEW.template_payload, '$.generator') = 'object'
                    AND (SELECT COUNT(*) FROM json_each(
                        json_extract(NEW.template_payload, '$.generator')
                    )) = 6
                    AND NOT EXISTS (
                        SELECT 1 FROM json_each(
                            json_extract(NEW.template_payload, '$.generator')
                        ) field
                        WHERE field.key NOT IN (
                            'algorithm','version','columns','seconds_per_column',
                            'numbers_per_column','answer_slots_per_column'
                        )
                    )
                    AND json_type(NEW.template_payload, '$.generator.algorithm') = 'text'
                    AND json_extract(NEW.template_payload, '$.generator.algorithm') <> ''
                    AND json_extract(NEW.template_payload, '$.generator.algorithm')
                        = trim(json_extract(NEW.template_payload, '$.generator.algorithm'))
                    AND instr(json_extract(NEW.template_payload, '$.generator.algorithm'), ' ') = 0
                    AND json_type(NEW.template_payload, '$.generator.version') = 'text'
                    AND json_extract(NEW.template_payload, '$.generator.version') <> ''
                    AND json_extract(NEW.template_payload, '$.generator.version')
                        = trim(json_extract(NEW.template_payload, '$.generator.version'))
                    AND instr(json_extract(NEW.template_payload, '$.generator.version'), ' ') = 0
                    AND json_type(NEW.template_payload, '$.generator.columns') = 'integer'
                    AND json_extract(NEW.template_payload, '$.generator.columns') = 50
                    AND json_type(NEW.template_payload, '$.generator.seconds_per_column') = 'integer'
                    AND json_extract(NEW.template_payload, '$.generator.seconds_per_column') = 15
                    AND json_type(NEW.template_payload, '$.generator.numbers_per_column') = 'integer'
                    AND json_extract(NEW.template_payload, '$.generator.numbers_per_column') = 28
                    AND json_type(NEW.template_payload, '$.generator.answer_slots_per_column') = 'integer'
                    AND json_extract(NEW.template_payload, '$.generator.answer_slots_per_column') = 27
                    AND (
                        SELECT SUM(json_extract(subtest.value, '$.item_count'))
                        FROM json_each(NEW.template_payload, '$.subtests') subtest
                    ) = 1350)
            )
            AND NOT EXISTS (
                WITH RECURSIVE identities(value) AS (
                    SELECT CAST(NEW.version AS TEXT)
                    UNION ALL SELECT CAST(NEW.provenance AS TEXT)
                    UNION ALL
                    SELECT CASE WHEN subtest.type = 'object'
                        THEN CAST(json_extract(subtest.value, '$.code') AS TEXT)
                        ELSE NULL END
                    FROM json_each(NEW.template_payload, '$.subtests') subtest
                    UNION ALL
                    SELECT CAST(json_extract(
                        NEW.template_payload, '$.generator.algorithm'
                    ) AS TEXT)
                    UNION ALL
                    SELECT CAST(json_extract(
                        NEW.template_payload, '$.generator.version'
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
                    SELECT json_extract(range.value, '$[0]'), json_extract(range.value, '$[1]')
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
        $valid = str_replace(
            '__FORBIDDEN_CODEPOINT_RANGES_JSON__',
            $this->sqliteForbiddenCodepointRangesJson(),
            $valid,
        );

        $this->executeGeneratedContractSql("CREATE TRIGGER assessment_session_definitions_insert_guard
            BEFORE INSERT ON assessment_session_definitions FOR EACH ROW
            WHEN COALESCE(({$valid}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'assessment session definition template is invalid'); END");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assessment_session_definitions_update_guard
            BEFORE UPDATE ON assessment_session_definitions FOR EACH ROW
            WHEN NOT (
                NEW.id IS OLD.id
                AND NEW.instrument IS OLD.instrument
                AND NEW.version IS OLD.version
                AND NEW.provenance IS OLD.provenance
                AND NEW.template_checksum IS OLD.template_checksum
                AND NEW.template_payload IS OLD.template_payload
                AND NEW.activated_at IS OLD.activated_at
                AND OLD.is_active = 1
                AND NEW.is_active = 0
                AND OLD.deactivated_at IS NULL
                AND NEW.deactivated_at IS NOT NULL
                AND NEW.deactivated_at >= OLD.activated_at
            )
            BEGIN SELECT RAISE(ABORT, 'assessment session definitions only permit one-way deactivation'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assessment_session_definitions_delete_guard
            BEFORE DELETE ON assessment_session_definitions FOR EACH ROW
            BEGIN SELECT RAISE(ABORT, 'assessment session definition history cannot be deleted'); END
            SQL);
    }

    private function postgresForbiddenCodepointMultirange(): string
    {
        return '{'.implode(',', array_map(
            static fn (array $range): string => sprintf('[%d,%d)', $range[0], $range[1] + 1),
            $this->forbiddenCodepointRanges(),
        )).'}';
    }

    private function executeGeneratedContractSql(string $sql): void
    {
        if (DB::connection()->getPdo()->exec($sql) === false) {
            throw new RuntimeException('Unable to install the generated session definition contract.');
        }
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
};
