<?php

declare(strict_types=1);

namespace Tests\Unit\Narrative;

use App\Domain\Narrative\ClusterNarrativeAssembler;
use App\Domain\Narrative\ReportingNarrativeCatalog;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportingNarrativeCatalogTest extends TestCase
{
    /** @throws JsonException */
    public function test_it_adapts_the_complete_canonical_catalog_to_exact_assembler_inputs(): void
    {
        $raw = $this->canonicalData();
        $catalog = new ReportingNarrativeCatalog($raw['narratives'], $raw['connectors']);

        $inputs = $catalog->assemblerInputs();

        $this->assertCount(90, $inputs['narrative_bank']);
        $this->assertCount(5, $inputs['connector_pools']['additive']);
        $this->assertCount(5, $inputs['connector_pools']['contrast']);
        $this->assertCount(2, $catalog->endingPool());
        $this->assertSame([
            'key' => $raw['narratives'][0]['key'],
            'aspect' => $raw['narratives'][0]['aspect'],
            'level' => $raw['narratives'][0]['level'],
            'text' => $raw['narratives'][0]['id'],
        ], $inputs['narrative_bank'][0]);
        $this->assertSame([
            'key' => 'connector.ADITIF.1',
            'text' => $raw['connectors'][0]['text'],
        ], $inputs['connector_pools']['additive'][0]);
        $this->assertSame([
            'key' => 'connector.PENUTUP.1',
            'text' => $raw['connectors'][10]['text'],
        ], $catalog->endingPool()[0]);
    }

    /** @throws JsonException */
    public function test_canonical_data_assembles_a_real_cluster_deterministically(): void
    {
        $raw = $this->canonicalData();
        $catalog = new ReportingNarrativeCatalog($raw['narratives'], $raw['connectors']);
        $inputs = $catalog->assemblerInputs();
        $assembler = new ClusterNarrativeAssembler($inputs['narrative_bank'], $inputs['connector_pools']);
        $aspects = [
            ['aspect' => 'A1', 'level' => 1, 'review_required' => false],
            ['aspect' => 'A2', 'level' => 2, 'review_required' => false],
        ];

        $first = $assembler->assemble('A', $aspects);
        $second = $assembler->assemble('A', $aspects);

        $this->assertSame($first, $second);
        $this->assertSame(
            $raw['narratives'][0]['id'].' '.$raw['connectors'][0]['text'].' '.$raw['narratives'][6]['id'],
            $first['narrative'],
        );
        $this->assertSame(['A1-L1', 'A2-L2'], $first['provenance_keys']);
        $this->assertSame(['connector.ADITIF.1'], $first['connector_sequence']);
    }

    /** @throws JsonException */
    #[DataProvider('invalidCases')]
    public function test_it_fails_closed_on_invalid_canonical_data(string $case): void
    {
        $raw = $this->canonicalData();

        switch ($case) {
            case 'narratives not list':
                $raw['narratives'] = [1 => $raw['narratives'][0]];
                break;
            case 'narrative item not array':
                $raw['narratives'][0] = 'invalid';
                break;
            case 'unexpected narrative key':
                $raw['narratives'][0]['extra'] = true;
                break;
            case 'missing narrative field':
                unset($raw['narratives'][0]['jp']);
                break;
            case 'missing narrative pair':
                array_pop($raw['narratives']);
                break;
            case 'duplicate narrative pair':
                $duplicate = $raw['narratives'][0];
                $duplicate['key'] = 'duplicate-pair';
                $raw['narratives'][] = $duplicate;
                break;
            case 'duplicate narrative key':
                $raw['narratives'][1]['key'] = $raw['narratives'][0]['key'];
                break;
            case 'unknown narrative aspect':
                $raw['narratives'][0]['aspect'] = 'Z1';
                break;
            case 'invalid narrative level':
                $raw['narratives'][0]['level'] = '1';
                break;
            case 'blank Indonesian narrative':
                $raw['narratives'][0]['id'] = ' ';
                break;
            case 'blank Japanese narrative':
                $raw['narratives'][0]['jp'] = '';
                break;
            case 'blank narrative key':
                $raw['narratives'][0]['key'] = "\n";
                break;
            case 'connectors not list':
                $raw['connectors'] = [1 => $raw['connectors'][0]];
                break;
            case 'connector item not array':
                $raw['connectors'][0] = 'invalid';
                break;
            case 'unexpected connector key':
                $raw['connectors'][0]['key'] = 'invented';
                break;
            case 'missing connector field':
                unset($raw['connectors'][0]['text']);
                break;
            case 'unknown connector group':
                $raw['connectors'][0]['group'] = 'LAIN';
                break;
            case 'missing connector group':
                $raw['connectors'] = array_values(array_filter(
                    $raw['connectors'],
                    static fn (array $entry): bool => $entry['group'] !== 'PENUTUP',
                ));
                break;
            case 'duplicate connector order':
                $raw['connectors'][1]['order'] = 1;
                break;
            case 'missing contiguous connector order':
                $raw['connectors'][1]['order'] = 3;
                break;
            case 'non-positive connector order':
                $raw['connectors'][0]['order'] = 0;
                break;
            case 'non-integer connector order':
                $raw['connectors'][0]['order'] = '1';
                break;
            case 'out-of-order connectors':
                [$raw['connectors'][0], $raw['connectors'][1]] = [$raw['connectors'][1], $raw['connectors'][0]];
                break;
            case 'blank connector text':
                $raw['connectors'][0]['text'] = "\t";
                break;
            case 'duplicate connector text':
                $raw['connectors'][1]['text'] = $raw['connectors'][0]['text'];
                break;
        }

        $this->expectException(InvalidArgumentException::class);

        new ReportingNarrativeCatalog($raw['narratives'], $raw['connectors']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCases(): iterable
    {
        $cases = [
            'narratives not list',
            'narrative item not array',
            'unexpected narrative key',
            'missing narrative field',
            'missing narrative pair',
            'duplicate narrative pair',
            'duplicate narrative key',
            'unknown narrative aspect',
            'invalid narrative level',
            'blank Indonesian narrative',
            'blank Japanese narrative',
            'blank narrative key',
            'connectors not list',
            'connector item not array',
            'unexpected connector key',
            'missing connector field',
            'unknown connector group',
            'missing connector group',
            'duplicate connector order',
            'missing contiguous connector order',
            'non-positive connector order',
            'non-integer connector order',
            'out-of-order connectors',
            'blank connector text',
            'duplicate connector text',
        ];

        foreach ($cases as $case) {
            yield $case => [$case];
        }
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
