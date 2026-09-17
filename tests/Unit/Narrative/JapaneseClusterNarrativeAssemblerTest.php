<?php

declare(strict_types=1);

namespace Tests\Unit\Narrative;

use App\Domain\Narrative\JapaneseClusterNarrativeAssembler;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JapaneseClusterNarrativeAssemblerTest extends TestCase
{
    public function test_it_concatenates_standalone_japanese_sentences_without_connectors(): void
    {
        $aspects = [
            ['aspect' => 'B1', 'level' => 2, 'review_required' => false],
            ['aspect' => 'B2', 'level' => 5, 'review_required' => false],
            ['aspect' => 'B3', 'level' => 1, 'review_required' => false],
        ];

        $result = $this->assemblerFor($aspects)->assemble('B', $aspects);

        $this->assertSame([
            'type' => 'cluster_narrative',
            'language' => 'jp',
            'cluster' => 'B',
            'narrative' => 'B1の独立した文です。 B2の独立した文です。 B3の独立した文です。',
            'review_required' => false,
            'omitted_aspects' => [],
            'provenance_keys' => ['jp.B1.2', 'jp.B2.5', 'jp.B3.1'],
            'connector_sequence' => [],
        ], $result);
    }

    public function test_review_required_aspects_are_omitted_with_typed_provenance(): void
    {
        $aspects = [
            ['aspect' => 'C1', 'level' => 2, 'review_required' => false],
            ['aspect' => 'C2', 'level' => 4, 'review_required' => true],
            ['aspect' => 'C3', 'level' => 3, 'review_required' => false],
        ];

        $result = $this->assemblerFor($aspects)->assemble('C', $aspects);

        $this->assertTrue($result['review_required']);
        $this->assertSame('C1の独立した文です。 C3の独立した文です。', $result['narrative']);
        $this->assertSame([
            ['aspect' => 'C2', 'level' => 4, 'position' => 2, 'provenance_key' => 'jp.C2.4'],
        ], $result['omitted_aspects']);
        $this->assertSame(['jp.C1.2', 'jp.C3.3'], $result['provenance_keys']);
        $this->assertSame([], $result['connector_sequence']);
    }

    public function test_all_review_required_aspects_produce_an_empty_review_draft(): void
    {
        $aspects = [
            ['aspect' => 'A1', 'level' => 1, 'review_required' => true],
            ['aspect' => 'A2', 'level' => 5, 'review_required' => true],
        ];

        $result = $this->assemblerFor($aspects)->assemble('A', $aspects);

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
        ];
        $assembler = $this->assemblerFor($aspects);

        $this->assertSame(
            $assembler->assemble('D', $aspects),
            $assembler->assemble('D', $aspects),
        );
    }

    /**
     * @param  array<mixed>  $aspects
     * @param  array<mixed>  $bank
     */
    #[DataProvider('invalidPayloads')]
    public function test_it_fails_closed_on_invalid_aspects_or_bank(string $cluster, array $aspects, array $bank): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JapaneseClusterNarrativeAssembler($bank))->assemble($cluster, $aspects);
    }

    /** @return iterable<string, array{string, array<mixed>, array<mixed>}> */
    public static function invalidPayloads(): iterable
    {
        $validAspects = [
            ['aspect' => 'A1', 'level' => 2, 'review_required' => false],
            ['aspect' => 'A2', 'level' => 3, 'review_required' => false],
        ];
        $validBank = self::staticBankFor($validAspects);

        yield 'unknown cluster' => ['E', $validAspects, $validBank];
        yield 'empty aspects' => ['A', [], []];
        yield 'aspects not a list' => ['A', [1 => $validAspects[0]], $validBank];
        yield 'aspect item not array' => ['A', ['invalid'], $validBank];
        yield 'missing aspect field' => ['A', [['aspect' => 'A1', 'level' => 2]], $validBank];
        yield 'extra aspect field' => ['A', [[...$validAspects[0], 'zone' => 'OK']], $validBank];
        yield 'unknown aspect' => ['A', [['aspect' => 'A3', 'level' => 2, 'review_required' => false]], [['key' => 'jp.A3.2', 'aspect' => 'A3', 'level' => 2, 'text' => '文です。']]];
        yield 'aspect outside cluster' => ['A', [['aspect' => 'B1', 'level' => 2, 'review_required' => false]], [['key' => 'jp.B1.2', 'aspect' => 'B1', 'level' => 2, 'text' => '文です。']]];
        yield 'level string' => ['A', [['aspect' => 'A1', 'level' => '2', 'review_required' => false]], $validBank];
        yield 'level below range' => ['A', [['aspect' => 'A1', 'level' => 0, 'review_required' => false]], $validBank];
        yield 'level above range' => ['A', [['aspect' => 'A1', 'level' => 6, 'review_required' => false]], $validBank];
        yield 'review flag not boolean' => ['A', [['aspect' => 'A1', 'level' => 2, 'review_required' => 1]], $validBank];
        yield 'duplicate input aspect' => ['A', [$validAspects[0], $validAspects[0]], $validBank];
        yield 'bank not a list' => ['A', $validAspects, [1 => $validBank[0]]];
        yield 'bank item not array' => ['A', $validAspects, [$validBank[0], 'invalid']];
        yield 'missing bank field' => ['A', $validAspects, [$validBank[0], ['key' => 'jp.A2.3', 'aspect' => 'A2', 'level' => 3]]];
        yield 'extra bank field' => ['A', $validAspects, [[...$validBank[0], 'id' => 'teks'], $validBank[1]]];
        yield 'unknown bank aspect' => ['A', $validAspects, [...$validBank, ['key' => 'jp.Z1.1', 'aspect' => 'Z1', 'level' => 1, 'text' => '文です。']]];
        yield 'invalid bank level' => ['A', $validAspects, [$validBank[0], [...$validBank[1], 'level' => '3']]];
        yield 'blank bank key' => ['A', $validAspects, [[...$validBank[0], 'key' => ' '], $validBank[1]]];
        yield 'untrimmed bank key' => ['A', $validAspects, [[...$validBank[0], 'key' => ' jp.A1.2'], $validBank[1]]];
        yield 'blank Japanese text' => ['A', $validAspects, [[...$validBank[0], 'text' => "\t"], $validBank[1]]];
        yield 'untrimmed Japanese text' => ['A', $validAspects, [[...$validBank[0], 'text' => ' 文です。'], $validBank[1]]];
        yield 'duplicate bank pair' => ['A', $validAspects, [$validBank[0], [...$validBank[0], 'key' => 'another.key'], $validBank[1]]];
        yield 'duplicate bank key' => ['A', $validAspects, [$validBank[0], [...$validBank[1], 'key' => $validBank[0]['key']]]];
        yield 'missing requested bank pair' => ['A', $validAspects, [$validBank[0]]];
    }

    /** @param  list<array{aspect: string, level: int, review_required: bool}>  $aspects */
    private function assemblerFor(array $aspects): JapaneseClusterNarrativeAssembler
    {
        return new JapaneseClusterNarrativeAssembler($this->bankFor($aspects));
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
                'key' => "jp.{$item['aspect']}.{$item['level']}",
                'aspect' => $item['aspect'],
                'level' => $item['level'],
                'text' => "{$item['aspect']}の独立した文です。",
            ],
            $aspects,
        );
    }
}
