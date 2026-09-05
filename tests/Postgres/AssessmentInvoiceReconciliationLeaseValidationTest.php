<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Actions\Payments\PersistAssessmentInvoiceOutcome;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReconcileAssessmentBillInvoice;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Actions\Payments\ReserveAssessmentInvoiceReconciliationHints;
use App\Actions\Payments\ValidateAssessmentInvoiceReconciliationLease;
use App\Contracts\PaymentProvider;
use App\Data\Payments\AssessmentInvoiceReconciliationPermit;
use App\Data\Payments\PaymentInvoice;
use App\Data\Payments\ProvisionalAssessmentInvoiceLease;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\OutboxMessage;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentPreviewFixture as Fixture;
use Throwable;

/** PostgreSQL-authoritative organization-first serialization and rotated-token fence proof. */
final class AssessmentInvoiceReconciliationLeaseValidationTest extends TestCase
{
    /** @var array{organization: int, participant: int, package: int, attempt: int, charge: int, bill: int, payer: string, source: int, client: int} */
    private array $fixture;

    private Admin $admin;

    private AssessmentBill $bill;

    private OutboxMessage $intent;

    private ProvisionalAssessmentInvoiceLease $provisional;

    private int $method;

    /** @var list<int> */
    private array $extraMessages = [];

    /** @var array<string, mixed> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(function_exists('pcntl_fork'), 'Independent process proof requires pcntl, never skip.');
        $this->assertFileExists('/.dockerenv');
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->previous = [
            'enabled' => config('assessment_integration.checkout.enabled'),
            'duration' => config('assessment_billing.invoice_duration_hours'),
            'batch' => config('assessment_billing.invoice_reconciliation_batch_size'),
            'scan' => config('assessment_billing.invoice_reconciliation_scan_limit'),
            'lease' => config('assessment_billing.invoice_reconciliation_lease_seconds'),
            'cooldown' => config('assessment_billing.invoice_reconciliation_cooldown_seconds'),
            'max' => config('assessment_billing.invoice_reconciliation_max_lookups'),
            'now' => Date::getTestNow(),
            'http' => Http::getFacadeRoot(),
        ];
        Date::setTestNow('2026-09-01T00:00:00Z');
        config()->set('assessment_integration.checkout.enabled', true);
        config()->set('assessment_billing.invoice_duration_hours', 24);
        config()->set('assessment_billing.invoice_reconciliation_batch_size', 25);
        config()->set('assessment_billing.invoice_reconciliation_scan_limit', 100);
        config()->set('assessment_billing.invoice_reconciliation_lease_seconds', 60);
        config()->set('assessment_billing.invoice_reconciliation_cooldown_seconds', 300);
        config()->set('assessment_billing.invoice_reconciliation_max_lookups', 12);
        Http::preventStrayRequests();
        Http::fake([]);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->fixture = $this->createFixture();
            DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->update([
                'funding_mode' => 'INVOICED_TO_ORGANIZATION',
                'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}',
            ]);
            $this->admin = Admin::create(['branch_id' => $this->fixture['organization'], 'name' => 'Synthetic PG validator',
                'email' => Str::ulid().'@example.test', 'password' => 'synthetic', 'role' => AdminRole::BranchAdmin]);
            $this->method = DB::table('payment_methods')->insertGetId([
                'code' => 'xendit', 'display_name' => 'Synthetic PG validator', 'is_active' => true,
            ]);
            $selection = [['assessmentParticipantId' => $this->fixture['attempt'], 'consultationRequested' => false]];
            $preview = app(PreviewAssessmentBill::class)->execute($this->fixture['organization'], $selection, PayerType::Organization);
            $this->bill = app(ReserveAssessmentBill::class)->execute(
                $this->admin, $selection, $this->method, $preview['selectionHash'], 'pg-lease-validation',
            );
            app(ClaimAssessmentBillInvoice::class)->execute($this->fixture['organization'], $this->bill->id);
            $this->intent = OutboxMessage::query()->where('aggregate_id', (string) $this->bill->id)->sole();
        });
        $this->assertNotNull(app(IssueAssessmentBillInvoice::class)->consume($this->intent->message_id));
        $leases = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(1, 1);
        $this->assertCount(1, $leases);
        $this->provisional = $leases[0];
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNull(app(RlsContextRunner::class)->current());
        } finally {
            app(RlsContextRunner::class)->runAsService(function (): void {
                DB::table('outbox_messages')->whereIn('id', $this->extraMessages)->delete();
                DB::table('outbox_messages')->where('aggregate_type', AssessmentBill::class)
                    ->where('aggregate_id', (string) $this->bill->id)->delete();
                DB::table('assessment_bill_items')->where('organization_id', $this->fixture['organization'])->delete();
                DB::table('assessment_bills')->where('organization_id', $this->fixture['organization'])->delete();
                DB::table('assessment_charges')->where('organization_id', $this->fixture['organization'])->delete();
                DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])->delete();
                DB::table('admins')->where('id', $this->admin->id)->delete();
                $code = DB::table('packages')->where('id', $this->fixture['package'])->value('code');
                DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->delete();
                DB::table('integration_sources')->where('id', $this->fixture['source'])->delete();
                DB::table('integration_clients')->where('id', $this->fixture['client'])->delete();
                DB::table('participants')->where('id', $this->fixture['participant'])->delete();
                DB::table('package_items')->where('package_id', $this->fixture['package'])->delete();
                DB::table('packages')->where('id', $this->fixture['package'])->delete();
                DB::table('payment_methods')->where('code', $code)->delete();
                DB::table('payment_methods')->where('id', $this->method)->delete();
                DB::table('branches')->where('id', $this->fixture['organization'])->delete();
            });
            config()->set('assessment_integration.checkout.enabled', $this->previous['enabled']);
            config()->set('assessment_billing.invoice_duration_hours', $this->previous['duration']);
            config()->set('assessment_billing.invoice_reconciliation_batch_size', $this->previous['batch']);
            config()->set('assessment_billing.invoice_reconciliation_scan_limit', $this->previous['scan']);
            config()->set('assessment_billing.invoice_reconciliation_lease_seconds', $this->previous['lease']);
            config()->set('assessment_billing.invoice_reconciliation_cooldown_seconds', $this->previous['cooldown']);
            config()->set('assessment_billing.invoice_reconciliation_max_lookups', $this->previous['max']);
            Date::setTestNow($this->previous['now']);
            Http::swap($this->previous['http']);
            parent::tearDown();
        }
    }

    public function test_two_validators_yield_one_rotated_permit_while_phase_one_bypasses_organization_lock(): void
    {
        $extra = $this->insertExtraHint();
        [$validators, $phaseOne] = $this->raceValidatorsAndReservation();
        $winners = array_values(array_filter($validators, fn (array $result): bool => $result['permit'] === true));
        $losers = array_values(array_filter($validators, fn (array $result): bool => $result['permit'] === false));
        $this->assertCount(1, $winners);
        $this->assertCount(1, $losers);
        $this->assertSame(1, $winners[0]['generation']);
        $this->assertTrue(Str::isUuid($winners[0]['token']));
        $this->assertNotSame($this->provisional->leaseToken, $winners[0]['token']);
        $this->assertSame([$this->messageId($extra)], $phaseOne['messages']);
        app(RlsContextRunner::class)->runAsService(function () use ($winners): void {
            $fresh = $this->intent->fresh();
            $this->assertSame(1, $fresh->reconciliation_lookup_attempts);
            $this->assertSame($winners[0]['token'], $fresh->reconciliation_lease_token);
            $this->assertSame('processing', $fresh->status);
            $this->assertSame(1, $fresh->attempts);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
        });
    }

    public function test_expired_and_stolen_tokens_fail_closed_under_runtime_constraints(): void
    {
        $expired = CarbonImmutable::now()->subMinute();
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('outbox_messages')->where('id', $this->intent->id)
            ->update(['reconciliation_lease_expires_at' => $expired]));
        $expiredInput = new ProvisionalAssessmentInvoiceLease(
            $this->provisional->messageId, $this->provisional->leaseToken, $expired,
        );
        $this->assertNull(app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($expiredInput));
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertNull($this->intent->fresh()->reconciliation_lease_token);
            $this->assertSame(0, $this->intent->fresh()->reconciliation_lookup_attempts);
        });

        $leases = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(1, 1);
        $this->assertCount(1, $leases);
        $stolen = (string) Str::uuid();
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('outbox_messages')->where('id', $this->intent->id)
            ->update(['reconciliation_lease_token' => $stolen]));
        $this->assertNull(app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($leases[0]));
        app(RlsContextRunner::class)->runAsService(function () use ($stolen): void {
            $this->assertSame($stolen, $this->intent->fresh()->reconciliation_lease_token);
            $this->assertSame(0, $this->intent->fresh()->reconciliation_lookup_attempts);
        });
    }

    public function test_issuance_and_leased_exact_results_serialize_to_one_persistence(): void
    {
        $permit = app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($this->provisional);
        $this->assertNotNull($permit);
        $invoice = $this->invoice();

        $result = $this->runIssuanceDuringLookup($permit, $invoice);

        $this->assertSame('issued', $result['issuance']);
        $this->assertSame('recovery_required', $result['leased']);
        app(RlsContextRunner::class)->runAsService(function () use ($permit): void {
            $message = $this->intent->fresh();
            $bill = $this->bill->fresh();
            $this->assertSame('pending', $bill->status);
            $this->assertSame('processed', $message->status);
            $this->assertSame(1, $message->reconciliation_lookup_attempts);
            $this->assertNull($message->reconciliation_lease_token);
            $this->assertNull($message->reconciliation_lease_expires_at);
            $this->assertNull($message->reconciliation_next_at);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')
                ->where('subject_id', (string) $permit->invoice->billId)->count());
        });
    }

    public function test_leased_exact_response_is_discarded_after_token_is_stolen_during_lookup(): void
    {
        $permit = app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($this->provisional);
        $this->assertNotNull($permit);
        $stolen = (string) Str::uuid();
        $result = $this->runBlockedLookup($permit, $stolen);

        $this->assertSame('recovery_required', $result['decision']);
        app(RlsContextRunner::class)->runAsService(function () use ($permit, $stolen): void {
            $message = $this->intent->fresh();
            $this->assertSame('issuing', $this->bill->fresh()->status);
            $this->assertSame('processing', $message->status);
            $this->assertSame($stolen, $message->reconciliation_lease_token);
            $this->assertSame($permit->lookupGeneration, $message->reconciliation_lookup_attempts);
            $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')
                ->where('subject_id', (string) $permit->invoice->billId)->count());
        });
    }

    /** @return array{issuance: string, leased: string} */
    private function runIssuanceDuringLookup(
        AssessmentInvoiceReconciliationPermit $permit,
        PaymentInvoice $invoice,
    ): array {
        DB::purge('pgsql');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create invoice outcome worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 15);
            try {
                $provider = Mockery::mock(PaymentProvider::class);
                $provider->shouldReceive('lookupInvoice')->once()->andReturnUsing(function () use ($pair, $invoice): PaymentInvoice {
                    fwrite($pair[1], "lookup\n");
                    if (fgets($pair[1]) !== "continue\n") {
                        throw new RuntimeException('Issuance race barrier timed out.');
                    }

                    return $invoice;
                });
                app()->instance(PaymentProvider::class, $provider);
                $result = app(ReconcileAssessmentBillInvoice::class)->executeLeased($permit);
                Mockery::close();
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
            $this->assertSame("lookup\n", fgets($pair[0]));
            $issuance = app(PersistAssessmentInvoiceOutcome::class)->execute($permit->invoice, $invoice);
            fwrite($pair[0], "continue\n");
            $leased = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('error', $leased, json_encode($leased));

            return ['issuance' => $issuance['decision'], 'leased' => $leased['decision']];
        } finally {
            fclose($pair[0]);
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    /** @return array{decision: string} */
    private function runBlockedLookup(AssessmentInvoiceReconciliationPermit $permit, string $stolen): array
    {
        DB::purge('pgsql');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create delayed lookup worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 15);
            try {
                $provider = Mockery::mock(PaymentProvider::class);
                $provider->shouldReceive('lookupInvoice')->once()->andReturnUsing(function () use ($pair): PaymentInvoice {
                    fwrite($pair[1], "lookup\n");
                    if (fgets($pair[1]) !== "continue\n") {
                        throw new RuntimeException('Delayed lookup barrier timed out.');
                    }

                    return $this->invoice();
                });
                app()->instance(PaymentProvider::class, $provider);
                $result = app(ReconcileAssessmentBillInvoice::class)->executeLeased($permit);
                Mockery::close();
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
            $this->assertSame("lookup\n", fgets($pair[0]));
            app(RlsContextRunner::class)->runAsService(fn () => DB::table('outbox_messages')
                ->where('id', $this->intent->id)->update(['reconciliation_lease_token' => $stolen]));
            fwrite($pair[0], "continue\n");
            $result = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('error', $result);

            return $result;
        } finally {
            fclose($pair[0]);
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    private function invoice(): PaymentInvoice
    {
        return new PaymentInvoice('inv-pg-leased', 'https://payments.example.test/pg-leased',
            $this->bill->amount, $this->bill->currency, CarbonImmutable::now()->addHour());
    }

    /** @return array{list<array<string, mixed>>, array<string, mixed>} */
    private function raceValidatorsAndReservation(): array
    {
        DB::purge('pgsql');
        $workers = [];
        foreach (['validate', 'validate', 'reserve'] as $operation) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false || ($pid = pcntl_fork()) === -1) {
                throw new RuntimeException('Unable to create lease-validation worker.');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                foreach ($workers as $worker) {
                    fclose($worker['socket']);
                }
                stream_set_timeout($pair[1], 15);
                try {
                    $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name');
                    DB::statement("SET lock_timeout = '10s'");
                    DB::statement("SET statement_timeout = '12s'");
                    fwrite($pair[1], json_encode(['pid' => $identity->pid, 'role' => $identity->name], JSON_THROW_ON_ERROR)."\n");
                    if (fgets($pair[1]) !== "go\n") {
                        throw new RuntimeException('Lease validation barrier timed out.');
                    }
                    if ($operation === 'validate') {
                        $permit = app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($this->provisional);
                        $result = ['permit' => $permit !== null, 'generation' => $permit?->lookupGeneration,
                            'token' => $permit?->leaseToken];
                    } else {
                        $leases = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(1, 1);
                        $result = ['messages' => array_map(fn ($lease): string => $lease->messageId, $leases)];
                    }
                    Http::assertNothingSent();
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
            $workers[] = ['pid' => $pid, 'socket' => $pair[0], 'operation' => $operation];
        }
        $validatorPids = [];
        foreach ($workers as $worker) {
            $identity = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('psikotes_runtime', $identity['role']);
            if ($worker['operation'] === 'validate') {
                $validatorPids[] = $identity['pid'];
            }
        }
        $phaseOne = null;
        try {
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $validatorPids, &$phaseOne): void {
                DB::table('branches')->where('id', $this->bill->organization_id)->lockForUpdate()->first();
                foreach ($workers as $worker) {
                    fwrite($worker['socket'], "go\n");
                }
                foreach ($validatorPids as $pid) {
                    $deadline = microtime(true) + 5;
                    do {
                        $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$pid]);
                        if ($waiting?->wait_event_type === 'Lock') {
                            break;
                        }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    $this->assertSame('Lock', $waiting?->wait_event_type,
                        'Both validators must serialize on the organization-first mutex.');
                }
                $reserve = $workers[2];
                $line = fgets($reserve['socket']);
                if (! is_string($line)) {
                    throw new RuntimeException('Phase one waited on the organization mutex.');
                }
                $phaseOne = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('error', $phaseOne);
            });
            $validators = [];
            foreach (array_slice($workers, 0, 2) as $worker) {
                $result = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('error', $result);
                $validators[] = $result;
            }
            if (! is_array($phaseOne)) {
                throw new RuntimeException('Phase-one result is missing.');
            }

            return [$validators, $phaseOne];
        } finally {
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
        }
    }

    private function insertExtraHint(): int
    {
        $id = app(RlsContextRunner::class)->runAsService(function (): int {
            $row = $this->intent->fresh()->getAttributes();
            unset($row['id']);
            $messageId = (string) Str::ulid();
            $row['message_id'] = $messageId;
            $row['deduplication_key'] = hash('sha256', $messageId);
            $row['aggregate_id'] = (string) random_int(1, PHP_INT_MAX);
            $row['reconciliation_lease_token'] = null;
            $row['reconciliation_lease_expires_at'] = null;
            $row['reconciliation_lookup_attempts'] = 0;

            return DB::table('outbox_messages')->insertGetId($row);
        });
        $this->extraMessages[] = $id;

        return $id;
    }

    private function messageId(int $id): string
    {
        $messageId = app(RlsContextRunner::class)->runAsService(fn () => DB::table('outbox_messages')
            ->where('id', $id)->value('message_id'));
        if (! is_string($messageId)) {
            throw new RuntimeException('Synthetic outbox hint is missing its message id.');
        }

        return $messageId;
    }

    /** @return array{organization: int, participant: int, package: int, attempt: int, charge: int, bill: int, payer: string, source: int, client: int} */
    private function createFixture(): array
    {
        $fixture = Fixture::create();
        DB::table('package_items')->insert([
            'package_id' => $fixture['package'], 'test_type' => 'dass21', 'sort_order' => 2,
        ]);
        foreach (['organization', 'participant', 'package', 'attempt', 'charge', 'bill', 'source', 'client'] as $key) {
            if (! is_int($fixture[$key] ?? null)) {
                throw new RuntimeException('Synthetic billing fixture is invalid.');
            }
        }
        if (! is_string($fixture['payer'] ?? null)) {
            throw new RuntimeException('Synthetic billing fixture payer is invalid.');
        }

        return ['organization' => $fixture['organization'], 'participant' => $fixture['participant'],
            'package' => $fixture['package'], 'attempt' => $fixture['attempt'], 'charge' => $fixture['charge'],
            'bill' => $fixture['bill'], 'payer' => $fixture['payer'], 'source' => $fixture['source'],
            'client' => $fixture['client']];
    }
}
