<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Integrations\ProvisionSelectionParticipant;
use App\Models\Branch;
use App\Security\RlsContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class SelectionParticipantProvisioningConcurrencyTest extends TestCase
{
    private int $branchId;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $this->key = (string) Str::ulid();
        config()->set('selection_integration.branch_ref', $this->key);
        config()->set('selection_integration.intended_field', 'UMUM');
        config()->set('selection_integration.test_types', ['ist']);
        $this->branchId = app(RlsContextRunner::class)->runAsService(fn (): int => Branch::query()->create([
            'code' => $this->key,
            'name' => 'Legacy Selection race',
            'ref_code' => $this->key,
            'organization_code' => $this->key,
            'display_name' => 'Legacy Selection race',
            'is_active' => true,
        ])->id);
    }

    public function test_unique_race_loser_refetches_and_validates_inside_one_locked_transaction(): void
    {
        $results = $this->race(fn (): array => $this->provision(), fn (): array => $this->provision());

        $this->assertSingleCreateAndReplay($results);
    }

    public function test_different_keys_for_the_same_candidate_store_the_winners_key_and_replay_the_loser(): void
    {
        $firstKey = 'selection-first-'.$this->key;
        $secondKey = 'selection-second-'.$this->key;
        $results = $this->race(
            fn (): array => $this->provision($firstKey),
            fn (): array => $this->provision($secondKey),
        );

        $this->assertSingleCreateAndReplay($results);
        $winner = array_values(array_filter($results, fn (array $result): bool => $result['replayed'] === false))[0];
        app(RlsContextRunner::class)->runAsService(function () use ($winner): void {
            $participants = DB::table('participants')->where('branch_id', $this->branchId)->pluck('id');
            $this->assertSame($winner['_requested_key'], DB::table('selection_participants')
                ->whereIn('participant_id', $participants)->value('idempotency_key'));
        });
    }

    /** @return array{participant_id:int,replayed:bool,_requested_key:string} */
    private function provision(?string $idempotencyKey = null): array
    {
        $idempotencyKey ??= 'selection-key-'.$this->key;
        $result = app(ProvisionSelectionParticipant::class)->handle([
            'externalCandidateId' => 'candidate-'.$this->key,
            'selectionRoundId' => 'round-'.$this->key,
            'registrationId' => 'registration-'.$this->key,
            'fullName' => 'Concurrent Selection Participant',
            'gender' => 'male',
            'birthDate' => '2000-01-01',
            'educationLevel' => 'SMA/SMK',
            'phone' => '628123456789',
            'email' => strtolower($this->key).'@example.test',
        ], 'selection-race-'.$this->key, $idempotencyKey);

        return [...$result, '_requested_key' => $idempotencyKey];
    }

    /** @param list<array<string, mixed>> $results */
    private function assertSingleCreateAndReplay(array $results): void
    {
        foreach ($results as $result) {
            $this->assertArrayNotHasKey('class', $result, json_encode($result, JSON_THROW_ON_ERROR));
        }
        $flags = array_column($results, 'replayed');
        sort($flags);
        $lookups = array_column($results, '_selection_lookup_count');
        sort($lookups);
        $this->assertSame([false, true], $flags);
        $this->assertSame([1, 2], $lookups);
        $this->assertSame($results[0]['participant_id'], $results[1]['participant_id']);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $participants = DB::table('participants')->where('branch_id', $this->branchId)->pluck('id');
            $this->assertCount(1, $participants);
            $this->assertSame(1, DB::table('selection_participants')->whereIn('participant_id', $participants)->count());
            $this->assertSame(1, DB::table('assessment_cases')->where('organization_id', $this->branchId)->count());
            $this->assertSame(2, DB::table('entitlements')->whereIn('participant_id', $participants)->count());
            $this->assertSame(1, DB::table('audit_logs')->where('branch_id', $this->branchId)
                ->where('action', 'selection_participant.provisioned')->count());
        });
    }

    /**
     * Independent runtime-role processes both cross the empty replay read before either writes.
     *
     * @return list<array<string, mixed>>
     */
    private function race(callable $first, callable $second): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $gate = random_int(1, 2_000_000_000);
        $gateHeld = false;
        $workers = [];
        try {
            foreach ([$first, $second] as $callback) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($pair === false || ($pid = pcntl_fork()) === -1) {
                    throw new RuntimeException('Unable to create Selection concurrency worker.');
                }
                if ($pid === 0) {
                    fclose($pair[0]);
                    foreach ($workers as $worker) {
                        fclose($worker['socket']);
                    }
                    DB::purge('pgsql');
                    stream_set_timeout($pair[1], 15);
                    try {
                        $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name');
                        if ($identity->name !== 'psikotes_runtime') {
                            throw new RuntimeException('Worker must use runtime role.');
                        }
                        $lookupCount = 0;
                        $gateArmed = true;
                        DB::listen(function (QueryExecuted $query) use (&$lookupCount, &$gateArmed, $gate): void {
                            if (! str_starts_with($query->sql, 'select')
                                || ! str_contains($query->sql, 'selection_participants')
                                || ! str_contains($query->sql, 'limit 2')) {
                                return;
                            }
                            $lookupCount++;
                            if ($gateArmed) {
                                $gateArmed = false;
                                DB::select('SELECT pg_advisory_lock_shared(?)', [$gate]);
                                DB::select('SELECT pg_advisory_unlock_shared(?)', [$gate]);
                            }
                        });
                        fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                        if (fgets($pair[1]) !== "go\n") {
                            throw new RuntimeException('Selection barrier timed out.');
                        }
                        $result = $callback();
                        $result['_selection_lookup_count'] = $lookupCount;
                    } catch (Throwable $exception) {
                        $result = ['class' => $exception::class, 'error' => $exception->getMessage()];
                    }
                    fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                    fclose($pair[1]);
                    DB::disconnect('pgsql');
                    exit(0);
                }
                fclose($pair[1]);
                stream_set_timeout($pair[0], 15);
                $workers[] = ['pid' => $pid, 'socket' => $pair[0]];
            }

            $backendIds = [];
            foreach ($workers as $worker) {
                $backendIds[] = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR)['pid'];
            }
            DB::select('SELECT pg_advisory_lock(?)', [$gate]);
            $gateHeld = true;
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }
            $this->assertNotSame($backendIds[0], $backendIds[1]);
            foreach ($backendIds as $backendId) {
                $deadline = microtime(true) + 5;
                do {
                    $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendId]);
                    if ($waiting?->wait_event_type === 'Lock') {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertSame('Lock', $waiting?->wait_event_type, 'Both workers must cross the empty replay read.');
            }
            DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            $gateHeld = false;

            $results = [];
            foreach ($workers as $worker) {
                $results[] = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if ($gateHeld) {
                DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            }
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
        }
    }
}
