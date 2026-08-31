<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Integrations\IdempotencyConflict;
use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\ProvisionCheckoutParticipant;
use App\Http\Requests\ProvisionCheckoutParticipantRequest;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class CheckoutProvisioningTest extends TestCase
{
    private array $f;

    private IntegrationClient $client;

    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $this->code = 'P9_'.Str::ulid();
        config()->set('assessment_integration.checkout.enabled', true);
        $this->f = app(RlsContextRunner::class)->runAsService(function (): array {
            $org = Branch::create(['code' => $this->code, 'ref_code' => $this->code, 'name' => 'P9 PG',
                'organization_code' => $this->code, 'display_name' => 'P9 PG', 'allowed_payer_types' => ['self', 'organization']]);
            $this->client = IntegrationClient::create(['organization_id' => $org->id, 'client_id' => $this->code,
                'credential_reference' => 'synthetic', 'enabled' => true])->refresh();
            $source = IntegrationSource::create(['integration_client_id' => $this->client->id,
                'source_system' => 'P9_SOURCE', 'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
                'allowed_assessment_packages' => [$this->code], 'allowed_funding_modes' => ['SPONSORED'],
                'allowed_payer_types' => ['self', 'organization']]);
            $package = TestPackage::create(['code' => $this->code, 'name' => 'P9 PG', 'amount' => 1000,
                'currency' => 'IDR', 'is_active' => true]);
            $package->items()->create(['test_type' => 'ist', 'sort_order' => 1]);

            return ['organization' => $org->id, 'package' => $package->id, 'source' => $source->id];
        });
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_participants')->where('organization_id', $this->f['organization'])->delete();
            DB::table('participants')->where('branch_id', $this->f['organization'])->delete();
            DB::table('integration_clients')->where('organization_id', $this->f['organization'])->delete();
            DB::table('package_items')->where('package_id', $this->f['package'])->delete();
            DB::table('packages')->where('id', $this->f['package'])->delete();
            DB::table('branches')->where('id', $this->f['organization'])->delete();
        });
        config()->set('assessment_integration.checkout.enabled', false);
        parent::tearDown();
    }

    public function test_runtime_non_owner_stores_partial_profile_without_access(): void
    {
        $role = DB::selectOne("SELECT current_user AS name, rolsuper, rolbypassrls,
            (SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'participants'::regclass) AS owner
            FROM pg_roles WHERE rolname = current_user");
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertNotSame($role->name, $role->owner);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $created = $this->provision();
        $this->assertSame('PROVISIONED', $created['assessment_status']);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $attempt = AssessmentParticipant::where('organization_id', $this->f['organization'])->sole();
            $this->assertNull($attempt->funding_mode);
            $this->assertSame(['checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => null], $attempt->metadata);
            $p = $attempt->participant;
            foreach (['full_name', 'birth_date', 'gender', 'intended_field', 'phone', 'education_level', 'test_number'] as $field) {
                $this->assertNull($p->getAttribute($field));
            }
            foreach (['assessment_entitlements', 'assessment_charges', 'assessment_bills'] as $table) {
                $this->assertSame(0, DB::table($table)->where('organization_id', $this->f['organization'])->count());
            }
            foreach (['entitlements', 'orders', 'consent_records', 'identity_verifications'] as $table) {
                $this->assertSame(0, DB::table($table)->where('participant_id', $p->id)->count());
            }
        });
    }

    public function test_duplicate_key_race_commits_only_one_attempt_and_participant(): void
    {
        [$first, $second] = $this->race(fn () => $this->provision(), fn () => $this->provision());
        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['assessment_attempt_id'], $second['assessment_attempt_id']);
        $this->assertRows(1, 1);
    }

    public function test_logical_retry_with_different_key_race_is_not_duplicated(): void
    {
        [$first, $second] = $this->race(fn () => $this->provision(), fn () => $this->provision(key: 'other-key'));
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['assessment_attempt_id'], $second['assessment_attempt_id']);
        $this->assertRows(1, 1);
    }

    public function test_two_new_rounds_race_reuses_external_identity(): void
    {
        [$first, $second] = $this->race(fn () => $this->provision(),
            fn () => $this->provision(['assessmentRoundId' => 'SECOND'], 'second'));
        $this->assertSame($first['participant_id'], $second['participant_id']);
        $this->assertNotSame($first['assessment_attempt_id'], $second['assessment_attempt_id']);
        $this->assertRows(1, 2);
    }

    public function test_conflicting_payload_race_fails_without_orphan(): void
    {
        [$first, $second] = $this->race(fn () => $this->provision(), fn () => $this->provision(['payerType' => 'self']));
        $this->assertFalse($first['replayed']);
        $this->assertSame(IdempotencyConflict::class, $second['class']);
        $this->assertRows(1, 1);
    }

    public function test_source_disabled_while_provisioning_waits_is_reloaded(): void
    {
        [$first, $second] = $this->race(function (): array {
            app(RlsContextRunner::class)->runAsService(function (): void {
                DB::table('branches')->where('id', $this->f['organization'])->lockForUpdate()->first();
                DB::table('integration_sources')->where('id', $this->f['source'])->update(['status' => 'SUSPENDED']);
            });

            return ['disabled'];
        }, fn () => $this->provision());
        $this->assertSame(['disabled'], $first);
        $this->assertSame(IntegrationContractViolation::class, $second['class']);
        $this->assertSame('SOURCE_NOT_ALLOWED', $second['error']);
        $this->assertRows(0, 0);
    }

    #[DataProvider('lifecycleCommits')]
    public function test_retry_waits_for_lifecycle_commit_and_preserves_initial_decision(string $funding, bool $changePolicy): void
    {
        $created = $this->provision();
        [$first, $second] = $this->race(function () use ($created, $funding, $changePolicy): array {
            app(RlsContextRunner::class)->runAsService(function () use ($created, $funding, $changePolicy): void {
                DB::table('branches')->where('id', $this->f['organization'])->lockForUpdate()->first();
                DB::table('assessment_participants')->where('assessment_attempt_id', $created['assessment_attempt_id'])
                    ->update(['funding_mode' => $funding, 'assessment_status' => 'IN_PROGRESS']);
                DB::table('participants')->where('id', $created['participant_id'])->update([
                    'full_name' => 'Completed Person', 'gender' => 'female', 'birth_date' => '2000-01-01',
                    'education_level' => 'SMA', 'intended_field' => 'KAIGO', 'phone' => '628123456789',
                ]);
                if ($changePolicy) {
                    DB::table('integration_sources')->where('id', $this->f['source'])->update(['locked_payer_type' => 'self']);
                }
            });

            return ['committed'];
        }, fn () => $this->provision());
        $this->assertSame(['committed'], $first);
        if ($changePolicy) {
            $this->assertSame(IdempotencyConflict::class, $second['class']);
        } else {
            $this->assertSame($created['assessment_attempt_id'], $second['assessment_attempt_id']);
            $this->assertSame('IN_PROGRESS', $second['assessment_status']);
            $this->assertTrue($second['replayed']);
        }
        app(RlsContextRunner::class)->runAsService(function () use ($funding): void {
            $attempt = AssessmentParticipant::where('organization_id', $this->f['organization'])->sole();
            $this->assertSame($funding, $attempt->funding_mode);
            $this->assertSame('IN_PROGRESS', $attempt->assessment_status);
            $this->assertSame(['checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => null], $attempt->metadata);
            $this->assertSame('Completed Person', $attempt->participant->full_name);
        });
        $this->assertRows(1, 1);
    }

    public static function lifecycleCommits(): iterable
    {
        yield 'self chosen' => ['COMMERCIAL_SELF_PAY', false];
        yield 'organization chosen' => ['INVOICED_TO_ORGANIZATION', false];
        yield 'matching lifecycle cannot hide policy change' => ['COMMERCIAL_SELF_PAY', true];
    }

    public function test_missing_or_invalid_snapshot_is_not_inferred_or_backfilled(): void
    {
        $this->provision(['payerType' => 'self']);
        foreach ([['checkout_contract_version' => 'checkout-v2'],
            ['checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => false],
            ['checkout_contract_version' => 'v1', 'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY']] as $metadata) {
            app(RlsContextRunner::class)->runAsService(function () use ($metadata): void {
                $attempt = AssessmentParticipant::where('organization_id', $this->f['organization'])->sole();
                $attempt->update(['metadata' => $metadata]);
                $before = $attempt->fresh()->getAttributes();
                try {
                    $this->provision(['payerType' => 'self']);
                    $this->fail('Invalid snapshot accepted');
                } catch (IdempotencyConflict) {
                    $this->assertSame($before, $attempt->fresh()->getAttributes());
                }
            });
        }
        $this->assertRows(1, 1);
    }

    public function test_post_insert_failure_rolls_back_savepoint_even_if_caller_commits(): void
    {
        $once = true;
        DB::listen(function (QueryExecuted $query) use (&$once): void {
            if ($once && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'assessment_participants')) {
                $once = false;
                throw new RuntimeException('synthetic provisioning crash');
            }
        });
        app(RlsContextRunner::class)->runAsService(function (): void {
            try {
                $this->provision();
                $this->fail('Expected crash');
            } catch (RuntimeException $e) {
                $this->assertSame('synthetic provisioning crash', $e->getMessage());
            }
        });
        $this->assertRows(0, 0);
        $this->assertFalse($this->provision()['replayed']);
        $this->assertRows(1, 1);
    }

    public function test_non_service_contexts_cannot_write_or_read_foreign_attempts(): void
    {
        $created = $this->provision();
        $runner = app(RlsContextRunner::class);
        foreach (['participant', 'branch_admin', 'super_admin', 'staff', 'psychologist'] as $role) {
            $runner->run(new RlsContext($role, $this->f['organization'], $created['participant_id']), function () use ($role, $created): void {
                try {
                    app(ProvisionCheckoutParticipant::class)->handle($this->request());
                    $this->fail('Action must not elevate '.$role);
                } catch (LogicException) {
                    $this->assertSame($role, app(RlsContextRunner::class)->current()->role);
                }
                if ($role === 'participant') {
                    $this->assertSame(0, DB::table('assessment_participants')->where('assessment_attempt_id', $created['assessment_attempt_id'])->count());
                }
            });
        }
        $runner->run(new RlsContext('branch_admin', $this->f['organization'] + 1000000), function () use ($created): void {
            $this->assertSame(0, DB::table('assessment_participants')->where('assessment_attempt_id', $created['assessment_attempt_id'])->count());
            $this->assertSame(0, DB::table('participants')->where('id', $created['participant_id'])->count());
        });
        $this->assertSame(0, DB::table('assessment_participants')->where('assessment_attempt_id', $created['assessment_attempt_id'])->count());
        $this->assertNull($runner->current());
        $this->assertRows(1, 1);
    }

    private function assertRows(int $participants, int $attempts): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($participants, $attempts): void {
            $this->assertSame($participants, DB::table('participants')->where('branch_id', $this->f['organization'])->count());
            $this->assertSame($attempts, DB::table('assessment_participants')->where('organization_id', $this->f['organization'])->count());
        });
    }

    private function request(array $override = [], string $key = 'p9-key'): ProvisionCheckoutParticipantRequest
    {
        $request = ProvisionCheckoutParticipantRequest::create('/_internal', 'POST', [...[
            'contractVersion' => 'checkout-v2', 'organizationCode' => $this->code, 'sourceSystem' => 'P9_SOURCE',
            'assessmentPackageCode' => $this->code, 'externalCandidateId' => 'CANDIDATE-1', 'profile' => [],
        ], ...$override]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->attributes->set('integration_client', $this->client);
        $request->headers->set('Idempotency-Key', $key);
        $request->validateResolved();

        return $request;
    }

    private function provision(array $override = [], string $key = 'p9-key'): array
    {
        $request = $this->request($override, $key);

        return app(RlsContextRunner::class)->runAsService(fn () => app(ProvisionCheckoutParticipant::class)->handle($request));
    }

    /** Independent processes, observed lock waits, and a parent barrier; no sequential concurrency claim. */
    private function race(callable $first, callable $second): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $workers = [];
        try {
            foreach ([$first, $second] as $callback) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($pair === false) {
                    throw new RuntimeException('Unable to create barrier socket.');
                }
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork worker.');
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
                            throw new RuntimeException('Barrier timed out.');
                        }
                        $result = $callback();
                    } catch (Throwable $e) {
                        $result = ['error' => $e->getMessage(), 'class' => $e::class];
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
                DB::table('branches')->where('id', $this->f['organization'])->lockForUpdate()->first();
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
                    $this->assertSame('Lock', $waiting?->wait_event_type, 'Both workers must overlap and wait.');
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
}
