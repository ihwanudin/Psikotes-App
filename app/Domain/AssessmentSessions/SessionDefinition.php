<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use InvalidArgumentException;
use JsonException;

final readonly class SessionDefinition
{
    private const REQUIRED_FIELDS = [
        'instrument',
        'version',
        'provenance',
        'checksum',
        'total_duration_seconds',
        'subtests',
        'randomization',
        'seed',
        'generator',
    ];

    private const SUBTEST_FIELDS = [
        'code',
        'duration_seconds',
        'item_count',
    ];

    private const KRAEPELIN_GENERATOR_FIELDS = [
        'algorithm',
        'version',
        'columns',
        'seconds_per_column',
        'numbers_per_column',
        'answer_slots_per_column',
    ];

    private const KRAEPELIN_COLUMNS = 50;

    private const KRAEPELIN_SECONDS_PER_COLUMN = 15;

    private const KRAEPELIN_NUMBERS_PER_COLUMN = 28;

    private const KRAEPELIN_ANSWER_SLOTS_PER_COLUMN = 27;

    /**
     * @param  list<array{code: string, duration_seconds: int, item_count: int}>  $subtests
     * @param  array{
     *     algorithm: string,
     *     version: string,
     *     columns: int,
     *     seconds_per_column: int,
     *     numbers_per_column: int,
     *     answer_slots_per_column: int
     * }|null  $generator
     */
    private function __construct(
        public GenericAssessmentInstrument $instrument,
        public string $version,
        public string $provenance,
        public string $checksum,
        public int $totalDurationSeconds,
        public array $subtests,
        public string $randomization,
        public ?string $seed,
        public ?array $generator,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        self::assertExactFields($input, self::REQUIRED_FIELDS, 'Session definition');

        $instrumentValue = $input['instrument'];
        if (! is_string($instrumentValue)) {
            throw new InvalidArgumentException('Session definition instrument must be a string.');
        }

        $instrument = GenericAssessmentInstrument::fromExternal($instrumentValue);
        $version = self::nonBlankString($input['version'], 'Session definition version');
        $provenance = self::nonBlankString($input['provenance'], 'Session definition provenance');
        $checksum = self::checksum($input['checksum']);
        $totalDurationSeconds = self::positiveInteger(
            $input['total_duration_seconds'],
            'Session definition total duration',
        );
        $subtests = self::subtests($input['subtests'], $totalDurationSeconds);
        $randomization = $input['randomization'];
        $seed = $input['seed'];
        $generator = $input['generator'];

        if ($instrument === GenericAssessmentInstrument::Kraepelin) {
            [$randomization, $seed, $generator] = self::kraepelinConfiguration(
                $randomization,
                $seed,
                $generator,
                $totalDurationSeconds,
                $subtests,
            );
        } else {
            [$randomization, $seed, $generator] = self::fixedConfiguration(
                $randomization,
                $seed,
                $generator,
            );
        }

        $expectedChecksum = self::checksumFor([
            'instrument' => $instrument->value,
            'version' => $version,
            'provenance' => $provenance,
            'total_duration_seconds' => $totalDurationSeconds,
            'subtests' => $subtests,
            'randomization' => $randomization,
            'seed' => $seed,
            'generator' => $generator,
        ]);

        if (! hash_equals($expectedChecksum, $checksum)) {
            throw new InvalidArgumentException('Session definition checksum does not match its canonical payload.');
        }

        return new self(
            instrument: $instrument,
            version: $version,
            provenance: $provenance,
            checksum: $checksum,
            totalDurationSeconds: $totalDurationSeconds,
            subtests: $subtests,
            randomization: $randomization,
            seed: $seed,
            generator: $generator,
        );
    }

    /**
     * @return array{
     *     instrument: string,
     *     version: string,
     *     provenance: string,
     *     checksum: string,
     *     total_duration_seconds: int,
     *     subtests: list<array{code: string, duration_seconds: int, item_count: int}>,
     *     randomization: string,
     *     seed: string|null,
     *     generator: array{
     *         algorithm: string,
     *         version: string,
     *         columns: int,
     *         seconds_per_column: int,
     *         numbers_per_column: int,
     *         answer_slots_per_column: int
     *     }|null
     * }
     */
    public function toArray(): array
    {
        return [
            'instrument' => $this->instrument->value,
            'version' => $this->version,
            'provenance' => $this->provenance,
            'checksum' => $this->checksum,
            'total_duration_seconds' => $this->totalDurationSeconds,
            'subtests' => $this->subtests,
            'randomization' => $this->randomization,
            'seed' => $this->seed,
            'generator' => $this->generator,
        ];
    }

    /** @param array<string, mixed> $input */
    public static function checksumFor(array $input): string
    {
        unset($input['checksum']);
        self::assertExactFields(
            $input,
            array_values(array_diff(self::REQUIRED_FIELDS, ['checksum'])),
            'Session definition checksum payload',
        );

        try {
            return hash('sha256', json_encode(
                self::canonicalize($input),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Session definition checksum payload is not encodable.', previous: $exception);
        }
    }

    /**
     * @return list<array{code: string, duration_seconds: int, item_count: int}>
     */
    private static function subtests(mixed $value, int $totalDurationSeconds): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('Session definition subtests must be a non-empty list.');
        }

        $subtests = [];
        $codes = [];
        $durationSum = 0;

        foreach ($value as $subtest) {
            if (! is_array($subtest)) {
                throw new InvalidArgumentException('Session definition subtest must be an object.');
            }

            self::assertExactFields($subtest, self::SUBTEST_FIELDS, 'Session definition subtest');
            $code = self::nonBlankString($subtest['code'], 'Session definition subtest code');

            if (isset($codes[$code])) {
                throw new InvalidArgumentException('Session definition subtest codes must be unique.');
            }

            $durationSeconds = self::positiveInteger(
                $subtest['duration_seconds'],
                'Session definition subtest duration',
            );
            $itemCount = self::positiveInteger($subtest['item_count'], 'Session definition subtest item count');
            $codes[$code] = true;
            $durationSum += $durationSeconds;
            $subtests[] = [
                'code' => $code,
                'duration_seconds' => $durationSeconds,
                'item_count' => $itemCount,
            ];
        }

        if ($durationSum !== $totalDurationSeconds) {
            throw new InvalidArgumentException('Session definition subtest durations must equal the total duration.');
        }

        return $subtests;
    }

    /** @return array{string, null, null} */
    private static function fixedConfiguration(mixed $randomization, mixed $seed, mixed $generator): array
    {
        if ($randomization !== 'fixed' || $seed !== null || $generator !== null) {
            throw new InvalidArgumentException('IST, PAPI, and RMIB definitions must be fixed and contain no seed or generator.');
        }

        return ['fixed', null, null];
    }

    /**
     * @param  list<array{code: string, duration_seconds: int, item_count: int}>  $subtests
     * @return array{
     *     string,
     *     string,
     *     array{
     *         algorithm: string,
     *         version: string,
     *         columns: int,
     *         seconds_per_column: int,
     *         numbers_per_column: int,
     *         answer_slots_per_column: int
     *     }
     * }
     */
    private static function kraepelinConfiguration(
        mixed $randomization,
        mixed $seed,
        mixed $generator,
        int $totalDurationSeconds,
        array $subtests,
    ): array {
        if ($randomization !== 'seeded') {
            throw new InvalidArgumentException('Kraepelin definitions must use seeded randomization.');
        }

        $seed = self::nonBlankString($seed, 'Kraepelin seed');

        if (! is_array($generator)) {
            throw new InvalidArgumentException('Kraepelin generator configuration is required.');
        }

        self::assertExactFields($generator, self::KRAEPELIN_GENERATOR_FIELDS, 'Kraepelin generator');
        $algorithm = self::nonBlankString($generator['algorithm'], 'Kraepelin generator algorithm');
        $version = self::nonBlankString($generator['version'], 'Kraepelin generator version');
        $columns = self::positiveInteger($generator['columns'], 'Kraepelin columns');
        $secondsPerColumn = self::positiveInteger(
            $generator['seconds_per_column'],
            'Kraepelin seconds per column',
        );
        $numbersPerColumn = self::positiveInteger(
            $generator['numbers_per_column'],
            'Kraepelin numbers per column',
        );
        $answerSlotsPerColumn = self::positiveInteger(
            $generator['answer_slots_per_column'],
            'Kraepelin answer slots per column',
        );

        if (
            $columns !== self::KRAEPELIN_COLUMNS
            || $secondsPerColumn !== self::KRAEPELIN_SECONDS_PER_COLUMN
            || $numbersPerColumn !== self::KRAEPELIN_NUMBERS_PER_COLUMN
            || $answerSlotsPerColumn !== self::KRAEPELIN_ANSWER_SLOTS_PER_COLUMN
            || $totalDurationSeconds !== $columns * $secondsPerColumn
        ) {
            throw new InvalidArgumentException('Kraepelin definition must use the canonical 50-column shape and timing.');
        }

        $itemCount = array_sum(array_column($subtests, 'item_count'));
        if ($itemCount !== $columns * $answerSlotsPerColumn) {
            throw new InvalidArgumentException('Kraepelin subtest item count must match its answer matrix.');
        }

        return ['seeded', $seed, [
            'algorithm' => $algorithm,
            'version' => $version,
            'columns' => $columns,
            'seconds_per_column' => $secondsPerColumn,
            'numbers_per_column' => $numbersPerColumn,
            'answer_slots_per_column' => $answerSlotsPerColumn,
        ]];
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private static function assertExactFields(array $value, array $expected, string $label): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new InvalidArgumentException("{$label} fields are incomplete or unknown.");
        }
    }

    private static function nonBlankString(mixed $value, string $label): string
    {
        if (
            ! is_string($value)
            || $value === ''
            || $value !== trim($value)
            || preg_match('/[\p{C}\p{Z}\s]/u', $value) !== 0
        ) {
            throw new InvalidArgumentException("{$label} must be a canonical non-blank identity string.");
        }

        return $value;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $entry) {
            $value[$key] = self::canonicalize($entry);
        }

        return $value;
    }

    private static function checksum(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Session definition checksum must be a lowercase SHA-256 digest.');
        }

        return $value;
    }

    private static function positiveInteger(mixed $value, string $label): int
    {
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("{$label} must be a positive integer.");
        }

        return $value;
    }
}
