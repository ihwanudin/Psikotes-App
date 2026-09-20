<?php

declare(strict_types=1);

namespace App\Services\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedPapiResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use App\Services\Scoring\PapiLevelCalculator;
use App\Services\Scoring\PapiRawScoreCalculator;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use Throwable;
use UnexpectedValueException;

/**
 * F2 lane G7 (2026-09-20), mirrors
 * app/Services/AssessmentResults/ScoreSealedIstAnswerSet.php. Reads
 * `source_text` (never `payload`, no fallback) for the same jsonb-checksum
 * reason documented there and fixed in the F2 lane's 3a increment.
 */
final readonly class ScoreSealedPapiAnswerSet
{
    // `bands`/`band_scores` (sheet 08) are present in the canonical payload
    // but are deliberately never read for HPP scoring: SCORING_ALGORITHM.md
    // keeps them as a historical/visual profile only, not a level input.
    private const PAYLOAD_FIELDS = ['version', 'mapping', 'bands', 'band_scores', 'white_zones', 'normalization'];

    private const ITEM_COUNT = 90;

    public function __construct(private RlsContextRunner $contexts) {}

    public function execute(SealedGenericAnswerSet $source, int $instrumentVersionId): SealedPapiResult
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('SEALED_PAPI_RESULT_CONTEXT_REQUIRED');
        }
        if ($source->instrument !== GenericAssessmentInstrument::Papi) {
            throw new UnexpectedValueException('SEALED_PAPI_RESULT_INSTRUMENT_UNSUPPORTED');
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
            $responses = $this->responses($source, $data);
            $rawResult = (new PapiRawScoreCalculator($data['mapping']))->calculate($responses);
            $levelCalculator = new PapiLevelCalculator($data['white_zones'], $data['normalization']);

            $dimensionCodes = array_keys($rawResult['dimensions']);
            sort($dimensionCodes, SORT_STRING);

            $dimensions = [];
            foreach ($dimensionCodes as $code) {
                $rawScore = $rawResult['dimensions'][$code]['raw_score'];
                $level = $levelCalculator->calculate($code, $rawScore);
                $dimensions[] = [
                    'code' => $code,
                    'rawScore' => $rawScore,
                    'standardScore' => $level['distance'],
                    'sourceScore' => $rawScore,
                    'level' => $level['level'],
                    'category' => $rawResult['dimensions'][$code]['type'],
                    'band' => ['lo' => $level['white_zone'][0], 'hi' => $level['white_zone'][1]],
                ];
            }

            return SealedPapiResult::seal(
                source: $source,
                scoringSource: [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'version' => (string) $row->version,
                    'sourceFile' => (string) $row->source_file,
                    'checksum' => (string) $row->checksum,
                ],
                dimensions: $dimensions,
            );
        } catch (UnexpectedValueException $exception) {
            if ($exception->getMessage() === 'SEALED_PAPI_RESULT_INVALID') {
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
            || ($row->code ?? null) !== 'papi'
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
        foreach (['mapping', 'white_zones', 'normalization'] as $field) {
            if (! is_array($data[$field])) {
                throw self::invalid();
            }
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<array{item:int,choice:string}>
     */
    private function responses(SealedGenericAnswerSet $source, array $data): array
    {
        $mappingItems = [];
        foreach ($data['mapping'] as $definition) {
            if (! is_array($definition) || ! is_int($definition['item'] ?? null) || $definition['item'] < 1) {
                throw self::invalid();
            }
            $mappingItems[] = $definition['item'];
        }
        sort($mappingItems);
        if ($mappingItems !== range(1, count($mappingItems)) || count($mappingItems) !== self::ITEM_COUNT) {
            throw self::invalid();
        }

        $totalItems = array_sum(array_column($source->definition->subtests, 'item_count'));
        if ($totalItems !== self::ITEM_COUNT || count($source->answers) !== self::ITEM_COUNT) {
            throw self::invalid();
        }

        $responses = [];
        foreach ($source->answers as $offset => $answer) {
            $itemNo = $offset + 1;
            if (! is_array($answer)
                || ($answer['item_no'] ?? null) !== $itemNo
                || ! is_string($answer['value'] ?? null)) {
                throw self::invalid();
            }
            $responses[] = ['item' => $itemNo, 'choice' => $answer['value']];
        }

        return $responses;
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
        return new UnexpectedValueException('SEALED_PAPI_RESULT_INVALID');
    }
}
