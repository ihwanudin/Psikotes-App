<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\Dass21Scorer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Dass21ScorerTest extends TestCase
{
    public function test_scores_three_subscales_and_uses_worst_level_as_general_category(): void
    {
        $data = self::canonicalData();
        $scorer = new Dass21Scorer($data['items'], $data['cutoffs'], $data['multiplier']);

        $result = $scorer->score(self::responsesForRawScores($data['items'], ['D' => 5, 'A' => 5, 'S' => 13]));

        $this->assertSame([
            'D' => ['raw_score' => 5, 'score_x2' => 10, 'category' => 'Ringan', 'level' => 2],
            'A' => ['raw_score' => 5, 'score_x2' => 10, 'category' => 'Sedang', 'level' => 3],
            'S' => ['raw_score' => 13, 'score_x2' => 26, 'category' => 'Parah', 'level' => 4],
        ], array_map(
            static fn (array $scale): array => array_intersect_key($scale, array_flip(['raw_score', 'score_x2', 'category', 'level'])),
            $result['subscales'],
        ));
        $this->assertSame([
            'category' => 'Parah',
            'level' => 4,
            'basis_scales' => ['S'],
        ], $result['general']);
        $this->assertSame(21, $result['provenance']['response_count']);
        $this->assertSame(['min' => 0, 'max' => 3], $result['provenance']['response_domain']);
        $this->assertSame(2, $result['provenance']['multiplier']);
        $this->assertSame(['D' => 7, 'A' => 7, 'S' => 7], $result['provenance']['scale_item_counts']);
        $this->assertSame([3 => 3, 5 => 2, 10 => 0, 13 => 0, 16 => 0, 17 => 0, 21 => 0], $result['subscales']['D']['item_scores']);
        $this->assertSame(['lo' => 10, 'hi' => 13], $result['subscales']['D']['cutoff']);
    }

    public function test_equal_worst_levels_preserve_every_basis_scale(): void
    {
        $data = self::canonicalData();
        $result = (new Dass21Scorer($data['items'], $data['cutoffs'], $data['multiplier']))
            ->score(self::responsesForRawScores($data['items'], ['D' => 0, 'A' => 0, 'S' => 0]));

        $this->assertSame('Normal', $result['general']['category']);
        $this->assertSame(1, $result['general']['level']);
        $this->assertSame(['D', 'A', 'S'], $result['general']['basis_scales']);
    }

    #[DataProvider('canonicalBoundaryCases')]
    public function test_canonical_cutoff_boundaries_are_inclusive(string $scale, int $rawScore, string $category, int $level): void
    {
        $data = self::canonicalData();
        $targets = ['D' => 0, 'A' => 0, 'S' => 0];
        $targets[$scale] = $rawScore;

        $result = (new Dass21Scorer($data['items'], $data['cutoffs'], $data['multiplier']))
            ->score(self::responsesForRawScores($data['items'], $targets));

        $this->assertSame($category, $result['subscales'][$scale]['category']);
        $this->assertSame($level, $result['subscales'][$scale]['level']);
    }

    /** @return iterable<string, array{string, int, string, int}> */
    public static function canonicalBoundaryCases(): iterable
    {
        yield 'depression normal upper reachable' => ['D', 4, 'Normal', 1];
        yield 'depression mild lower reachable' => ['D', 5, 'Ringan', 2];
        yield 'anxiety mild lower' => ['A', 4, 'Ringan', 2];
        yield 'anxiety moderate lower' => ['A', 5, 'Sedang', 3];
        yield 'anxiety severe lower reachable' => ['A', 8, 'Parah', 4];
        yield 'anxiety extremely severe lower' => ['A', 10, 'Sangat Parah', 5];
        yield 'stress normal upper' => ['S', 7, 'Normal', 1];
        yield 'stress mild lower reachable' => ['S', 8, 'Ringan', 2];
        yield 'stress moderate lower reachable' => ['S', 10, 'Sedang', 3];
        yield 'stress severe lower' => ['S', 13, 'Parah', 4];
        yield 'stress extremely severe lower' => ['S', 17, 'Sangat Parah', 5];
    }

    /** @param array<mixed> $responses */
    #[DataProvider('invalidResponses')]
    public function test_invalid_or_incomplete_responses_fail_closed(array $responses, string $message): void
    {
        $data = self::canonicalData();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new Dass21Scorer($data['items'], $data['cutoffs'], $data['multiplier']))->score($responses);
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidResponses(): iterable
    {
        $data = self::canonicalData();
        $valid = self::responsesForRawScores($data['items'], ['D' => 0, 'A' => 0, 'S' => 0]);

        yield 'missing answer' => [array_slice($valid, 0, 20), 'DASS-21 responses must contain exactly 21 answers.'];
        yield 'extra answer' => [[...$valid, $valid[0]], 'DASS-21 responses must contain exactly 21 answers.'];

        $duplicate = $valid;
        $duplicate[20]['item'] = 1;
        yield 'duplicate item' => [$duplicate, 'DASS-21 response item is duplicated.'];

        $unknown = $valid;
        $unknown[20]['item'] = 22;
        yield 'unknown item' => [$unknown, 'DASS-21 response references an unknown item.'];

        $stringScore = $valid;
        $stringScore[0]['score'] = '0';
        yield 'numeric string' => [$stringScore, 'DASS-21 response must contain integer item and score.'];

        $outsideDomain = $valid;
        $outsideDomain[0]['score'] = 4;
        yield 'score above domain' => [$outsideDomain, 'DASS-21 response score must be between 0 and 3.'];
    }

    /**
     * @param  array<mixed>  $items
     * @param  array<mixed>  $cutoffs
     */
    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_fails_closed(array $items, array $cutoffs, mixed $multiplier, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Dass21Scorer($items, $cutoffs, $multiplier);
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>, mixed, string}> */
    public static function invalidConfigurations(): iterable
    {
        $data = self::canonicalData();

        yield 'missing item' => [array_slice($data['items'], 0, 20), $data['cutoffs'], 2, 'DASS-21 configuration must define exactly 21 items.'];

        $duplicateItem = $data['items'];
        $duplicateItem[20]['item'] = 1;
        yield 'duplicate item' => [$duplicateItem, $data['cutoffs'], 2, 'DASS-21 item identifiers must cover 1 through 21 exactly once.'];

        $unknownScale = $data['items'];
        $unknownScale[0]['scale'] = 'X';
        yield 'unknown scale' => [$unknownScale, $data['cutoffs'], 2, 'DASS-21 item scale must be D, A, or S.'];

        yield 'invalid multiplier' => [$data['items'], $data['cutoffs'], 0, 'DASS-21 multiplier must be a positive integer.'];

        $missingCutoff = array_slice($data['cutoffs'], 0, 14);
        yield 'missing cutoff' => [$data['items'], $missingCutoff, 2, 'Each DASS-21 scale must define levels one through five.'];

        $malformedRange = $data['cutoffs'];
        $malformedRange[0]['range_x2'] = 'not a range';
        yield 'malformed range' => [$data['items'], $malformedRange, 2, 'DASS-21 cutoff range is invalid.'];

        $gap = $data['cutoffs'];
        $gap[1]['range_x2'] = '11 – 13';
        yield 'cutoff gap' => [$data['items'], $gap, 2, 'DASS-21 cutoffs must cover the score domain without gaps or overlaps.'];

        $categoryConflict = $data['cutoffs'];
        $categoryConflict[5]['category'] = 'Different Normal';
        yield 'category conflict' => [$data['items'], $categoryConflict, 2, 'Each DASS-21 severity level must use one canonical category.'];

        $levelConflict = $data['cutoffs'];
        $levelConflict[1]['category'] = 'Normal';
        yield 'category reused at another level' => [$data['items'], $levelConflict, 2, 'Each DASS-21 severity level must use one canonical category.'];
    }

    /**
     * @param  array<mixed>  $items
     * @param  array<string, int>  $rawScores
     * @return list<array{item: int, score: int}>
     */
    private static function responsesForRawScores(array $items, array $rawScores): array
    {
        $remaining = $rawScores;
        $responses = [];

        foreach ($items as $item) {
            $score = min(3, $remaining[$item['scale']]);
            $remaining[$item['scale']] -= $score;
            $responses[] = ['item' => $item['item'], 'score' => $score];
        }

        if (array_sum($remaining) !== 0) {
            throw new RuntimeException('Requested raw score is outside the DASS-21 response domain.');
        }

        return $responses;
    }

    /** @return array{items: array<mixed>, cutoffs: array<mixed>, multiplier: mixed} */
    private static function canonicalData(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/dass21.json');

        if ($contents === false) {
            throw new RuntimeException('Unable to read canonical DASS-21 data.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return [
            'items' => $data['items'],
            'cutoffs' => $data['cutoffs'],
            'multiplier' => $data['multiplier'],
        ];
    }
}
