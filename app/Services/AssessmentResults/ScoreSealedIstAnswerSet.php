<?php

declare(strict_types=1);

namespace App\Services\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedIstResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use App\Services\Scoring\IstIqCalculator;
use App\Services\Scoring\IstIqLevelCalculator;
use App\Services\Scoring\IstRawScoreCalculator;
use App\Services\Scoring\IstStandardScoreCalculator;
use App\Services\Scoring\IstSwLevelCalculator;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use Throwable;
use UnexpectedValueException;

final readonly class ScoreSealedIstAnswerSet
{
    private const PAYLOAD_FIELDS = [
        'version',
        'keys',
        'ge_dictionary',
        'norms',
        'iq_ranges',
        'sw_score_bands',
        'iq_score_bands',
        'iq_level_bands',
        'match_strategy',
    ];

    public function __construct(private RlsContextRunner $contexts) {}

    public function execute(SealedGenericAnswerSet $source, int $instrumentVersionId): SealedIstResult
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('SEALED_IST_RESULT_CONTEXT_REQUIRED');
        }
        if ($source->instrument !== GenericAssessmentInstrument::Ist) {
            throw new UnexpectedValueException('SEALED_IST_RESULT_INSTRUMENT_UNSUPPORTED');
        }
        if ($instrumentVersionId < 1) {
            throw self::invalid();
        }

        $row = DB::table('instrument_versions')
            ->where('id', $instrumentVersionId)
            ->select(['id', 'code', 'version', 'source_file', 'checksum', 'payload'])
            ->first();
        if ($row === null) {
            throw self::invalid();
        }

        try {
            $data = $this->scoringData($row);
            $responses = $this->responses($source, $data);
            $rawScores = (new IstRawScoreCalculator($data['keys'], $data['ge_dictionary']))
                ->calculate($responses);
            $standardScores = (new IstStandardScoreCalculator($data['norms']))
                ->calculate($rawScores);
            $swLevels = new IstSwLevelCalculator($data['sw_score_bands']);
            $subtests = [];
            foreach ($source->definition->subtests as $definition) {
                $code = $definition['code'];
                $level = $swLevels->calculate($standardScores[$code]);
                $subtests[] = [
                    'code' => $code,
                    'rawScore' => $rawScores[$code],
                    'standardScore' => $level['standard_score'],
                    'sourceScore' => $level['source_score'],
                    'level' => $level['level'],
                    'category' => $level['category'],
                    'band' => $level['band'],
                ];
            }

            $iq = (new IstIqCalculator($data['iq_ranges'], $data['norms']))->calculate($rawScores);
            $iqLevel = (new IstIqLevelCalculator($data['iq_level_bands']))->calculate($iq);

            return SealedIstResult::seal(
                source: $source,
                scoringSource: [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'version' => (string) $row->version,
                    'sourceFile' => (string) $row->source_file,
                    'checksum' => (string) $row->checksum,
                ],
                subtests: $subtests,
                iq: [
                    'rawTotal' => array_sum($rawScores),
                    'iq' => $iqLevel['iq'],
                    'level' => $iqLevel['level'],
                    'sourceScores' => $iqLevel['source_scores'],
                    'category' => $iqLevel['category'],
                    'band' => $iqLevel['band'],
                ],
            );
        } catch (UnexpectedValueException $exception) {
            if ($exception->getMessage() === 'SEALED_IST_RESULT_INVALID') {
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
        $payload = $row->payload ?? null;

        if ((int) ($row->id ?? 0) < 1
            || ($row->code ?? null) !== 'ist'
            || ! self::canonicalIdentity($version)
            || ! self::canonicalIdentity($row->source_file ?? null)
            || ! is_string($row->checksum ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $row->checksum) !== 1
            || ! is_string($payload)) {
            throw self::invalid();
        }
        if (! hash_equals($row->checksum, hash('sha256', $payload))) {
            throw self::invalid();
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw self::invalid();
        }
        if (! is_array($data) || ! self::hasExactFields($data, self::PAYLOAD_FIELDS)
            || ($data['version'] ?? null) !== $version) {
            throw self::invalid();
        }
        foreach (['keys', 'ge_dictionary', 'norms', 'iq_ranges', 'sw_score_bands', 'iq_level_bands'] as $field) {
            if (! is_array($data[$field])) {
                throw self::invalid();
            }
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<array{subtest:string,item:int,answer:string}>
     */
    private function responses(SealedGenericAnswerSet $source, array $data): array
    {
        $itemCounts = [];
        $items = [];
        foreach ($data['keys'] as $key) {
            if (! is_array($key)
                || ! is_string($key['subtest'] ?? null)
                || $key['subtest'] === 'GE'
                || ! is_int($key['item'] ?? null)
                || $key['item'] < 1) {
                throw self::invalid();
            }
            $items[$key['subtest']][] = $key['item'];
        }
        foreach ($data['ge_dictionary'] as $definition) {
            if (! is_array($definition) || ! is_int($definition['item'] ?? null) || $definition['item'] < 1) {
                throw self::invalid();
            }
            $items['GE'][] = $definition['item'];
        }
        foreach ($items as $code => $subtestItems) {
            sort($subtestItems);
            if ($subtestItems !== range(1, count($subtestItems))) {
                throw self::invalid();
            }
            $itemCounts[$code] = count($subtestItems);
        }

        $definitionCodes = array_column($source->definition->subtests, 'code');
        $expectedCodes = array_keys($itemCounts);
        sort($definitionCodes);
        sort($expectedCodes);
        if ($definitionCodes !== $expectedCodes || count($source->answers) !== array_sum($itemCounts)) {
            throw self::invalid();
        }

        $responses = [];
        $globalItem = 0;
        foreach ($source->definition->subtests as $subtest) {
            $code = $subtest['code'];
            if (($itemCounts[$code] ?? null) !== $subtest['item_count']) {
                throw self::invalid();
            }
            for ($item = 1; $item <= $subtest['item_count']; $item++) {
                $answer = $source->answers[$globalItem] ?? null;
                $globalItem++;
                if (! is_array($answer)
                    || ($answer['item_no'] ?? null) !== $globalItem
                    || ! is_string($answer['value'] ?? null)) {
                    throw self::invalid();
                }
                $responses[] = ['subtest' => $code, 'item' => $item, 'answer' => $answer['value']];
            }
        }

        return $responses;
    }

    /**
     * @param  array<string,mixed>  $value
     * @param  list<string>  $fields
     */
    private static function hasExactFields(array $value, array $fields): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($fields);

        return $actual === $fields;
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
        return new UnexpectedValueException('SEALED_IST_RESULT_INVALID');
    }
}
