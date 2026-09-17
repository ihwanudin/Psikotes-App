<?php

declare(strict_types=1);

namespace Tests\Unit\Narrative;

use App\Domain\Narrative\BilingualClusterNarrativeComposer;
use App\Domain\Narrative\ReportingNarrativeCatalog;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BilingualClusterNarrativeComposerTest extends TestCase
{
    /** @throws JsonException */
    public function test_it_composes_all_four_clusters_with_child_provenance_and_typed_omissions(): void
    {
        $raw = $this->canonicalData();
        $composer = $this->composer($raw);
        $input = $this->validInput();
        $input['aspects'][3]['review_required'] = true;
        $input['aspects'][9]['review_required'] = true;

        $result = $composer->compose($input);

        $this->assertSame(['type', 'clusters', 'review_required', 'omitted_aspects'], array_keys($result));
        $this->assertSame('bilingual_cluster_narratives', $result['type']);
        $this->assertSame(['A', 'B', 'C', 'D'], array_keys($result['clusters']));
        foreach (['A', 'B', 'C', 'D'] as $cluster) {
            $this->assertSame(['id', 'jp'], array_keys($result['clusters'][$cluster]));
            $this->assertSame('id', $result['clusters'][$cluster]['id']['language']);
            $this->assertSame('jp', $result['clusters'][$cluster]['jp']['language']);
            $this->assertSame($cluster, $result['clusters'][$cluster]['id']['cluster']);
            $this->assertSame($cluster, $result['clusters'][$cluster]['jp']['cluster']);
            $this->assertSame([], $result['clusters'][$cluster]['jp']['connector_sequence']);
        }
        $this->assertTrue($result['review_required']);
        $this->assertSame([
            [
                'aspect' => 'B2',
                'level' => 4,
                'position' => 4,
                'provenance' => ['id' => 'B2-L4', 'jp' => 'B2-L4'],
            ],
            [
                'aspect' => 'C4',
                'level' => 5,
                'position' => 10,
                'provenance' => ['id' => 'C4-L5', 'jp' => 'C4-L5'],
            ],
        ], $result['omitted_aspects']);
        $this->assertNotContains('B2-L4', $result['clusters']['B']['id']['provenance_keys']);
        $this->assertNotContains('C4-L5', $result['clusters']['C']['jp']['provenance_keys']);
        $this->assertStringNotContainsString($raw['narratives'][18]['id'], $result['clusters']['B']['id']['narrative']);
        $this->assertStringNotContainsString($raw['narratives'][49]['jp'], $result['clusters']['C']['jp']['narrative']);
    }

    /** @throws JsonException */
    public function test_identical_input_produces_an_exactly_identical_bilingual_result(): void
    {
        $composer = $this->composer($this->canonicalData());
        $input = $this->validInput();
        $first = $composer->compose($input);

        $this->assertFalse($first['review_required']);
        $this->assertSame([], $first['omitted_aspects']);
        $this->assertSame($first, $composer->compose($input));
    }

    /** @throws JsonException */
    #[DataProvider('invalidInputCases')]
    public function test_it_fails_closed_on_noncanonical_psychometric_input(string $case): void
    {
        $input = $this->validInput();

        switch ($case) {
            case 'extra top-level field':
                $input['dass'] = ['general_level' => 5];
                break;
            case 'missing top-level field':
                unset($input['aspects']);
                break;
            case 'wrong top-level field':
                $input = ['levels' => $input['aspects']];
                break;
            case 'aspects not a list':
                $input['aspects'] = [1 => $input['aspects'][0]];
                break;
            case 'missing aspect':
                array_pop($input['aspects']);
                break;
            case 'duplicate aspect':
                $input['aspects'][1] = $input['aspects'][0];
                break;
            case 'out-of-order aspects':
                [$input['aspects'][0], $input['aspects'][1]] = [$input['aspects'][1], $input['aspects'][0]];
                break;
            case 'aspect item not array':
                $input['aspects'][0] = 'invalid';
                break;
            case 'extra embedded field':
                $input['aspects'][0]['dass'] = true;
                break;
            case 'missing embedded field':
                unset($input['aspects'][0]['review_required']);
                break;
            case 'unknown aspect':
                $input['aspects'][0]['aspect'] = 'Z1';
                break;
            case 'non-string aspect':
                $input['aspects'][0]['aspect'] = 1;
                break;
            case 'level string':
                $input['aspects'][0]['level'] = '1';
                break;
            case 'level below range':
                $input['aspects'][0]['level'] = 0;
                break;
            case 'level above range':
                $input['aspects'][0]['level'] = 6;
                break;
            case 'review flag not boolean':
                $input['aspects'][0]['review_required'] = 1;
                break;
        }

        $this->expectException(InvalidArgumentException::class);

        $this->composer($this->canonicalData())->compose($input);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputCases(): iterable
    {
        $cases = [
            'extra top-level field',
            'missing top-level field',
            'wrong top-level field',
            'aspects not a list',
            'missing aspect',
            'duplicate aspect',
            'out-of-order aspects',
            'aspect item not array',
            'extra embedded field',
            'missing embedded field',
            'unknown aspect',
            'non-string aspect',
            'level string',
            'level below range',
            'level above range',
            'review flag not boolean',
        ];

        foreach ($cases as $case) {
            yield $case => [$case];
        }
    }

    /**
     * @param  array{narratives: array<mixed>, connectors: array<mixed>}  $raw
     */
    private function composer(array $raw): BilingualClusterNarrativeComposer
    {
        return new BilingualClusterNarrativeComposer(
            new ReportingNarrativeCatalog($raw['narratives'], $raw['connectors']),
        );
    }

    /** @return array{aspects: list<array{aspect: string, level: int, review_required: bool}>} */
    private function validInput(): array
    {
        $aspects = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

        return [
            'aspects' => array_map(
                static fn (string $aspect, int $index): array => [
                    'aspect' => $aspect,
                    'level' => ($index % 5) + 1,
                    'review_required' => false,
                ],
                $aspects,
                array_keys($aspects),
            ),
        ];
    }

    /**
     * @return array{narratives: array<mixed>, connectors: array<mixed>}
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

        return [
            'narratives' => $decoded['narratives'],
            'connectors' => $decoded['connectors'],
        ];
    }
}
