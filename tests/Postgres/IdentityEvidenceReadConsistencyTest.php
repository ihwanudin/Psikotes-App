<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Identity\StoreIdentityEvidence;
use App\Contracts\IdentityMatcher;
use App\Security\RlsContextRunner;
use App\Services\Identity\ManualReviewIdentityMatcher;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentAccessFixture;
use Throwable;

/** Retains the original mutex regression and verifies coherent serialized identity reads. */
final class IdentityEvidenceReadConsistencyTest extends TestCase
{
    private array $fixture;

    private string $disk;

    private mixed $previousDisk;

    private IdentityMatcher $previousMatcher;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertRuntime();
        $this->anchor = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS at')->at)->utc()->startOfSecond();
        Date::setTestNow($this->anchor->subSeconds(5));
        $this->previousDisk = config('identity.disk');
        $this->previousMatcher = app(IdentityMatcher::class);
        $this->disk = 'idrd-'.strtolower((string) Str::ulid());
        $fake = Storage::fake($this->disk);
        $this->assertLessThanOrEqual(32, strlen($this->disk));
        $this->assertStringStartsWith('/workspace/storage/framework/testing/disks/idrd-', $fake->path(''));
        config()->set('identity.disk', $this->disk);
        app()->instance(IdentityMatcher::class, new ManualReviewIdentityMatcher);
        $this->fixture = app(RlsContextRunner::class)->runAsService(fn (): array => AssessmentAccessFixture::create());
        // Real action creates both synthetic objects. Seed only the initial gate evidence afterwards.
        $this->replace();
        $this->setInitialVerificationTime($this->anchor->subSeconds(5));
        Date::setTestNow($this->anchor);
        $this->assertTrue(app(RlsContextRunner::class)->runAsService(fn (): bool => $this->gateReady()));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->fixture)) {
                app(RlsContextRunner::class)->runAsService(function (): void {
                    $method = DB::table('assessment_bills')->where('id', $this->fixture['bill'])->value('payment_method_id');
                    foreach (['assessment_entitlements', 'assessment_bill_items', 'assessment_bills', 'assessment_charges',
                        'assessment_participants', 'integration_clients'] as $table) {
                        DB::table($table)->where('organization_id', $this->fixture['organization'])->delete();
                    }
                    foreach (['consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
                        DB::table($table)->where('participant_id', $this->fixture['participant'])->delete();
                    }
                    DB::table('participants')->where('id', $this->fixture['participant'])->delete();
                    DB::table('package_items')->where('package_id', $this->fixture['package'])->delete();
                    DB::table('packages')->where('id', $this->fixture['package'])->delete();
                    DB::table('payment_methods')->where('id', $method)->delete();
                    DB::table('branches')->where('id', $this->fixture['organization'])->delete();
                });
            }
        } finally {
            try {
                // Unique fake disk lives only on the runner's tmpfs, never the configured identity disk.
                if (isset($this->disk)) {
                    Storage::disk($this->disk)->deleteDirectory('/');
                    Storage::forgetDisk($this->disk);
                }
            } finally {
                if (isset($this->previousMatcher)) {
                    config()->set('identity.disk', $this->previousDisk);
                    app()->instance(IdentityMatcher::class, $this->previousMatcher);
                }
                Date::setTestNow();
                parent::tearDown();
            }
        }
    }

    public function test_existing_replacement_must_wait_for_the_canonical_participant_mutex(): void
    {
        $before = app(RlsContextRunner::class)->runAsService(fn (): array => $this->evidenceKeys());
        $worker = $this->startWriter();
        try {
            $observation = app(RlsContextRunner::class)->runAsService(function () use (&$worker): string {
                $this->lockParent();
                $this->signal($worker);

                return $this->observe($worker);
            });
            $this->finish($worker);
            $this->assertSame(['completed' => true, 'rolledBack' => false, 'outcome' => 'pending'], $worker['result']);
            $after = app(RlsContextRunner::class)->runAsService(fn (): array => $this->evidenceKeys());
            $this->assertNotSame($before, $after);
            foreach ($before as $key) {
                Storage::disk($this->disk)->assertMissing($key);
            }
            foreach ($after as $key) {
                Storage::disk($this->disk)->assertExists($key);
            }
            // Original RED regression retained: a commit while the mutex is held must fail.
            $this->assertSame('blocked_by_parent', $observation,
                'Existing-row StoreIdentityEvidence completed while canonical participant FOR UPDATE was held.');
        } finally {
            $this->stopWriter($worker);
        }
    }

    public function test_initial_insert_control_observes_fk_parent_blocking(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('identity_evidence')->where('participant_id', $this->fixture['participant'])->delete();
            DB::table('identity_verifications')->where('participant_id', $this->fixture['participant'])->delete();
        });
        $worker = $this->startWriter();
        try {
            $observation = app(RlsContextRunner::class)->runAsService(function () use (&$worker): string {
                $this->lockParent();
                $this->signal($worker);

                return $this->observe($worker);
            });
            $this->finish($worker);
            $this->assertSame('blocked_by_parent', $observation);
            $this->assertTrue($worker['result']['completed'] ?? false);
        } finally {
            $this->stopWriter($worker);
        }
    }

    public function test_reader_first_keeps_old_revision_coherent_until_later_replacement(): void
    {
        $race = $this->raceGate();
        $this->assertSame('blocked_by_parent', $race['observation']);
        $this->assertTrue($race['racedReady']);
        $this->assertFalse(app(RlsContextRunner::class)->runAsService(fn (): bool => $this->gateReady()));
    }

    public function test_reader_first_keeps_same_second_revisions_separate(): void
    {
        $this->setInitialVerificationTime($this->anchor);
        $this->assertTrue(app(RlsContextRunner::class)->runAsService(fn (): bool => $this->gateReady()));
        $race = $this->raceGate();
        $this->assertSame('blocked_by_parent', $race['observation']);
        $this->assertTrue($race['racedReady']);
        $this->assertFalse(app(RlsContextRunner::class)->runAsService(fn (): bool => $this->gateReady()));
        // The original diagnostic mixed read is now prevented even at second precision.
    }

    public function test_actual_action_rollback_restores_rows_and_cleans_only_new_objects(): void
    {
        $before = app(RlsContextRunner::class)->runAsService(fn (): array => $this->identityRows());
        $files = Storage::disk($this->disk)->allFiles();
        sort($files);
        $worker = $this->startWriter(rollback: true);
        try {
            app(RlsContextRunner::class)->runAsService(function () use (&$worker): void {
                $this->lockParent();
                $this->signal($worker);
                $this->observe($worker);
            });
            $this->finish($worker);
            $this->assertSame(['completed' => false, 'rolledBack' => true, 'outcome' => null], $worker['result']);
            $this->assertSame($before, app(RlsContextRunner::class)->runAsService(fn (): array => $this->identityRows()));
            $after = Storage::disk($this->disk)->allFiles();
            sort($after);
            $this->assertSame($files, $after);
        } finally {
            $this->stopWriter($worker);
        }
    }

    public function test_writer_first_makes_reader_wait_and_observe_only_committed_pending_identity(): void
    {
        $writer = $this->startWriter(pauseAfterParticipant: true);
        $reader = null;
        try {
            $this->signal($writer);
            $this->awaitParticipantRead($writer);
            $reader = $this->startWriter(reader: true);
            $this->signal($reader);
            $this->assertSame('blocked_by_parent', $this->observe($reader, $writer['backend']));
            $this->resume($writer);
            $this->finish($writer);
            $this->finish($reader);
            $this->assertSame(['completed' => true, 'rolledBack' => false, 'outcome' => 'pending'], $writer['result']);
            $this->assertSame(['completed' => true, 'rolledBack' => false, 'outcome' => 'locked'], $reader['result']);
            $this->assertOnlyCurrentObjectsRemain();
        } finally {
            // Closing a paused writer's socket aborts its transaction on a failed assertion.
            $this->stopWriter($writer);
            if ($reader !== null) {
                $this->stopWriter($reader);
            }
        }
    }

    public function test_two_actual_writers_serialize_replacement_without_orphaned_objects(): void
    {
        $before = app(RlsContextRunner::class)->runAsService(fn (): array => $this->evidenceKeys());
        $first = $this->startWriter(pauseAfterParticipant: true);
        $second = null;
        try {
            $this->signal($first);
            $this->awaitParticipantRead($first);
            $second = $this->startWriter();
            $this->signal($second);
            $this->assertSame('blocked_by_parent', $this->observe($second, $first['backend']));
            $this->assertSame($before, app(RlsContextRunner::class)->runAsService(fn (): array => $this->evidenceKeys()));
            $this->resume($first);
            $this->finish($first);
            $this->finish($second);
            foreach ([$first, $second] as $worker) {
                $this->assertSame(['completed' => true, 'rolledBack' => false, 'outcome' => 'pending'], $worker['result']);
            }
            $this->assertNotSame($before, app(RlsContextRunner::class)->runAsService(fn (): array => $this->evidenceKeys()));
            $this->assertOnlyCurrentObjectsRemain();
            $this->assertFalse(app(RlsContextRunner::class)->runAsService(fn (): bool => $this->gateReady()));
        } finally {
            $this->stopWriter($first);
            if ($second !== null) {
                $this->stopWriter($second);
            }
        }
    }

    private function assertOnlyCurrentObjectsRemain(): void
    {
        $keys = app(RlsContextRunner::class)->runAsService(fn (): array => $this->evidenceKeys());
        $files = Storage::disk($this->disk)->allFiles();
        sort($keys);
        sort($files);
        $this->assertCount(2, $keys);
        $this->assertSame($keys, $files);
    }

    public function test_aborting_a_paused_writer_cleans_files_and_cannot_resume_the_test_suite(): void
    {
        $before = app(RlsContextRunner::class)->runAsService(fn (): array => $this->identityRows());
        $worker = $this->startWriter(pauseAfterParticipant: true);
        try {
            $this->signal($worker);
            $this->awaitParticipantRead($worker);
        } finally {
            $this->stopWriter($worker);
        }
        $this->assertSame($before, app(RlsContextRunner::class)->runAsService(fn (): array => $this->identityRows()));
        $this->assertOnlyCurrentObjectsRemain();
    }

    /** @return array{observation:string,racedReady:bool} */
    private function raceGate(): array
    {
        $beforeKeys = app(RlsContextRunner::class)->runAsService(fn (): array => $this->evidenceKeys());
        $worker = $this->startWriter();
        $armed = true;
        $observation = 'not_observed';
        $connection = DB::connection();
        $previousEvents = $connection->getEventDispatcher();
        $events = clone $previousEvents;
        $connection->setEventDispatcher($events);
        try {
            $events->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, &$worker, &$observation, $beforeKeys): void {
                // Pause after the old verification SELECT, before evidence. The writer must
                // remain blocked until the reader finishes and releases its parent mutex.
                if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "identity_verifications"')) {
                    $armed = false;
                    $this->signal($worker);
                    $observation = $this->observe($worker);
                    $this->assertSame($beforeKeys, $this->evidenceKeys(), 'Reader must retain the old evidence revision while its parent mutex is held.');
                }
            });
            $racedReady = app(RlsContextRunner::class)->runAsService(function (): bool {
                $this->lockParent();

                return $this->gateReady();
            });
            $this->assertFalse($armed, 'Gate did not reach the verification SELECT barrier.');
            $this->finish($worker);
            $this->assertTrue($worker['result']['completed'] ?? false);
            $this->assertNotSame($beforeKeys, app(RlsContextRunner::class)->runAsService(fn (): array => $this->evidenceKeys()));

            return compact('observation', 'racedReady');
        } finally {
            $armed = false;
            $connection->setEventDispatcher($previousEvents);
            $this->stopWriter($worker);
        }
    }

    private function gateReady(): bool
    {
        try {
            app(AssessmentEntitlementGate::class)->assertReady(new AssessmentPrincipal(
                $this->fixture['participant'], $this->fixture['organization'], $this->fixture['attempt'],
            ), 'ist');

            return true;
        } catch (EntitlementLocked) {
            return false;
        }
    }

    private function lockParent(): void
    {
        DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
        DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->lockForUpdate()->first();
        DB::table('participants')->where('id', $this->fixture['participant'])->lockForUpdate()->first();
    }

    private function setInitialVerificationTime(CarbonImmutable $at): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($at): void {
            DB::table('identity_verifications')->where('participant_id', $this->fixture['participant'])->update([
                'matcher' => 'synthetic-old-match', 'outcome' => 'match', 'manual_status' => 'pending', 'checked_at' => $at,
            ]);
            DB::table('identity_evidence')->where('participant_id', $this->fixture['participant'])->update(['updated_at' => $at]);
        });
    }

    private function replace(): string
    {
        // Valid synthetic 1x1 PNG: the disposable runtime has no GD. This exercises
        // the action's real image/file path, not the HTTP upload-validation layer.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1cAAAAASUVORK5CYII=', true);
        $this->assertIsString($png);
        $this->assertSame([1, 1], array_slice(getimagesizefromstring($png), 0, 2));
        $result = app(StoreIdentityEvidence::class)->handle($this->fixture['participant'],
            UploadedFile::fake()->createWithContent('synthetic-document.png', $png),
            UploadedFile::fake()->createWithContent('synthetic-selfie.png', $png));

        return $result->outcome;
    }

    private function assertRuntime(): void
    {
        $row = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $row->name);
        $this->assertFalse($row->rolsuper);
        $this->assertFalse($row->rolbypassrls);
    }

    /** @return list<string> */
    private function evidenceKeys(): array
    {
        return DB::table('identity_evidence')->where('participant_id', $this->fixture['participant'])
            ->orderBy('type')->pluck('object_key')->all();
    }

    private function identityRows(): array
    {
        return [DB::table('identity_evidence')->where('participant_id', $this->fixture['participant'])->orderBy('id')->get()->toJson(),
            DB::table('identity_verifications')->where('participant_id', $this->fixture['participant'])->get()->toJson()];
    }

    private function startWriter(bool $rollback = false, bool $pauseAfterParticipant = false, bool $reader = false): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Disposable Linux runtime with pcntl is required; no skip.');
        DB::purge('pgsql');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to start identity diagnostic worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 20);
            try {
                $this->assertRuntime();
                DB::statement("SET lock_timeout = '12s'");
                DB::statement("SET statement_timeout = '15s'");
                fwrite($pair[1], json_encode(['backend' => DB::selectOne('SELECT pg_backend_pid() AS id')->id], JSON_THROW_ON_ERROR)."\n");
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Identity diagnostic barrier failed.');
                }
                $events = clone DB::connection()->getEventDispatcher();
                DB::connection()->setEventDispatcher($events);
                if ($rollback) {
                    $events->listen(QueryExecuted::class, function (QueryExecuted $query): void {
                        if (str_starts_with($query->sql, 'update "identity_verifications"')) {
                            throw new RuntimeException('Synthetic identity rollback.');
                        }
                    });
                }
                if ($pauseAfterParticipant) {
                    $armed = true;
                    $events->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $pair): void {
                        if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "participants"')) {
                            $armed = false;
                            fwrite($pair[1], "participant-read\n");
                            if (fgets($pair[1]) !== "resume\n") {
                                throw new RuntimeException('Identity writer release barrier failed.');
                            }
                        }
                    });
                }
                $outcome = $reader ? app(RlsContextRunner::class)->runAsService(function (): string {
                    $this->lockParent();

                    return $this->gateReady() ? 'ready' : 'locked';
                }) : $this->replace();
                $result = ['completed' => true, 'rolledBack' => false, 'outcome' => $outcome];
            } catch (Throwable $exception) {
                $result = $rollback && $exception instanceof RuntimeException && $exception->getMessage() === 'Synthetic identity rollback.'
                    ? ['completed' => false, 'rolledBack' => true, 'outcome' => null]
                    : ['unexpected' => $exception::class];
            }
            if (app(RlsContextRunner::class)->current() !== null || DB::transactionLevel() !== 0) {
                $result = ['unexpected' => 'context-leak'];
            }
            // A broken IPC channel must never unwind into the child's inherited PHPUnit loop.
            $exitCode = 0;
            try {
                fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
            } catch (Throwable) {
                $exitCode = 2;
            } finally {
                fclose($pair[1]);
                DB::disconnect('pgsql');
                exit($exitCode);
            }
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 20);
        $line = fgets($pair[0]);
        if (! is_string($line)) {
            throw new RuntimeException('Identity worker did not report backend identity.');
        }
        $ready = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        return ['pid' => $pid, 'socket' => $pair[0], 'backend' => $ready['backend'], 'result' => null, 'paused' => false];
    }

    private function signal(array $worker): void
    {
        fwrite($worker['socket'], "go\n");
    }

    private function awaitParticipantRead(array &$worker): void
    {
        $this->assertSame("participant-read\n", fgets($worker['socket']));
        $worker['paused'] = true;
    }

    private function resume(array &$worker): void
    {
        fwrite($worker['socket'], "resume\n");
        $worker['paused'] = false;
    }

    private function observe(array &$worker, ?int $blocker = null): string
    {
        $blocker ??= DB::selectOne('SELECT pg_backend_pid() AS id')->id;
        $deadline = microtime(true) + 6;
        do {
            $read = [$worker['socket']];
            $write = $except = [];
            if (stream_select($read, $write, $except, 0, 50_000) > 0) {
                $this->finish($worker);

                return ($worker['result']['completed'] ?? false) ? 'completed' : 'rolled_back_or_failed';
            }
            $waiting = DB::selectOne('SELECT wait_event_type, ? = ANY(pg_blocking_pids(pid)) AS blocked_by_parent FROM pg_stat_activity WHERE pid = ?', [$blocker, $worker['backend']]);
            if ($waiting?->wait_event_type === 'Lock' && $waiting->blocked_by_parent) {
                return 'blocked_by_parent';
            }
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Identity diagnostic observed neither completion nor parent lock wait.');
    }

    private function finish(array &$worker): void
    {
        if ($worker['result'] !== null) {
            return;
        }
        $line = fgets($worker['socket']);
        if (! is_string($line)) {
            throw new RuntimeException('Identity worker did not report outcome.');
        }
        $worker['result'] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }

    private function stopWriter(array $worker): void
    {
        if ($worker['paused'] && $worker['result'] === null) {
            // Drain the aborted child's response before closing, including on assertion failure.
            fwrite($worker['socket'], "abort\n");
            $this->finish($worker);
        }
        // Shutdown also releases a child barrier when another fork inherited this socket.
        stream_socket_shutdown($worker['socket'], STREAM_SHUT_RDWR);
        fclose($worker['socket']);
        pcntl_waitpid($worker['pid'], $status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }
}
