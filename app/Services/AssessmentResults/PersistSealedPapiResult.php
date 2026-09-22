<?php

declare(strict_types=1);

namespace App\Services\AssessmentResults;

use App\Domain\AssessmentResults\SealedPapiResult;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;
use UnexpectedValueException;

/**
 * F2 lane G7 (2026-09-20), mirrors
 * app/Services/AssessmentResults/PersistSealedIstResult.php. Writes into the
 * same generic ledger (`generic_instrument_results` /
 * `generic_instrument_result_sources`,
 * database/migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php)
 * — no PAPI-specific table exists or is needed. Field-semantics mapping is
 * documented at tasks/handoffs/f2/generic-instrument-result-field-mapping.md.
 */
final readonly class PersistSealedPapiResult
{
    public function __construct(private RlsContextRunner $contexts) {}

    public function execute(SealedPapiResult $result): string
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('PAPI_RESULT_PERSISTENCE_CONTEXT_REQUIRED');
        }

        try {
            $session = DB::table('test_sessions as session')
                ->where('session.id', $result->sessionId)
                ->select([
                    'session.id', 'session.public_id', 'session.assessment_case_id',
                    'session.participant_id', 'session.test_type', 'session.attempt_no',
                    'session.status', 'session.submitted_at', 'session.answers_revision',
                    'session.session_definition_version', 'session.session_definition_provenance',
                    'session.session_definition_checksum', 'session.session_definition_payload',
                ])
                ->lockForUpdate()
                ->first();
            if (! $this->sessionMatches($session, $result)) {
                throw self::invalid();
            }

            $instrument = DB::table('instrument_versions')
                ->where('id', $result->scoringSource['id'])
                ->first(['id', 'code', 'version', 'source_file', 'checksum']);
            if (! $this->instrumentMatches($instrument, $result)) {
                throw self::invalid();
            }

            $existing = DB::table('generic_instrument_results')
                ->where('session_id', $result->sessionId)
                ->first();
            if ($existing !== null) {
                if (! $this->replayMatches($existing, $result)) {
                    throw self::conflict();
                }

                return (string) $existing->public_id;
            }

            $createdAt = now('UTC')->format('Y-m-d H:i:s.uP');
            $publicId = strtoupper((string) Str::ulid());
            $resultId = DB::table('generic_instrument_results')->insertGetId([
                ...$this->parentValues($result),
                'public_id' => $publicId,
                'created_at' => $createdAt,
            ]);
            $sources = [];
            foreach ($result->dimensions as $offset => $dimension) {
                $sources[] = [
                    'result_id' => $resultId,
                    'ordinal' => $offset + 1,
                    'source_code' => $dimension['code'],
                    'raw_score' => $dimension['rawScore'],
                    'standard_score' => $dimension['standardScore'],
                    'source_score' => $dimension['sourceScore'],
                    'level' => $dimension['level'],
                    'category' => $dimension['category'],
                    'band_low' => $dimension['band']['lo'],
                    'band_high' => $dimension['band']['hi'],
                    'created_at' => $createdAt,
                ];
            }
            DB::table('generic_instrument_result_sources')->insert($sources);

            return $publicId;
        } catch (UnexpectedValueException $exception) {
            if (in_array($exception->getMessage(), [
                'PAPI_RESULT_PERSISTENCE_INVALID', 'PAPI_RESULT_PERSISTENCE_CONFLICT',
            ], true)) {
                throw $exception;
            }

            throw self::invalid();
        } catch (Throwable) {
            throw self::invalid();
        }
    }

    private function sessionMatches(?object $session, SealedPapiResult $result): bool
    {
        if ($session === null) {
            return false;
        }

        return (int) ($session->id ?? 0) === $result->sessionId
            && (int) ($session->assessment_case_id ?? 0) === $result->assessmentCaseId
            && (int) ($session->participant_id ?? 0) === $result->participantId
            && strtoupper((string) ($session->public_id ?? '')) === $result->sessionPublicId
            && ($session->test_type ?? null) === 'papi'
            && (int) ($session->attempt_no ?? 0) === $result->attemptNo
            && ($session->status ?? null) === 'submitted'
            && $this->sameTimestamp($session->submitted_at ?? null, $result->submittedAt)
            && (int) ($session->answers_revision ?? 0) === $result->answersRevision
            && ($session->session_definition_version ?? null) === $result->sessionDefinition['version']
            && ($session->session_definition_provenance ?? null) === $result->sessionDefinition['provenance']
            && ($session->session_definition_checksum ?? null) === $result->sessionDefinition['checksum']
            && $this->sameJson(
                $session->session_definition_payload ?? null,
                json_encode($result->sessionDefinition, JSON_THROW_ON_ERROR),
            );
    }

    private function instrumentMatches(?object $instrument, SealedPapiResult $result): bool
    {
        return $instrument !== null
            && (int) ($instrument->id ?? 0) === $result->scoringSource['id']
            && ($instrument->code ?? null) === 'papi'
            && ($instrument->version ?? null) === $result->scoringSource['version']
            && ($instrument->source_file ?? null) === $result->scoringSource['sourceFile']
            && ($instrument->checksum ?? null) === $result->scoringSource['checksum'];
    }

    private function replayMatches(object $parent, SealedPapiResult $result): bool
    {
        $parentState = (array) $parent;
        $values = $this->parentValues($result);
        foreach ($values as $column => $expected) {
            $actual = $parentState[$column] ?? null;
            $matches = match ($column) {
                'assessment_case_id', 'session_id', 'participant_id', 'attempt_no',
                'answers_revision', 'instrument_version_id' => (int) $actual === $expected,
                'submitted_at' => $this->sameTimestamp($actual, (string) $expected),
                'session_definition_payload' => $this->sameJson(
                    $actual,
                    json_encode($result->sessionDefinition, JSON_THROW_ON_ERROR),
                ),
                'result_payload' => $this->sameJson($actual, $result->canonicalJson()),
                default => $actual === $expected,
            };
            if (! $matches) {
                return false;
            }
        }
        $parentId = (int) ($parentState['id'] ?? 0);
        $publicId = $parentState['public_id'] ?? null;
        $createdAt = $parentState['created_at'] ?? null;
        if ($parentId < 1
            || ! is_string($publicId)
            || ! Str::isUlid($publicId)
            || strtoupper($publicId) !== $publicId
            || ! $this->validTimestamp($createdAt)) {
            return false;
        }

        $sources = DB::table('generic_instrument_result_sources')
            ->where('result_id', $parentId)
            ->orderBy('ordinal')
            ->get();
        if ($sources->count() !== count($result->dimensions)) {
            return false;
        }
        foreach ($sources as $offset => $source) {
            $sourceState = (array) $source;
            $expected = $result->dimensions[$offset];
            if ((int) ($sourceState['id'] ?? 0) < 1
                || (int) ($sourceState['result_id'] ?? 0) !== $parentId
                || (int) ($sourceState['ordinal'] ?? 0) !== $offset + 1
                || ($sourceState['source_code'] ?? null) !== $expected['code']
                || (int) ($sourceState['raw_score'] ?? -1) !== $expected['rawScore']
                || (int) ($sourceState['standard_score'] ?? 0) !== $expected['standardScore']
                || (int) ($sourceState['source_score'] ?? 0) !== $expected['sourceScore']
                || (int) ($sourceState['level'] ?? 0) !== $expected['level']
                || ($sourceState['category'] ?? null) !== $expected['category']
                || $this->nullableInteger($sourceState['band_low'] ?? null) !== $expected['band']['lo']
                || $this->nullableInteger($sourceState['band_high'] ?? null) !== $expected['band']['hi']
                || ! $this->sameTimestamp($sourceState['created_at'] ?? null, (string) $createdAt)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,int|string> */
    private function parentValues(SealedPapiResult $result): array
    {
        return [
            'assessment_case_id' => $result->assessmentCaseId,
            'session_id' => $result->sessionId,
            'participant_id' => $result->participantId,
            'session_public_id' => $result->sessionPublicId,
            'instrument_code' => 'papi',
            'attempt_no' => $result->attemptNo,
            'submitted_at' => $result->submittedAt,
            'answers_revision' => $result->answersRevision,
            'sealed_source_checksum' => $result->sealedSourceChecksum,
            'session_definition_version' => $result->sessionDefinition['version'],
            'session_definition_provenance' => $result->sessionDefinition['provenance'],
            'session_definition_checksum' => $result->sessionDefinition['checksum'],
            'session_definition_payload' => json_encode($result->sessionDefinition, JSON_THROW_ON_ERROR),
            'instrument_version_id' => $result->scoringSource['id'],
            'instrument_version' => $result->scoringSource['version'],
            'instrument_source_file' => $result->scoringSource['sourceFile'],
            'instrument_checksum' => $result->scoringSource['checksum'],
            'result_contract_version' => $result->resultContractVersion,
            'engine_version' => $result->engineVersion,
            'result_payload' => $result->canonicalJson(),
            'result_checksum' => $result->resultChecksum,
        ];
    }

    private function sameJson(mixed $actual, string $expected): bool
    {
        if (! is_string($actual)) {
            return false;
        }
        try {
            return $this->canonicalize(json_decode($actual, true, flags: JSON_THROW_ON_ERROR))
                === $this->canonicalize(json_decode($expected, true, flags: JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return false;
        }
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

    private function sameTimestamp(mixed $actual, string $expected): bool
    {
        return $this->timestamp($actual) !== null
            && $this->timestamp($actual) === $this->timestamp($expected);
    }

    private function validTimestamp(mixed $value): bool
    {
        return $this->timestamp($value) !== null;
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z');
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function invalid(): UnexpectedValueException
    {
        return new UnexpectedValueException('PAPI_RESULT_PERSISTENCE_INVALID');
    }

    private static function conflict(): UnexpectedValueException
    {
        return new UnexpectedValueException('PAPI_RESULT_PERSISTENCE_CONFLICT');
    }
}
