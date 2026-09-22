<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentResults;

use App\Domain\AssessmentResults\PersistedIstResult;
use App\Domain\AssessmentSessions\SessionDefinition;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UnexpectedValueException;

final class PersistedIstResultTest extends TestCase
{
    public function test_it_hydrates_an_exact_canonical_immutable_result(): void
    {
        [$parent, $sources, $payload] = $this->fixture();

        $result = PersistedIstResult::hydrate($parent, $sources);

        $this->assertTrue((new ReflectionClass($result))->isReadOnly());
        $this->assertSame($parent['public_id'], $result->publicId);
        $this->assertSame($parent['created_at'], $result->createdAt);
        $this->assertEquals($payload['sessionDefinition'], $result->sessionDefinition);
        $this->assertEquals($payload['scoringSource'], $result->scoringSource);
        $this->assertEquals($payload['subtests'], $result->subtests);
        $this->assertEquals($payload['iq'], $result->iq);
        $this->assertSame($parent['result_checksum'], $result->resultChecksum);
        $this->assertSame($payload, json_decode($result->canonicalJson(), true, flags: JSON_THROW_ON_ERROR));

        $reordered = array_reverse($payload, true);
        $reordered['iq'] = array_reverse($reordered['iq'], true);
        $reordered['scoringSource'] = array_reverse($reordered['scoringSource'], true);
        $reordered['sessionDefinition'] = array_reverse($reordered['sessionDefinition'], true);
        $reordered['subtests'] = array_map(static fn (array $row): array => array_reverse($row, true), $reordered['subtests']);
        $fromJsonbOrdering = PersistedIstResult::hydrate([
            ...$parent, 'result_payload' => json_encode($reordered, JSON_THROW_ON_ERROR),
        ], $sources);
        $this->assertSame($result->canonicalJson(), $fromJsonbOrdering->canonicalJson());
    }

    public function test_it_rejects_noncanonical_json_types_and_a_forged_checksum(): void
    {
        [$parent, $sources] = $this->fixture();
        $forgeries = [];

        $wrongType = json_decode($parent['result_payload'], true, flags: JSON_THROW_ON_ERROR);
        $wrongType['iq']['iq'] = '100';
        $forgeries[] = [...$parent, 'result_payload' => json_encode($wrongType, JSON_THROW_ON_ERROR)];
        $forgeries[] = [...$parent, 'result_checksum' => str_repeat('0', 64)];
        $forgeries[] = [...$parent, 'session_definition_payload' => '{"subtests":{}}'];

        foreach ($forgeries as $forged) {
            try {
                PersistedIstResult::hydrate($forged, $sources);
                $this->fail('Counterfeit persisted result state must fail closed.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('IST_RESULT_READ_INVALID', $exception->getMessage());
            }
        }
    }

    public function test_it_rejects_correctly_resigned_numeric_strings_in_every_payload_numeric_class(): void
    {
        [$parent, $sources, $payload] = $this->fixture();
        $paths = [
            ['assessmentCaseId'], ['sessionId'], ['participantId'], ['attemptNo'], ['answersRevision'],
            ['scoringSource', 'id'],
            ['sessionDefinition', 'total_duration_seconds'],
            ['sessionDefinition', 'subtests', 0, 'duration_seconds'],
            ['sessionDefinition', 'subtests', 0, 'item_count'],
            ['subtests', 0, 'rawScore'], ['subtests', 0, 'standardScore'],
            ['subtests', 0, 'sourceScore'], ['subtests', 0, 'level'],
            ['subtests', 0, 'band', 'lo'], ['subtests', 0, 'band', 'hi'],
            ['iq', 'rawTotal'], ['iq', 'iq'], ['iq', 'level'],
            ['iq', 'sourceScores', 0], ['iq', 'band', 'lo'], ['iq', 'band', 'hi'],
        ];

        foreach ($paths as $path) {
            $wrongType = $this->resign($this->replaceWithNumericString($payload, $path));

            try {
                PersistedIstResult::hydrate([
                    ...$parent,
                    'result_payload' => json_encode($wrongType, JSON_THROW_ON_ERROR),
                    'result_checksum' => $wrongType['resultChecksum'],
                ], $sources);
                $this->fail('A re-signed numeric string must not cross the persisted JSON boundary: '.implode('.', $path));
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('IST_RESULT_READ_INVALID', $exception->getMessage());
            }
        }
    }

    public function test_database_driver_numeric_strings_remain_compatible_with_strict_payload_numbers(): void
    {
        [$parent, $sources] = $this->fixture();
        foreach ([
            'id', 'assessment_case_id', 'session_id', 'participant_id', 'attempt_no',
            'answers_revision', 'instrument_version_id',
        ] as $column) {
            $parent[$column] = (string) $parent[$column];
        }
        foreach ($sources as &$source) {
            foreach ([
                'id', 'result_id', 'ordinal', 'raw_score', 'standard_score', 'source_score',
                'level', 'band_low', 'band_high',
            ] as $column) {
                $source[$column] = (string) $source[$column];
            }
        }
        unset($source);

        $result = PersistedIstResult::hydrate($parent, $sources);

        $this->assertSame(4, $result->sessionId);
        $this->assertSame(100, $result->iq['iq']);
        $this->assertSame(90, $result->subtests[0]['band']['lo']);
    }

    public function test_it_rejects_missing_reordered_duplicate_or_divergent_sources(): void
    {
        [$parent, $sources] = $this->fixture();
        $invalidSources = [
            array_slice($sources, 0, 8),
            [$sources[1], $sources[0], ...array_slice($sources, 2)],
            [$sources[0], $sources[0], ...array_slice($sources, 2)],
            array_replace($sources, [3 => [...$sources[3], 'source_score' => 999]]),
            array_replace($sources, [8 => [...$sources[8], 'created_at' => '2026-09-13T05:01:00.000000Z']]),
        ];

        foreach ($invalidSources as $invalid) {
            try {
                PersistedIstResult::hydrate($parent, $invalid);
                $this->fail('Non-exact source history must fail closed.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('IST_RESULT_READ_INVALID', $exception->getMessage());
            }
        }
    }

    /** @return array{array<string,mixed>,list<array<string,mixed>>,array<string,mixed>} */
    private function fixture(): array
    {
        $codes = ['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'];
        $subtests = [];
        $sources = [];
        foreach ($codes as $offset => $code) {
            $subtests[] = [
                'code' => $code, 'rawScore' => $offset + 1, 'standardScore' => 90 + $offset,
                'sourceScore' => 100 + $offset, 'level' => 3, 'category' => 'synthetic',
                'band' => ['lo' => 90, 'hi' => 109],
            ];
            $sources[] = [
                'id' => $offset + 10, 'result_id' => 7, 'ordinal' => $offset + 1,
                'source_code' => $code, 'raw_score' => $offset + 1,
                'standard_score' => 90 + $offset, 'source_score' => 100 + $offset,
                'level' => 3, 'category' => 'synthetic', 'band_low' => 90, 'band_high' => 109,
                'created_at' => '2026-09-13T05:00:00.000000Z',
            ];
        }
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'definition-v1', 'provenance' => 'fixture',
            'total_duration_seconds' => 540,
            'subtests' => array_map(static fn (string $code): array => [
                'code' => $code, 'duration_seconds' => 60, 'item_count' => 1,
            ], $codes),
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = [
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ];
        $payloadWithoutChecksum = [
            'resultContractVersion' => 'ist-result:v2', 'engineVersion' => 'ist-scoring:v1',
            'assessmentCaseId' => 3,
            'sessionId' => 4, 'participantId' => 2,
            'sessionPublicId' => '01K53GZQ5W3H7G9JTF6ZV8YQ4M', 'attemptNo' => 1,
            'submittedAt' => '2026-09-13T04:20:00.654321Z', 'answersRevision' => 1,
            'sealedSourceChecksum' => str_repeat('b', 64), 'sessionDefinition' => $definition,
            'scoringSource' => [
                'id' => 5, 'code' => 'ist', 'version' => 'scorer-v1',
                'sourceFile' => 'ist.json', 'checksum' => str_repeat('c', 64),
            ],
            'subtests' => $subtests,
            'iq' => [
                'rawTotal' => 45, 'iq' => 100, 'level' => 3,
                'sourceScores' => [100], 'category' => 'synthetic',
                'band' => ['lo' => 90, 'hi' => 109],
            ],
        ];
        $canonical = $this->canonicalize($payloadWithoutChecksum);
        $checksum = hash('sha256', 'sealed-ist-result:v1|'.json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
        $payload = $this->canonicalize([...$payloadWithoutChecksum, 'resultChecksum' => $checksum]);
        $parent = [
            'id' => 7, 'public_id' => '01K53H1AZ7S3BRFDGFDPA3XKTQ',
            'assessment_case_id' => 3, 'session_id' => 4, 'participant_id' => 2,
            'session_public_id' => '01K53GZQ5W3H7G9JTF6ZV8YQ4M', 'instrument_code' => 'ist',
            'attempt_no' => 1, 'submitted_at' => '2026-09-13 04:20:00.654321+00:00',
            'answers_revision' => 1, 'sealed_source_checksum' => str_repeat('b', 64),
            'session_definition_version' => 'definition-v1', 'session_definition_provenance' => 'fixture',
            'session_definition_checksum' => $definition['checksum'],
            'session_definition_payload' => json_encode($definition, JSON_THROW_ON_ERROR),
            'instrument_version_id' => 5, 'instrument_version' => 'scorer-v1',
            'instrument_source_file' => 'ist.json', 'instrument_checksum' => str_repeat('c', 64),
            'result_contract_version' => 'ist-result:v2', 'engine_version' => 'ist-scoring:v1',
            'result_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'result_checksum' => $checksum,
            'created_at' => '2026-09-13T05:00:00.000000Z',
        ];

        return [$parent, $sources, $payload];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = $this->canonicalize($entry);
        }

        return $value;
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function resign(array $payload): array
    {
        unset($payload['resultChecksum']);
        $payload = $this->canonicalize($payload);
        $checksum = hash('sha256', 'sealed-ist-result:v1|'.json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));

        return $this->canonicalize([...$payload, 'resultChecksum' => $checksum]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  non-empty-list<int|string>  $path
     * @return array<string,mixed>
     */
    private function replaceWithNumericString(array $payload, array $path): array
    {
        $segment = array_shift($path);
        if (! is_string($segment)) {
            throw new UnexpectedValueException('Invalid test payload path.');
        }
        if ($path === []) {
            $payload[$segment] = (string) $payload[$segment];

            return $payload;
        }
        $payload[$segment] = $this->replaceNestedNumericString($payload[$segment] ?? null, $path);

        return $payload;
    }

    /** @param non-empty-list<int|string> $path */
    private function replaceNestedNumericString(mixed $value, array $path): mixed
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException('Invalid test payload path.');
        }
        $segment = array_shift($path);
        if ($path === []) {
            $value[$segment] = (string) $value[$segment];

            return $value;
        }
        $value[$segment] = $this->replaceNestedNumericString($value[$segment] ?? null, $path);

        return $value;
    }
}
