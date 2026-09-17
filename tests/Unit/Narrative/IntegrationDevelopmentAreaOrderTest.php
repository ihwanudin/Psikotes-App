<?php

declare(strict_types=1);

namespace Tests\Unit\Narrative;

use App\Domain\Narrative\IntegrationDevelopmentAreaOrder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntegrationDevelopmentAreaOrderTest extends TestCase
{
    public function test_it_selects_and_orders_development_areas_with_typed_review_omissions(): void
    {
        $input = $this->validInput();
        $this->setZone($input, 'A1', 'BELUM');
        $this->setZone($input, 'A2', 'GREY');
        $this->setZone($input, 'B1', 'BELUM');
        $this->setZone($input, 'B2', 'GREY', true);
        $this->setZone($input, 'C1', 'GREY');
        $this->setZone($input, 'C2', 'BELUM');
        $this->setZone($input, 'C3', 'BELUM', true);

        $result = $this->order()->order($input);

        $this->assertSame([
            'type' => 'integration_development_area_order',
            'ordered_aspects' => [
                ['aspect' => 'C2', 'zone' => 'BELUM', 'source_position' => 8],
                ['aspect' => 'A1', 'zone' => 'BELUM', 'source_position' => 1],
                ['aspect' => 'B1', 'zone' => 'BELUM', 'source_position' => 3],
                ['aspect' => 'C1', 'zone' => 'GREY', 'source_position' => 7],
                ['aspect' => 'A2', 'zone' => 'GREY', 'source_position' => 2],
            ],
            'review_required' => true,
            'omitted_aspects' => [
                ['aspect' => 'B2', 'zone' => 'GREY', 'source_position' => 4],
                ['aspect' => 'C3', 'zone' => 'BELUM', 'source_position' => 9],
            ],
        ], $result);
    }

    public function test_equal_priority_ties_use_aspect_code_and_identical_input_is_deterministic(): void
    {
        $input = $this->validInput();
        $this->setZone($input, 'A2', 'BELUM');
        $this->setZone($input, 'B1', 'BELUM');
        $this->setZone($input, 'B2', 'BELUM');
        $this->setZone($input, 'C5', 'BELUM');

        $first = $this->order()->order($input);
        $second = $this->order()->order($input);

        $this->assertSame(['C5', 'A2', 'B1', 'B2'], array_column($first['ordered_aspects'], 'aspect'));
        $this->assertSame($first, $second);
    }

    public function test_all_ok_input_returns_a_stable_empty_selection(): void
    {
        $input = $this->validInput();

        $this->assertSame([
            'type' => 'integration_development_area_order',
            'ordered_aspects' => [],
            'review_required' => false,
            'omitted_aspects' => [],
        ], $this->order()->order($input));
    }

    #[DataProvider('invalidInputCases')]
    public function test_it_fails_closed_on_noncanonical_input(string $case): void
    {
        $input = $this->validInput();

        switch ($case) {
            case 'not a list':
                $input = [1 => $input[0]];
                break;
            case 'missing aspect':
                array_pop($input);
                break;
            case 'extra aspect':
                $input[] = ['aspect' => 'D1', 'zone' => 'BELUM', 'review_required' => false];
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
                unset($input[0]['zone']);
                break;
            case 'extra key':
                $input[0]['level'] = 2;
                break;
            case 'zone not string':
                $input[0]['zone'] = true;
                break;
            case 'unknown zone':
                $input[0]['zone'] = 'UNASSESSED';
                break;
            case 'review flag not boolean':
                $input[0]['review_required'] = 1;
                break;
        }

        $this->expectException(InvalidArgumentException::class);

        $this->order()->order($input);
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
            'zone not string',
            'unknown zone',
            'review flag not boolean',
        ];

        foreach ($cases as $case) {
            yield $case => [$case];
        }
    }

    /** @param array<mixed> $priorities */
    #[DataProvider('invalidPriorityCases')]
    public function test_it_fails_closed_on_noncanonical_cluster_priorities(array $priorities): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IntegrationDevelopmentAreaOrder($priorities);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidPriorityCases(): iterable
    {
        yield 'missing cluster' => [['A' => 0.3, 'B' => 0.3]];
        yield 'extra cluster' => [['A' => 0.3, 'B' => 0.3, 'C' => 0.4, 'D' => 0.0]];
        yield 'wrong key order' => [['C' => 0.4, 'A' => 0.3, 'B' => 0.3]];
        yield 'wrong priority value' => [['A' => 0.3, 'B' => 0.2, 'C' => 0.4]];
        yield 'string priority value' => [['A' => '0.3', 'B' => 0.3, 'C' => 0.4]];
    }

    private function order(): IntegrationDevelopmentAreaOrder
    {
        return new IntegrationDevelopmentAreaOrder(['A' => 0.3, 'B' => 0.3, 'C' => 0.4]);
    }

    /** @return list<array{aspect: string, zone: 'OK'|'GREY'|'BELUM', review_required: bool}> */
    private function validInput(): array
    {
        $aspects = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7'];

        return array_map(
            static fn (string $aspect): array => [
                'aspect' => $aspect,
                'zone' => 'OK',
                'review_required' => false,
            ],
            $aspects,
        );
    }

    /**
     * @param  list<array{aspect: string, zone: 'OK'|'GREY'|'BELUM', review_required: bool}>  $input
     * @param  'OK'|'GREY'|'BELUM'  $zone
     */
    private function setZone(array &$input, string $aspect, string $zone, bool $reviewRequired = false): void
    {
        foreach ($input as &$item) {
            if ($item['aspect'] === $aspect) {
                $item['zone'] = $zone;
                $item['review_required'] = $reviewRequired;

                return;
            }
        }

        self::fail("Canonical aspect {$aspect} is missing from the test fixture.");
    }
}
