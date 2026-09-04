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

/** Diagnostic only: the mutex expectation may remain RED; do not weaken it or edit the writer here. */
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
        // Unique fake disk lives only on the runner's tmpfs, never the configured identity disk.
        Storage::disk($this->disk)->deleteDirectory('/');
        Storage::forgetDisk($this->disk);
        config()->set('identity.disk', $this->previousDisk);
        app()->instance(IdentityMatcher::class, $this->previousMatcher);
        Date::setTestNow();
        parent::tearDown();
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
            // Intended regression assertion. A commit while the mutex is held is diagnostic RED.
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

    public function test_later_evidence_replacement_does_not_make_stale_verification_ready(): void
    {
        $race = $this->raceGate();
        $this->assertSame('completed', $race['observation']);
        $this->assertFalse($race['racedReady']);
        $this->assertFalse(app(RlsContextRunner::class)->runAsService(fn (): bool => $this->gateReady()));
    }

    public function test_same_second_mixed_read_is_not_by_itself_proof_of_wrong_ready(): void
    {
        $this->setInitialVerificationTime($this->anchor);
        $this->assertTrue(app(RlsContextRunner::class)->runAsService(fn (): bool => $this->gateReady()));
        $race = $this->raceGate();
        $this->assertSame('completed', $race['observation']);
        $this->assertTrue($race['racedReady'], 'Old valid verification and same-second replacement satisfy the existing timestamp predicate.');
        $this->assertFalse(app(RlsContextRunner::class)->runAsService(fn (): bool => $this->gateReady()));
        // Initial state was ready, final state locked: the raced ready can linearize before replacement.
        // Do not label this as a never-valid / fabricated readiness decision.
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

    /** @return array{observation:string,racedReady:bool} */
    private function raceGate(): array
    {
        $beforeKeys = app(RlsContextRunner::class)->runAsService(fn (): array => $this->evidenceKeys());
        $worker = $this->startWriter();
        $armed = true;
        $observation = 'not_observed';
        try {
            DB::listen(function (QueryExecuted $query) use (&$armed, &$worker, &$observation): void {
                // QueryExecuted fires after SELECT has obtained the old verification row, before
                // AssessmentAccessPrerequisites can query evidence. The action then commits independently.
                if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "identity_verifications"')) {
                    $armed = false;
                    $this->signal($worker);
                    $observation = $this->observe($worker);
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

    private function startWriter(bool $rollback = false): array
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
                if ($rollback) {
                    DB::listen(function (QueryExecuted $query): void {
                        if (str_starts_with($query->sql, 'update "identity_verifications"')) {
                            throw new RuntimeException('Synthetic identity rollback.');
                        }
                    });
                }
                $outcome = $this->replace();
                $result = ['completed' => true, 'rolledBack' => false, 'outcome' => $outcome];
            } catch (Throwable $exception) {
                $result = $rollback && $exception instanceof RuntimeException && $exception->getMessage() === 'Synthetic identity rollback.'
                    ? ['completed' => false, 'rolledBack' => true, 'outcome' => null]
                    : ['unexpected' => $exception::class];
            }
            if (app(RlsContextRunner::class)->current() !== null || DB::transactionLevel() !== 0) {
                $result = ['unexpected' => 'context-leak'];
            }
            fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
            fclose($pair[1]);
            DB::disconnect('pgsql');
            exit(0);
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 20);
        $line = fgets($pair[0]);
        if (! is_string($line)) {
            throw new RuntimeException('Identity worker did not report backend identity.');
        }
        $ready = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        return ['pid' => $pid, 'socket' => $pair[0], 'backend' => $ready['backend'], 'result' => null];
    }

    private function signal(array $worker): void
    {
        fwrite($worker['socket'], "go\n");
    }

    private function observe(array &$worker): string
    {
        $deadline = microtime(true) + 6;
        do {
            $read = [$worker['socket']];
            $write = $except = [];
            if (stream_select($read, $write, $except, 0, 50_000) > 0) {
                $this->finish($worker);

                return ($worker['result']['completed'] ?? false) ? 'completed' : 'rolled_back_or_failed';
            }
            $waiting = DB::selectOne('SELECT wait_event_type, pg_backend_pid() = ANY(pg_blocking_pids(pid)) AS blocked_by_parent FROM pg_stat_activity WHERE pid = ?', [$worker['backend']]);
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
        fclose($worker['socket']);
        pcntl_waitpid($worker['pid'], $status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }
}
