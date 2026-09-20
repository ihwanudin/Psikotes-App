<?php

declare(strict_types=1);

namespace App\Services\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedRmibResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use App\Services\Scoring\RmibRankLevelCalculator;
use App\Services\Scoring\RmibRawScoreCalculator;
use App\Services\Scoring\RmibScoreCalculator;
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
final readonly class ScoreSealedRmibAnswerSet
{
    private const PAYLOAD_FIELDS = ['version', 'categories', 'rotation', 'rank_to_score', 'rank_to_level', 'tie_policy'];

    private const GROUP_COUNT = 9;

    private const POSITIONS_PER_GROUP = 12;

    private const TOTAL_ITEMS = self::GROUP_COUNT * self::POSITIONS_PER_GROUP;

    public function __construct(private RlsContextRunner $contexts) {}

    public function execute(SealedGenericAnswerSet $source, int $instrumentVersionId): SealedRmibResult
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('SEALED_RMIB_RESULT_CONTEXT_REQUIRED');
        }
        if ($source->instrument !== GenericAssessmentInstrument::Rmib) {
            throw new UnexpectedValueException('SEALED_RMIB_RESULT_INSTRUMENT_UNSUPPORTED');
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
            $responses = $this->responses($source);
            $rawResult = (new RmibRawScoreCalculator($data['categories'], $this->rotation($data['rotation'])))
                ->calculate($responses);
            $scoreCalculator = new RmibScoreCalculator($data['rank_to_score']);
            $levelCalculator = new RmibRankLevelCalculator($data['rank_to_level']);

            $indexes = array_keys($rawResult['categories']);
            sort($indexes);

            $categories = [];
            foreach ($indexes as $index) {
                $category = $rawResult['categories'][$index];
                $rank = $category['rank'];
                $score = $scoreCalculator->calculate($rank);
                $level = $levelCalculator->calculate($rank);
                $categories[] = [
                    'code' => $category['code'],
                    'rawScore' => $category['total'],
                    'standardScore' => $rank,
                    'sourceScore' => $score['score'],
                    'level' => $level['level'],
                    'category' => $category['name'],
                    // Degenerate band: RMIB has no norm/zone concept. See
                    // SealedRmibResult's class docblock — this is not a range.
                    'band' => ['lo' => $rank, 'hi' => $rank],
                ];
            }

            return SealedRmibResult::seal(
                source: $source,
                scoringSource: [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'version' => (string) $row->version,
                    'sourceFile' => (string) $row->source_file,
                    'checksum' => (string) $row->checksum,
                ],
                categories: $categories,
            );
        } catch (UnexpectedValueException $exception) {
            if ($exception->getMessage() === 'SEALED_RMIB_RESULT_INVALID') {
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
            || ($row->code ?? null) !== 'rmib'
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
        foreach (['categories', 'rotation', 'rank_to_score', 'rank_to_level'] as $field) {
            if (! is_array($data[$field])) {
                throw self::invalid();
            }
        }

        return $data;
    }

    /**
     * RmibRawScoreCalculator expects a flat list of rotation cells; the
     * canonical payload stores the same list under `rotation`.
     *
     * @param  array<mixed>  $rotation
     * @return array<mixed>
     */
    private function rotation(array $rotation): array
    {
        return $rotation;
    }

    /**
     * @return list<array{group:int,position:int,rank:int}>
     */
    private function responses(SealedGenericAnswerSet $source): array
    {
        $totalItems = array_sum(array_column($source->definition->subtests, 'item_count'));
        if ($totalItems !== self::TOTAL_ITEMS
            || count($source->definition->subtests) !== self::GROUP_COUNT
            || count($source->answers) !== self::TOTAL_ITEMS) {
            throw self::invalid();
        }

        $responses = [];
        $globalItem = 0;
        foreach ($source->definition->subtests as $groupIndex => $subtest) {
            if ($subtest['item_count'] !== self::POSITIONS_PER_GROUP) {
                throw self::invalid();
            }
            for ($position = 1; $position <= self::POSITIONS_PER_GROUP; $position++) {
                $answer = $source->answers[$globalItem] ?? null;
                $globalItem++;
                if (! is_array($answer)
                    || ($answer['item_no'] ?? null) !== $globalItem
                    || ! is_string($answer['value'] ?? null)
                    || preg_match('/\A(?:[1-9]|1[0-2])\z/', $answer['value']) !== 1) {
                    throw self::invalid();
                }
                $responses[] = [
                    'group' => $groupIndex + 1,
                    'position' => $position,
                    'rank' => (int) $answer['value'],
                ];
            }
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
        return new UnexpectedValueException('SEALED_RMIB_RESULT_INVALID');
    }
}
