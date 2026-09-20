<?php

declare(strict_types=1);

namespace App\Services\AssessmentResults;

use App\Domain\AssessmentResults\SealedKraepelinResult;
use App\Security\RlsContextRunner;
use App\Services\Scoring\KraepelinBandMapper;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use Throwable;
use UnexpectedValueException;

/**
 * F2 lane G7 (2026-09-20). See app/Domain/AssessmentResults/SealedKraepelinResult.php
 * for why this class is named the way it is and not
 * "ScoreSealedKraepelinAnswerSet": it does not score raw answers. It seals
 * FOUR ALREADY-COMPUTED factors — the caller must have already run
 * KraepelinFactorCalculator::calculate() on real graded column data (which
 * this repository has no way to produce from raw digit answers; see the
 * class docblock referenced above) — into an immutable, checksum-verified
 * SealedKraepelinResult ready for PersistSealedKraepelinResult.
 *
 * This class does NOT trust its caller. Every value is re-validated against
 * the versioned Kraepelin authority (`instrument_versions`, code=kraepelin)
 * exactly like every other scorer in this lane, via KraepelinBandMapper,
 * which independently classifies each factor against the approved norm
 * bands — a caller cannot supply an arbitrary level/category/band and have
 * it accepted; those are always derived here, never taken from the caller.
 */
final readonly class SealPrecomputedKraepelinFactors
{
    private const PAYLOAD_FIELDS = ['version', 'hanker_formula', 'panker_achievement', 'factor_rounding', 'score_bands'];

    /** @var array<string,string> Caller-facing lowercase factor key -> BandMapper's expected Titlecase key. */
    private const FACTOR_KEY_MAP = [
        'panker' => 'Panker', 'tianker' => 'Tianker', 'hanker' => 'Hanker', 'janker' => 'Janker',
    ];

    public function __construct(private RlsContextRunner $contexts) {}

    /**
     * @param array{
     *     assessmentCaseId:int, sessionId:int, participantId:int, sessionPublicId:string,
     *     attemptNo:int, submittedAt:string, answersRevision:int, sessionDefinition:array<string,mixed>
     * } $identity
     * @param  array{panker:int|float, tianker:int|float, hanker:int|float, janker:int|float}  $factors
     *                                                                                                   The exact output shape of KraepelinFactorCalculator::calculate() — the caller must have
     *                                                                                                   already run that calculator (and only that calculator) on real graded column data.
     */
    public function execute(
        array $identity,
        int $instrumentVersionId,
        string $normGroup,
        array $factors,
    ): SealedKraepelinResult {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('SEALED_KRAEPELIN_RESULT_CONTEXT_REQUIRED');
        }
        if ($instrumentVersionId < 1) {
            throw self::invalid();
        }

        $row = DB::table('instrument_versions')
            ->where('id', $instrumentVersionId)
            ->select(['id', 'code', 'version', 'source_file', 'checksum', 'source_text'])
            ->first();
        if ($row === null) {
            throw self::invalid();
        }

        try {
            $data = $this->scoringData($row);
            $roundedFactors = $this->assertAndRoundFactors($factors, (int) $data['factor_rounding']['precision']);

            $mapper = new KraepelinBandMapper(
                $data['score_bands'],
                [], // No group_fallbacks are present in the current canonical payload;
                // see tasks/handoffs/f2/generic-instrument-result-field-mapping.md.
                $data['factor_rounding'],
            );
            $mapped = $mapper->map($roundedFactors, $normGroup);

            $factorRows = [];
            foreach (['panker', 'tianker', 'hanker', 'janker'] as $lower) {
                $titlecase = self::FACTOR_KEY_MAP[$lower];
                $entry = $mapped['factors'][$titlecase];
                $factorRows[] = [
                    'code' => strtoupper($lower),
                    'rawScore' => $entry['raw_factor'],
                    'standardScore' => $entry['source_score'],
                    'sourceScore' => $entry['source_score'],
                    'level' => $entry['level'],
                    'category' => $entry['category'],
                    'band' => ['lo' => $entry['band']['lo'], 'hi' => $entry['band']['hi']],
                ];
            }

            $sealedSourceChecksum = $this->sealedSourceChecksum($identity, $normGroup, $roundedFactors);

            return SealedKraepelinResult::seal(
                identity: $identity,
                scoringSource: [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'version' => (string) $row->version,
                    'sourceFile' => (string) $row->source_file,
                    'checksum' => (string) $row->checksum,
                ],
                normGroup: $normGroup,
                factors: $factorRows,
                sealedSourceChecksum: $sealedSourceChecksum,
            );
        } catch (UnexpectedValueException $exception) {
            if ($exception->getMessage() === 'SEALED_KRAEPELIN_RESULT_INVALID') {
                throw $exception;
            }

            throw self::invalid();
        } catch (Throwable) {
            throw self::invalid();
        }
    }

    /** @return array<string,mixed> */
    private function scoringData(object $row): array
    {
        $version = $row->version ?? null;
        // See ScoreSealedIstAnswerSet for why `source_text`, never `payload`,
        // with no fallback: PostgreSQL jsonb normalization on write means
        // `payload` can never be hashed back to `checksum`.
        $sourceText = $row->source_text ?? null;

        if ((int) ($row->id ?? 0) < 1
            || ($row->code ?? null) !== 'kraepelin'
            || ! self::canonicalIdentity($version)
            || ! self::canonicalIdentity($row->source_file ?? null)
            || ! is_string($row->checksum ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $row->checksum) !== 1
            || ! is_string($sourceText)
            || $sourceText === '') {
            throw self::invalid();
        }
        if (! hash_equals($row->checksum, hash('sha256', $sourceText))) {
            throw self::invalid();
        }

        try {
            $data = json_decode($sourceText, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw self::invalid();
        }
        if (! is_array($data) || ! self::hasExactFields($data, self::PAYLOAD_FIELDS)
            || ($data['version'] ?? null) !== $version) {
            throw self::invalid();
        }
        if (! is_array($data['score_bands']) || ! is_array($data['factor_rounding'])
            || ! isset($data['factor_rounding']['precision']) || ! is_int($data['factor_rounding']['precision'])
            || $data['factor_rounding']['precision'] < 0 || $data['factor_rounding']['precision'] > 6) {
            throw self::invalid();
        }

        return $data;
    }

    /**
     * Deliberately typed as a plain associative array, not the exact
     * `execute()` shape — see SealedKraepelinResult::assertIdentity()'s
     * docblock for why: this method exists to verify the shape at runtime,
     * so its parameter type must not assume the shape is already certain.
     *
     * @param  array<string,mixed>  $factors
     * @return array{Panker:int|float, Tianker:int|float, Hanker:int|float, Janker:int|float}
     */
    private function assertAndRoundFactors(array $factors, int $precision): array
    {
        if (array_keys($factors) !== ['panker', 'tianker', 'hanker', 'janker']) {
            throw self::invalid();
        }

        $result = [];
        foreach (self::FACTOR_KEY_MAP as $lower => $titlecase) {
            $value = $factors[$lower];
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw self::invalid();
            }
            // The caller must supply an already-rounded value — silently
            // re-rounding here would hide a caller bug rather than reject it
            // (Lead-required 2026-09-20).
            if ((float) $value !== round((float) $value, $precision)) {
                throw self::invalid();
            }
            $result[$titlecase] = $value;
        }

        return $result;
    }

    /**
     * @param array{
     *     assessmentCaseId:int, sessionId:int, participantId:int, sessionPublicId:string,
     *     attemptNo:int, submittedAt:string, answersRevision:int, sessionDefinition:array<string,mixed>
     * } $identity
     * @param  array{Panker:int|float, Tianker:int|float, Hanker:int|float, Janker:int|float}  $roundedFactors
     */
    private function sealedSourceChecksum(array $identity, string $normGroup, array $roundedFactors): string
    {
        try {
            $json = json_encode(
                ['identity' => $identity, 'normGroup' => $normGroup, 'factors' => $roundedFactors],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            throw self::invalid();
        }

        return hash('sha256', 'sealed-precomputed-kraepelin-factors:v1|'.$json);
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expectedFields
     */
    private function hasExactFields(array $value, array $expectedFields): bool
    {
        $actual = array_keys($value);
        sort($actual);
        $expected = $expectedFields;
        sort($expected);

        return $actual === $expected;
    }

    private static function canonicalIdentity(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && $value === trim($value)
            && preg_match('/[\p{C}\p{Z}\s]/u', $value) === 0;
    }

    private static function invalid(): UnexpectedValueException
    {
        return new UnexpectedValueException('SEALED_KRAEPELIN_RESULT_INVALID');
    }
}
