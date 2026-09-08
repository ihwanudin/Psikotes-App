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
            'allocation_intent_id', 'duration_seconds', 'status', 'answers_revision', 'started_at', 'ends_at',
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
        $first = [...$this->sessionRow($participant, 'submitted'), 'answers_revision' => 1];
        $firstId = DB::table('test_sessions')->insertGetId($first);

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

    public function test_state_graph_and_fixed_timing_window_are_enforced_by_the_database(): void
    {
        $created = DB::table('test_sessions')->insertGetId($this->sessionRow());
        DB::table('test_sessions')->where('id', $created)->update([
            'status' => 'in_progress', 'started_at' => '2026-09-08 03:00:00',
            'ends_at' => '2026-09-08 04:00:00',
        ]);
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $created)
            ->update(['ends_at' => '2026-09-08 04:01:00']));
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $created)
            ->update(['started_at' => '2026-09-08 02:59:00']));

        $illegal = DB::table('test_sessions')->insertGetId($this->sessionRow());
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $illegal)->update([
            'status' => 'submitted', 'started_at' => '2026-09-08 03:00:00',
            'ends_at' => '2026-09-08 04:00:00', 'submitted_at' => '2026-09-08 04:00:00',
        ]));

        DB::table('test_sessions')->where('id', $created)->update([
            'status' => 'submitted', 'submitted_at' => '2026-09-08 04:00:00',
        ]);
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $created)->update([
            'status' => 'expired', 'submitted_at' => null, 'expired_at' => '2026-09-08 04:00:01',
        ]));

        DB::table('test_sessions')->where('id', $created)->update([
            'status' => 'scored', 'scored_at' => '2026-09-08 04:01:00',
        ]);
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $created)->update([
            'status' => 'void', 'voided_at' => '2026-09-08 04:02:00', 'void_reason' => 'not legal',
        ]));

        $expiring = DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'in_progress'));
        DB::table('test_sessions')->where('id', $expiring)->update([
            'status' => 'expired', 'expired_at' => '2026-09-08 04:00:01',
        ]);
        $this->assertDatabaseHas('test_sessions', ['id' => $expiring, 'status' => 'expired']);

        $voided = DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'void'));
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $voided)->update([
            'status' => 'created', 'voided_at' => null, 'void_reason' => null,
        ]));
    }

    public function test_submitted_and_expired_sessions_can_be_voided_without_erasing_evidence(): void
    {
        $submitted = DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'submitted'));
        DB::table('test_sessions')->where('id', $submitted)->update([
            'status' => 'void', 'voided_at' => '2026-09-08 04:02:00',
            'void_reason' => 'authorized recovery',
        ]);
        $this->assertDatabaseHas('test_sessions', [
            'id' => $submitted, 'status' => 'void', 'submitted_at' => '2026-09-08 04:00:00',
        ]);

        $expired = DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'expired'));
        DB::table('test_sessions')->where('id', $expired)->update([
            'status' => 'void', 'voided_at' => '2026-09-08 04:02:00',
            'void_reason' => 'authorized retest',
        ]);
        $this->assertDatabaseHas('test_sessions', [
            'id' => $expired, 'status' => 'void', 'expired_at' => '2026-09-08 04:00:01',
        ]);

        $created = DB::table('test_sessions')->insertGetId($this->sessionRow());
        DB::table('test_sessions')->where('id', $created)->update([
            'status' => 'void', 'voided_at' => '2026-09-08 03:01:00',
            'void_reason' => 'allocation cancelled',
        ]);
        $inProgress = DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'in_progress'));
        DB::table('test_sessions')->where('id', $inProgress)->update([
            'status' => 'void', 'voided_at' => '2026-09-08 03:30:00',
            'void_reason' => 'authorized recovery',
        ]);
        $this->assertDatabaseHas('test_sessions', ['id' => $created, 'status' => 'void', 'started_at' => null]);
        $this->assertDatabaseHas('test_sessions', [
            'id' => $inProgress, 'status' => 'void', 'started_at' => '2026-09-08 03:00:00',
        ]);

        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $submitted)
            ->update(['submitted_at' => null]));
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $expired)
            ->update(['expired_at' => null]));
    }

    public function test_answer_identity_is_immutable_and_updates_require_a_strictly_newer_revision(): void
    {
        $session = DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'in_progress'));
        $answer = DB::table('answers')->insertGetId($this->answerRow($session));

        foreach ([
            ['item_no' => 2, 'revision' => 2],
            ['session_id' => DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'submitted')), 'revision' => 2],
            ['value' => json_encode(['choice' => 'B']), 'revision' => 1],
            ['value' => json_encode(['choice' => 'B']), 'revision' => 0],
        ] as $override) {
            $this->assertRejected(fn () => DB::table('answers')->where('id', $answer)->update($override));
        }

        DB::table('assessment_autosave_mutations')->insert($this->mutationRow($session));
        DB::table('test_sessions')->where('id', $session)->update(['answers_revision' => 1]);
        DB::table('answers')->where('id', $answer)->update([
            'value' => json_encode(['choice' => 'B']), 'revision' => 2,
            'answered_at' => '2026-09-08 03:06:00',
        ]);
        $this->assertDatabaseHas('answers', ['id' => $answer, 'revision' => 2]);
    }

    public function test_session_revision_requires_the_exact_matching_mutation_receipt(): void
    {
        $session = DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'in_progress'));
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $session)
            ->update(['answers_revision' => 1]));

        $other = DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'in_progress'));
        DB::table('assessment_autosave_mutations')->insert($this->mutationRow($other));
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $session)
            ->update(['answers_revision' => 1]));

        DB::table('assessment_autosave_mutations')->insert($this->mutationRow($session));
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $session)
            ->update(['answers_revision' => 2]));
        DB::table('test_sessions')->where('id', $session)->update(['answers_revision' => 1]);
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $session)
            ->update(['answers_revision' => 3]));
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
            'duration_seconds' => 3600, 'status' => $status, 'answers_revision' => 0,
            'started_at' => $started, 'ends_at' => $ends,
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
