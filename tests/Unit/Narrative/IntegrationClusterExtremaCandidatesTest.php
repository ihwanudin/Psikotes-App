<?php

declare(strict_types=1);

namespace Tests\Unit\Narrative;

use App\Domain\Narrative\IntegrationClusterExtremaCandidates;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntegrationClusterExtremaCandidatesTest extends TestCase
{
    public function test_it_returns_all_tied_cluster_b_extrema_in_canonical_order(): void
    {
        $input = $this->validInput('B', [5, 2, 5, 2]);

        $result = (new IntegrationClusterExtremaCandidates)->candidates('B', $input);

        $this->assertSame([
            'type' => 'integration_cluster_extrema_candidates',
            'cluster' => 'B',
            'highest_level' => 5,
            'highest_candidates' => [
                ['aspect' => 'B1', 'level' => 5, 'source_position' => 1],
                ['aspect' => 'B3', 'level' => 5, 'source_position' => 3],
            ],
            'lowest_level' => 2,
            'lowest_candidates' => [
                ['aspect' => 'B2', 'level' => 2, 'source_position' => 2],
                ['aspect' => 'B4', 'level' => 2, 'source_position' => 4],
            ],
            'review_required' => false,
            'omitted_aspects' => [],
        ], $result);
    }

    public function test_it_omits_unresolved_cluster_c_aspects_and_preserves_typed_provenance(): void
    {
        $input = $this->validInput('C', [5, 1, 4, 2, 1, 4, 3]);
        $input[0]['review_required'] = true;
        $input[1]['review_required'] = true;

        $result = (new IntegrationClusterExtremaCandidates)->candidates('C', $input);

        $this->assertSame(4, $result['highest_level']);
        $this->assertSame([
            ['aspect' => 'C3', 'level' => 4, 'source_position' => 3],
            ['aspect' => 'C6', 'level' => 4, 'source_position' => 6],
        ], $result['highest_candidates']);
        $this->assertSame(1, $result['lowest_level']);
        $this->assertSame([
            ['aspect' => 'C5', 'level' => 1, 'source_position' => 5],
        ], $result['lowest_candidates']);
        $this->assertTrue($result['review_required']);
        $this->assertSame([
            ['aspect' => 'C1', 'level' => 5, 'source_position' => 1],
            ['aspect' => 'C2', 'level' => 1, 'source_position' => 2],
        ], $result['omitted_aspects']);
    }

    public function test_equal_levels_return_the_same_full_set_for_both_extrema_deterministically(): void
    {
        $input = $this->validInput('B', [3, 3, 3, 3]);
        $selector = new IntegrationClusterExtremaCandidates;

        $first = $selector->candidates('B', $input);
        $second = $selector->candidates('B', $input);

        $this->assertSame($first['highest_candidates'], $first['lowest_candidates']);
        $this->assertSame(['B1', 'B2', 'B3', 'B4'], array_column($first['highest_candidates'], 'aspect'));
        $this->assertSame($first, $second);
    }

    #[DataProvider('unsupportedClusterCases')]
    public function test_it_fails_closed_on_unsupported_clusters(string $cluster): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new IntegrationClusterExtremaCandidates)->candidates($cluster, []);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedClusterCases(): iterable
    {
        yield 'cluster A' => ['A'];
        yield 'cluster D' => ['D'];
        yield 'unknown cluster' => ['Z'];
        yield 'untrimmed cluster' => [' B'];
    }

    #[DataProvider('invalidInputCases')]
    public function test_it_fails_closed_on_noncanonical_input(string $case): void
    {
        $input = $this->validInput('B', [1, 2, 3, 4]);

        switch ($case) {
            case 'not a list':
                $input = [1 => $input[0]];
                break;
            case 'missing aspect':
                array_pop($input);
                break;
            case 'extra aspect':
                $input[] = ['aspect' => 'B5', 'level' => 2, 'review_required' => false];
                break;
            case 'duplicate aspect':
                $input[1] = $input[0];
                break;
            case 'out of order':
                [$input[0], $input[1]] = [$input[1], $input[0]];
                break;
            case 'unknown aspect':
                $input[0]['aspect'] = 'Z1';
                break;
            case 'D aspect':
                $input[0]['aspect'] = 'D1';
                break;
            case 'item not array':
                $input[0] = 'invalid';
                break;
            case 'missing key':
                unset($input[0]['level']);
                break;
            case 'extra key':
                $input[0]['zone'] = 'OK';
                break;
            case 'level string':
                $input[0]['level'] = '1';
                break;
            case 'level boolean':
                $input[0]['level'] = true;
                break;
            case 'level below range':
                $input[0]['level'] = 0;
                break;
            case 'level above range':
                $input[0]['level'] = 6;
                break;
            case 'review flag not boolean':
                $input[0]['review_required'] = 1;
                break;
        }

        $this->expectException(InvalidArgumentException::class);

        (new IntegrationClusterExtremaCandidates)->candidates('B', $input);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputCases(): iterable
    {
        $cases = [
            'not a list',
            'missing aspect',
            'extra aspect',
            'duplicate aspect',
            'out of order',
            'unknown aspect',
            'D aspect',
            'item not array',
            'missing key',
            'extra key',
            'level string',
            'level boolean',
            'level below range',
            'level above range',
            'review flag not boolean',
        ];

        foreach ($cases as $case) {
            yield $case => [$case];
        }
    }

    public function test_it_fails_closed_when_all_aspects_require_review(): void
    {
        $input = $this->validInput('C', [1, 2, 3, 4, 5, 4, 3]);
        foreach ($input as &$item) {
            $item['review_required'] = true;
        }
        unset($item);

        $this->expectException(InvalidArgumentException::class);

        (new IntegrationClusterExtremaCandidates)->candidates('C', $input);
    }

    /**
     * @param  'B'|'C'  $cluster
     * @param  list<int>  $levels
     * @return list<array{aspect: string, level: int, review_required: bool}>
     */
    private function validInput(string $cluster, array $levels): array
    {
        $aspects = $cluster === 'B'
            ? ['B1', 'B2', 'B3', 'B4']
            : ['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7'];

        return array_map(
            static fn (string $aspect, int $level): array => [
                'aspect' => $aspect,
                'level' => $level,
                'review_required' => false,
            ],
            $aspects,
            $levels,
        );
    }
}
