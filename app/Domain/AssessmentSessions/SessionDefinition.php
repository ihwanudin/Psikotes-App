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

    private const SUBTEST_REQUIRED_FIELDS = [
        'code',
        'duration_seconds',
        'item_count',
    ];

    /**
     * F2 timed-segments stage 2 (2026-09-22): optional, not required, and
     * deliberately NOT filled with defaults in the array used for checksum
     * recomputation below -- only in the derived $segments/
     * $totalReadingCapSeconds properties, computed AFTER checksum
     * verification. A payload written before this stage never had these
     * keys; requiring them here would break replay of every already-
     * persisted PAPI/RMIB/Kraepelin session (IST cannot start yet, so this
     * is about the instruments that already have real sessions in
     * 'created'/'in_progress' status). See tasks/handoffs/f2/
     * timed-segments-plan.md's "data model changes" section.
     */
    private const SUBTEST_OPTIONAL_FIELDS = [
        'reading_cap_seconds',
        'allow_early_finish',
        'segments',
    ];

    private const SEGMENT_FIELDS = [
        'code',
        'duration_seconds',
        'reading_cap_seconds',
        'allow_early_finish',
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
     * @param  list<array<string, mixed>>  $subtests
     * @param  list<TimedSegment>  $segments  Derived, not part of the serialized
     *                                        shape: the whole session's segments,
     *                                        flattened to one global ordered list
     *                                        (one per subtest, except a subtest
     *                                        with its own `segments`, which
     *                                        contributes each of those instead).
     *                                        See flattenSegments().
     * @param  array{
     *     algorithm: string,
     *     version: string,
     *     columns: int,
     *     seconds_per_column: int,
     *     numbers_per_column: int,
     *     answer_slots_per_column: int
     * }|null  $generator
     * @param  list<int>  $segmentSubtestIndex  Derived: parallel to $segments,
     *                                          which index into $subtests each
     *                                          flattened segment came from. See
     *                                          flattenSegmentSubtestIndex().
     * @param  list<SubtestItemRange>  $subtestItemRanges  Derived: the item_no
     *                                                     range each subtest
     *                                                     owns, in order. See
     *                                                     subtestItemRanges().
     */
    private function __construct(
        public GenericAssessmentInstrument $instrument,
        public string $version,
        public string $provenance,
        public string $checksum,
        public int $totalDurationSeconds,
        public array $subtests,
        public array $segments,
        public int $totalReadingCapSeconds,
        public string $randomization,
        public ?string $seed,
        public ?array $generator,
        public array $segmentSubtestIndex = [],
        public array $subtestItemRanges = [],
    ) {}

    /**
     * F2 timed-segments stage 5 (2026-09-22): the item_no range
     * (1-indexed, inclusive both ends) belonging to whichever subtest owns
     * the flattened segment at $segmentIndex -- e.g. for a session where SE
     * covers 1-20 and WA covers 21-40, a $segmentIndex pointing anywhere
     * inside WA's own segment(s) (whether WA has one segment or several,
     * like ME's memorize/answer) returns [21, 40]. AutosaveAssessmentAnswers
     * uses this to reject an answer for an item_no outside the CURRENT
     * subtest's range, not just outside the whole instrument.
     */
    public function currentSubtestItemRange(int $segmentIndex): SubtestItemRange
    {
        $subtestIndex = $this->segmentSubtestIndex[$segmentIndex]
            ?? throw new InvalidArgumentException('Segment index is out of range.');

        return $this->subtestItemRanges[$subtestIndex];
    }

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

        // Derived from $subtests AFTER checksum verification, never fed back
        // into it -- see SUBTEST_OPTIONAL_FIELDS's docblock.
        $segments = self::flattenSegments($subtests);
        $totalReadingCapSeconds = array_sum(array_map(
            static fn (TimedSegment $segment): int => $segment->readingCapSeconds,
            $segments,
        ));
        $segmentSubtestIndex = self::flattenSegmentSubtestIndex($subtests);
        $subtestItemRanges = self::subtestItemRanges($subtests);

        return new self(
            instrument: $instrument,
            version: $version,
            provenance: $provenance,
            checksum: $checksum,
            totalDurationSeconds: $totalDurationSeconds,
            subtests: $subtests,
            segments: $segments,
            totalReadingCapSeconds: $totalReadingCapSeconds,
            randomization: $randomization,
            segmentSubtestIndex: $segmentSubtestIndex,
            subtestItemRanges: $subtestItemRanges,
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
     *     subtests: list<array<string, mixed>>,
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
     * @return list<array<string, mixed>>
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

            self::assertKnownFields(
                $subtest,
                self::SUBTEST_REQUIRED_FIELDS,
                self::SUBTEST_OPTIONAL_FIELDS,
                'Session definition subtest',
            );
            $code = self::nonBlankString($subtest['code'], 'Session definition subtest code');
            self::assertUniqueCode($codes, $code);

            $durationSeconds = self::positiveInteger(
                $subtest['duration_seconds'],
                'Session definition subtest duration',
            );
            $itemCount = self::positiveInteger($subtest['item_count'], 'Session definition subtest item count');
            $durationSum += $durationSeconds;

            $normalized = [
                'code' => $code,
                'duration_seconds' => $durationSeconds,
                'item_count' => $itemCount,
            ];

            // Left absent (never defaulted) when the input omits them --
            // required for old, already-persisted payloads to still
            // checksum-verify. See SUBTEST_OPTIONAL_FIELDS's docblock.
            if (array_key_exists('reading_cap_seconds', $subtest)) {
                $normalized['reading_cap_seconds'] = self::nonNegativeInteger(
                    $subtest['reading_cap_seconds'],
                    'Session definition subtest reading cap',
                );
            }
            if (array_key_exists('allow_early_finish', $subtest)) {
                $normalized['allow_early_finish'] = self::boolean(
                    $subtest['allow_early_finish'],
                    'Session definition subtest allow_early_finish',
                );
            }
            if (array_key_exists('segments', $subtest)) {
                $normalized['segments'] = self::segments($subtest['segments'], $durationSeconds, $codes);
            }

            $subtests[] = $normalized;
        }

        if ($durationSum !== $totalDurationSeconds) {
            throw new InvalidArgumentException('Session definition subtest durations must equal the total duration.');
        }

        return $subtests;
    }

    /**
     * Only present when a subtest has more than one timed phase (IST's ME
     * today). Each segment's own reading_cap_seconds/allow_early_finish are
     * required here (not optional like the parent subtest's) -- this key
     * never existed before this stage, so there is no old-payload
     * compatibility concern for it.
     *
     * @param  array<string, bool>  $codes  Shared code registry across the
     *                                      whole definition, passed by
     *                                      reference so segment codes and
     *                                      subtest codes can never collide --
     *                                      current_segment.code must
     *                                      unambiguously address exactly one
     *                                      entry in the flattened session-wide
     *                                      segment list.
     * @return list<array{code: string, duration_seconds: int, reading_cap_seconds: int, allow_early_finish: bool}>
     */
    private static function segments(mixed $value, int $subtestDurationSeconds, array &$codes): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('Session definition subtest segments must be a non-empty list.');
        }

        $segments = [];
        $durationSum = 0;

        foreach ($value as $segment) {
            if (! is_array($segment)) {
                throw new InvalidArgumentException('Session definition segment must be an object.');
            }

            self::assertExactFields($segment, self::SEGMENT_FIELDS, 'Session definition segment');
            $code = self::nonBlankString($segment['code'], 'Session definition segment code');
            self::assertUniqueCode($codes, $code);

            $durationSeconds = self::positiveInteger(
                $segment['duration_seconds'],
                'Session definition segment duration',
            );
            $readingCapSeconds = self::nonNegativeInteger(
                $segment['reading_cap_seconds'],
                'Session definition segment reading cap',
            );
            $allowEarlyFinish = self::boolean(
                $segment['allow_early_finish'],
                'Session definition segment allow_early_finish',
            );
            $durationSum += $durationSeconds;

            $segments[] = [
                'code' => $code,
                'duration_seconds' => $durationSeconds,
                'reading_cap_seconds' => $readingCapSeconds,
                'allow_early_finish' => $allowEarlyFinish,
            ];
        }

        if ($durationSum !== $subtestDurationSeconds) {
            throw new InvalidArgumentException('Session definition segment durations must equal the parent subtest duration.');
        }

        return $segments;
    }

    /**
     * The whole session's segments as one global ordered list: one entry
     * per subtest, except a subtest carrying its own `segments`, which
     * contributes each of those instead of the subtest itself. A subtest
     * with no explicit `segments` degenerates to a single implicit segment
     * built from its own code/duration/reading-cap/early-finish (defaulting
     * the latter two to 0/false here -- this is the one place old-payload
     * absence is finally defaulted, purely for runtime use, never fed back
     * into anything checksummed).
     *
     * @param  list<array<string, mixed>>  $subtests
     * @return list<TimedSegment>
     */
    private static function flattenSegments(array $subtests): array
    {
        $segments = [];

        foreach ($subtests as $subtest) {
            if (isset($subtest['segments'])) {
                foreach ($subtest['segments'] as $segment) {
                    $segments[] = new TimedSegment(
                        $segment['code'],
                        $segment['duration_seconds'],
                        $segment['reading_cap_seconds'],
                        $segment['allow_early_finish'],
                    );
                }

                continue;
            }

            $segments[] = new TimedSegment(
                $subtest['code'],
                $subtest['duration_seconds'],
                $subtest['reading_cap_seconds'] ?? 0,
                $subtest['allow_early_finish'] ?? false,
            );
        }

        return $segments;
    }

    /**
     * Parallel to flattenSegments(): for each entry in the flattened
     * $segments list, which index into $subtests it came from.
     *
     * @param  list<array<string, mixed>>  $subtests
     * @return list<int>
     */
    private static function flattenSegmentSubtestIndex(array $subtests): array
    {
        $map = [];

        foreach ($subtests as $subtestIndex => $subtest) {
            $segmentCount = isset($subtest['segments']) ? count($subtest['segments']) : 1;
            for ($i = 0; $i < $segmentCount; $i++) {
                $map[] = $subtestIndex;
            }
        }

        return $map;
    }

    /**
     * The 1-indexed, inclusive item_no range each subtest owns, in order --
     * e.g. item_count 20 then 20 produces [[1,20],[21,40]].
     *
     * @param  list<array<string, mixed>>  $subtests
     * @return list<SubtestItemRange>
     */
    private static function subtestItemRanges(array $subtests): array
    {
        $ranges = [];
        $cursor = 1;

        foreach ($subtests as $subtest) {
            $itemCount = $subtest['item_count'];
            $ranges[] = new SubtestItemRange($cursor, $cursor + $itemCount - 1);
            $cursor += $itemCount;
        }

        return $ranges;
    }

    /** @param  array<string, bool>  $codes */
    private static function assertUniqueCode(array &$codes, string $code): void
    {
        if (isset($codes[$code])) {
            throw new InvalidArgumentException('Session definition codes must be unique.');
        }

        $codes[$code] = true;
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
     * @param  list<array<string, mixed>>  $subtests
     * @return array{
     *     string,
     *     null,
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
        // F2 (2026-09-21): owner decision "Kraepelin numbers are fixed, not
        // seeded" -- the answer numbers come from the official sheet,
        // identical for every participant, not generated per session. This
        // used to require 'seeded' randomization with a real seed; it now
        // requires 'fixed' with no seed, exactly like ist/papi/rmib
        // (fixedConfiguration() below). The `generator` block stays required
        // and validated: it describes the grid's structural shape/timing
        // (50 columns, 15s/column, 28 numbers/column, 27 answer slots), not
        // a randomization seed.
        if ($randomization !== 'fixed' || $seed !== null) {
            throw new InvalidArgumentException('Kraepelin definitions must be fixed and contain no seed -- the answer numbers come from the official sheet, identical for every participant, not generated per session.');
        }

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

        return ['fixed', null, [
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

    /**
     * Like assertExactFields(), but $optional keys may be absent -- used
     * where old, already-persisted payloads must keep parsing after a
     * field becomes available going forward. Still rejects anything not in
     * $required or $optional, and still requires everything in $required.
     *
     * @param  array<string, mixed>  $value
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    private static function assertKnownFields(array $value, array $required, array $optional, string $label): void
    {
        $actual = array_keys($value);
        $allowed = array_merge($required, $optional);

        if (array_diff($actual, $allowed) !== [] || array_diff($required, $actual) !== []) {
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

    private static function nonNegativeInteger(mixed $value, string $label): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("{$label} must be a non-negative integer.");
        }

        return $value;
    }

    private static function boolean(mixed $value, string $label): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException("{$label} must be a boolean.");
        }

        return $value;
    }
}
