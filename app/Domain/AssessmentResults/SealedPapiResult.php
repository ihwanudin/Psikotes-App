<?php

declare(strict_types=1);

namespace App\Domain\AssessmentResults;

use JsonException;
use UnexpectedValueException;

/**
 * F2 lane G7 (2026-09-20), mirrors app/Domain/AssessmentResults/SealedIstResult.php.
 *
 * Field-semantics mapping for PAPI (documented in full, with the cross-lane
 * rule that G7 must never compare `standardScore` across instruments, at
 * tasks/handoffs/f2/generic-instrument-result-field-mapping.md), derived
 * entirely from already-computed values in
 * app/Services/Scoring/PapiLevelCalculator.php — nothing here invents a new
 * psychometric value:
 * - `code` = dimension letter (e.g. "W", "G", "N")
 * - `rawScore` = raw forced-choice count for the dimension, 0-9
 * - `standardScore` = distance from the white (optimal) zone
 * - `sourceScore` = the same raw forced-choice count (PAPI has no separate
 *   standard-score lookup the way IST does; `sourceScore` and `rawScore`
 *   are deliberately identical for this instrument)
 * - `level` = the psychologist-approved distance-to-level mapping, 1-5
 * - `category` = the dimension's fixed type, "ROLE" or "NEED"
 * - `band` = the white zone [lo, hi] for the dimension
 *
 * All twenty dimensions are persisted, including G, I, X, and Z: PAPI HPP
 * exclusion for those four happens by their absence from
 * database/seeders/data/aspect_sources.json, not by omitting them here —
 * SCORING_ALGORITHM.md ("Dimensi G, I, X, dan Z tetap dihitung dan terlihat
 * oleh psikolog, tetapi tidak masuk agregasi HPP") requires they stay
 * visible.
 */
final readonly class SealedPapiResult
{
    // ADR-0032 PR1 (2026-09-22): bumped v1->v2, payload gained `engineVersion`.
    public const CONTRACT_VERSION = 'papi-result:v2';

    /**
     * The scoring CODE version -- see SealedIstResult::ENGINE_VERSION's
     * docblock. PAPI's own scoring rules (ScoreSealedPapiAnswerSet,
     * PapiRawScoreCalculator) are untouched by ADR-0032 (Lead, 2026-09-22:
     * do not touch either file) -- this constant exists only so every row
     * in `generic_instrument_results` (NOT NULL `engine_version`) carries a
     * provenance value, not because PAPI's formula changed.
     */
    public const ENGINE_VERSION = 'papi-scoring:v1';

    public const DIMENSION_COUNT = 20;

    /**
     * @param  array{id:int,code:string,version:string,sourceFile:string,checksum:string}  $scoringSource
     * @param list<array{
     *     code:string,
     *     rawScore:int,
     *     standardScore:int,
     *     sourceScore:int,
     *     level:int,
     *     category:string,
     *     band:array{lo:int|null,hi:int|null}
     * }> $dimensions
     * @param  array<string,mixed>  $sessionDefinition
     */
    private function __construct(
        public string $resultContractVersion,
        public string $engineVersion,
        public int $assessmentCaseId,
        public int $sessionId,
        public int $participantId,
        public string $sessionPublicId,
        public int $attemptNo,
        public string $submittedAt,
        public int $answersRevision,
        public string $sealedSourceChecksum,
        public array $sessionDefinition,
        public array $scoringSource,
        public array $dimensions,
        public string $resultChecksum,
        private string $canonicalJson,
    ) {}

    /**
     * @param  array{id:int,code:string,version:string,sourceFile:string,checksum:string}  $scoringSource
     * @param list<array{
     *     code:string,
     *     rawScore:int,
     *     standardScore:int,
     *     sourceScore:int,
     *     level:int,
     *     category:string,
     *     band:array{lo:int|null,hi:int|null}
     * }> $dimensions
     */
    public static function seal(
        SealedGenericAnswerSet $source,
        array $scoringSource,
        array $dimensions,
    ): self {
        self::assertScoringSource($scoringSource);
        self::assertDimensions($dimensions);

        $payload = self::canonicalize([
            'resultContractVersion' => self::CONTRACT_VERSION,
            'engineVersion' => self::ENGINE_VERSION,
            'assessmentCaseId' => $source->assessmentCaseId,
            'sessionId' => $source->sessionId,
            'participantId' => $source->participantId,
            'sessionPublicId' => $source->sessionPublicId,
            'attemptNo' => $source->attemptNo,
            'submittedAt' => $source->submittedAt,
            'answersRevision' => $source->answersRevision,
            'sealedSourceChecksum' => $source->sourceChecksum,
            'sessionDefinition' => $source->definition->toArray(),
            'scoringSource' => $scoringSource,
            'dimensions' => $dimensions,
        ]);
        $payloadJson = self::encode($payload);
        $resultChecksum = hash('sha256', 'sealed-papi-result:v1|'.$payloadJson);
        $canonicalJson = self::encode(self::canonicalize([
            ...$payload,
            'resultChecksum' => $resultChecksum,
        ]));

        return new self(
            resultContractVersion: self::CONTRACT_VERSION,
            engineVersion: self::ENGINE_VERSION,
            assessmentCaseId: $source->assessmentCaseId,
            sessionId: $source->sessionId,
            participantId: $source->participantId,
            sessionPublicId: $source->sessionPublicId,
            attemptNo: $source->attemptNo,
            submittedAt: $source->submittedAt,
            answersRevision: $source->answersRevision,
            sealedSourceChecksum: $source->sourceChecksum,
            sessionDefinition: $source->definition->toArray(),
            scoringSource: $scoringSource,
            dimensions: $dimensions,
            resultChecksum: $resultChecksum,
            canonicalJson: $canonicalJson,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'resultContractVersion' => $this->resultContractVersion,
            'engineVersion' => $this->engineVersion,
            'assessmentCaseId' => $this->assessmentCaseId,
            'sessionId' => $this->sessionId,
            'participantId' => $this->participantId,
            'sessionPublicId' => $this->sessionPublicId,
            'attemptNo' => $this->attemptNo,
            'submittedAt' => $this->submittedAt,
            'answersRevision' => $this->answersRevision,
            'sealedSourceChecksum' => $this->sealedSourceChecksum,
            'sessionDefinition' => $this->sessionDefinition,
            'scoringSource' => $this->scoringSource,
            'dimensions' => $this->dimensions,
            'resultChecksum' => $this->resultChecksum,
        ];
    }

    public function canonicalJson(): string
    {
        return $this->canonicalJson;
    }

    /** @param array<string,mixed> $source */
    private static function assertScoringSource(array $source): void
    {
        if (array_keys($source) !== ['id', 'code', 'version', 'sourceFile', 'checksum']
            || ! is_int($source['id']) || $source['id'] < 1
            || $source['code'] !== 'papi'
            || ! self::canonicalIdentity($source['version'])
            || ! self::canonicalIdentity($source['sourceFile'])
            || preg_match('/\A[a-f0-9]{64}\z/', $source['checksum']) !== 1) {
            throw self::invalid();
        }
    }

    /** @param array<mixed> $dimensions */
    private static function assertDimensions(array $dimensions): void
    {
        if (! array_is_list($dimensions) || count($dimensions) !== self::DIMENSION_COUNT) {
            throw self::invalid();
        }

        $seen = [];
        foreach ($dimensions as $dimension) {
            if (! is_array($dimension)
                || array_keys($dimension) !== [
                    'code', 'rawScore', 'standardScore', 'sourceScore', 'level', 'category', 'band',
                ]
                || ! self::canonicalIdentity($dimension['code'])
                || isset($seen[$dimension['code']])
                || ! is_int($dimension['rawScore']) || $dimension['rawScore'] < 0 || $dimension['rawScore'] > 9
                || ! is_int($dimension['standardScore']) || $dimension['standardScore'] < 0
                || ! is_int($dimension['sourceScore']) || $dimension['sourceScore'] !== $dimension['rawScore']
                || ! is_int($dimension['level']) || $dimension['level'] < 1 || $dimension['level'] > 5
                || ! is_string($dimension['category']) || ! in_array($dimension['category'], ['ROLE', 'NEED'], true)
                || ! self::validBand($dimension['band'])) {
                throw self::invalid();
            }
            $seen[$dimension['code']] = true;
        }
    }

    private static function validBand(mixed $band): bool
    {
        return is_array($band)
            && array_keys($band) === ['lo', 'hi']
            && (is_int($band['lo']) || $band['lo'] === null)
            && (is_int($band['hi']) || $band['hi'] === null)
            && ($band['lo'] !== null || $band['hi'] !== null)
            && (! is_int($band['lo']) || ! is_int($band['hi']) || $band['lo'] <= $band['hi']);
    }

    private static function canonicalIdentity(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && $value === trim($value)
            && preg_match('/[\p{C}\p{Z}\s]/u', $value) === 0;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = self::canonicalize($entry);
        }

        return $value;
    }

    private static function encode(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            throw self::invalid();
        }
    }

    private static function invalid(): UnexpectedValueException
    {
        return new UnexpectedValueException('SEALED_PAPI_RESULT_INVALID');
    }
}
