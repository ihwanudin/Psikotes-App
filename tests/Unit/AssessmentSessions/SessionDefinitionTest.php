<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\AssessmentSessions\TimedSegment;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionDefinitionTest extends TestCase
{
    #[DataProvider('fixedInstruments')]
    public function test_it_builds_complete_fixed_instrument_definitions(string $instrument): void
    {
        $definition = SessionDefinition::fromArray($this->fixedDefinition($instrument));

        $this->assertSame(GenericAssessmentInstrument::fromExternal($instrument), $definition->instrument);
        $this->assertSame('synthetic-v1', $definition->version);
        $this->assertSame('synthetic-test-fixture', $definition->provenance);
        $this->assertSame(SessionDefinition::checksumFor($this->fixedDefinition($instrument)), $definition->checksum);
        $this->assertSame(60, $definition->totalDurationSeconds);
        $this->assertSame('fixed', $definition->randomization);
        $this->assertNull($definition->seed);
        $this->assertNull($definition->generator);
    }

    /** @return iterable<string, array{string}> */
    public static function fixedInstruments(): iterable
    {
        yield 'IST' => ['ist'];
        yield 'PAPI' => ['papi'];
        yield 'RMIB' => ['rmib'];
    }

    public function test_it_builds_only_the_exact_complete_kraepelin_shape(): void
    {
        $definition = SessionDefinition::fromArray($this->kraepelinDefinition());

        $this->assertSame(GenericAssessmentInstrument::Kraepelin, $definition->instrument);
        $this->assertSame(750, $definition->totalDurationSeconds);
        $this->assertSame('fixed', $definition->randomization);
        $this->assertNull($definition->seed);
        $this->assertSame([
            'algorithm' => 'synthetic-generator',
            'version' => 'synthetic-generator-v1',
            'columns' => 50,
            'seconds_per_column' => 15,
            'numbers_per_column' => 28,
            'answer_slots_per_column' => 27,
        ], $definition->generator);
    }

    #[DataProvider('fixedInstruments')]
    public function test_it_exports_fixed_definitions_as_exact_canonical_round_trips(string $instrument): void
    {
        $input = $this->fixedDefinition($instrument);
        $reordered = array_reverse($input, preserve_keys: true);
        $reordered['subtests'][0] = array_reverse($reordered['subtests'][0], preserve_keys: true);

        $exported = SessionDefinition::fromArray($reordered)->toArray();

        $this->assertSame([
            'instrument',
            'version',
            'provenance',
            'checksum',
            'total_duration_seconds',
            'subtests',
            'randomization',
            'seed',
            'generator',
        ], array_keys($exported));
        $this->assertSame($input, $exported);
        $this->assertEquals(SessionDefinition::fromArray($input), SessionDefinition::fromArray($exported));
    }

    public function test_it_exports_fixed_kraepelin_as_an_exact_canonical_round_trip(): void
    {
        $input = $this->kraepelinDefinition();
        $reordered = array_reverse($input, preserve_keys: true);
        $reordered['subtests'][0] = array_reverse($reordered['subtests'][0], preserve_keys: true);
        $reordered['generator'] = array_reverse($reordered['generator'], preserve_keys: true);

        $exported = SessionDefinition::fromArray($reordered)->toArray();

        $this->assertSame($input, $exported);
        $this->assertSame('synthetic-v1', $exported['version']);
        $this->assertSame('synthetic-test-fixture', $exported['provenance']);
        $this->assertSame(750, $exported['total_duration_seconds']);
        $this->assertSame('fixed', $exported['randomization']);
        $this->assertNull($exported['seed']);
        $this->assertSame($input['generator'], $exported['generator']);
        $this->assertSame($input['checksum'], $exported['checksum']);
        $this->assertEquals(SessionDefinition::fromArray($input), SessionDefinition::fromArray($exported));
    }

    public function test_exported_snapshots_do_not_mutate_the_readonly_definition(): void
    {
        $definition = SessionDefinition::fromArray($this->kraepelinDefinition());
        $expected = $definition->toArray();
        $exported = $definition->toArray();

        $exported['version'] = 'mutated';
        $exported['subtests'][0]['duration_seconds'] = 1;
        $exported['generator']['columns'] = 1;

        $this->assertTrue((new \ReflectionClass($definition))->isReadOnly());
        $this->assertSame($expected, $definition->toArray());
    }

    public function test_it_rejects_a_checksum_reused_for_different_definition_content(): void
    {
        $input = $this->fixedDefinition('ist');
        $input['total_duration_seconds'] = 120;
        $input['subtests'][0]['duration_seconds'] = 120;

        $this->expectException(InvalidArgumentException::class);

        SessionDefinition::fromArray($input);
    }

    public function test_its_checksum_is_canonical_across_associative_field_order(): void
    {
        $input = $this->fixedDefinition('ist');
        $reordered = array_reverse($input, preserve_keys: true);
        $reordered['subtests'][0] = array_reverse($reordered['subtests'][0], preserve_keys: true);

        $this->assertSame(
            SessionDefinition::checksumFor($input),
            SessionDefinition::checksumFor($reordered),
        );
    }

    #[DataProvider('unsupportedInstruments')]
    public function test_it_rejects_dass_and_unknown_instruments(string $instrument): void
    {
        $input = $this->fixedDefinition('ist');
        $input['instrument'] = $instrument;

        $this->expectException(UnsupportedGenericAssessmentInstrument::class);

        SessionDefinition::fromArray($input);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedInstruments(): iterable
    {
        yield 'DASS-21 isolated path' => ['dass21'];
        yield 'unknown' => ['unknown'];
    }

    #[DataProvider('invalidCommonDefinitions')]
    public function test_it_rejects_incomplete_or_inconsistent_common_definitions(callable $mutate): void
    {
        $input = $this->fixedDefinition('ist');
        $mutate($input);

        $this->expectException(InvalidArgumentException::class);

        SessionDefinition::fromArray($input);
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void}> */
    public static function invalidCommonDefinitions(): iterable
    {
        yield 'missing field' => [static function (array &$input): void {
            unset($input['version']);
        }];
        yield 'unknown field' => [static function (array &$input): void {
            $input['duration'] = 60;
        }];
        yield 'blank version' => [static function (array &$input): void {
            $input['version'] = ' ';
        }];
        yield 'blank provenance' => [static function (array &$input): void {
            $input['provenance'] = '';
        }];
        yield 'invalid checksum' => [static function (array &$input): void {
            $input['checksum'] = 'not-a-sha256';
        }];
        yield 'invalid total duration' => [static function (array &$input): void {
            $input['total_duration_seconds'] = 0;
        }];
        yield 'subtest duration sum mismatch' => [static function (array &$input): void {
            $input['total_duration_seconds'] = 61;
        }];
        yield 'duplicate subtest code' => [static function (array &$input): void {
            $input['subtests'][] = $input['subtests'][0];
            $input['total_duration_seconds'] = 120;
        }];
        yield 'invalid subtest field' => [static function (array &$input): void {
            $input['subtests'][0]['item_count'] = 0;
        }];
    }

    #[DataProvider('identityStringAliases')]
    public function test_it_rejects_padded_control_and_format_aliases(callable $mutate): void
    {
        $input = $this->fixedDefinition('ist');
        $mutate($input);
        $input['checksum'] = SessionDefinition::checksumFor($input);

        $this->expectException(InvalidArgumentException::class);

        SessionDefinition::fromArray($input);
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void}> */
    public static function identityStringAliases(): iterable
    {
        yield 'padded version' => [static function (array &$input): void {
            $input['version'] = ' synthetic-v1 ';
        }];
        yield 'control in provenance' => [static function (array &$input): void {
            $input['provenance'] = "synthetic\nfixture";
        }];
        yield 'format character in subtest code' => [static function (array &$input): void {
            $input['subtests'][0]['code'] = "SYN\u{200B}THETIC";
        }];
        yield 'padded generator algorithm' => [static function (array &$input): void {
            $input = self::syntheticKraepelinDefinition();
            $input['generator']['algorithm'] = ' synthetic-generator';
        }];
        yield 'control in generator version' => [static function (array &$input): void {
            $input = self::syntheticKraepelinDefinition();
            $input['generator']['version'] = "synthetic\tgenerator-v1";
        }];
    }

    #[DataProvider('invalidFixedDefinitions')]
    public function test_it_rejects_randomization_or_seed_material_for_fixed_instruments(callable $mutate): void
    {
        $input = $this->fixedDefinition('papi');
        $mutate($input);

        $this->expectException(InvalidArgumentException::class);

        SessionDefinition::fromArray($input);
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void}> */
    public static function invalidFixedDefinitions(): iterable
    {
        yield 'seeded mode' => [static function (array &$input): void {
            $input['randomization'] = 'seeded';
        }];
        yield 'non-null seed' => [static function (array &$input): void {
            $input['seed'] = 'unexpected';
        }];
        yield 'generator config' => [static function (array &$input): void {
            $input['generator'] = ['algorithm' => 'unexpected'];
        }];
    }

    #[DataProvider('invalidKraepelinDefinitions')]
    public function test_it_rejects_incomplete_or_noncanonical_kraepelin_definitions(callable $mutate): void
    {
        $input = $this->kraepelinDefinition();
        $mutate($input);

        $this->expectException(InvalidArgumentException::class);

        SessionDefinition::fromArray($input);
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void}> */
    public static function invalidKraepelinDefinitions(): iterable
    {
        yield 'seeded mode' => [static function (array &$input): void {
            $input['randomization'] = 'seeded';
        }];
        yield 'non-null seed' => [static function (array &$input): void {
            $input['seed'] = 'unexpected';
        }];
        yield 'blank generator algorithm' => [static function (array &$input): void {
            $input['generator']['algorithm'] = '';
        }];
        yield 'missing generator version' => [static function (array &$input): void {
            unset($input['generator']['version']);
        }];
        yield 'not 50 columns' => [static function (array &$input): void {
            $input['generator']['columns'] = 49;
        }];
        yield 'not 15 seconds per column' => [static function (array &$input): void {
            $input['generator']['seconds_per_column'] = 14;
        }];
        yield 'not 750 seconds total' => [static function (array &$input): void {
            $input['total_duration_seconds'] = 749;
            $input['subtests'][0]['duration_seconds'] = 749;
        }];
        yield 'not 28 numbers per column' => [static function (array &$input): void {
            $input['generator']['numbers_per_column'] = 27;
        }];
        yield 'not 27 answer slots per column' => [static function (array &$input): void {
            $input['generator']['answer_slots_per_column'] = 26;
        }];
        yield 'item count does not match the matrix' => [static function (array &$input): void {
            $input['subtests'][0]['item_count'] = 1349;
        }];
    }

    // ═══════════════════════════════════════════════
    // TIMED SEGMENTS (F2 stage 2, 2026-09-22) --
    // tasks/handoffs/f2/timed-segments-plan.md
    // ═══════════════════════════════════════════════

    // ─── stage 5: currentSubtestItemRange() ───

    public function test_current_subtest_item_range_maps_a_flattened_segment_index_back_to_its_owning_subtest(): void
    {
        $input = $this->fixedDefinition('ist');
        $input['total_duration_seconds'] = 120;
        $input['subtests'][0]['duration_seconds'] = 60;
        $input['subtests'][0]['item_count'] = 20;
        $input['subtests'][] = ['code' => 'OTHER', 'duration_seconds' => 60, 'item_count' => 20];
        $input['checksum'] = SessionDefinition::checksumFor($input);

        $definition = SessionDefinition::fromArray($input);

        $first = $definition->currentSubtestItemRange(0);
        $this->assertSame(1, $first->start);
        $this->assertSame(20, $first->end);

        $second = $definition->currentSubtestItemRange(1);
        $this->assertSame(21, $second->start);
        $this->assertSame(40, $second->end);
    }

    public function test_current_subtest_item_range_resolves_every_segment_of_a_multi_segment_subtest_to_the_same_range(): void
    {
        $input = $this->fixedDefinition('ist');
        $input['subtests'][0]['item_count'] = 10;
        $input['subtests'][0]['segments'] = [
            ['code' => 'SYNTHETIC_MEMORIZE', 'duration_seconds' => 20, 'reading_cap_seconds' => 0, 'allow_early_finish' => false],
            ['code' => 'SYNTHETIC_ANSWER', 'duration_seconds' => 40, 'reading_cap_seconds' => 0, 'allow_early_finish' => false],
        ];
        $input['checksum'] = SessionDefinition::checksumFor($input);

        $definition = SessionDefinition::fromArray($input);

        $memorize = $definition->currentSubtestItemRange(0);
        $answer = $definition->currentSubtestItemRange(1);
        $this->assertEquals($memorize, $answer);
        $this->assertSame(1, $memorize->start);
        $this->assertSame(10, $memorize->end);
    }

    public function test_a_definition_with_no_timed_segment_fields_degenerates_to_one_segment_per_subtest(): void
    {
        $definition = SessionDefinition::fromArray($this->fixedDefinition('ist'));

        $this->assertSame(0, $definition->totalReadingCapSeconds);
        $this->assertCount(1, $definition->segments);
        $segment = $definition->segments[0];
        $this->assertInstanceOf(TimedSegment::class, $segment);
        $this->assertSame('SYNTHETIC', $segment->code);
        $this->assertSame(60, $segment->durationSeconds);
        $this->assertSame(0, $segment->readingCapSeconds);
        $this->assertFalse($segment->allowEarlyFinish);
    }

    /**
     * The core backward-compatibility guarantee this stage depends on: a
     * payload written and checksummed BEFORE reading_cap_seconds/
     * allow_early_finish/segments existed must still parse and verify
     * today, because real PAPI/RMIB/Kraepelin sessions in 'created'/
     * 'in_progress' status already have such payloads persisted. Proven two
     * ways: it parses without error, AND recomputing the checksum with the
     * new fields explicitly present (even at their same default values)
     * produces a DIFFERENT digest -- so this isn't passing by accident
     * because the fields are being silently defaulted back into the
     * checksummed payload.
     */
    public function test_a_pre_existing_payload_without_timed_segment_fields_still_checksum_verifies(): void
    {
        $oldPayload = $this->fixedDefinition('papi');

        $definition = SessionDefinition::fromArray($oldPayload);

        $this->assertSame($oldPayload['checksum'], $definition->checksum);
        $this->assertSame(0, $definition->totalReadingCapSeconds);

        $withExplicitDefaults = $oldPayload;
        $withExplicitDefaults['subtests'][0]['reading_cap_seconds'] = 0;
        $withExplicitDefaults['subtests'][0]['allow_early_finish'] = false;
        $this->assertNotSame(
            $oldPayload['checksum'],
            SessionDefinition::checksumFor($withExplicitDefaults),
            'Presence of the new fields must affect the checksum, proving old payloads are never silently defaulted before hashing.',
        );
    }

    public function test_a_subtest_reading_cap_and_early_finish_produce_one_segment_carrying_them(): void
    {
        $input = $this->fixedDefinition('ist');
        $input['subtests'][0]['reading_cap_seconds'] = 30;
        $input['subtests'][0]['allow_early_finish'] = true;
        $input['checksum'] = SessionDefinition::checksumFor($input);

        $definition = SessionDefinition::fromArray($input);

        $this->assertSame(30, $definition->totalReadingCapSeconds);
        $this->assertCount(1, $definition->segments);
        $segment = $definition->segments[0];
        $this->assertSame(60, $segment->durationSeconds);
        $this->assertSame(30, $segment->readingCapSeconds);
        $this->assertTrue($segment->allowEarlyFinish);
    }

    public function test_a_multi_segment_subtest_flattens_to_its_own_segments_not_the_subtest_itself(): void
    {
        $input = $this->fixedDefinition('ist');
        $input['subtests'][0]['segments'] = [
            ['code' => 'SYNTHETIC_MEMORIZE', 'duration_seconds' => 20, 'reading_cap_seconds' => 0, 'allow_early_finish' => false],
            ['code' => 'SYNTHETIC_ANSWER', 'duration_seconds' => 40, 'reading_cap_seconds' => 10, 'allow_early_finish' => true],
        ];
        $input['checksum'] = SessionDefinition::checksumFor($input);

        $definition = SessionDefinition::fromArray($input);

        // The parent subtest code itself never appears as a segment -- only
        // its own explicit phases do.
        $this->assertCount(2, $definition->segments);
        $this->assertSame(['SYNTHETIC_MEMORIZE', 'SYNTHETIC_ANSWER'], array_map(
            static fn (TimedSegment $s): string => $s->code,
            $definition->segments,
        ));
        $this->assertSame(10, $definition->totalReadingCapSeconds);

        [$memorize, $answer] = $definition->segments;
        $this->assertSame(20, $memorize->durationSeconds);
        $this->assertFalse($memorize->allowEarlyFinish);
        $this->assertSame(40, $answer->durationSeconds);
        $this->assertTrue($answer->allowEarlyFinish);
    }

    public function test_a_segment_code_colliding_with_another_subtests_code_is_rejected(): void
    {
        $input = $this->fixedDefinition('ist');
        $input['total_duration_seconds'] = 120;
        $input['subtests'][0]['duration_seconds'] = 60;
        $input['subtests'][] = ['code' => 'OTHER', 'duration_seconds' => 60, 'item_count' => 1];
        // A segment inside SYNTHETIC re-uses OTHER's code.
        $input['subtests'][0]['segments'] = [
            ['code' => 'OTHER', 'duration_seconds' => 60, 'reading_cap_seconds' => 0, 'allow_early_finish' => false],
        ];
        $input['checksum'] = SessionDefinition::checksumFor($input);

        $this->expectException(InvalidArgumentException::class);

        SessionDefinition::fromArray($input);
    }

    public function test_segment_durations_must_sum_to_the_parent_subtest_duration(): void
    {
        $input = $this->fixedDefinition('ist');
        $input['subtests'][0]['segments'] = [
            ['code' => 'SYNTHETIC_MEMORIZE', 'duration_seconds' => 20, 'reading_cap_seconds' => 0, 'allow_early_finish' => false],
            ['code' => 'SYNTHETIC_ANSWER', 'duration_seconds' => 30, 'reading_cap_seconds' => 0, 'allow_early_finish' => false],
        ];
        $input['checksum'] = SessionDefinition::checksumFor($input);

        $this->expectException(InvalidArgumentException::class);

        SessionDefinition::fromArray($input);
    }

    public function test_an_unknown_subtest_field_is_still_rejected(): void
    {
        $input = $this->fixedDefinition('ist');
        $input['subtests'][0]['unexpected'] = true;
        $input['checksum'] = SessionDefinition::checksumFor($input);

        $this->expectException(InvalidArgumentException::class);

        SessionDefinition::fromArray($input);
    }

    public function test_a_segment_missing_a_required_field_is_rejected(): void
    {
        $input = $this->fixedDefinition('ist');
        $input['subtests'][0]['segments'] = [
            ['code' => 'SYNTHETIC_ONLY', 'duration_seconds' => 60, 'allow_early_finish' => false],
        ];
        $input['checksum'] = SessionDefinition::checksumFor($input);

        $this->expectException(InvalidArgumentException::class);

        SessionDefinition::fromArray($input);
    }

    /** @return array<string, mixed> */
    private function fixedDefinition(string $instrument): array
    {
        $input = [
            'instrument' => $instrument,
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-test-fixture',
            'checksum' => '',
            'total_duration_seconds' => 60,
            'subtests' => [[
                'code' => 'SYNTHETIC',
                'duration_seconds' => 60,
                'item_count' => 1,
            ]],
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => null,
        ];

        $input['checksum'] = SessionDefinition::checksumFor($input);

        return $input;
    }

    /** @return array<string, mixed> */
    private function kraepelinDefinition(): array
    {
        return self::syntheticKraepelinDefinition();
    }

    /** @return array<string, mixed> */
    private static function syntheticKraepelinDefinition(): array
    {
        $input = [
            'instrument' => 'kraepelin',
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-test-fixture',
            'checksum' => '',
            'total_duration_seconds' => 750,
            'subtests' => [[
                'code' => 'WORK',
                'duration_seconds' => 750,
                'item_count' => 1350,
            ]],
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => [
                'algorithm' => 'synthetic-generator',
                'version' => 'synthetic-generator-v1',
                'columns' => 50,
                'seconds_per_column' => 15,
                'numbers_per_column' => 28,
                'answer_slots_per_column' => 27,
            ],
        ];

        $input['checksum'] = SessionDefinition::checksumFor($input);

        return $input;
    }
}
