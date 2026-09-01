<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\ReviewAssessmentBillTransfer;
use App\Data\Payments\AssessmentBillManualReview;
use App\Enums\AdminRole;
use App\Enums\AssessmentBillManualDecision;
use App\Enums\AssessmentBillManualRejectionCode;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentAccessFixture;
use Throwable;

/** PostgreSQL-authoritative runtime RLS, reviewer serialization, and revocation proof. */
final class AssessmentBillManualReviewTest extends TestCase
{
    private array $fixture;

    private int $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        Date::setTestNow(now()->startOfSecond());
        $this->reviewer = app(RlsContextRunner::class)->runAsService(fn (): int => Admin::query()->insertGetId([
            'name' => 'Synthetic manual reviewer', 'email' => uniqid().'@example.test',
            'password' => 'not-a-real-password', 'role' => AdminRole::SuperAdmin->value,
            'can_verify_payments' => false, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->fixture = app(RlsContextRunner::class)->runAsService(function (): array {
            $fixture = AssessmentAccessFixture::create();
            DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'assessment_status' => 'PROVISIONED',
                'funding_mode' => 'INVOICED_TO_ORGANIZATION',
                'metadata' => json_encode([
                    'checkout_contract_version' => 'checkout-v2',
                    'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION',
                ], JSON_THROW_ON_ERROR),
            ]);
            DB::table('assessment_entitlements')->where('id', $fixture['entitlement'])
                ->update(['status' => 'locked', 'ready_at' => null]);
            DB::table('assessment_bill_items')->where('id', $fixture['item'])->update(['settled_at' => null]);
            $method = (int) DB::table('assessment_bills')->where('id', $fixture['bill'])->value('payment_method_id');
            DB::table('payment_methods')->where('id', $method)->update(['code' => 'manual_transfer', 'is_active' => false]);
            DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
                'status' => 'pending', 'paid_at' => null, 'verified_at' => null,
                'verified_by_admin_id' => null, 'rejection_reason' => null,
                'gateway_ref' => null, 'invoice_url' => null,
                'proof_object_key' => 'assessment-bills/ab/'.str_repeat('c', 62).'.jpg',
                'proof_checksum_sha256' => str_repeat('a', 64),
                'proof_mime_type' => 'image/jpeg', 'proof_size_bytes' => 100,
                'proof_uploaded_at' => '2026-09-01T00:00:00.000000Z',
            ]);
            $bill = AssessmentBill::query()->findOrFail($fixture['bill']);
            $fingerprint = hash('sha256', implode("\0", [
                $bill->proof_object_key, $bill->proof_checksum_sha256, $bill->proof_mime_type,
                (string) $bill->proof_size_bytes, $bill->proof_uploaded_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ]));

            return [...$fixture, 'method' => $method, 'reference' => $bill->public_reference,
                'fingerprint' => $fingerprint];
        });
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $organization = $this->fixture['organization'];
            DB::table('outbox_messages')->where('topic', 'assessment.activation')
                ->where('aggregate_id', (string) $this->fixture['attempt'])->delete();
            DB::table('audit_logs')->where('branch_id', $organization)->delete();
            foreach (['assessment_entitlements', 'assessment_bill_items', 'assessment_bills', 'assessment_charges',
                'assessment_participants', 'integration_clients'] as $table) {
                DB::table($table)->where('organization_id', $organization)->delete();
            }
            foreach (['consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
                DB::table($table)->where('participant_id', $this->fixture['participant'])->delete();
            }
            DB::table('participants')->where('id', $this->fixture['participant'])->delete();
            DB::table('package_items')->where('package_id', $this->fixture['package'])->delete();
            DB::table('packages')->where('id', $this->fixture['package'])->delete();
            DB::table('payment_methods')->where('id', $this->fixture['method'])->delete();
            DB::table('branches')->where('id', $organization)->delete();
        });
        app(RlsContextRunner::class)->runAsService(
            fn (): int => DB::table('admins')->where('id', $this->reviewer)->delete(),
        );
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_same_review_in_two_runtime_processes_has_one_settlement_audit_and_outbox(): void
    {
        $this->assertRuntimeRole();
        [$first, $second] = $this->race(
            fn (): array => $this->execute($this->review()),
            fn (): array => $this->execute($this->review()),
        );
        $decisions = [$first['decision'] ?? null, $second['decision'] ?? null];
        sort($decisions);
        $this->assertSame(['replayed', 'settled'], $decisions);
        $this->assertCanonicalCounts('paid', 1);
    }

    public function test_opposite_reviews_in_two_processes_commit_only_one_terminal_decision(): void
    {
        $this->assertRuntimeRole();
        [$approve, $reject] = $this->race(
            fn (): array => $this->execute($this->review()),
            fn (): array => $this->execute($this->review(
                AssessmentBillManualDecision::Reject,
                AssessmentBillManualRejectionCode::UnreadableProof,
            )),
        );
        $results = [$approve, $reject];
        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => isset($result['decision']))));
        $this->assertSame(1, count(array_filter($results,
            static fn (array $result): bool => ($result['error'] ?? null) === 'ASSESSMENT_BILL_REVIEW_CONFLICT')));
        app(RlsContextRunner::class)->runAsService(function (): void {
            $bill = DB::table('assessment_bills')->where('id', $this->fixture['bill'])->first();
            $this->assertContains($bill->status, ['paid', 'rejected']);
            $this->assertSame(1, DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])
                ->whereIn('action', ['assessment_bill.paid', 'assessment_bill.rejected'])->count());
            $this->assertLessThanOrEqual(1, DB::table('outbox_messages')->where('topic', 'assessment.activation')
                ->where('aggregate_id', (string) $this->fixture['attempt'])->count());
        });
    }

    public function test_committed_actor_revocation_wins_before_bill_lookup_and_mutation(): void
    {
        $result = $this->reviewWhileActorIsRevoked();
        $this->assertSame('ASSESSMENT_BILL_REVIEW_NOT_FOUND', $result['error'] ?? null);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame('pending', DB::table('assessment_bills')->where('id', $this->fixture['bill'])->value('status'));
            $this->assertSame(0, DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])->count());
            $this->assertSame(0, DB::table('outbox_messages')->where('topic', 'assessment.activation')
                ->where('aggregate_id', (string) $this->fixture['attempt'])->count());
        });
    }

    private function assertRuntimeRole(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    private function assertCanonicalCounts(string $status, int $outbox): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($status, $outbox): void {
            $this->assertSame($status, DB::table('assessment_bills')->where('id', $this->fixture['bill'])->value('status'));
            $this->assertSame(1, DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])
                ->where('action', 'assessment_bill.'.$status)->count());
            $this->assertSame($outbox, DB::table('outbox_messages')->where('topic', 'assessment.activation')
                ->where('aggregate_id', (string) $this->fixture['attempt'])->count());
        });
    }

    private function review(AssessmentBillManualDecision $decision = AssessmentBillManualDecision::Approve,
        ?AssessmentBillManualRejectionCode $reason = null): AssessmentBillManualReview
    {
        return new AssessmentBillManualReview(
            $this->reviewer,
            $this->fixture['reference'],
            $this->fixture['fingerprint'],
            $decision,
            $reason,
        );
    }

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    private function execute(AssessmentBillManualReview $review): array
    {
        return app(ReviewAssessmentBillTransfer::class)->execute($review);
    }

    /** Independent processes overlap behind the organization lock and serialize on canonical locks. */
    private function race(callable $first, callable $second): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $workers = [];
        try {
            foreach ([$first, $second] as $callback) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($pair === false || ($pid = pcntl_fork()) === -1) {
                    throw new RuntimeException('Unable to create concurrent manual-review worker.');
                }
                if ($pid === 0) {
                    fclose($pair[0]);
                    foreach ($workers as $worker) {
                        fclose($worker['socket']);
                    }
                    stream_set_timeout($pair[1], 15);
                    try {
                        $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name');
                        if ($identity->name !== 'psikotes_runtime') {
                            throw new RuntimeException('Worker must use runtime role.');
                        }
                        DB::statement("SET lock_timeout = '10s'");
                        DB::statement("SET statement_timeout = '12s'");
                        fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                        if (fgets($pair[1]) !== "go\n") {
                            throw new RuntimeException('Manual-review barrier timed out.');
                        }
                        $result = $callback();
                    } catch (Throwable $exception) {
                        $result = ['error' => $exception->getMessage(), 'class' => $exception::class];
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
            $this->assertNotSame($backendIds[0], $backendIds[1]);
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendIds): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                foreach ($workers as $index => $worker) {
                    fwrite($worker['socket'], "go\n");
                    $deadline = microtime(true) + 5;
                    do {
                        $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendIds[$index]]);
                        if ($waiting?->wait_event_type === 'Lock') {
                            break;
                        }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    $this->assertSame('Lock', $waiting?->wait_event_type);
                }
            });
            $results = [];
            foreach ($workers as $worker) {
                $results[] = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
        }
    }

    /** @return array<string, mixed> */
    private function reviewWhileActorIsRevoked(): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create actor-revocation worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 15);
            try {
                $backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
                DB::statement("SET lock_timeout = '10s'");
                fwrite($pair[1], json_encode(['pid' => $backend->pid], JSON_THROW_ON_ERROR)."\n");
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Actor-revocation barrier timed out.');
                }
                $result = $this->execute($this->review());
            } catch (Throwable $exception) {
                $result = ['error' => $exception->getMessage(), 'class' => $exception::class];
            }
            fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
            fclose($pair[1]);
            DB::disconnect('pgsql');
            exit(0);
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 15);
        try {
            $backendId = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR)['pid'];
            app(RlsContextRunner::class)->runAsService(fn (): mixed => DB::transaction(function () use ($pair, $backendId): void {
                DB::table('admins')->where('id', $this->reviewer)->lockForUpdate()
                    ->update(['role' => AdminRole::Staff->value]);
                fwrite($pair[0], "go\n");
                $deadline = microtime(true) + 5;
                do {
                    $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendId]);
                    if ($waiting?->wait_event_type === 'Lock') {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertSame('Lock', $waiting?->wait_event_type);
            }));

            return json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            fclose($pair[0]);
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }
}
