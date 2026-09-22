<?php

declare(strict_types=1);

namespace App\Services\AssessmentResults;

use App\Domain\AssessmentResults\SealedKraepelinResult;
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
 * app/Services/AssessmentResults/PersistSealedIstResult.php, with one
 * deliberate difference: `raw_score`/`band_low`/`band_high` are compared as
 * decimals (via bccomp at three decimal places), never cast to `(int)`.
 * Casting Panker/Hanker to int would silently truncate 15.86 to 15 — exactly
 * the mistake this lane's Lead caught in review before this file was
 * written; see
 * database/migrations/2026_09_20_000200_widen_generic_instrument_result_source_fractional_columns.php.
 */
final readonly class PersistSealedKraepelinResult
{
    public function __construct(private RlsContextRunner $contexts) {}

    public function execute(SealedKraepelinResult $result): string
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('KRAEPELIN_RESULT_PERSISTENCE_CONTEXT_REQUIRED');
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
            foreach ($result->factors as $offset => $factor) {
                $sources[] = [
                    'result_id' => $resultId,
                    'ordinal' => $offset + 1,
                    'source_code' => $factor['code'],
                    'raw_score' => $factor['rawScore'],
                    'standard_score' => $factor['standardScore'],
                    'source_score' => $factor['sourceScore'],
                    'level' => $factor['level'],
                    'category' => $factor['category'],
                    'band_low' => $factor['band']['lo'],
                    'band_high' => $factor['band']['hi'],
                    'created_at' => $createdAt,
                ];
            }
            DB::table('generic_instrument_result_sources')->insert($sources);

            return $publicId;
        } catch (UnexpectedValueException $exception) {
            if (in_array($exception->getMessage(), [
                'KRAEPELIN_RESULT_PERSISTENCE_INVALID', 'KRAEPELIN_RESULT_PERSISTENCE_CONFLICT',
            ], true)) {
                throw $exception;
            }

            throw self::invalid();
        } catch (Throwable) {
            throw self::invalid();
        }
    }

    private function sessionMatches(?object $session, SealedKraepelinResult $result): bool
    {
        if ($session === null) {
            return false;
        }

        return (int) ($session->id ?? 0) === $result->sessionId
            && (int) ($session->assessment_case_id ?? 0) === $result->assessmentCaseId
            && (int) ($session->participant_id ?? 0) === $result->participantId
            && strtoupper((string) ($session->public_id ?? '')) === $result->sessionPublicId
            && ($session->test_type ?? null) === 'kraepelin'
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

    private function instrumentMatches(?object $instrument, SealedKraepelinResult $result): bool
    {
        return $instrument !== null
            && (int) ($instrument->id ?? 0) === $result->scoringSource['id']
            && ($instrument->code ?? null) === 'kraepelin'
            && ($instrument->version ?? null) === $result->scoringSource['version']
            && ($instrument->source_file ?? null) === $result->scoringSource['sourceFile']
            && ($instrument->checksum ?? null) === $result->scoringSource['checksum'];
    }

    private function replayMatches(object $parent, SealedKraepelinResult $result): bool
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
        if ($sources->count() !== count($result->factors)) {
            return false;
        }
        foreach ($sources as $offset => $source) {
            $sourceState = (array) $source;
            $expected = $result->factors[$offset];
            if ((int) ($sourceState['id'] ?? 0) < 1
                || (int) ($sourceState['result_id'] ?? 0) !== $parentId
                || (int) ($sourceState['ordinal'] ?? 0) !== $offset + 1
                || ($sourceState['source_code'] ?? null) !== $expected['code']
                || ! $this->sameDecimal($sourceState['raw_score'] ?? null, $expected['rawScore'])
                || (int) ($sourceState['standard_score'] ?? 0) !== $expected['standardScore']
                || (int) ($sourceState['source_score'] ?? 0) !== $expected['sourceScore']
                || (int) ($sourceState['level'] ?? 0) !== $expected['level']
                || ($sourceState['category'] ?? null) !== $expected['category']
                || ! $this->sameNullableDecimal($sourceState['band_low'] ?? null, $expected['band']['lo'])
                || ! $this->sameNullableDecimal($sourceState['band_high'] ?? null, $expected['band']['hi'])
                || ! $this->sameTimestamp($sourceState['created_at'] ?? null, (string) $createdAt)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,int|string> */
    private function parentValues(SealedKraepelinResult $result): array
    {
        return [
            'assessment_case_id' => $result->assessmentCaseId,
            'session_id' => $result->sessionId,
            'participant_id' => $result->participantId,
            'session_public_id' => $result->sessionPublicId,
            'instrument_code' => 'kraepelin',
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
            // ADR-0032 PR1 (2026-09-22) added `engine_version` NOT NULL to the
            // shared `generic_instrument_results` ledger for IST/PAPI/RMIB
            // provenance. Kraepelin's own scoring code is untouched and out
            // of ADR-0032's scope -- this is a fixed DB-column value only,
            // deliberately NOT threaded into SealedKraepelinResult's sealed
            // payload/checksum (unlike the other three instruments), so
            // nothing about Kraepelin's own seal shape or any of its
            // existing readers changes.
            'engine_version' => 'kraepelin-scoring:v1',
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

    /**
     * Decimal-safe comparison at three decimal places — never `(int)` cast,
     * which would silently truncate a fractional Panker/Hanker value. `$actual`
     * comes back from the database as a numeric string (e.g. "15.860");
     * `$expected` is the in-memory int|float this class wrote. Formats both
     * sides to a fixed three-decimal string rather than depending on the
     * bcmath extension (not declared in composer.json, so not guaranteed
     * present everywhere this runs).
     */
    private function sameDecimal(mixed $actual, int|float $expected): bool
    {
        if (! is_string($actual) && ! is_int($actual) && ! is_float($actual)) {
            return false;
        }
        if (! is_numeric($actual)) {
            return false;
        }

        return sprintf('%.3F', (float) $actual) === sprintf('%.3F', (float) $expected);
    }

    private function sameNullableDecimal(mixed $actual, int|float|null $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }

        return $actual !== null && $this->sameDecimal($actual, $expected);
    }

    private static function invalid(): UnexpectedValueException
    {
        return new UnexpectedValueException('KRAEPELIN_RESULT_PERSISTENCE_INVALID');
    }

    private static function conflict(): UnexpectedValueException
    {
        return new UnexpectedValueException('KRAEPELIN_RESULT_PERSISTENCE_CONFLICT');
    }
}
