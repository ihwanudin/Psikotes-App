<?php

declare(strict_types=1);

namespace Tests\Unit\Narrative;

use App\Domain\Narrative\ClusterNarrativeAssembler;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClusterNarrativeAssemblerTest extends TestCase
{
    public function test_it_assembles_indonesian_narratives_in_input_order_with_directional_connectors(): void
    {
        $aspects = [
            ['aspect' => 'B1', 'level' => 2, 'review_required' => false],
            ['aspect' => 'B2', 'level' => 3, 'review_required' => false],
            ['aspect' => 'B3', 'level' => 1, 'review_required' => false],
            ['aspect' => 'B4', 'level' => 1, 'review_required' => false],
        ];

        $result = $this->assemblerFor($aspects)->assemble('B', $aspects);

        $this->assertSame([
            'type' => 'cluster_narrative',
            'language' => 'id',
            'cluster' => 'B',
            'narrative' => 'Narasi B1 tingkat 2. Selain itu, Narasi B2 tingkat 3. Sebaliknya, Narasi B3 tingkat 1. Kemudian, Narasi B4 tingkat 1.',
            'review_required' => false,
            'omitted_aspects' => [],
            'provenance_keys' => ['bank.B1.2', 'bank.B2.3', 'bank.B3.1', 'bank.B4.1'],
            'connector_sequence' => ['connector.add.1', 'connector.contrast.1', 'connector.add.2'],
        ], $result);
    }

    public function test_connector_selection_cycles_by_visible_transition_and_never_repeats_consecutively(): void
    {
        $aspects = [
            ['aspect' => 'C1', 'level' => 1, 'review_required' => false],
            ['aspect' => 'C2', 'level' => 2, 'review_required' => false],
            ['aspect' => 'C3', 'level' => 3, 'review_required' => false],
            ['aspect' => 'C4', 'level' => 4, 'review_required' => false],
        ];

        $result = $this->assemblerFor($aspects)->assemble('C', $aspects);

        $this->assertSame(
            ['connector.add.1', 'connector.add.2', 'connector.add.1'],
            $result['connector_sequence'],
        );
        $this->assertSame(
            'Narasi C1 tingkat 1. Selain itu, Narasi C2 tingkat 2. Kemudian, Narasi C3 tingkat 3. Selain itu, Narasi C4 tingkat 4.',
            $result['narrative'],
        );
    }

    public function test_review_required_aspects_are_omitted_and_reported_with_typed_provenance(): void
    {
        $aspects = [
            ['aspect' => 'B1', 'level' => 2, 'review_required' => false],
            ['aspect' => 'B2', 'level' => 5, 'review_required' => true],
            ['aspect' => 'B3', 'level' => 1, 'review_required' => false],
        ];

        $result = $this->assemblerFor($aspects)->assemble('B', $aspects);

        $this->assertTrue($result['review_required']);
        $this->assertSame([
            ['aspect' => 'B2', 'level' => 5, 'position' => 2, 'provenance_key' => 'bank.B2.5'],
        ], $result['omitted_aspects']);
        $this->assertSame('Narasi B1 tingkat 2. Sebaliknya, Narasi B3 tingkat 1.', $result['narrative']);
        $this->assertSame(['bank.B1.2', 'bank.B3.1'], $result['provenance_keys']);
        $this->assertSame(['connector.contrast.1'], $result['connector_sequence']);
    }

    public function test_all_review_required_aspects_produce_an_empty_review_draft_without_requiring_connectors(): void
    {
        $aspects = [
            ['aspect' => 'A1', 'level' => 1, 'review_required' => true],
            ['aspect' => 'A2', 'level' => 5, 'review_required' => true],
        ];

        $result = (new ClusterNarrativeAssembler($this->bankFor($aspects), [
            'additive' => [],
            'contrast' => [],
        ]))->assemble('A', $aspects);

        $this->assertSame('', $result['narrative']);
        $this->assertTrue($result['review_required']);
        $this->assertCount(2, $result['omitted_aspects']);
        $this->assertSame([], $result['provenance_keys']);
        $this->assertSame([], $result['connector_sequence']);
    }

    public function test_identical_input_produces_an_exactly_identical_result(): void
    {
        $aspects = [
            ['aspect' => 'D1', 'level' => 2, 'review_required' => false],
            ['aspect' => 'D2', 'level' => 4, 'review_required' => false],
            ['aspect' => 'D3', 'level' => 3, 'review_required' => false],
        ];
        $bank = $this->bankFor($aspects);
        $connectors = $this->connectors();
        $assembler = new ClusterNarrativeAssembler($bank, $connectors);

        $this->assertSame(
            $assembler->assemble('D', $aspects),
            $assembler->assemble('D', $aspects),
        );
    }

    /**
     * @param  array<mixed>  $aspects
     * @param  array<mixed>  $bank
     * @param  array<mixed>  $connectors
     */
    #[DataProvider('invalidPayloads')]
    public function test_it_fails_closed_on_malformed_or_incomplete_input(
        string $cluster,
        array $aspects,
        array $bank,
        array $connectors,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        (new ClusterNarrativeAssembler($bank, $connectors))->assemble($cluster, $aspects);
    }

    /** @return iterable<string, array{string, array<mixed>, array<mixed>, array<mixed>}> */
    public static function invalidPayloads(): iterable
    {
        $validAspects = [
            ['aspect' => 'A1', 'level' => 2, 'review_required' => false],
            ['aspect' => 'A2', 'level' => 3, 'review_required' => false],
        ];
        $validBank = self::staticBankFor($validAspects);
        $validConnectors = self::staticConnectors();

        yield 'unknown cluster' => ['E', $validAspects, $validBank, $validConnectors];
        yield 'empty aspects' => ['A', [], [], $validConnectors];
        yield 'aspects must be a list' => ['A', [1 => $validAspects[0]], $validBank, $validConnectors];
        yield 'unexpected aspect key' => ['A', [[...$validAspects[0], 'zone' => 'OK']], $validBank, $validConnectors];
        yield 'unknown aspect' => ['A', [['aspect' => 'A3', 'level' => 2, 'review_required' => false]], [['key' => 'bank.A3.2', 'aspect' => 'A3', 'level' => 2, 'text' => 'Narasi.']], $validConnectors];
        yield 'aspect outside cluster' => ['A', [['aspect' => 'B1', 'level' => 2, 'review_required' => false]], [['key' => 'bank.B1.2', 'aspect' => 'B1', 'level' => 2, 'text' => 'Narasi.']], $validConnectors];
        yield 'level string' => ['A', [['aspect' => 'A1', 'level' => '2', 'review_required' => false]], $validBank, $validConnectors];
        yield 'level below range' => ['A', [['aspect' => 'A1', 'level' => 0, 'review_required' => false]], $validBank, $validConnectors];
        yield 'level above range' => ['A', [['aspect' => 'A1', 'level' => 6, 'review_required' => false]], $validBank, $validConnectors];
        yield 'review flag is not boolean' => ['A', [['aspect' => 'A1', 'level' => 2, 'review_required' => 1]], $validBank, $validConnectors];
        yield 'duplicate input aspect' => ['A', [$validAspects[0], $validAspects[0]], $validBank, $validConnectors];
        yield 'bank must be a list' => ['A', $validAspects, [1 => $validBank[0]], $validConnectors];
        yield 'unexpected bank key' => ['A', $validAspects, [[...$validBank[0], 'jp' => 'teks'], $validBank[1]], $validConnectors];
        yield 'duplicate bank pair' => ['A', $validAspects, [$validBank[0], [...$validBank[0], 'key' => 'another.key'], $validBank[1]], $validConnectors];
        yield 'duplicate provenance key' => ['A', $validAspects, [$validBank[0], [...$validBank[1], 'key' => $validBank[0]['key']]], $validConnectors];
        yield 'missing bank pair' => ['A', $validAspects, [$validBank[0]], $validConnectors];
        yield 'bank item is not an array' => ['A', $validAspects, [$validBank[0], 'invalid'], $validConnectors];
        yield 'bank level is not an integer' => ['A', $validAspects, [$validBank[0], [...$validBank[1], 'level' => '3']], $validConnectors];
        yield 'unknown bank aspect' => ['A', $validAspects, [...$validBank, ['key' => 'bank.Z1.1', 'aspect' => 'Z1', 'level' => 1, 'text' => 'Narasi.']], $validConnectors];
        yield 'bank text is blank' => ['A', $validAspects, [[...$validBank[0], 'text' => ' '], $validBank[1]], $validConnectors];
        yield 'missing connector pool' => ['A', $validAspects, $validBank, ['additive' => $validConnectors['additive']]];
        yield 'unexpected connector pool' => ['A', $validAspects, $validBank, [...$validConnectors, 'ending' => []]];
        yield 'needed additive pool is empty' => ['A', $validAspects, $validBank, ['additive' => [], 'contrast' => $validConnectors['contrast']]];
        yield 'needed contrast pool is empty' => ['A', [$validAspects[1], $validAspects[0]], $validBank, ['additive' => $validConnectors['additive'], 'contrast' => []]];
        yield 'connector pool must be a list' => ['A', $validAspects, $validBank, ['additive' => [1 => $validConnectors['additive'][0]], 'contrast' => $validConnectors['contrast']]];
        yield 'connector item is not an array' => ['A', $validAspects, $validBank, ['additive' => ['invalid'], 'contrast' => $validConnectors['contrast']]];
        yield 'connector entry has unexpected key' => ['A', $validAspects, $validBank, ['additive' => [[...$validConnectors['additive'][0], 'order' => 1]], 'contrast' => $validConnectors['contrast']]];
        yield 'duplicate connector key' => ['A', $validAspects, $validBank, ['additive' => $validConnectors['additive'], 'contrast' => [[...$validConnectors['contrast'][0], 'key' => 'connector.add.1']]]];
        yield 'duplicate connector text' => ['A', $validAspects, $validBank, ['additive' => $validConnectors['additive'], 'contrast' => [[...$validConnectors['contrast'][0], 'text' => 'Selain itu,']]]];
    }

    public function test_it_fails_when_a_single_connector_cannot_avoid_an_immediate_repeat(): void
    {
        $aspects = [
            ['aspect' => 'C1', 'level' => 1, 'review_required' => false],
            ['aspect' => 'C2', 'level' => 2, 'review_required' => false],
            ['aspect' => 'C3', 'level' => 3, 'review_required' => false],
        ];

        $this->expectException(InvalidArgumentException::class);

        (new ClusterNarrativeAssembler($this->bankFor($aspects), [
            'additive' => [['key' => 'connector.only', 'text' => 'Selain itu,']],
            'contrast' => [],
        ]))->assemble('C', $aspects);
    }

    /** @param  list<array{aspect: string, level: int, review_required: bool}>  $aspects */
    private function assemblerFor(array $aspects): ClusterNarrativeAssembler
    {
        return new ClusterNarrativeAssembler($this->bankFor($aspects), $this->connectors());
    }

    /**
     * @param  list<array{aspect: string, level: int, review_required: bool}>  $aspects
     * @return list<array{key: string, aspect: string, level: int, text: string}>
     */
    private function bankFor(array $aspects): array
    {
        return self::staticBankFor($aspects);
    }

    /**
     * @param  list<array{aspect: string, level: int, review_required: bool}>  $aspects
     * @return list<array{key: string, aspect: string, level: int, text: string}>
     */
    private static function staticBankFor(array $aspects): array
    {
        return array_map(
            static fn (array $item): array => [
                'key' => "bank.{$item['aspect']}.{$item['level']}",
                'aspect' => $item['aspect'],
                'level' => $item['level'],
                'text' => "Narasi {$item['aspect']} tingkat {$item['level']}.",
            ],
            $aspects,
        );
    }

    /** @return array{additive: list<array{key: string, text: string}>, contrast: list<array{key: string, text: string}>} */
    private function connectors(): array
    {
        return self::staticConnectors();
    }

    /** @return array{additive: list<array{key: string, text: string}>, contrast: list<array{key: string, text: string}>} */
    private static function staticConnectors(): array
    {
        return [
            'additive' => [
                ['key' => 'connector.add.1', 'text' => 'Selain itu,'],
                ['key' => 'connector.add.2', 'text' => 'Kemudian,'],
            ],
            'contrast' => [
                ['key' => 'connector.contrast.1', 'text' => 'Sebaliknya,'],
                ['key' => 'connector.contrast.2', 'text' => 'Akan tetapi,'],
            ],
        ];
    }
}
