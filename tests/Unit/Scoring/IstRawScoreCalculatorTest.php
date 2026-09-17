<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\IstRawScoreCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IstRawScoreCalculatorTest extends TestCase
{
    public function test_non_ge_answers_use_exact_key_matching(): void
    {
        $calculator = new IstRawScoreCalculator(
            keys: [
                ['subtest' => 'SE', 'item' => 1, 'key' => 'a'],
                ['subtest' => 'SE', 'item' => 2, 'key' => 'B'],
            ],
            geDictionary: [],
        );

        $scores = $calculator->calculate([
            ['subtest' => 'SE', 'item' => 1, 'answer' => 'a'],
            ['subtest' => 'SE', 'item' => 2, 'answer' => 'b'],
        ]);

        $this->assertSame(['SE' => 1], $scores);
    }

    public function test_ge_dictionary_scores_two_one_and_unknown_as_zero_after_normalization(): void
    {
        $calculator = new IstRawScoreCalculator(
            keys: [],
            geDictionary: [[
                'item' => 1,
                'answers' => [
                    ['answer' => 'bunga', 'score' => 2],
                    ['answer' => 'tanaman', 'score' => 1],
                ],
            ], [
                'item' => 2,
                'answers' => [
                    ['answer' => 'indra', 'score' => 2],
                ],
            ], [
                'item' => 3,
                'answers' => [],
            ]],
        );

        $scores = $calculator->calculate([
            ['subtest' => 'GE', 'item' => 1, 'answer' => ' TANAMAN '],
            ['subtest' => 'GE', 'item' => 2, 'answer' => 'indra'],
            ['subtest' => 'GE', 'item' => 3, 'answer' => 'tidak dikenal'],
        ]);

        $this->assertSame(['GE' => 3], $scores);
    }

    public function test_canonical_f0_data_scores_every_ist_item(): void
    {
        $data = $this->canonicalData();
        $responses = array_map(
            static fn (array $key): array => [
                'subtest' => $key['subtest'],
                'item' => $key['item'],
                'answer' => $key['key'],
            ],
            $data['keys'],
        );

        foreach ($data['ge_dictionary'] as $item) {
            $responses[] = [
                'subtest' => 'GE',
                'item' => $item['item'],
                'answer' => $item['answers'][0]['answer'],
            ];
        }

        $calculator = new IstRawScoreCalculator($data['keys'], $data['ge_dictionary']);

        $this->assertSame([
            'SE' => 20,
            'WA' => 20,
            'AN' => 20,
            'RA' => 20,
            'ZR' => 20,
            'FA' => 20,
            'WU' => 20,
            'ME' => 20,
            'GE' => 32,
        ], $calculator->calculate($responses));
    }

    /** @param array<mixed> $responses */
    #[DataProvider('invalidResponses')]
    public function test_invalid_response_contract_is_rejected(array $responses, string $message): void
    {
        $calculator = $this->calculatorWithTwoItems();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $calculator->calculate($responses);
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidResponses(): iterable
    {
        yield 'malformed' => [[
            ['subtest' => 'SE', 'item' => 1],
            ['subtest' => 'GE', 'item' => 1, 'answer' => 'bunga'],
        ], 'IST response must contain string subtest and answer plus an integer item.'];

        yield 'missing' => [[
            ['subtest' => 'SE', 'item' => 1, 'answer' => 'a'],
        ], 'IST responses must contain every configured item exactly once.'];

        yield 'duplicate' => [[
            ['subtest' => 'SE', 'item' => 1, 'answer' => 'a'],
            ['subtest' => 'SE', 'item' => 1, 'answer' => 'a'],
            ['subtest' => 'GE', 'item' => 1, 'answer' => 'bunga'],
        ], 'IST response item is duplicated.'];

        yield 'out of domain' => [[
            ['subtest' => 'SE', 'item' => 1, 'answer' => 'a'],
            ['subtest' => 'GE', 'item' => 2, 'answer' => 'bunga'],
        ], 'IST response item is outside the supplied scoring data.'];
    }

    /**
     * @param  array<mixed>  $keys
     * @param  array<mixed>  $geDictionary
     */
    #[DataProvider('invalidScoringData')]
    public function test_invalid_scoring_data_is_rejected(array $keys, array $geDictionary): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IstRawScoreCalculator($keys, $geDictionary);
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>}> */
    public static function invalidScoringData(): iterable
    {
        yield 'malformed key' => [[
            ['subtest' => 'SE', 'item' => 1],
        ], []];

        yield 'duplicate key item' => [[
            ['subtest' => 'SE', 'item' => 1, 'key' => 'a'],
            ['subtest' => 'SE', 'item' => 1, 'key' => 'a'],
        ], []];

        yield 'GE in ordinary keys' => [[
            ['subtest' => 'GE', 'item' => 1, 'key' => 'a'],
        ], []];

        yield 'invalid GE score' => [[], [[
            'item' => 1,
            'answers' => [['answer' => 'bunga', 'score' => 3]],
        ]]];

        yield 'duplicate normalized GE answer' => [[], [[
            'item' => 1,
            'answers' => [
                ['answer' => 'bunga', 'score' => 2],
                ['answer' => ' BUNGA ', 'score' => 1],
            ],
        ]]];
    }

    private function calculatorWithTwoItems(): IstRawScoreCalculator
    {
        return new IstRawScoreCalculator(
            keys: [['subtest' => 'SE', 'item' => 1, 'key' => 'a']],
            geDictionary: [[
                'item' => 1,
                'answers' => [['answer' => 'bunga', 'score' => 2]],
            ]],
        );
    }

    /** @return array{keys: array<mixed>, ge_dictionary: array<mixed>} */
    private function canonicalData(): array
    {
        $path = dirname(__DIR__, 3).'/database/seeders/data/ist.json';
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Canonical IST data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)
            || ! isset($data['keys'], $data['ge_dictionary'])
            || ! is_array($data['keys'])
            || ! is_array($data['ge_dictionary'])) {
            throw new RuntimeException('Canonical IST scoring data is missing.');
        }

        return [
            'keys' => $data['keys'],
            'ge_dictionary' => $data['ge_dictionary'],
        ];
    }
}
