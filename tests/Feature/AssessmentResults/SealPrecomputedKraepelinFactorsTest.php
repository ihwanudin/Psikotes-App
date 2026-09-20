<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentResults;

use App\Domain\AssessmentResults\SealedKraepelinResult;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\SealPrecomputedKraepelinFactors;
use App\Services\Scoring\KraepelinFactorCalculator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use UnexpectedValueException;

/**
 * Mirrors tests/Feature/AssessmentResults/ScoreSealedIstAnswerSetTest.php's
 * structure, but the input is the real F0 golden achievement matrix from
 * tests/Unit/Scoring/KraepelinFactorCalculatorTest.php run through the real
 * KraepelinFactorCalculator (never a hand-picked factor value), proving the
 * fractional Panker/Hanker round-trip end to end: calculator -> seal ->
 * persist -> read back, exactly 15.86 and -0.622, never 15 or 15.860000001.
 */
final class SealPrecomputedKraepelinFactorsTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private const GROUP = 'S1/S2 (IPA)';

    public function test_it_seals_the_real_golden_factor_matrix_into_a_stable_immutable_result(): void
    {
        $identity = $this->identity();
        $scoringSourceId = $this->insertCanonicalScoringSource(isActive: false);
        $factors = $this->goldenFactors();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'from "instrument_versions"')) {
                $queries[] = $query->sql;
            }
        });

        [$first, $second] = app(RlsContextRunner::class)->runAsService(function () use (
            $identity,
            $scoringSourceId,
            $factors,
        ): array {
            $sealer = app(SealPrecomputedKraepelinFactors::class);

            return [
                $sealer->execute($identity, $scoringSourceId, self::GROUP, $factors),
                $sealer->execute($identity, $scoringSourceId, self::GROUP, $factors),
            ];
        });

        $this->assertInstanceOf(SealedKraepelinResult::class, $first);
        $this->assertSame($first->canonicalJson(), $second->canonicalJson());
        $this->assertSame($first->resultChecksum, $second->resultChecksum);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first->resultChecksum);
        $this->assertSame('kraepelin-result:v1', $first->resultContractVersion);
        $this->assertSame(self::GROUP, $first->normGroup);
        $this->assertCount(4, $first->factors);

        $byCode = [];
        foreach ($first->factors as $factor) {
            $byCode[$factor['code']] = $factor;
        }
        $this->assertSame(['PANKER', 'TIANKER', 'HANKER', 'JANKER'], array_keys($byCode));

        // Exact golden values, computed independently via the real
        // KraepelinBandMapper against S1/S2 (IPA) bands — not copied from
        // the sealing service under test.
        $this->assertSame(15.86, $byCode['PANKER']['rawScore']);
        $this->assertSame(7, $byCode['PANKER']['sourceScore']);
        $this->assertSame(7, $byCode['PANKER']['standardScore']);
        $this->assertSame(4, $byCode['PANKER']['level']);
        $this->assertSame('Baik', $byCode['PANKER']['category']);
        $this->assertSame(['lo' => 14.973, 'hi' => 16.09], $byCode['PANKER']['band']);

        $this->assertSame(-0.622, $byCode['HANKER']['rawScore']);
        $this->assertSame(4, $byCode['HANKER']['sourceScore']);
        $this->assertSame(2, $byCode['HANKER']['level']);
        $this->assertSame('Kurang', $byCode['HANKER']['category']);
        $this->assertSame(['lo' => -1.209, 'hi' => -0.469], $byCode['HANKER']['band']);

        $this->assertSame(7, $byCode['TIANKER']['rawScore']);
        $this->assertSame(7, $byCode['JANKER']['rawScore']);
        $this->assertSame(2, count($queries));
        $this->assertStringNotContainsString('dass', strtolower($first->canonicalJson()));
    }

    public function test_it_requires_a_service_transaction_and_positive_internal_source_id_before_sql(): void
    {
        $identity = $this->identity();
        $factors = $this->goldenFactors();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            app(SealPrecomputedKraepelinFactors::class)->execute($identity, 1, self::GROUP, $factors);
            $this->fail('Sealing must require an existing service transaction.');
        } catch (LogicException $exception) {
            $this->assertSame('SEALED_KRAEPELIN_RESULT_CONTEXT_REQUIRED', $exception->getMessage());
            $this->assertSame([], $queries);
        }

        app(RlsContextRunner::class)->runAsService(function () use (&$queries, $identity, $factors): void {
            try {
                app(SealPrecomputedKraepelinFactors::class)->execute($identity, 0, self::GROUP, $factors);
                $this->fail('Only a positive trusted scoring-source ID is valid.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_KRAEPELIN_RESULT_INVALID', $exception->getMessage());
                $this->assertSame([], $queries);
            }
        });
    }

    public function test_an_unrounded_factor_fails_closed_instead_of_being_silently_rounded(): void
    {
        $identity = $this->identity();
        $scoringSourceId = $this->insertCanonicalScoringSource();
        $factors = $this->goldenFactors();
        $factors['panker'] = 15.8649; // Not pre-rounded to 3 decimals.

        app(RlsContextRunner::class)->runAsService(function () use ($identity, $scoringSourceId, $factors): void {
            try {
                app(SealPrecomputedKraepelinFactors::class)->execute($identity, $scoringSourceId, self::GROUP, $factors);
                $this->fail('An unrounded factor must fail closed, not be silently rounded.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_KRAEPELIN_RESULT_INVALID', $exception->getMessage());
            }
        });
    }

    public function test_an_unknown_norm_group_fails_closed(): void
    {
        $identity = $this->identity();
        $scoringSourceId = $this->insertCanonicalScoringSource();
        $factors = $this->goldenFactors();

        app(RlsContextRunner::class)->runAsService(function () use ($identity, $scoringSourceId, $factors): void {
            try {
                app(SealPrecomputedKraepelinFactors::class)->execute(
                    $identity,
                    $scoringSourceId,
                    'Unknown Group',
                    $factors,
                );
                $this->fail('An unconfigured norm group must fail closed.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_KRAEPELIN_RESULT_INVALID', $exception->getMessage());
            }
        });
    }

    public function test_missing_factor_keys_fail_closed_without_a_default(): void
    {
        $identity = $this->identity();
        $scoringSourceId = $this->insertCanonicalScoringSource();
        $incomplete = ['panker' => 15.86, 'tianker' => 7, 'hanker' => -0.622]; // janker missing.

        app(RlsContextRunner::class)->runAsService(function () use ($identity, $scoringSourceId, $incomplete): void {
            try {
                app(SealPrecomputedKraepelinFactors::class)->execute(
                    $identity,
                    $scoringSourceId,
                    self::GROUP,
                    $incomplete,
                );
                $this->fail('A missing factor must fail closed, never default to zero or be omitted.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_KRAEPELIN_RESULT_INVALID', $exception->getMessage());
            }
        });
    }

    /** @return array{panker:float,tianker:int,hanker:float,janker:int} */
    private function goldenFactors(): array
    {
        $achievement = [18, 19, 19, 18, 15, 19, 17, 17, 13, 16, 16, 13, 14, 15, 16, 14, 15, 15, 15, 15, 13, 16, 12, 18, 18, 16, 16, 15, 15, 17, 15, 18, 16, 16, 16, 15, 15, 14, 15, 18, 17, 16, 13, 15, 16, 18, 17, 17, 14, 17];
        $incorrectByColumn = [0 => 7];
        $columns = array_map(
            static fn (int $value, int $index): array => [
                'achievement' => $value, 'correct' => $value - ($incorrectByColumn[$index] ?? 0),
                'incorrect' => $incorrectByColumn[$index] ?? 0, 'skipped' => 0,
            ],
            $achievement,
            array_keys($achievement),
        );
        $result = (new KraepelinFactorCalculator)->calculate($columns);

        return [
            'panker' => $result['panker'], 'tianker' => $result['tianker'],
            'hanker' => $result['hanker'], 'janker' => $result['janker'],
        ];
    }

    /** @return array{assessmentCaseId:int,sessionId:int,participantId:int,sessionPublicId:string,attemptNo:int,submittedAt:string,answersRevision:int,sessionDefinition:array<string,mixed>} */
    private function identity(): array
    {
        $definitionSource = [
            'instrument' => 'kraepelin', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-test-only', 'total_duration_seconds' => 750,
            'subtests' => [['code' => 'KRAEPELIN', 'duration_seconds' => 750, 'item_count' => 1350]],
            'randomization' => 'seeded', 'seed' => 'synthetic-seed-01',
            'generator' => [
                'algorithm' => 'synthetic-test-generator', 'version' => 'v1', 'columns' => 50,
                'seconds_per_column' => 15, 'numbers_per_column' => 28, 'answer_slots_per_column' => 27,
            ],
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);

        return [
            'assessmentCaseId' => 11, 'sessionId' => 22, 'participantId' => 33,
            'sessionPublicId' => '01K50SYNTHETICKRAEPELINSES0',
            'attemptNo' => 1, 'submittedAt' => '2026-09-20T03:20:00.654321Z', 'answersRevision' => 1,
            'sessionDefinition' => $definition->toArray(),
        ];
    }

    /** @param array<string, mixed> $changes */
    private function insertCanonicalScoringSource(
        bool $isActive = true,
        array $changes = [],
        bool $uniqueVersion = false,
    ): int {
        $payload = $this->canonicalPayload();
        $version = 'F2-2026.09';
        if ($uniqueVersion) {
            $version .= '-'.str_pad((string) (DB::table('instrument_versions')->count() + 1), 2, '0', STR_PAD_LEFT);
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            $decoded['version'] = $version;
            $payload = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $row = [
            'code' => 'kraepelin',
            'version' => $version,
            'source_file' => 'kraepelin.json',
            'checksum' => hash('sha256', $payload),
            'payload' => $payload,
            'source_text' => $payload,
            'is_active' => $isActive,
            'created_at' => '2026-09-20 03:00:00.000000+00:00',
            'updated_at' => '2026-09-20 03:00:00.000000+00:00',
        ];

        return DB::table('instrument_versions')->insertGetId([...$row, ...$changes]);
    }

    private function canonicalPayload(): string
    {
        $payload = file_get_contents(database_path('seeders/data/kraepelin.json'));
        if (! is_string($payload)) {
            throw new RuntimeException('Canonical Kraepelin scoring data could not be read.');
        }

        return $payload;
    }
}
