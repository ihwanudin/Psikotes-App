<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\LoadSealedGenericAnswerSet;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\OrganizationPaymentTestCase;
use UnexpectedValueException;

final class LoadSealedGenericAnswerSetTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    public function test_it_loads_one_complete_sealed_snapshot_with_stable_canonical_bytes(): void
    {
        $sessionId = $this->sealedSession('ist', [
            ['item_no' => 1, 'value' => ['z' => 2, 'a' => 1], 'revision' => 1],
            ['item_no' => 2, 'value' => ['whole' => 1, 'fraction' => 1.0], 'revision' => 2],
            ['item_no' => 3, 'value' => 'C', 'revision' => 2],
        ]);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        [$first, $second] = app(RlsContextRunner::class)->runAsService(function () use ($sessionId): array {
            $loader = app(LoadSealedGenericAnswerSet::class);

            return [$loader->execute($sessionId), $loader->execute($sessionId)];
        });

        $this->assertInstanceOf(SealedGenericAnswerSet::class, $first);
        $this->assertSame($first->canonicalJson(), $second->canonicalJson());
        $this->assertSame($first->sourceChecksum, $second->sourceChecksum);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->sourceChecksum);
        $this->assertSame(['item_no', 'value', 'revision', 'answered_at'], array_keys($first->answers[0]));
        $this->assertSame([1, 2, 3], array_column($first->answers, 'item_no'));
        $this->assertSame([1, 2, 2], array_column($first->answers, 'revision'));
        $this->assertEquals((object) ['a' => 1, 'z' => 2], $first->answers[0]['value']);
        $this->assertIsInt($first->answers[1]['value']->whole);
        $this->assertIsFloat($first->answers[1]['value']->fraction);
        $this->assertStringContainsString('"fraction":1.0', $first->canonicalJson());
        $this->assertSame(
            2,
            count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "test_sessions"'))),
        );
        $this->assertSame(
            2,
            count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "answers"'))),
        );
    }

    public function test_scored_sessions_are_readable_without_mutation(): void
    {
        $sessionId = $this->sealedSession('papi', $this->completeAnswers(), 'scored');
        $before = DB::table('test_sessions')->where('id', $sessionId)->first();

        $snapshot = $this->load($sessionId);

        $after = DB::table('test_sessions')->where('id', $sessionId)->first();
        $this->assertSame('papi', $snapshot->instrument->value);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->updated_at, $after->updated_at);
    }

    public function test_it_rejects_calls_without_an_existing_service_transaction_before_sql(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            app(LoadSealedGenericAnswerSet::class)->execute(1);
            $this->fail('The loader must require a service transaction.');
        } catch (LogicException $exception) {
            $this->assertSame('SEALED_GENERIC_ANSWER_CONTEXT_REQUIRED', $exception->getMessage());
            $this->assertSame([], $queries);
        }
    }

    public function test_it_rejects_non_positive_internal_session_ids_before_sql(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(RlsContextRunner::class)->runAsService(function () use (&$queries): void {
            try {
                app(LoadSealedGenericAnswerSet::class)->execute(0);
                $this->fail('Only positive trusted internal IDs are valid.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_GENERIC_ANSWER_INVALID', $exception->getMessage());
                $this->assertSame([], $queries);
            }
        });
    }

    public function test_dass_and_kraepelin_are_rejected_before_the_answer_query(): void
    {
        $dassSession = $this->sealedSession('ist', $this->completeAnswers());
        $this->dropTriggers('test_sessions');
        DB::table('test_sessions')->where('id', $dassSession)->update(['test_type' => 'dass21']);

        $kraepelinSession = $this->sealedSession('kraepelin', [], definition: $this->kraepelinDefinition());

        foreach ([$dassSession, $kraepelinSession] as $sessionId) {
            $answerQueries = 0;
            DB::listen(static function (QueryExecuted $query) use (&$answerQueries): void {
                if (str_contains(strtolower($query->sql), 'from "answers"')) {
                    $answerQueries++;
                }
            });

            try {
                $this->load($sessionId);
                $this->fail('DASS and Kraepelin must not enter the generic answer reader.');
            } catch (UnexpectedValueException $exception) {
                $this->assertSame('SEALED_GENERIC_ANSWER_INSTRUMENT_UNSUPPORTED', $exception->getMessage());
                $this->assertSame(0, $answerQueries);
            }
        }
    }

    public function test_open_terminal_and_unbound_sessions_fail_closed(): void
    {
        foreach (['in_progress', 'expired', 'void'] as $status) {
            $sessionId = $this->sealedSession('rmib', $this->completeAnswers(), $status);
            $this->assertInvalid($sessionId);
        }

        $unbound = $this->sealedSession('ist', $this->completeAnswers(), bindCase: false);
        $this->assertInvalid($unbound);
    }

    public function test_definition_snapshot_mismatch_fails_closed_without_exposing_authority_values(): void
    {
        $sessionId = $this->sealedSession('ist', $this->completeAnswers());
        $this->dropTriggers('test_sessions');
        DB::table('test_sessions')->where('id', $sessionId)->update([
            'session_definition_provenance' => 'private-authority-value',
        ]);

        try {
            $this->load($sessionId);
            $this->fail('A mismatched definition snapshot must fail closed.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_GENERIC_ANSWER_INVALID', $exception->getMessage());
            $this->assertStringNotContainsString('private-authority-value', $exception->getMessage());
        }
    }

    public function test_papi_missing_or_unknown_item_numbers_fail_closed(): void
    {
        // ADR-0032 (2026-09-22): PAPI keeps today's all-or-nothing behaviour
        // verbatim -- unlike IST/RMIB below, a missing item is still
        // rejected for PAPI, just under a more specific error code now.
        $missing = $this->sealedSession('papi', [
            ['item_no' => 1, 'value' => 'A', 'revision' => 1],
            ['item_no' => 2, 'value' => 'B', 'revision' => 1],
        ]);
        $unknown = $this->sealedSession('papi', [
            ['item_no' => 1, 'value' => 'A', 'revision' => 1],
            ['item_no' => 2, 'value' => 'B', 'revision' => 1],
            ['item_no' => 4, 'value' => 'D', 'revision' => 1],
        ]);

        $this->assertIncomplete($missing);
        $this->assertInvalid($unknown);
    }

    public function test_ist_and_rmib_load_an_incomplete_answer_set_without_throwing(): void
    {
        foreach (['ist', 'rmib'] as $instrument) {
            $sessionId = $this->sealedSession($instrument, [
                ['item_no' => 1, 'value' => 'A', 'revision' => 1],
            ]);

            $snapshot = $this->load($sessionId);

            $this->assertSame([1], array_column($snapshot->answers, 'item_no'), $instrument);
        }
    }

    public function test_more_answers_than_the_definition_expects_still_fails_closed_for_every_instrument(): void
    {
        foreach (['ist', 'papi', 'rmib'] as $instrument) {
            $sessionId = $this->sealedSession($instrument, [
                ['item_no' => 1, 'value' => 'A', 'revision' => 1],
                ['item_no' => 2, 'value' => 'B', 'revision' => 1],
                ['item_no' => 3, 'value' => 'C', 'revision' => 1],
                ['item_no' => 4, 'value' => 'D', 'revision' => 1],
            ]);

            $this->assertInvalid($sessionId, $instrument);
        }
    }

    public function test_answer_revision_outside_the_sealed_range_fails_closed(): void
    {
        $sessionId = $this->sealedSession('ist', $this->completeAnswers());
        $this->dropTriggers('answers');
        DB::table('answers')->where('session_id', $sessionId)->where('item_no', 2)->update(['revision' => 99]);

        $this->assertInvalid($sessionId);
    }

    public function test_malformed_answer_json_fails_closed_without_exposing_the_answer(): void
    {
        $sessionId = $this->sealedSession('ist', $this->completeAnswers());
        $this->dropTriggers('answers');
        DB::table('answers')->where('session_id', $sessionId)->where('item_no', 2)->update([
            'value' => '{private-answer',
        ]);

        try {
            $this->load($sessionId);
            $this->fail('Malformed answer JSON must fail closed.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_GENERIC_ANSWER_INVALID', $exception->getMessage());
            $this->assertStringNotContainsString('private-answer', $exception->getMessage());
        }
    }

    public function test_empty_json_objects_remain_objects_in_the_canonical_source(): void
    {
        $sessionId = $this->sealedSession('ist', [
            ['item_no' => 1, 'value' => (object) [], 'revision' => 1],
            ['item_no' => 2, 'value' => [], 'revision' => 1],
            ['item_no' => 3, 'value' => 'C', 'revision' => 1],
        ]);

        $snapshot = $this->load($sessionId);

        $this->assertInstanceOf(\stdClass::class, $snapshot->answers[0]['value']);
        $this->assertSame([], $snapshot->answers[1]['value']);
        $this->assertStringContainsString('"value":{}', $snapshot->canonicalJson());
        $this->assertStringContainsString('"value":[]', $snapshot->canonicalJson());
    }

    public function test_out_of_range_json_integer_is_rejected_instead_of_being_coerced(): void
    {
        $sessionId = $this->sealedSession('ist', $this->completeAnswers());
        $this->dropTriggers('answers');
        DB::table('answers')->where('session_id', $sessionId)->where('item_no', 2)->update([
            'value' => '9223372036854775808',
        ]);

        $this->assertInvalid($sessionId);
    }

    private function load(int $sessionId): SealedGenericAnswerSet
    {
        return app(RlsContextRunner::class)->runAsService(
            fn (): SealedGenericAnswerSet => app(LoadSealedGenericAnswerSet::class)->execute($sessionId),
        );
    }

    private function assertInvalid(int $sessionId, string $context = ''): void
    {
        try {
            $this->load($sessionId);
            $this->fail('The corrupted sealed source must fail closed.'.($context !== '' ? " ({$context})" : ''));
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_GENERIC_ANSWER_INVALID', $exception->getMessage(), $context);
        }
    }

    private function assertIncomplete(int $sessionId): void
    {
        try {
            $this->load($sessionId);
            $this->fail('An incomplete PAPI answer set must still be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_GENERIC_ANSWER_INCOMPLETE', $exception->getMessage());
        }
    }

    /** @return list<array{item_no:int,value:mixed,revision:int}> */
    private function completeAnswers(): array
    {
        return [
            ['item_no' => 1, 'value' => 'A', 'revision' => 1],
            ['item_no' => 2, 'value' => 'B', 'revision' => 1],
            ['item_no' => 3, 'value' => 'C', 'revision' => 1],
        ];
    }

    /**
     * @param  list<array{item_no:int,value:mixed,revision:int}>  $answers
     * @param  array<string,mixed>|null  $definition
     */
    private function sealedSession(
        string $instrument,
        array $answers,
        string $status = 'submitted',
        ?array $definition = null,
        bool $bindCase = true,
    ): int {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key,
            'ref_code' => $key,
            'name' => 'Synthetic',
            'organization_code' => $key,
            'display_name' => 'Synthetic',
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch,
            'referral_branch_id' => $branch,
            'referral_source' => 'default',
            'full_name' => 'Synthetic',
            'phone' => '620000000000',
        ]);
        $case = $bindCase ? DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant,
            'organization_id' => $branch,
            'package_id' => null,
            'origin' => 'DIRECT_PUBLIC',
            'created_at' => '2026-09-13 01:00:00.000000+00:00',
            'updated_at' => '2026-09-13 01:00:00.000000+00:00',
        ]) : null;
        $definition ??= $this->fixedDefinition($instrument);
        $duration = (int) $definition['total_duration_seconds'];

        $sessionId = DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant,
            'assessment_case_id' => $case,
            'test_type' => $instrument,
            'attempt_no' => 1,
            'authorization_id' => 'synthetic-auth-'.$key,
            'allocation_intent_id' => 'synthetic-allocation-'.$key,
            'duration_seconds' => $duration,
            'status' => 'in_progress',
            'answers_revision' => 0,
            'started_at' => '2026-09-13 01:00:00.000000+00:00',
            'ends_at' => '2026-09-13 02:00:00.000000+00:00',
            'session_definition_version' => $definition['version'],
            'session_definition_provenance' => $definition['provenance'],
            'session_definition_checksum' => $definition['checksum'],
            'session_definition_payload' => json_encode($definition, JSON_THROW_ON_ERROR),
            'created_at' => '2026-09-13 01:00:00.000000+00:00',
            'updated_at' => '2026-09-13 01:00:00.000000+00:00',
        ]);

        foreach ($this->answersByRevision($answers) as $revision => $revisionAnswers) {
            DB::table('assessment_autosave_mutations')->insert([
                'session_id' => $sessionId,
                'mutation_id' => (string) Str::ulid(),
                'revision' => $revision,
                'request_hash' => hash('sha256', $key.'-'.$revision),
                'accepted_item_numbers' => json_encode(array_column($revisionAnswers, 'item_no'), JSON_THROW_ON_ERROR),
                'received_at' => sprintf('2026-09-13 01:00:%02d.000000+00:00', $revision),
                'created_at' => sprintf('2026-09-13 01:00:%02d.000000+00:00', $revision),
            ]);
            foreach ($revisionAnswers as $answer) {
                DB::table('answers')->insert([
                    'session_id' => $sessionId,
                    'item_no' => $answer['item_no'],
                    'value' => json_encode($answer['value'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    'revision' => $revision,
                    'answered_at' => sprintf('2026-09-13 01:00:%02d.123456+00:00', $revision),
                    'created_at' => sprintf('2026-09-13 01:00:%02d.123456+00:00', $revision),
                    'updated_at' => sprintf('2026-09-13 01:00:%02d.123456+00:00', $revision),
                ]);
            }
            DB::table('test_sessions')->where('id', $sessionId)->update([
                'answers_revision' => $revision,
                'updated_at' => sprintf('2026-09-13 01:00:%02d.123456+00:00', $revision),
            ]);
        }

        if ($status !== 'in_progress') {
            DB::table('test_sessions')->where('id', $sessionId)->update([
                'status' => $status === 'scored' ? 'submitted' : $status,
                'submitted_at' => in_array($status, ['submitted', 'scored'], true)
                    ? '2026-09-13 01:59:00.654321+00:00'
                    : null,
                'expired_at' => $status === 'expired' ? '2026-09-13 02:00:00.000001+00:00' : null,
                'voided_at' => $status === 'void' ? '2026-09-13 01:30:00.000000+00:00' : null,
                'void_reason' => $status === 'void' ? 'synthetic void' : null,
                'updated_at' => '2026-09-13 01:59:00.654321+00:00',
            ]);
            if ($status === 'scored') {
                DB::table('test_sessions')->where('id', $sessionId)->update([
                    'status' => 'scored',
                    'scored_at' => '2026-09-13 02:00:00.654321+00:00',
                    'updated_at' => '2026-09-13 02:00:00.654321+00:00',
                ]);
            }
        }

        return $sessionId;
    }

    /** @param list<array{item_no:int,value:mixed,revision:int}> $answers
     * @return array<int,list<array{item_no:int,value:mixed,revision:int}>>
     */
    private function answersByRevision(array $answers): array
    {
        $grouped = [];
        foreach ($answers as $answer) {
            $grouped[$answer['revision']][] = $answer;
        }
        ksort($grouped);

        return $grouped;
    }

    /** @return array<string,mixed> */
    private function fixedDefinition(string $instrument): array
    {
        $source = [
            'instrument' => $instrument,
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-test-only',
            'total_duration_seconds' => 3600,
            'subtests' => [
                ['code' => 'A', 'duration_seconds' => 1200, 'item_count' => 1],
                ['code' => 'B', 'duration_seconds' => 2400, 'item_count' => 2],
            ],
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => null,
        ];

        return [...$source, 'checksum' => SessionDefinition::checksumFor($source)];
    }

    /** @return array<string,mixed> */
    private function kraepelinDefinition(): array
    {
        $source = [
            'instrument' => 'kraepelin',
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-test-only',
            'total_duration_seconds' => 750,
            'subtests' => [
                ['code' => 'K', 'duration_seconds' => 750, 'item_count' => 1350],
            ],
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => [
                'algorithm' => 'synthetic-generator',
                'version' => 'synthetic-v1',
                'columns' => 50,
                'seconds_per_column' => 15,
                'numbers_per_column' => 28,
                'answer_slots_per_column' => 27,
            ],
        ];

        return [...$source, 'checksum' => SessionDefinition::checksumFor($source)];
    }

    private function dropTriggers(string $table): void
    {
        $names = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('tbl_name', $table)
            ->pluck('name');
        foreach ($names as $name) {
            DB::statement('DROP TRIGGER "'.str_replace('"', '""', (string) $name).'"');
        }
    }
}
