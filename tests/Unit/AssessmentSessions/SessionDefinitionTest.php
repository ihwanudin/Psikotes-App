<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
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
        $this->assertSame('seeded', $definition->randomization);
        $this->assertSame('synthetic-seed', $definition->seed);
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

    public function test_it_exports_seeded_kraepelin_as_an_exact_canonical_round_trip(): void
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
        $this->assertSame('seeded', $exported['randomization']);
        $this->assertSame('synthetic-seed', $exported['seed']);
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
        yield 'unicode separator in seed' => [static function (array &$input): void {
            $input = self::syntheticKraepelinDefinition();
            $input['seed'] = "synthetic\u{00A0}seed";
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
        yield 'fixed mode' => [static function (array &$input): void {
            $input['randomization'] = 'fixed';
        }];
        yield 'missing seed' => [static function (array &$input): void {
            $input['seed'] = null;
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
            'randomization' => 'seeded',
            'seed' => 'synthetic-seed',
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
