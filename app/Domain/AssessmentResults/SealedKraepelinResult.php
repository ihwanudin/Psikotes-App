<?php

declare(strict_types=1);

namespace App\Domain\AssessmentResults;

use JsonException;
use UnexpectedValueException;

/**
 * F2 lane G7 (2026-09-20), mirrors app/Domain/AssessmentResults/SealedIstResult.php
 * in shape, but NOT in provenance — read this before touching it.
 *
 * KNOWN GAP, INTENTIONAL: there is no code anywhere in this repository that
 * grades a participant's raw Kraepelin digit answers (50 columns) into
 * {achievement, correct, incorrect, skipped} per column — the input
 * app/Services/Scoring/KraepelinFactorCalculator.php actually requires. That
 * derivation needs the exact seeded number-generation algorithm, and the
 * Kraepelin authority pack (tasks/handoffs/authority-pack/kraepelin.md) is
 * BLOCKED 0/4: the seeded-versus-fixed generator algorithm has no
 * psychologist approval. Building that derivation here would mean inventing
 * instrument generation/grading logic without authority — explicitly
 * forbidden. This class does NOT do that.
 *
 * This class instead seals FOUR ALREADY-COMPUTED factors (Panker, Tianker,
 * Hanker, Janker) — the output of a caller that has already run
 * KraepelinFactorCalculator::calculate() and KraepelinBandMapper::map()
 * itself. It is the ledger-write half of Kraepelin G7 only. Whoever builds
 * the raw-digit-grading step in the future must call
 * KraepelinFactorCalculator then KraepelinBandMapper, then hand the four
 * results to app/Services/AssessmentResults/SealPrecomputedKraepelinFactors.php
 * (not build a competing seal/persist path).
 *
 * Field-semantics mapping (tasks/handoffs/f2/generic-instrument-result-field-mapping.md):
 * - `code` = factor name, uppercase ("PANKER","TIANKER","HANKER","JANKER")
 * - `rawScore` = KraepelinBandMapper's `raw_factor` (the exact value that was
 *   classified — int|float; Panker/Hanker are fractional by design)
 * - `standardScore` = deliberately IDENTICAL to `sourceScore` (Lead-approved
 *   2026-09-20): Kraepelin has no second native metric distinct from the
 *   Sheet-style band score, unlike PAPI's `distance`. Duplicating the
 *   fractional `rawScore` here was rejected because it would silently
 *   truncate Panker/Hanker in the (deliberately un-widened) integer column —
 *   see the migration and field-mapping doc.
 * - `sourceScore` = KraepelinBandMapper's `source_score` (native integer,
 *   1-10)
 * - `level`, `category` = KraepelinBandMapper's `level`/`category`
 * - `band` = KraepelinBandMapper's `band` (lo/hi; NOT degenerate for
 *   Kraepelin — these are real norm-table boundaries, may be fractional and
 *   may be null at an open domain edge)
 */
final readonly class SealedKraepelinResult
{
    public const CONTRACT_VERSION = 'kraepelin-result:v1';

    /** @var list<string> */
    private const FACTOR_CODES = ['PANKER', 'TIANKER', 'HANKER', 'JANKER'];

    /**
     * @param  array{id:int,code:string,version:string,sourceFile:string,checksum:string}  $scoringSource
     * @param list<array{
     *     code:string,
     *     rawScore:int|float,
     *     standardScore:int,
     *     sourceScore:int,
     *     level:int,
     *     category:string,
     *     band:array{lo:int|float|null,hi:int|float|null}
     * }> $factors
     * @param  array<string,mixed>  $sessionDefinition
     */
    private function __construct(
        public string $resultContractVersion,
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
        public string $normGroup,
        public array $factors,
        public string $resultChecksum,
        private string $canonicalJson,
    ) {}

    /**
     * @param array{
     *     assessmentCaseId:int, sessionId:int, participantId:int, sessionPublicId:string,
     *     attemptNo:int, submittedAt:string, answersRevision:int, sessionDefinition:array<string,mixed>
     * } $identity Explicit, not a SealedGenericAnswerSet: no raw Kraepelin answer set has ever
     *     been sealed anywhere in this codebase (LoadSealedGenericAnswerSet does not support
     *     Kraepelin), so wrapping one here would falsely imply raw answers were verified.
     * @param  array{id:int,code:string,version:string,sourceFile:string,checksum:string}  $scoringSource
     * @param list<array{
     *     code:string,
     *     rawScore:int|float,
     *     standardScore:int,
     *     sourceScore:int,
     *     level:int,
     *     category:string,
     *     band:array{lo:int|float|null,hi:int|float|null}
     * }> $factors
     */
    public static function seal(
        array $identity,
        array $scoringSource,
        string $normGroup,
        array $factors,
        string $sealedSourceChecksum,
    ): self {
        self::assertIdentity($identity);
        self::assertScoringSource($scoringSource);
        self::assertNormGroup($normGroup);
        self::assertFactors($factors);
        self::assertChecksumShape($sealedSourceChecksum);

        $payload = self::canonicalize([
            'resultContractVersion' => self::CONTRACT_VERSION,
            'assessmentCaseId' => $identity['assessmentCaseId'],
            'sessionId' => $identity['sessionId'],
            'participantId' => $identity['participantId'],
            'sessionPublicId' => $identity['sessionPublicId'],
            'attemptNo' => $identity['attemptNo'],
            'submittedAt' => $identity['submittedAt'],
            'answersRevision' => $identity['answersRevision'],
            'sealedSourceChecksum' => $sealedSourceChecksum,
            'sessionDefinition' => $identity['sessionDefinition'],
            'scoringSource' => $scoringSource,
            'normGroup' => $normGroup,
            'factors' => $factors,
        ]);
        $payloadJson = self::encode($payload);
        $resultChecksum = hash('sha256', 'sealed-kraepelin-result:v1|'.$payloadJson);
        $canonicalJson = self::encode(self::canonicalize([
            ...$payload,
            'resultChecksum' => $resultChecksum,
        ]));

        return new self(
            resultContractVersion: self::CONTRACT_VERSION,
            assessmentCaseId: $identity['assessmentCaseId'],
            sessionId: $identity['sessionId'],
            participantId: $identity['participantId'],
            sessionPublicId: $identity['sessionPublicId'],
            attemptNo: $identity['attemptNo'],
            submittedAt: $identity['submittedAt'],
            answersRevision: $identity['answersRevision'],
            sealedSourceChecksum: $sealedSourceChecksum,
            sessionDefinition: $identity['sessionDefinition'],
            scoringSource: $scoringSource,
            normGroup: $normGroup,
            factors: $factors,
            resultChecksum: $resultChecksum,
            canonicalJson: $canonicalJson,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'resultContractVersion' => $this->resultContractVersion,
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
            'normGroup' => $this->normGroup,
            'factors' => $this->factors,
            'resultChecksum' => $this->resultChecksum,
        ];
    }

    public function canonicalJson(): string
    {
        return $this->canonicalJson;
    }

    /**
     * Deliberately typed as a plain associative array, not the exact
     * `seal()` shape: this method exists to verify the shape at runtime, so
     * its own parameter type must not assume the shape is already certain
     * (PHPStan would otherwise treat the array_keys() check below as
     * statically unreachable and flag it — correctly, for the PHPDoc type,
     * but wrongly for what this method is actually for: not trusting the
     * caller).
     *
     * @param  array<string,mixed>  $identity
     */
    private static function assertIdentity(array $identity): void
    {
        if (array_keys($identity) !== [
            'assessmentCaseId', 'sessionId', 'participantId', 'sessionPublicId',
            'attemptNo', 'submittedAt', 'answersRevision', 'sessionDefinition',
        ]
            || ! is_int($identity['assessmentCaseId']) || $identity['assessmentCaseId'] < 1
            || ! is_int($identity['sessionId']) || $identity['sessionId'] < 1
            || ! is_int($identity['participantId']) || $identity['participantId'] < 1
            || ! self::canonicalIdentity($identity['sessionPublicId'])
            || ! is_int($identity['attemptNo']) || $identity['attemptNo'] < 1
            || ! is_string($identity['submittedAt']) || $identity['submittedAt'] === ''
            || ! is_int($identity['answersRevision']) || $identity['answersRevision'] < 1
            || ! is_array($identity['sessionDefinition']) || $identity['sessionDefinition'] === []) {
            throw self::invalid();
        }
    }

    /** @param array<string,mixed> $source */
    private static function assertScoringSource(array $source): void
    {
        if (array_keys($source) !== ['id', 'code', 'version', 'sourceFile', 'checksum']
            || ! is_int($source['id']) || $source['id'] < 1
            || $source['code'] !== 'kraepelin'
            || ! self::canonicalIdentity($source['version'])
            || ! self::canonicalIdentity($source['sourceFile'])
            || preg_match('/\A[a-f0-9]{64}\z/', $source['checksum']) !== 1) {
            throw self::invalid();
        }
    }

    private static function assertNormGroup(string $normGroup): void
    {
        // Deliberately more permissive than canonicalIdentity(): norm group
        // names are human-readable labels from kraepelin.json's score_bands,
        // e.g. "S1/S2 (IPA)" or "D3 (IPS) Laki-laki" — internal spaces are
        // legitimate. Still reject empty, untrimmed, or control-character
        // input, which are never valid regardless of format.
        if ($normGroup === ''
            || $normGroup !== trim($normGroup)
            || preg_match('/[\p{C}]/u', $normGroup) !== 0) {
            throw self::invalid();
        }
    }

    private static function assertChecksumShape(string $checksum): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1) {
            throw self::invalid();
        }
    }

    /** @param array<mixed> $factors */
    private static function assertFactors(array $factors): void
    {
        if (! array_is_list($factors) || count($factors) !== count(self::FACTOR_CODES)) {
            throw self::invalid();
        }

        $seen = [];
        foreach ($factors as $factor) {
            if (! is_array($factor)
                || array_keys($factor) !== [
                    'code', 'rawScore', 'standardScore', 'sourceScore', 'level', 'category', 'band',
                ]
                || ! in_array($factor['code'], self::FACTOR_CODES, true)
                || isset($seen[$factor['code']])
                || (! is_int($factor['rawScore']) && ! is_float($factor['rawScore']))
                || ! is_finite((float) $factor['rawScore'])
                || (float) $factor['rawScore'] !== round((float) $factor['rawScore'], 3)
                // Only Hanker's norm bands ever go negative
                // (database/seeders/data/kraepelin.json; see
                // tasks/handoffs/f2/generic-instrument-result-field-mapping.md).
                // Panker/Tianker/Janker are never negative in any configured
                // group, so a negative value for them is rejected here, not
                // merely left to the database — the ledger's
                // `raw_score >= 0` check was dropped globally only because
                // Hanker needs it (Lead-approved 2026-09-20); this class is
                // where the per-instrument (here, per-factor) guard now lives.
                || ($factor['code'] !== 'HANKER' && (float) $factor['rawScore'] < 0)
                || ! is_int($factor['standardScore']) || $factor['standardScore'] < 1 || $factor['standardScore'] > 10
                || ! is_int($factor['sourceScore']) || $factor['sourceScore'] !== $factor['standardScore']
                || ! is_int($factor['level']) || $factor['level'] < 1 || $factor['level'] > 5
                || ! is_string($factor['category']) || trim($factor['category']) === ''
                || ! self::validBand($factor['band'])) {
                throw self::invalid();
            }
            $seen[$factor['code']] = true;
        }
        if (array_keys($seen) !== self::FACTOR_CODES) {
            // Every one of the four factors must be present, in this exact order.
            throw self::invalid();
        }
    }

    private static function validBand(mixed $band): bool
    {
        if (! is_array($band) || array_keys($band) !== ['lo', 'hi']) {
            return false;
        }
        foreach (['lo', 'hi'] as $edge) {
            $value = $band[$edge];
            if ($value === null) {
                continue;
            }
            if ((! is_int($value) && ! is_float($value))
                || ! is_finite((float) $value)
                || (float) $value !== round((float) $value, 3)) {
                return false;
            }
        }
        if ($band['lo'] === null && $band['hi'] === null) {
            return false;
        }

        return $band['lo'] === null || $band['hi'] === null || (float) $band['lo'] <= (float) $band['hi'];
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
        return new UnexpectedValueException('SEALED_KRAEPELIN_RESULT_INVALID');
    }
}
