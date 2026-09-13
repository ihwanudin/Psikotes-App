<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedIstResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\ScoreSealedIstAnswerSet;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use UnexpectedValueException;

final class ScoreSealedIstAnswerSetTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    public function test_it_scores_one_exact_historical_ist_source_into_a_stable_immutable_result(): void
    {
        $source = $this->istSource();
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
            $scorer = app(ScoreSealedIstAnswerSet::class);

            return [
                $scorer->execute($source, $scoringSourceId),
                $scorer->execute($source, $scoringSourceId),
            ];
        });

        $this->assertInstanceOf(SealedIstResult::class, $first);
        $this->assertSame($first->canonicalJson(), $second->canonicalJson());
        $this->assertSame($first->resultChecksum, $second->resultChecksum);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first->resultChecksum);
        $this->assertSame('ist-result:v1', $first->resultContractVersion);
        $this->assertSame($source->sourceChecksum, $first->sealedSourceChecksum);
        $this->assertSame([
            'id' => $scoringSourceId,
            'code' => 'ist',
            'version' => 'F2-2026.09',
            'sourceFile' => 'ist.json',
            'checksum' => hash('sha256', $this->canonicalPayload()),
        ], $first->scoringSource);
        $this->assertCount(9, $first->subtests);
        $this->assertSame(['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'], array_column($first->subtests, 'code'));
        $this->assertSame([20, 20, 20, 0, 20, 20, 0, 0, 0], array_column($first->subtests, 'rawScore'));
        $this->assertSame(100, $first->iq['rawTotal']);
        $this->assertSame(109, $first->iq['iq']);
        $this->assertSame(3, $first->iq['level']);
        $this->assertSame(2, count($queries));
        $this->assertStringNotContainsString('dass', strtolower($first->canonicalJson()));
    }

    public function test_it_requires_a_service_transaction_and_positive_internal_source_id_before_sql(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            app(ScoreSealedIstAnswerSet::class)->execute($this->istSource(), 1);
            $this->fail('Scoring must require an existing service transaction.');
        } catch (LogicException $exception) {
            $this->assertSame('SEALED_IST_RESULT_CONTEXT_REQUIRED', $exception->getMessage());
            $this->assertSame([], $queries);
        }

        app(RlsContextRunner::class)->runAsService(function () use (&$queries): void {
            try {
                app(ScoreSealedIstAnswerSet::class)->execute($this->istSource(), 0);
                $this->fail('Only a positive trusted scoring-source ID is valid.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_IST_RESULT_INVALID', $exception->getMessage());
                $this->assertSame([], $queries);
            }
        });
    }

    public function test_non_ist_sealed_sources_are_rejected_before_the_scoring_source_query(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $source = $this->sealedSource(
            instrument: GenericAssessmentInstrument::Papi,
            subtests: [['code' => 'P', 'duration_seconds' => 60, 'item_count' => 1]],
            values: ['A'],
        );

        app(RlsContextRunner::class)->runAsService(function () use ($source): void {
            try {
                app(ScoreSealedIstAnswerSet::class)->execute($source, 1);
                $this->fail('Only IST may enter the IST scorer.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_IST_RESULT_INSTRUMENT_UNSUPPORTED', $exception->getMessage());
            }
        });

        $this->assertSame([], $queries);
    }

    public function test_scoring_source_identity_and_payload_must_match_the_exact_ist_row(): void
    {
        $source = $this->istSource();
        $private = 'private-scoring-authority';
        $cases = [
            'wrong code' => ['code' => 'dass21'],
            'version mismatch' => ['version' => $private],
            'invalid checksum' => ['checksum' => $private],
            'invalid source file' => ['source_file' => "bad\nfile.json"],
            'invalid payload' => ['payload' => '{'],
        ];

        foreach ($cases as $changes) {
            $id = $this->insertCanonicalScoringSource(changes: $changes, uniqueVersion: true);

            try {
                $this->score($source, $id);
                $this->fail('Malformed scoring authority must fail closed.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_IST_RESULT_INVALID', $exception->getMessage());
                $this->assertStringNotContainsString($private, $exception->getMessage());
            }
        }
    }

    public function test_definition_shape_and_answer_values_fail_closed_without_answer_disclosure(): void
    {
        $scoringSourceId = $this->insertCanonicalScoringSource();
        $wrongDefinition = $this->sealedSource(
            instrument: GenericAssessmentInstrument::Ist,
            subtests: [['code' => 'SE', 'duration_seconds' => 60, 'item_count' => 1]],
            values: ['A'],
        );
        $privateAnswer = 'private-answer-value';
        $invalidAnswer = $this->istSource(valueOverride: $privateAnswer, valueOverrideItem: 1, asObject: true);

        foreach ([$wrongDefinition, $invalidAnswer] as $source) {
            try {
                $this->score($source, $scoringSourceId);
                $this->fail('Definition and answers must match the exact IST scorer contract.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_IST_RESULT_INVALID', $exception->getMessage());
                $this->assertStringNotContainsString($privateAnswer, $exception->getMessage());
            }
        }
    }

    private function score(SealedGenericAnswerSet $source, int $scoringSourceId): SealedIstResult
    {
        return app(RlsContextRunner::class)->runAsService(
            fn (): SealedIstResult => app(ScoreSealedIstAnswerSet::class)->execute($source, $scoringSourceId),
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
            'code' => 'ist',
            'version' => $version,
            'source_file' => 'ist.json',
            'checksum' => hash('sha256', $payload),
            'payload' => $payload,
            'is_active' => $isActive,
            'created_at' => '2026-09-13 03:00:00.000000+00:00',
            'updated_at' => '2026-09-13 03:00:00.000000+00:00',
        ];

        return DB::table('instrument_versions')->insertGetId([...$row, ...$changes]);
    }

    private function istSource(
        string $valueOverride = '',
        int $valueOverrideItem = 0,
        bool $asObject = false,
    ): SealedGenericAnswerSet {
        $data = $this->canonicalData();
        $keys = [];
        foreach ($data['keys'] as $key) {
            $keys[$key['subtest']][$key['item']] = $key['key'];
        }
        foreach ($data['ge_dictionary'] as $definition) {
            $keys['GE'][$definition['item']] = '__unknown__';
        }

        $subtests = [];
        $values = [];
        $globalItem = 0;
        $correctSubtests = ['SE', 'WA', 'AN', 'RA', 'ZR'];
        foreach (['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'] as $code) {
            $count = count($keys[$code]);
            $subtests[] = ['code' => $code, 'duration_seconds' => 60, 'item_count' => $count];
            for ($item = 1; $item <= $count; $item++) {
                $globalItem++;
                $value = in_array($code, $correctSubtests, true) ? $keys[$code][$item] : '__wrong__';
                if ($globalItem === $valueOverrideItem) {
                    $value = $asObject ? (object) ['answer' => $valueOverride] : $valueOverride;
                }
                $values[] = $value;
            }
        }

        return $this->sealedSource(GenericAssessmentInstrument::Ist, $subtests, $values);
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
                'answered_at' => '2026-09-13T03:10:00.123456Z',
            ];
        }

        return SealedGenericAnswerSet::seal(
            assessmentCaseId: 11,
            sessionId: 22,
            participantId: 33,
            sessionPublicId: '01K50SYNTHETICISTSESSION000',
            instrument: $instrument,
            attemptNo: 1,
            submittedAt: '2026-09-13T03:20:00.654321Z',
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
            throw new RuntimeException('Canonical IST scoring data is invalid.');
        }

        return $data;
    }

    private function canonicalPayload(): string
    {
        $payload = file_get_contents(database_path('seeders/data/ist.json'));
        if (! is_string($payload)) {
            throw new RuntimeException('Canonical IST scoring data could not be read.');
        }

        return $payload;
    }
}
