<?php

declare(strict_types=1);

namespace App\Services\TestNumber;

use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Date;
use LogicException;

final readonly class MonthlyTestNumberIssuer
{
    private const string RANDOM_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(private DatabaseManager $database) {}

    public function issue(?CarbonInterface $at = null): string
    {
        $period = $this->period($at);
        $sequence = $this->database->transaction(function () use ($period): int {
            $now = Date::now();
            $this->database->table('test_number_sequences')->insertOrIgnore([
                'period' => $period,
                'last_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = $this->database->table('test_number_sequences')
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            if ($row === null || ! is_numeric($row->last_value)) {
                throw new LogicException('Test number sequence could not be initialized.');
            }

            $next = (int) $row->last_value + 1;
            $this->database->table('test_number_sequences')
                ->where('period', $period)
                ->update(['last_value' => $next, 'updated_at' => $now]);

            return $next;
        }, 5);

        return sprintf('LSI-%s-%06d-%s', $period, $sequence, $this->randomSuffix());
    }

    public function prepare(?CarbonInterface $at = null): void
    {
        $now = Date::now();

        $this->database->table('test_number_sequences')->insertOrIgnore([
            'period' => $this->period($at),
            'last_value' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function period(?CarbonInterface $at): string
    {
        $instant = $at ?? Date::now();

        return $instant->clone()
            ->setTimezone((string) config('participant_auth.test_number_timezone'))
            ->format('Ym');
    }

    private function randomSuffix(): string
    {
        $suffix = '';
        $lastIndex = strlen(self::RANDOM_ALPHABET) - 1;

        for ($index = 0; $index < 6; $index++) {
            $suffix .= self::RANDOM_ALPHABET[random_int(0, $lastIndex)];
        }

        return $suffix;
    }
}
