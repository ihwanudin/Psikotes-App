<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class AssessmentSessionSchemaTest extends OrganizationPaymentTestCase
{
    private int $attemptSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
    }

    public function test_schema_and_portable_constraints_are_authoritative(): void
    {
        $this->assertTrue(Schema::hasColumns('test_sessions', [
            'id', 'public_id', 'participant_id', 'test_type', 'attempt_no', 'authorization_id',
            'allocation_intent_id', 'status', 'answers_revision', 'started_at', 'ends_at',
            'submitted_at', 'scored_at', 'expired_at', 'voided_at', 'void_reason',
            'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('answers', [
            'id', 'session_id', 'item_no', 'value', 'revision', 'answered_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('assessment_autosave_mutations', [
            'id', 'session_id', 'mutation_id', 'revision', 'request_hash',
            'accepted_item_numbers', 'received_at', 'created_at',
        ]));

        foreach ([
            ['test_type' => 'dass21'], ['status' => 'unknown'], ['attempt_no' => 0],
            ['answers_revision' => -1], ['public_id' => strtolower((string) Str::ulid())],
        ] as $override) {
            $this->assertRejected(fn () => DB::table('test_sessions')->insert([...$this->sessionRow(), ...$override]));
        }
        $this->assertRejected(fn () => DB::table('test_sessions')->insert([
            ...$this->sessionRow(null, 'submitted'), 'submitted_at' => '2026-09-08 04:00:01',
        ]));
    }

    public function test_active_attempt_and_allocation_identities_are_unique_and_session_identity_is_immutable(): void
    {
        $participant = $this->participant();
        $first = $this->sessionRow($participant, 'submitted');
        $firstId = DB::table('test_sessions')->insertGetId($first);
        DB::table('test_sessions')->where('id', $firstId)->update(['answers_revision' => 1]);

        foreach ([
            ['public_id' => $first['public_id']],
            ['attempt_no' => $first['attempt_no']],
            ['authorization_id' => $first['authorization_id']],
            ['allocation_intent_id' => $first['allocation_intent_id']],
        ] as $override) {
            $this->assertRejected(fn () => DB::table('test_sessions')->insert([
                ...$this->sessionRow($participant, 'submitted'), ...$override,
            ]));
        }

        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $firstId)
            ->update(['public_id' => (string) Str::ulid()]));
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $firstId)
            ->update(['answers_revision' => 0]));

        DB::table('test_sessions')->insert($this->sessionRow($participant, 'created'));
        $this->assertRejected(fn () => DB::table('test_sessions')->insert($this->sessionRow($participant, 'in_progress')));
        $this->assertRejected(fn () => DB::table('participants')->where('id', $participant)->delete());
    }

    public function test_answers_and_mutation_ledger_enforce_replay_keys_and_append_only_history(): void
    {
        $session = DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'in_progress'));
        DB::table('answers')->insert($this->answerRow($session));
        $this->assertRejected(fn () => DB::table('answers')->insert($this->answerRow($session)));
        $this->assertRejected(fn () => DB::table('answers')->insert([...$this->answerRow($session), 'item_no' => 0]));
        $this->assertRejected(fn () => DB::table('answers')->insert([...$this->answerRow($session), 'revision' => 0]));
        $this->assertRejected(fn () => DB::table('answers')->insert([
            ...$this->answerRow($session), 'item_no' => 2, 'value' => '{',
        ]));

        $mutation = $this->mutationRow($session);
        DB::table('assessment_autosave_mutations')->insert($mutation);
        $this->assertRejected(fn () => DB::table('assessment_autosave_mutations')->insert([
            ...$mutation, 'revision' => 2,
        ]));
        $this->assertRejected(fn () => DB::table('assessment_autosave_mutations')->insert([
            ...$mutation, 'mutation_id' => (string) Str::ulid(),
        ]));
        $this->assertRejected(fn () => DB::table('assessment_autosave_mutations')->insert([
            ...$this->mutationRow($session), 'revision' => 2, 'accepted_item_numbers' => json_encode([]),
        ]));
        $this->assertRejected(fn () => DB::table('assessment_autosave_mutations')->insert([
            ...$this->mutationRow($session), 'revision' => 3, 'request_hash' => str_repeat('A', 64),
        ]));
        $this->assertRejected(fn () => DB::table('assessment_autosave_mutations')->update(['request_hash' => str_repeat('b', 64)]));
        $this->assertRejected(fn () => DB::table('assessment_autosave_mutations')->delete());

        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $session)->delete());
    }

    public function test_populated_down_refuses_before_mutation_and_empty_up_down_up_is_recoverable(): void
    {
        DB::table('test_sessions')->insert($this->sessionRow());
        try {
            $this->runMigration('down');
            $this->fail('Rollback discarded assessment session history.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Assessment session history prevents rollback.', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('test_sessions'));

        DB::table('test_sessions')->delete();
        $this->runMigration('down');
        $this->assertFalse(Schema::hasTable('test_sessions'));
        $this->runMigration('up');
        $this->assertTrue(Schema::hasTable('assessment_autosave_mutations'));
    }

    /** @return array<string, mixed> */
    private function sessionRow(?int $participant = null, string $status = 'created'): array
    {
        $started = in_array($status, ['in_progress', 'submitted', 'scored', 'expired'], true)
            ? '2026-09-08 03:00:00' : null;
        $ends = $started === null ? null : '2026-09-08 04:00:00';

        return [
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant ?? $this->participant(),
            'test_type' => 'ist', 'attempt_no' => ++$this->attemptSequence,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'status' => $status, 'answers_revision' => 0, 'started_at' => $started, 'ends_at' => $ends,
            'submitted_at' => in_array($status, ['submitted', 'scored'], true) ? '2026-09-08 04:00:00' : null,
            'scored_at' => $status === 'scored' ? '2026-09-08 04:01:00' : null,
            'expired_at' => $status === 'expired' ? '2026-09-08 04:00:01' : null,
            'voided_at' => $status === 'void' ? '2026-09-08 03:30:00' : null,
            'void_reason' => $status === 'void' ? 'authorized correction' : null,
        ];
    }

    private function participant(): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic',
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);

        return DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
    }

    /** @return array<string, mixed> */
    private function answerRow(int $session): array
    {
        return ['session_id' => $session, 'item_no' => 1, 'value' => json_encode(['choice' => 'A']),
            'revision' => 1, 'answered_at' => '2026-09-08 03:05:00'];
    }

    /** @return array<string, mixed> */
    private function mutationRow(int $session): array
    {
        return ['session_id' => $session, 'mutation_id' => (string) Str::ulid(), 'revision' => 1,
            'request_hash' => str_repeat('a', 64), 'accepted_item_numbers' => json_encode([1]),
            'received_at' => '2026-09-08 03:05:00', 'created_at' => '2026-09-08 03:05:00'];
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid assessment session schema operation was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function runMigration(string $method): void
    {
        $migration = require database_path('migrations/2026_09_08_000100_create_generic_assessment_sessions.php');
        if (! is_object($migration) || ! in_array($method, ['up', 'down'], true) || ! method_exists($migration, $method)) {
            throw new RuntimeException('Assessment session migration is invalid.');
        }

        (new ReflectionMethod($migration, $method))->invoke($migration);
    }
}
