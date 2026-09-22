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
 *
 * ADR-0032 PR3 (2026-09-23): `responses()` no longer requires every one of
 * the 108 item_no slots to be answered -- a missing item_no is passed
 * through to RmibRawScoreCalculator as a missing (group, position) cell, and
 * the calculator's own tiered rule (P3) decides per-group whether that's
 * reconstructable, excludes the group, or (2+ excluded groups) makes the
 * whole session not scorable. A PRESENT but malformed value (wrong item_no
 * pairing, or a value not matching the rank pattern) is NOT treated as
 * missing -- it stays a hard `self::invalid()` for the whole result, same as
 * before PR3. Reasoning: answers reaching this class have already passed
 * the write-side FormRequest validation that constrains rank values, so a
 * malformed-but-present value here signals a data-integrity problem, not a
 * participant who left an item blank -- exactly the same distinction
 * ScoreAssessmentSession's own docblock draws for the loader's exceptions.
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

            if (! $rawResult['scorable']) {
                // ADR-0032 PR3 (P3): 2+ rank groups excluded (missing 2+
                // positions and/or a duplicate rank) -- not a bug, a
                // predictable outcome ScoreAssessmentSession is expected to
                // catch and record as `not_scorable`, never partially
                // scored. Thrown before touching RmibScoreCalculator/
                // RmibRankLevelCalculator, which assume a real rank.
                throw self::notScorable();
            }

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
                reviewRequired: $rawResult['review_required'],
                excludedGroups: $rawResult['excluded_groups'],
            );
        } catch (UnexpectedValueException $exception) {
            if (in_array($exception->getMessage(), ['SEALED_RMIB_RESULT_INVALID', 'SEALED_RMIB_RESULT_NOT_SCORABLE'], true)) {
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
            || count($source->definition->subtests) !== self::GROUP_COUNT) {
            throw self::invalid();
        }

        $values = [];
        foreach ($source->answers as $answer) {
            if (! is_array($answer) || ! is_int($answer['item_no'] ?? null)) {
                throw self::invalid();
            }
            $values[$answer['item_no']] = $answer['value'] ?? null;
        }

        $responses = [];
        $globalItem = 0;
        foreach ($source->definition->subtests as $groupIndex => $subtest) {
            if ($subtest['item_count'] !== self::POSITIONS_PER_GROUP) {
                throw self::invalid();
            }
            for ($position = 1; $position <= self::POSITIONS_PER_GROUP; $position++) {
                $globalItem++;
                if (! array_key_exists($globalItem, $values)) {
                    // No item_no recorded for this cell -- RmibRawScoreCalculator
                    // decides per-group (P3 tiering) whether that's
                    // reconstructable, excludes the group, or makes the
                    // whole session not scorable. See this class's own
                    // docblock for why a PRESENT-but-malformed value below is
                    // NOT treated the same way.
                    continue;
                }
                $value = $values[$globalItem];
                if (! is_string($value) || preg_match('/\A(?:[1-9]|1[0-2])\z/', $value) !== 1) {
                    throw self::invalid();
                }
                $responses[] = [
                    'group' => $groupIndex + 1,
                    'position' => $position,
                    'rank' => (int) $value,
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

    /**
     * Distinct from `invalid()` on purpose -- mirrors
     * LoadSealedGenericAnswerSet::incomplete()'s reasoning. This is the one
     * rejection ScoreAssessmentSession is expected to catch and record as
     * `not_scorable` without rolling back the caller's transaction
     * (ADR-0032 PR3, P3's "2+ defective groups" rule). Every other
     * UnexpectedValueException this class throws stays
     * `SEALED_RMIB_RESULT_INVALID` and is NOT meant to be swallowed.
     */
    private static function notScorable(): UnexpectedValueException
    {
        return new UnexpectedValueException('SEALED_RMIB_RESULT_NOT_SCORABLE');
    }
}
