<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedPapiResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\ScoreSealedPapiAnswerSet;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use UnexpectedValueException;

/** Mirrors tests/Feature/AssessmentResults/ScoreSealedIstAnswerSetTest.php for PAPI. */
final class ScoreSealedPapiAnswerSetTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    public function test_it_scores_one_exact_historical_papi_source_into_a_stable_immutable_result(): void
    {
        $source = $this->papiSource();
        $scoringSourceId = $this->insertCanonicalScoringSource(isActive: false);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'from "instrument_versions"')) {
                $queries[] = $query->sql;
            }
        });

        [$first, $second] = app(RlsContextRunner::class)->runAsService(function () use (
            $source,
            $scoringSourceId,
        ): array {
            $scorer = app(ScoreSealedPapiAnswerSet::class);

            return [
                $scorer->execute($source, $scoringSourceId),
                $scorer->execute($source, $scoringSourceId),
            ];
        });

        $this->assertInstanceOf(SealedPapiResult::class, $first);
        $this->assertSame($first->canonicalJson(), $second->canonicalJson());
        $this->assertSame($first->resultChecksum, $second->resultChecksum);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first->resultChecksum);
        $this->assertSame('papi-result:v1', $first->resultContractVersion);
        $this->assertSame($source->sourceChecksum, $first->sealedSourceChecksum);
        $this->assertSame([
            'id' => $scoringSourceId,
            'code' => 'papi',
            'version' => 'F2-2026.09',
            'sourceFile' => 'papi.json',
            'checksum' => hash('sha256', $this->canonicalPayload()),
        ], $first->scoringSource);
        $this->assertCount(20, $first->dimensions);
        $codes = array_column($first->dimensions, 'code');
        sort($codes);
        $this->assertSame([
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'I', 'K', 'L', 'N', 'O', 'P', 'R', 'S', 'T', 'V', 'W', 'X', 'Z',
        ], $codes);
        $byCode = [];
        foreach ($first->dimensions as $dimension) {
            $byCode[$dimension['code']] = $dimension;
        }
        // Every response chose 'a'; expected raw scores computed directly from
        // database/seeders/data/papi.json's mapping (independently verified,
        // not copied from the scorer under test).
        $this->assertSame(1, $byCode['A']['rawScore']);
        $this->assertSame(1, $byCode['A']['sourceScore']);
        $this->assertSame(4, $byCode['A']['standardScore']);
        $this->assertSame(1, $byCode['A']['level']);
        $this->assertSame('NEED', $byCode['A']['category']);
        $this->assertSame(['lo' => 5, 'hi' => 8], $byCode['A']['band']);
        $this->assertSame(9, $byCode['G']['rawScore']);
        $this->assertSame(3, $byCode['G']['level']);
        $this->assertSame('ROLE', $byCode['G']['category']);
        $this->assertSame(7, $byCode['I']['rawScore']);
        $this->assertSame(5, $byCode['I']['level']);
        $this->assertSame(0, $byCode['I']['standardScore']);
        $this->assertSame(2, count($queries)); // second call replays, both hit instrument_versions once each
        $this->assertStringNotContainsString('dass', strtolower($first->canonicalJson()));
    }

    public function test_it_requires_a_service_transaction_and_positive_internal_source_id_before_sql(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            app(ScoreSealedPapiAnswerSet::class)->execute($this->papiSource(), 1);
            $this->fail('Scoring must require an existing service transaction.');
        } catch (LogicException $exception) {
            $this->assertSame('SEALED_PAPI_RESULT_CONTEXT_REQUIRED', $exception->getMessage());
            $this->assertSame([], $queries);
        }

        app(RlsContextRunner::class)->runAsService(function () use (&$queries): void {
            try {
                app(ScoreSealedPapiAnswerSet::class)->execute($this->papiSource(), 0);
                $this->fail('Only a positive trusted scoring-source ID is valid.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_PAPI_RESULT_INVALID', $exception->getMessage());
                $this->assertSame([], $queries);
            }
        });
    }

    public function test_non_papi_sealed_sources_are_rejected_before_the_scoring_source_query(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $source = $this->sealedSource(
            instrument: GenericAssessmentInstrument::Ist,
            subtests: [['code' => 'SE', 'duration_seconds' => 60, 'item_count' => 1]],
            values: ['A'],
        );

        app(RlsContextRunner::class)->runAsService(function () use ($source): void {
            try {
                app(ScoreSealedPapiAnswerSet::class)->execute($source, 1);
                $this->fail('Only PAPI may enter the PAPI scorer.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_PAPI_RESULT_INSTRUMENT_UNSUPPORTED', $exception->getMessage());
            }
        });

        $this->assertSame([], $queries);
    }

    public function test_scoring_source_identity_and_payload_must_match_the_exact_papi_row(): void
    {
        $source = $this->papiSource();
        $private = 'private-scoring-authority';
        $cases = [
            'wrong code' => ['code' => 'ist'],
            'version mismatch' => ['version' => $private],
            'payload checksum mismatch' => ['checksum' => str_repeat('a', 64)],
            'invalid source file' => ['source_file' => "bad\nfile.json"],
            'invalid source text' => ['source_text' => '{'],
            'null source text' => ['source_text' => null],
        ];

        foreach ($cases as $changes) {
            $id = $this->insertCanonicalScoringSource(changes: $changes, uniqueVersion: true);

            try {
                $this->score($source, $id);
                $this->fail('Malformed scoring authority must fail closed.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_PAPI_RESULT_INVALID', $exception->getMessage());
                $this->assertStringNotContainsString($private, $exception->getMessage());
            }
        }
    }

    public function test_definition_shape_and_answer_values_fail_closed_without_answer_disclosure(): void
    {
        $scoringSourceId = $this->insertCanonicalScoringSource();
        $wrongDefinition = $this->sealedSource(
            instrument: GenericAssessmentInstrument::Papi,
            subtests: [['code' => 'PAPI', 'duration_seconds' => 60, 'item_count' => 1]],
            values: ['a'],
        );
        $privateAnswer = 'private-answer-value';
        $invalidAnswer = $this->papiSource(valueOverride: $privateAnswer, valueOverrideItem: 1, asObject: true);

        foreach ([$wrongDefinition, $invalidAnswer] as $source) {
            try {
                $this->score($source, $scoringSourceId);
                $this->fail('Definition and answers must match the exact PAPI scorer contract.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_PAPI_RESULT_INVALID', $exception->getMessage());
                $this->assertStringNotContainsString($privateAnswer, $exception->getMessage());
            }
        }
    }

    private function score(SealedGenericAnswerSet $source, int $scoringSourceId): SealedPapiResult
    {
        return app(RlsContextRunner::class)->runAsService(
            fn (): SealedPapiResult => app(ScoreSealedPapiAnswerSet::class)->execute($source, $scoringSourceId),
        );
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
            'code' => 'papi',
            'version' => $version,
            'source_file' => 'papi.json',
            'checksum' => hash('sha256', $payload),
            'payload' => $payload,
            'source_text' => $payload,
            'is_active' => $isActive,
            'created_at' => '2026-09-20 03:00:00.000000+00:00',
            'updated_at' => '2026-09-20 03:00:00.000000+00:00',
        ];

        return DB::table('instrument_versions')->insertGetId([...$row, ...$changes]);
    }

    private function papiSource(
        string $valueOverride = '',
        int $valueOverrideItem = 0,
        bool $asObject = false,
    ): SealedGenericAnswerSet {
        $data = $this->canonicalData();
        $itemCount = count($data['mapping']);
        $subtests = [['code' => 'PAPI', 'duration_seconds' => 60, 'item_count' => $itemCount]];
        $values = [];
        for ($item = 1; $item <= $itemCount; $item++) {
            $value = 'a';
            if ($item === $valueOverrideItem) {
                $value = $asObject ? (object) ['answer' => $valueOverride] : $valueOverride;
            }
            $values[] = $value;
        }

        return $this->sealedSource(GenericAssessmentInstrument::Papi, $subtests, $values);
    }

    /**
     * @param  list<array{code:string,duration_seconds:int,item_count:int}>  $subtests
     * @param  list<mixed>  $values
     */
    private function sealedSource(
        GenericAssessmentInstrument $instrument,
        array $subtests,
        array $values,
    ): SealedGenericAnswerSet {
        $definitionSource = [
            'instrument' => $instrument->value,
            'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-test-only',
            'total_duration_seconds' => array_sum(array_column($subtests, 'duration_seconds')),
            'subtests' => $subtests,
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource,
            'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $answers = [];
        foreach ($values as $offset => $value) {
            $answers[] = [
                'item_no' => $offset + 1,
                'value' => $value,
                'revision' => 1,
                'answered_at' => '2026-09-20T03:10:00.123456Z',
            ];
        }

        return SealedGenericAnswerSet::seal(
            assessmentCaseId: 11,
            sessionId: 22,
            participantId: 33,
            sessionPublicId: '01K50SYNTHETICPAPISESSION00',
            instrument: $instrument,
            attemptNo: 1,
            submittedAt: '2026-09-20T03:20:00.654321Z',
            answersRevision: 1,
            definition: $definition,
            answers: $answers,
        );
    }

    /** @return array<string, mixed> */
    private function canonicalData(): array
    {
        $data = json_decode($this->canonicalPayload(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Canonical PAPI scoring data is invalid.');
        }

        return $data;
    }

    private function canonicalPayload(): string
    {
        $payload = file_get_contents(database_path('seeders/data/papi.json'));
        if (! is_string($payload)) {
            throw new RuntimeException('Canonical PAPI scoring data could not be read.');
        }

        return $payload;
    }
}
