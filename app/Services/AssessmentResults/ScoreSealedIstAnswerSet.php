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
    /** ADR-0032 (2026-09-22): stand-in for "no response was recorded for this item". */
    private const UNANSWERED = '';

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
            ->select(['id', 'code', 'version', 'source_file', 'checksum', 'source_text'])
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
        // `source_text` is the byte-identical raw file content the checksum was
        // computed from. `payload` (jsonb) is intentionally never read here: it is
        // re-serialized by PostgreSQL on write and can never be hashed back to
        // `checksum`, and historical rows seeded before this column existed leave
        // it NULL rather than fabricating a value nobody can vouch for — both fail
        // closed the same way, deliberately, with no fallback to `payload`.
        $sourceText = $row->source_text ?? null;

        if ((int) ($row->id ?? 0) < 1
            || ($row->code ?? null) !== 'ist'
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
        if ($definitionCodes !== $expectedCodes || count($source->answers) > array_sum($itemCounts)) {
            throw self::invalid();
        }

        // ADR-0032 (2026-09-22): P1 -- a blank IST item scores as wrong, it
        // is no longer rejected outright. `$source->answers` can now be
        // shorter than the full item count (LoadSealedGenericAnswerSet no
        // longer requires an exact match for IST), so this can no longer
        // walk it positionally; it looks answers up by their own item_no
        // and fills any gap with the sentinel below.
        $answersByItemNo = [];
        foreach ($source->answers as $answer) {
            if (! is_array($answer) || ! is_int($answer['item_no'] ?? null) || ! is_string($answer['value'] ?? null)) {
                throw self::invalid();
            }
            $answersByItemNo[$answer['item_no']] = $answer['value'];
        }
        if (count($answersByItemNo) !== count($source->answers)) {
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
                $globalItem++;
                // '' can never equal an answer key (single letters a-e) or a
                // recorded GE answer (IstRawScoreCalculator rejects an empty
                // GE answer text at construction time), so an unanswered
                // item scores as wrong through the exact same comparison a
                // real wrong answer takes -- IstRawScoreCalculator itself
                // needs no change for this rule.
                $responses[] = [
                    'subtest' => $code,
                    'item' => $item,
                    'answer' => $answersByItemNo[$globalItem] ?? self::UNANSWERED,
                ];
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
