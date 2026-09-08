<?php

declare(strict_types=1);

namespace Tests\Integration\Narrative;

use App\Domain\Narrative\BilingualClusterNarrativeComposer;
use App\Domain\Narrative\ClusterNarrativeAssembler;
use App\Domain\Narrative\ReportingNarrativeCatalog;
use JsonException;
use PHPUnit\Framework\TestCase;

final class ConnectorRotationAcceptanceTest extends TestCase
{
    /** @throws JsonException */
    public function test_t21_canonical_connectors_rotate_by_direction_without_early_repetition_and_japanese_remains_standalone(): void
    {
        $raw = $this->canonicalData();
        $catalog = new ReportingNarrativeCatalog($raw['narratives'], $raw['connectors']);
        $inputs = $catalog->assemblerInputs();
        $assembler = new ClusterNarrativeAssembler($inputs['narrative_bank'], $inputs['connector_pools']);
        $input = $this->canonicalInput();
        $clusterB = array_slice($input['aspects'], 2, 4);
        $clusterC = array_slice($input['aspects'], 6, 7);
        $clusterD = array_slice($input['aspects'], 13, 5);

        $mixed = $assembler->assemble('B', $clusterB);
        $additive = $assembler->assemble('C', $clusterC);
        $contrast = $assembler->assemble('D', $clusterD);

        $additivePoolKeys = array_column($inputs['connector_pools']['additive'], 'key');
        $contrastPoolKeys = array_column($inputs['connector_pools']['contrast'], 'key');
        $this->assertCount(5, $additivePoolKeys);
        $this->assertCount(5, $contrastPoolKeys);
        $this->assertSame(
            [$contrastPoolKeys[0], $additivePoolKeys[0], $contrastPoolKeys[1]],
            $mixed['connector_sequence'],
            'Each direction must start and advance through its own independent pool.',
        );
        $this->assertSame(
            [...$additivePoolKeys, $additivePoolKeys[0]],
            $additive['connector_sequence'],
            'Equal and rising levels must exhaust the additive pool before cycling.',
        );
        $this->assertSame(
            array_slice($contrastPoolKeys, 0, 4),
            $contrast['connector_sequence'],
            'Falling levels must rotate through distinct contrast connectors.',
        );
        $this->assertCount(5, array_unique(array_slice($additive['connector_sequence'], 0, 5)));
        $this->assertCount(4, array_unique($contrast['connector_sequence']));

        $composer = new BilingualClusterNarrativeComposer($catalog);
        $first = $composer->compose($input);
        $second = $composer->compose($input);

        $this->assertSame($first, $second);
        $this->assertSame($mixed['connector_sequence'], $first['clusters']['B']['id']['connector_sequence']);
        $this->assertSame($additive['connector_sequence'], $first['clusters']['C']['id']['connector_sequence']);
        $this->assertSame($contrast['connector_sequence'], $first['clusters']['D']['id']['connector_sequence']);
        foreach ($first['clusters'] as $cluster => $languages) {
            $this->assertSame([], $languages['jp']['connector_sequence']);
            foreach ($raw['connectors'] as $connector) {
                if ($connector['group'] === 'ADITIF' || $connector['group'] === 'KONTRAS') {
                    $this->assertStringNotContainsString(
                        $connector['text'],
                        $languages['jp']['narrative'],
                        "Cluster {$cluster} JP must not contain an Indonesian connector.",
                    );
                }
            }
        }
    }

    /** @return array{aspects: list<array{aspect: string, level: int, review_required: bool}>} */
    private function canonicalInput(): array
    {
        $levels = [
            'A1' => 1, 'A2' => 2,
            'B1' => 5, 'B2' => 4, 'B3' => 5, 'B4' => 4,
            'C1' => 1, 'C2' => 1, 'C3' => 2, 'C4' => 2, 'C5' => 3, 'C6' => 3, 'C7' => 4,
            'D1' => 5, 'D2' => 4, 'D3' => 3, 'D4' => 2, 'D5' => 1,
        ];

        return [
            'aspects' => array_map(
                static fn (string $aspect, int $level): array => [
                    'aspect' => $aspect,
                    'level' => $level,
                    'review_required' => false,
                ],
                array_keys($levels),
                array_values($levels),
            ),
        ];
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
