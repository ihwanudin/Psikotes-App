<?php

declare(strict_types=1);

namespace Tests\Integration\Narrative;

use App\Domain\Narrative\BilingualClusterNarrativeComposer;
use App\Domain\Narrative\ReportingNarrativeCatalog;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;

final class BilingualNarrativeDeterminismAcceptanceTest extends TestCase
{
    /** @var list<string> */
    private const ASPECTS = [
        'A1', 'A2',
        'B1', 'B2', 'B3', 'B4',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7',
        'D1', 'D2', 'D3', 'D4', 'D5',
    ];

    /** @throws JsonException */
    public function test_t20_repeated_canonical_input_produces_identical_bilingual_payload_and_aligned_g7_omissions(): void
    {
        $raw = $this->canonicalData();
        $composer = new BilingualClusterNarrativeComposer(
            new ReportingNarrativeCatalog($raw['narratives'], $raw['connectors']),
        );
        $input = $this->canonicalInput();
        $input['aspects'][3]['review_required'] = true;
        $input['aspects'][9]['review_required'] = true;

        $first = $composer->compose($input);
        $second = $composer->compose($input);

        $this->assertSame($first, $second);
        $this->assertTrue($first['review_required']);
        $this->assertSame(['B2', 'C4'], array_column($first['omitted_aspects'], 'aspect'));

        $coveredAspects = ['id' => [], 'jp' => []];
        foreach ($first['clusters'] as $cluster => $languages) {
            $this->assertSame(
                array_column($languages['id']['omitted_aspects'], 'aspect'),
                array_column($languages['jp']['omitted_aspects'], 'aspect'),
                "Cluster {$cluster} must omit the same aspects in ID and JP.",
            );
            $this->assertSame(
                array_column($languages['id']['omitted_aspects'], 'level'),
                array_column($languages['jp']['omitted_aspects'], 'level'),
                "Cluster {$cluster} must preserve the same omitted levels in ID and JP.",
            );
            $this->assertSame(
                $languages['id']['provenance_keys'],
                $languages['jp']['provenance_keys'],
                "Cluster {$cluster} must preserve aligned ID and JP provenance.",
            );

            foreach (['id', 'jp'] as $language) {
                foreach ($languages[$language]['provenance_keys'] as $key) {
                    $coveredAspects[$language][] = explode('-L', $key, 2)[0];
                }
                foreach ($languages[$language]['omitted_aspects'] as $omitted) {
                    $coveredAspects[$language][] = $omitted['aspect'];
                }
            }
        }

        $expectedAspects = self::ASPECTS;
        sort($expectedAspects);
        foreach ($coveredAspects as $language => $covered) {
            sort($covered);
            $this->assertSame($expectedAspects, $covered, "All 18 canonical aspects must be covered in {$language}.");
        }

        foreach ($first['omitted_aspects'] as $omitted) {
            $this->assertSame($omitted['provenance']['id'], $omitted['provenance']['jp']);
            $canonical = $this->narrativeFor($raw['narratives'], $omitted['aspect'], $omitted['level']);
            $cluster = $omitted['aspect'][0];
            $this->assertStringNotContainsString($canonical['id'], $first['clusters'][$cluster]['id']['narrative']);
            $this->assertStringNotContainsString($canonical['jp'], $first['clusters'][$cluster]['jp']['narrative']);
        }
    }

    /** @throws JsonException */
    public function test_t20_reordered_input_fails_closed_instead_of_being_normalized(): void
    {
        $raw = $this->canonicalData();
        $composer = new BilingualClusterNarrativeComposer(
            new ReportingNarrativeCatalog($raw['narratives'], $raw['connectors']),
        );
        $input = $this->canonicalInput();
        [$input['aspects'][0], $input['aspects'][1]] = [$input['aspects'][1], $input['aspects'][0]];

        $this->expectException(InvalidArgumentException::class);

        $composer->compose($input);
    }

    /** @return array{aspects: list<array{aspect: string, level: int, review_required: bool}>} */
    private function canonicalInput(): array
    {
        return [
            'aspects' => array_map(
                static fn (string $aspect, int $position): array => [
                    'aspect' => $aspect,
                    'level' => ($position % 5) + 1,
                    'review_required' => false,
                ],
                self::ASPECTS,
                array_keys(self::ASPECTS),
            ),
        ];
    }

    /**
     * @param  list<array{key: string, aspect: string, level: int, id: string, jp: string}>  $narratives
     * @return array{key: string, aspect: string, level: int, id: string, jp: string}
     */
    private function narrativeFor(array $narratives, string $aspect, int $level): array
    {
        foreach ($narratives as $narrative) {
            if ($narrative['aspect'] === $aspect && $narrative['level'] === $level) {
                return $narrative;
            }
        }

        self::fail("Canonical narrative {$aspect} level {$level} is missing.");
    }

    /**
     * @return array{
     *     narratives: list<array{key: string, aspect: string, level: int, id: string, jp: string}>,
     *     connectors: list<array{group: string, order: int, text: string}>
     * }
     *
     * @throws JsonException
     */
    private function canonicalData(): array
    {
        $path = dirname(__DIR__, 3).'/database/seeders/data/reporting.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            self::fail('Canonical reporting data could not be read.');
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)
            || ! isset($decoded['narratives'], $decoded['connectors'])
            || ! is_array($decoded['narratives'])
            || ! is_array($decoded['connectors'])) {
            self::fail('Canonical reporting data has an invalid top-level shape.');
        }

        /** @var array{
         *     narratives: list<array{key: string, aspect: string, level: int, id: string, jp: string}>,
         *     connectors: list<array{group: string, order: int, text: string}>
         * } $decoded
         */
        return $decoded;
    }
}
