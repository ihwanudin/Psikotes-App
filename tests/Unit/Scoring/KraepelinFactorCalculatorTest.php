<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\KraepelinFactorCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KraepelinFactorCalculatorTest extends TestCase
{
    public function test_first_f0_golden_calculates_all_factors_and_provenance(): void
    {
        $achievement = [18, 19, 19, 18, 15, 19, 17, 17, 13, 16, 16, 13, 14, 15, 16, 14, 15, 15, 15, 15, 13, 16, 12, 18, 18, 16, 16, 15, 15, 17, 15, 18, 16, 16, 16, 15, 15, 14, 15, 18, 17, 16, 13, 15, 16, 18, 17, 17, 14, 17];
        $columns = self::columns($achievement, [0 => 7]);

        $result = (new KraepelinFactorCalculator)->calculate($columns);

        $this->assertSame(15.86, $result['panker']);
        $this->assertSame(7, $result['tianker']);
        $this->assertSame(-0.622, $result['hanker']);
        $this->assertSame(7, $result['janker']);
        $this->assertFalse($result['review_required']);
        $this->assertNull($result['review_reason']);
        $this->assertSame([
            'column_count' => 50,
            'sum_achievement' => 793,
            'sum_correct' => 786,
            'sum_incorrect' => 7,
            'sum_skipped' => 0,
            'sum_x' => 1275,
            'sum_xy' => 20092,
            'sum_x_squared' => 42925,
            'slope' => -0.012437,
        ], $result['provenance']);
    }

    public function test_second_f0_golden_uses_final_hanker_slope_times_fifty_formula(): void
    {
        $achievement = [10, 11, 11, 10, 12, 11, 11, 12, 12, 11, 12, 12, 11, 12, 13, 12, 12, 13, 12, 13, 12, 13, 13, 12, 14, 13, 13, 14, 13, 14, 13, 14, 14, 13, 15, 14, 14, 15, 14, 15, 14, 15, 15, 14, 16, 15, 15, 16, 15, 16];
        $columns = self::columns($achievement, [0 => 4], [1 => 1]);

        $result = (new KraepelinFactorCalculator)->calculate($columns);

        $this->assertSame(13.12, $result['panker']);
        $this->assertSame(5, $result['tianker']);
        $this->assertSame(5.032, $result['hanker']);
        $this->assertSame(6, $result['janker']);
        $this->assertFalse($result['review_required']);
        $this->assertNull($result['review_reason']);
        $this->assertSame(656, $result['provenance']['sum_achievement']);
        $this->assertSame(652, $result['provenance']['sum_correct']);
        $this->assertSame(4, $result['provenance']['sum_incorrect']);
        $this->assertSame(1, $result['provenance']['sum_skipped']);
        $this->assertSame(17776, $result['provenance']['sum_xy']);
        $this->assertSame(0.100648, $result['provenance']['slope']);
    }

    public function test_column_capacity_boundary_accepts_complete_and_partial_columns(): void
    {
        $columns = self::columns(array_fill(0, 50, 10));
        $columns[0] = ['achievement' => 27, 'correct' => 27, 'incorrect' => 0, 'skipped' => 0];
        $columns[1] = ['achievement' => 26, 'correct' => 25, 'incorrect' => 1, 'skipped' => 1];

        $result = (new KraepelinFactorCalculator)->calculate($columns);

        $this->assertSame(53, $result['provenance']['sum_achievement'] - 48 * 10);
        $this->assertSame(1, $result['provenance']['sum_skipped']);
    }

    public function test_hanker_above_janker_exposes_machine_readable_review_flag(): void
    {
        $columns = self::columns([...array_fill(0, 25, 0), ...array_fill(0, 25, 27)]);

        $result = (new KraepelinFactorCalculator)->calculate($columns);

        $this->assertGreaterThan($result['janker'], abs($result['hanker']));
        $this->assertTrue($result['review_required']);
        $this->assertSame('absolute_hanker_exceeds_janker', $result['review_reason']);
    }

    public function test_hanker_not_above_janker_does_not_require_review(): void
    {
        $result = (new KraepelinFactorCalculator)->calculate(self::columns(array_fill(0, 50, 10)));

        $this->assertSame(0.0, $result['hanker']);
        $this->assertSame(0, $result['janker']);
        $this->assertFalse($result['review_required']);
        $this->assertNull($result['review_reason']);
    }

    /** @param array<mixed> $columns */
    #[DataProvider('invalidColumns')]
    public function test_invalid_column_payload_fails_closed(array $columns, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new KraepelinFactorCalculator)->calculate($columns);
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidColumns(): iterable
    {
        $valid = self::columns(array_fill(0, 50, 10));

        yield 'missing column' => [array_slice($valid, 0, 49), 'Kraepelin input must contain exactly 50 columns.'];
        yield 'extra column' => [[...$valid, $valid[0]], 'Kraepelin input must contain exactly 50 columns.'];

        $notAList = $valid;
        unset($notAList[1]);
        yield 'not a list' => [$notAList, 'Kraepelin input must contain exactly 50 columns.'];

        $missingField = $valid;
        unset($missingField[0]['skipped']);
        yield 'missing field' => [$missingField, 'Each Kraepelin column must contain integer achievement, correct, incorrect, and skipped values.'];

        $stringValue = $valid;
        $stringValue[0]['correct'] = '10';
        yield 'numeric string' => [$stringValue, 'Each Kraepelin column must contain integer achievement, correct, incorrect, and skipped values.'];

        $negative = $valid;
        $negative[0] = ['achievement' => 0, 'correct' => 0, 'incorrect' => 0, 'skipped' => -1];
        yield 'negative value' => [$negative, 'Kraepelin column values must be non-negative.'];

        $tooMany = $valid;
        $tooMany[0] = ['achievement' => 28, 'correct' => 28, 'incorrect' => 0, 'skipped' => 0];
        yield 'achievement above domain' => [$tooMany, 'Kraepelin achievement cannot exceed 27.'];

        $inconsistent = $valid;
        $inconsistent[0]['incorrect'] = 1;
        yield 'inconsistent achievement' => [$inconsistent, 'Kraepelin correct plus incorrect must equal achievement.'];

        $overCapacity = $valid;
        $overCapacity[0] = ['achievement' => 27, 'correct' => 27, 'incorrect' => 0, 'skipped' => 1];
        yield 'achievement plus skipped above capacity' => [$overCapacity, 'Kraepelin achievement plus skipped cannot exceed 27.'];
    }

    /**
     * @param  list<int>  $achievement
     * @param  array<int, int>  $incorrectByColumn
     * @param  array<int, int>  $skippedByColumn
     * @return list<array{achievement: int, correct: int, incorrect: int, skipped: int}>
     */
    private static function columns(array $achievement, array $incorrectByColumn = [], array $skippedByColumn = []): array
    {
        return array_map(
            static function (int $value, int $index) use ($incorrectByColumn, $skippedByColumn): array {
                $incorrect = $incorrectByColumn[$index] ?? 0;

                return [
                    'achievement' => $value,
                    'correct' => $value - $incorrect,
                    'incorrect' => $incorrect,
                    'skipped' => $skippedByColumn[$index] ?? 0,
                ];
            },
            $achievement,
            array_keys($achievement),
        );
    }
}
