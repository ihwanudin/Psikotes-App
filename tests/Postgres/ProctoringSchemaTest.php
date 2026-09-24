<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/** PostgreSQL-authoritative proctoring persistence schema and RLS proof (F7, 2026-09-24). */
final class ProctoringSchemaTest extends TestCase
{
    private int $attemptSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_runtime_is_non_owner_with_forced_rls_on_all_four_tables(): void
    {
        $tables = ['proctor_session_monitors', 'proctor_photos', 'proctor_logs', 'proctor_adjudications'];

        foreach ($tables as $table) {
            $security = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity,
                pg_get_userbyid(relowner) AS table_owner FROM pg_class WHERE oid = '{$table}'::regclass");
            $this->assertTrue($security->relrowsecurity, $table);
            $this->assertTrue($security->relforcerowsecurity, $table);
            $this->assertNotSame('psikotes_runtime', $security->table_owner, $table);
        }

        foreach (['proctor_photos', 'proctor_logs', 'proctor_adjudications'] as $table) {
            $this->assertFalse(DB::selectOne(
                "SELECT has_table_privilege('psikotes_runtime', '{$table}', 'UPDATE') AS allowed",
            )->allowed, $table);
            $this->assertFalse(DB::selectOne(
                "SELECT has_table_privilege('psikotes_runtime', '{$table}', 'DELETE') AS allowed",
            )->allowed, $table);
        }
        $this->assertTrue(DB::selectOne(
            "SELECT has_table_privilege('psikotes_runtime', 'proctor_session_monitors', 'UPDATE') AS allowed",
        )->allowed);
    }

    public function test_append_only_tables_reject_update_and_delete_even_as_service(): void
    {
        [$sessionId, $photoId] = app(RlsContextRunner::class)->runAsService(function (): array {
            $graph = $this->graph();
            $sessionId = DB::table('test_sessions')->insertGetId($this->sessionRow($graph['participant']));
            $photoId = DB::table('proctor_photos')->insertGetId($this->photoRow($sessionId, 1));
            $evidenceId = (string) Str::ulid();
            DB::table('proctor_logs')->insert($this->logRow($sessionId, $evidenceId));

            return [$sessionId, $photoId, $evidenceId];
        });

        // 42501 (insufficient_privilege), not the trigger's P0001: `service`
        // has no UPDATE/DELETE GRANT at all on these tables (append-only is
        // enforced at the privilege level first, same as
        // assessment_autosave_mutations), so Postgres never gets far enough
        // to run the trigger. The trigger is defense-in-depth for a session
        // that somehow bypasses the GRANT (e.g. the table owner), which
        // these tests can't exercise without superuser/owner credentials
        // this suite deliberately never holds.
        app(RlsContextRunner::class)->runAsService(function () use ($sessionId, $photoId): void {
            $this->assertSqlState('42501', fn () => DB::table('proctor_photos')
                ->where('id', $photoId)->update(['face_match_status' => 'match']));
            $this->assertSqlState('42501', fn () => DB::table('proctor_photos')->where('id', $photoId)->delete());
            $this->assertSqlState('42501', fn () => DB::table('proctor_logs')
                ->where('test_session_id', $sessionId)->update(['duration_ms' => 1]));
            $this->assertSqlState('42501', fn () => DB::table('proctor_logs')
                ->where('test_session_id', $sessionId)->delete());
        });
    }

    public function test_role_matrix_separates_operational_metadata_from_evidentiary_content(): void
    {
        [$own, $foreign] = app(RlsContextRunner::class)->runAsService(function (): array {
            $own = $this->graph();
            $foreign = $this->graph();
            foreach ([$own, $foreign] as &$graph) {
                $graph['session'] = DB::table('test_sessions')->insertGetId($this->sessionRow($graph['participant']));
                DB::table('proctor_session_monitors')->insert($this->monitorRow($graph['session']));
                DB::table('proctor_photos')->insert($this->photoRow($graph['session'], 1));
                DB::table('proctor_logs')->insert($this->logRow($graph['session'], (string) Str::ulid()));
            }

            return [$own, $foreign];
        });

        app(RlsContextRunner::class)->run(new RlsContext('psychologist'), function (): void {
            $this->assertSame(2, DB::table('proctor_session_monitors')->count());
            $this->assertSame(2, DB::table('proctor_photos')->count());
            $this->assertSame(2, DB::table('proctor_logs')->count());
        });

        foreach (['super_admin', 'central_admin'] as $role) {
            app(RlsContextRunner::class)->run(new RlsContext($role), function () use ($role): void {
                $this->assertSame(2, DB::table('proctor_session_monitors')->count(), $role);
                $this->assertSame(0, DB::table('proctor_photos')->count(), $role);
                $this->assertSame(0, DB::table('proctor_logs')->count(), $role);
            });
        }

        foreach (['branch_admin', 'staff'] as $role) {
            app(RlsContextRunner::class)->run(new RlsContext($role, $own['branch']), function () use ($role): void {
                $this->assertSame(1, DB::table('proctor_session_monitors')->count(), $role);
                $this->assertSame(0, DB::table('proctor_photos')->count(), $role);
                $this->assertSame(0, DB::table('proctor_logs')->count(), $role);
            });
        }

        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true),
            set_config('app.participant_id', '', true)");
        $this->assertSame(0, DB::table('proctor_session_monitors')->count());
        $this->assertSame(0, DB::table('proctor_photos')->count());
        $this->assertNotSame($own['participant'], $foreign['participant']);
    }

    public function test_contract_checks_reject_invalid_enum_values_and_malformed_identifiers(): void
    {
        $sessionId = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('test_sessions')->insertGetId($this->sessionRow($this->graph()['participant'])),
        );

        app(RlsContextRunner::class)->runAsService(function () use ($sessionId): void {
            $this->assertSqlState('23514', fn () => DB::table('proctor_logs')->insert([
                ...$this->logRow($sessionId, (string) Str::ulid()), 'event_kind' => 'NOT_A_REAL_KIND',
            ]));
            $this->assertSqlState('23514', fn () => DB::table('proctor_photos')->insert([
                ...$this->photoRow($sessionId, 1), 'face_match_status' => 'bogus_status',
            ]));
            $this->assertSqlState('23514', fn () => DB::table('proctor_session_monitors')->insert([
                ...$this->monitorRow($sessionId), 'expected_capture_min_seconds' => 20,
                'expected_capture_max_seconds' => 12,
            ]));
        });
    }

    public function test_adjudication_requires_an_existing_matching_log_evidence_id(): void
    {
        [$sessionId, $adminId] = app(RlsContextRunner::class)->runAsService(function (): array {
            $sessionId = DB::table('test_sessions')->insertGetId($this->sessionRow($this->graph()['participant']));
            $adminId = DB::table('admins')->insertGetId([
                'name' => 'Synthetic Psychologist', 'email' => Str::ulid().'@example.test',
                'password' => 'x', 'role' => 'psychologist',
            ]);

            return [$sessionId, $adminId];
        });

        app(RlsContextRunner::class)->runAsService(function () use ($sessionId, $adminId): void {
            $this->assertSqlState('23503', fn () => DB::table('proctor_adjudications')->insert(
                $this->adjudicationRow($sessionId, (string) Str::ulid(), $adminId),
            ));

            $evidenceId = (string) Str::ulid();
            DB::table('proctor_logs')->insert($this->logRow($sessionId, $evidenceId));
            DB::table('proctor_adjudications')->insert($this->adjudicationRow($sessionId, $evidenceId, $adminId));
            $this->assertSame(1, DB::table('proctor_adjudications')->count());
        });
    }

    /** @return array{branch:int,participant:int} */
    private function graph(): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic',
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);

        return compact('branch', 'participant');
    }

    /** @return array<string, mixed> */
    private function sessionRow(int $participant): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant, 'test_type' => 'ist',
            'attempt_no' => ++$this->attemptSequence, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => 3600,
            'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => '2026-09-24 03:00:00.000000+00', 'ends_at' => '2026-09-24 04:00:00.000000+00',
        ];
    }

    /** @return array<string, mixed> */
    private function monitorRow(int $sessionId): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'test_session_id' => $sessionId, 'instrument' => 'ist',
            'expected_capture_min_seconds' => 12, 'expected_capture_max_seconds' => 20,
            'started_at' => '2026-09-24 03:00:00.000000+00',
        ];
    }

    /** @return array<string, mixed> */
    private function photoRow(int $sessionId, int $sequence): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'test_session_id' => $sessionId, 'instrument' => 'ist',
            'capture_kind' => 'periodic', 'sequence' => $sequence,
            'received_at' => '2026-09-24 03:05:00.000000+00', 'disk' => 'proctoring',
            'object_key' => 'proctoring/photos/2026/09/'.Str::ulid().'.jpg', 'mime_type' => 'image/jpeg',
            'size_bytes' => 40000, 'width' => 400, 'height' => 300,
            'checksum_sha256' => str_repeat('a', 64), 'client_event_id' => (string) Str::ulid(),
            'retention_expires_at' => '2026-12-23 03:05:00.000000+00',
            'created_at' => '2026-09-24 03:05:00.000000+00',
        ];
    }

    /** @return array<string, mixed> */
    private function logRow(int $sessionId, string $evidenceId): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'test_session_id' => $sessionId, 'instrument' => 'ist',
            'event_kind' => 'CAMERA_INTERRUPTED', 'evidence_source' => 'CLIENT_OBSERVATION',
            'evidence_id' => $evidenceId, 'occurred_at' => '2026-09-24 03:05:00.000000+00',
            'received_at' => '2026-09-24 03:05:01.000000+00', 'created_at' => '2026-09-24 03:05:01.000000+00',
        ];
    }

    /** @return array<string, mixed> */
    private function adjudicationRow(int $sessionId, string $sourceEvidenceId, int $adminId): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'test_session_id' => $sessionId,
            'source_evidence_id' => $sourceEvidenceId, 'finding_kind' => 'SIGNAL_DISMISSED',
            'adjudicator_admin_id' => $adminId, 'adjudication_token' => (string) Str::ulid(),
            'adjudicated_at' => '2026-09-24 05:00:00.000000+00', 'created_at' => '2026-09-24 05:00:00.000000+00',
        ];
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail("Expected PostgreSQL SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->getCode());
        } finally {
            DB::rollBack();
        }
    }
}
