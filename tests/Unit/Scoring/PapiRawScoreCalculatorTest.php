<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\PapiRawScoreCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PapiRawScoreCalculatorTest extends TestCase
{
    public function test_canonical_all_a_fixture_scores_twenty_dimensions_from_zero_to_nine(): void
    {
        $mapping = $this->canonicalMapping();
        $calculator = new PapiRawScoreCalculator($mapping);
        $responses = array_map(
            static fn (array $item): array => ['item' => $item['item'], 'choice' => 'a'],
            $mapping,
        );

        $result = $calculator->calculate($responses);

        $this->assertSame([
            'G' => 9, 'E' => 0, 'A' => 1, 'N' => 0, 'P' => 2,
            'X' => 3, 'B' => 4, 'O' => 5, 'Z' => 6, 'K' => 7,
            'F' => 8, 'W' => 9, 'C' => 1, 'L' => 8, 'D' => 2,
            'I' => 7, 'R' => 3, 'T' => 6, 'S' => 4, 'V' => 5,
        ], array_map(static fn (array $dimension): int => $dimension['raw_score'], $result['dimensions']));
        $this->assertSame(['ROLE' => 45, 'NEED' => 45], $result['type_totals']);
        $this->assertSame(90, $result['response_count']);

        foreach ($result['dimensions'] as $dimension) {
            $this->assertSame(9, $dimension['opportunities']);
            $this->assertContains($dimension['raw_score'], range(0, 9));
            $this->assertContains($dimension['type'], ['ROLE', 'NEED']);
        }
    }

    public function test_scores_follow_the_injected_choice_mapping(): void
    {
        $mapping = $this->canonicalMapping();
        [$mapping[0]['a'], $mapping[0]['b']] = [$mapping[0]['b'], $mapping[0]['a']];
        $calculator = new PapiRawScoreCalculator($mapping);
        $responses = array_map(
            static fn (array $item): array => ['item' => $item['item'], 'choice' => 'a'],
            $mapping,
        );

        $result = $calculator->calculate($responses);

        $this->assertSame(8, $result['dimensions']['G']['raw_score']);
        $this->assertSame(1, $result['dimensions']['E']['raw_score']);
    }

    /** @param array<mixed> $responses */
    #[DataProvider('invalidResponses')]
    public function test_invalid_response_payload_is_rejected(array $responses, string $message): void
    {
        $calculator = new PapiRawScoreCalculator($this->canonicalMapping());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $calculator->calculate($responses);
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidResponses(): iterable
    {
        $complete = array_map(
            static fn (int $item): array => ['item' => $item, 'choice' => 'a'],
            range(1, 90),
        );

        yield 'missing item' => [array_slice($complete, 0, 89), 'PAPI responses must contain every configured item exactly once.'];
        yield 'duplicate item' => [[...$complete, ['item' => 1, 'choice' => 'a']], 'PAPI response item is duplicated.'];
        yield 'out of domain item' => [[...array_slice($complete, 0, 89), ['item' => 91, 'choice' => 'a']], 'PAPI response item is outside the supplied mapping.'];
        yield 'invalid choice' => [[...array_slice($complete, 0, 89), ['item' => 90, 'choice' => 'A']], 'PAPI response choice must be exactly a or b.'];
        yield 'malformed item' => [[...array_slice($complete, 0, 89), ['item' => '90', 'choice' => 'a']], 'PAPI response must contain an integer item and string choice.'];
    }

    /** @param array<mixed> $mapping */
    #[DataProvider('invalidMappings')]
    public function test_invalid_mapping_configuration_is_rejected(array $mapping): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PapiRawScoreCalculator($mapping);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidMappings(): iterable
    {
        $mapping = self::loadCanonicalMapping();

        yield 'empty mapping' => [[]];
        yield 'duplicate item' => [[...$mapping, $mapping[0]]];

        $gapped = $mapping;
        $gapped[89]['item'] = 91;
        yield 'gapped item domain' => [$gapped];

        $invalidType = $mapping;
        $invalidType[0]['type'] = 'OTHER';
        yield 'invalid type' => [$invalidType];

        $nineteenDimensions = $mapping;
        foreach ($nineteenDimensions as &$item) {
            if ($item['a'] === 'Z') {
                $item['a'] = 'A';
            }
            if ($item['b'] === 'Z') {
                $item['b'] = 'A';
            }
        }
        unset($item);
        yield 'not exactly twenty dimensions' => [$nineteenDimensions];
    }

    /** @return array<mixed> */
    private function canonicalMapping(): array
    {
        return self::loadCanonicalMapping();
    }

    /** @return array<mixed> */
    private static function loadCanonicalMapping(): array
    {
        $path = dirname(__DIR__, 3).'/database/seeders/data/papi.json';
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Canonical PAPI data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! isset($data['mapping']) || ! is_array($data['mapping'])) {
            throw new RuntimeException('Canonical PAPI mapping is missing.');
        }

        return $data['mapping'];
    }
}
