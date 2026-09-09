<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReconcileAssessmentBillInvoice;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Contracts\PaymentProvider;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentEvent;
use App\Data\Payments\PaymentInvoice;
use App\Data\Payments\PaymentWebhookInput;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\OutboxMessage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\PaymentProviderException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentPreviewFixture as Fixture;
use Throwable;

/** Real runtime role, independent PostgreSQL processes, and a disposable database only. */
final class AssessmentBillInvoiceIssuanceTest extends TestCase
{
    /** @var list<array{organization: int, participant: int, package: int, attempt: int, charge: int, bill: int, payer: string, source: int, client: int}> */
    private array $fixtures = [];

    private Admin $admin;

    private AssessmentBill $bill;

    private OutboxMessage $intent;

    private int $method;

    private mixed $previousNow;

    private mixed $previousHttp;

    private PaymentProvider $previousProvider;

    private mixed $previousEnabled;

    private mixed $previousDuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(function_exists('pcntl_fork'), 'Independent process proof requires pcntl, never skip.');
        $this->assertSame(0, DB::transactionLevel());
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->previousNow = Date::getTestNow();
        $this->previousHttp = Http::getFacadeRoot();
        $this->previousProvider = app(PaymentProvider::class);
        $this->previousEnabled = config('assessment_integration.checkout.enabled');
        $this->previousDuration = config('assessment_billing.invoice_duration_hours');
        Date::setTestNow('2026-09-01T00:00:00Z');
        config()->set('assessment_integration.checkout.enabled', true);
        config()->set('assessment_billing.invoice_duration_hours', 24);
        Http::preventStrayRequests();
        Http::fake([]);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->fixtures[] = $this->createFixture();
            $org = $this->fixtures[0]['organization'];
            for ($index = 1; $index < 10; $index++) {
                $this->fixtures[] = $this->createFixture(['organization' => $org]);
            }
            DB::table('assessment_participants')->where('organization_id', $org)->update(['funding_mode' => 'INVOICED_TO_ORGANIZATION',
                'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}']);
            $this->admin = Admin::create(['branch_id' => $org, 'name' => 'Synthetic PG issuer',
                'email' => Str::ulid().'@example.test', 'password' => 'synthetic', 'role' => AdminRole::BranchAdmin]);
            $this->method = DB::table('payment_methods')->insertGetId(['code' => 'xendit', 'display_name' => 'Synthetic PG', 'is_active' => true]);
            $selection = array_map(fn (array $fixture): array => [
                'assessmentParticipantId' => $fixture['attempt'],
                'consultationRequested' => false,
            ], $this->fixtures);
            $preview = app(PreviewAssessmentBill::class)->execute($org, $selection, PayerType::Organization);
            $this->bill = app(ReserveAssessmentBill::class)->execute($this->admin, $selection, $this->method, $preview['selectionHash'], 'pg-issue-ten');
            app(ClaimAssessmentBillInvoice::class)->execute($org, $this->bill->id);
            $this->intent = OutboxMessage::query()->where('aggregate_id', (string) $this->bill->id)->sole();
        });
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNull(app(RlsContextRunner::class)->current());
        } finally {
            app(RlsContextRunner::class)->runAsService(function (): void {
                $org = $this->fixtures[0]['organization'];
                DB::table('outbox_messages')->where('aggregate_type', AssessmentBill::class)->where('aggregate_id', (string) $this->bill->id)->delete();
                DB::table('assessment_bill_items')->where('organization_id', $org)->delete();
                DB::table('assessment_bills')->where('organization_id', $org)->delete();
                DB::table('assessment_charges')->where('organization_id', $org)->delete();
                DB::table('audit_logs')->where('branch_id', $org)->delete();
                DB::table('admins')->where('id', $this->admin->id)->delete();
                DB::table('payment_methods')->where('id', $this->method)->delete();
            });
            Date::setTestNow($this->previousNow);
            config()->set('assessment_integration.checkout.enabled', $this->previousEnabled);
            config()->set('assessment_billing.invoice_duration_hours', $this->previousDuration);
            Http::swap($this->previousHttp);
            app()->instance(PaymentProvider::class, $this->previousProvider);
            parent::tearDown();
        }
    }

    public function test_two_runtime_processes_have_one_permit_winner_and_one_create(): void
    {
        [$first, $second] = $this->race();
        $decisions = [$first['decision'], $second['decision']];
        sort($decisions);
        $this->assertSame(['issued', 'recovery_required'], $decisions);
        $this->assertSame(1, $first['creates'] + $second['creates']);
        $this->assertSame(1, $first['lookups'] + $second['lookups']);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $message = $this->intent->fresh();
            $bill = $this->bill->fresh();
            $this->assertSame('processed', $message->status);
            $this->assertSame(1, $message->attempts);
            $this->assertSame('pending', $bill->status);
            $this->assertSame('pg-invoice-123', $bill->gateway_ref);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_permit_consumed')
                ->where('subject_id', (string) $bill->id)->count());
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')
                ->where('subject_id', (string) $bill->id)->count());
            $this->assertSame(0, DB::table('assessment_entitlements')->where('organization_id', $bill->organization_id)->count());
        });
    }

    public function test_permit_audit_failure_rolls_back_counter_and_releases_lock(): void
    {
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'audit_logs')) {
                $armed = false;
                throw new RuntimeException('synthetic-permit-rollback');
            }
        });
        try {
            app(IssueAssessmentBillInvoice::class)->consume($this->intent->message_id);
            $this->fail('Audit injection must run.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic-permit-rollback', $e->getMessage());
            $this->assertFalse($armed);
        } finally {
            $armed = false;
        }
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame('pending', $this->intent->fresh()->status);
            $this->assertSame(0, $this->intent->fresh()->attempts);
            $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_permit_consumed')
                ->where('subject_id', (string) $this->bill->id)->count());
        });
        $provider = new SyntheticIssuanceProvider($this->bill->public_reference, $this->bill->amount);
        app()->instance(PaymentProvider::class, $provider);
        $this->assertSame('issued', app(IssueAssessmentBillInvoice::class)->execute($this->intent->message_id)['decision']);
        $this->assertSame(1, $provider->creates);
    }

    public function test_committed_crash_boundary_is_visible_and_never_rearmed(): void
    {
        $permit = app(IssueAssessmentBillInvoice::class)->consume($this->intent->message_id);
        $this->assertNotNull($permit);
        DB::purge('pgsql');
        $observed = app(RlsContextRunner::class)->runAsService(fn () => [
            OutboxMessage::query()->where('message_id', $this->intent->message_id)->value('status'),
            OutboxMessage::query()->where('message_id', $this->intent->message_id)->value('attempts'),
        ]);
        $this->assertSame(['processing', 1], $observed);
        $provider = new SyntheticIssuanceProvider($this->bill->public_reference, $this->bill->amount);
        app()->instance(PaymentProvider::class, $provider);
        $this->assertSame('recovery_required', app(IssueAssessmentBillInvoice::class)->execute($this->intent->message_id)['decision']);
        $this->assertSame(0, $provider->creates);
        $this->assertSame(0, $provider->lookups);
    }

    public function test_two_runtime_reconcilers_persist_one_exact_result_without_create(): void
    {
        app(IssueAssessmentBillInvoice::class)->consume($this->intent->message_id);
        [$first, $second] = $this->race('reconcile');
        $decisions = [$first['decision'], $second['decision']];
        sort($decisions);
        $this->assertSame(['issued', 'recovery_required'], $decisions);
        $this->assertSame(0, $first['creates'] + $second['creates']);
        $this->assertContains($first['lookups'] + $second['lookups'], [1, 2],
            'P10c-a has no durable lookup lease, so overlapping workers may both perform the read-only GET.');
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame('pending', $this->bill->fresh()->status);
            $this->assertSame('processed', $this->intent->fresh()->status);
            $this->assertSame(1, $this->intent->fresh()->attempts);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')
                ->where('subject_id', (string) $this->bill->id)->count());
        });
    }

    public function test_two_runtime_reconcilers_move_unknown_once_without_create_or_audit_spam(): void
    {
        app(IssueAssessmentBillInvoice::class)->consume($this->intent->message_id);
        [$first, $second] = $this->race('reconcile', true);
        $this->assertSame(['unknown', 'unknown'], [$first['decision'], $second['decision']]);
        $this->assertSame(0, $first['creates'] + $second['creates']);
        $this->assertContains($first['lookups'] + $second['lookups'], [1, 2]);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame('unknown', $this->bill->fresh()->status);
            $this->assertSame('failed', $this->intent->fresh()->status);
            $this->assertSame(1, $this->intent->fresh()->attempts);
            $this->assertSame('INVOICE_OUTCOME_UNKNOWN', $this->intent->fresh()->last_error);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_unknown')
                ->where('subject_id', (string) $this->bill->id)->count());
        });
    }

    public function test_ambient_service_and_user_contexts_cannot_enter_handler(): void
    {
        foreach (['service', 'participant', 'branch_admin', 'super_admin'] as $role) {
            try {
                app(RlsContextRunner::class)->run(new RlsContext($role, $this->bill->organization_id, $this->fixtures[0]['participant']),
                    fn () => app(IssueAssessmentBillInvoice::class)->execute($this->intent->message_id));
                $this->fail('Ambient RLS entry must fail.');
            } catch (LogicException) {
                app(RlsContextRunner::class)->runAsService(function (): void {
                    $this->assertSame('pending', $this->intent->fresh()->status);
                    $this->assertSame(0, $this->intent->fresh()->attempts);
                });
            }
        }
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function race(string $operation = 'issue', bool $unknown = false): array
    {
        DB::purge('pgsql'); // Never inherit a live PDO into either child.
        $workers = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($pair === false || ($pid = pcntl_fork()) === -1) {
                    throw new RuntimeException('Unable to create issuance worker.');
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
                            throw new RuntimeException('Barrier timed out.');
                        }
                        $provider = new SyntheticIssuanceProvider($this->bill->public_reference, $this->bill->amount, $unknown);
                        app()->instance(PaymentProvider::class, $provider);
                        $result = $operation === 'reconcile'
                            ? app(ReconcileAssessmentBillInvoice::class)->execute($this->intent->message_id)
                            : app(IssueAssessmentBillInvoice::class)->execute($this->intent->message_id);
                        $result['creates'] = $provider->creates;
                        $result['lookups'] = $provider->lookups;
                        Http::assertNothingSent();
                    } catch (Throwable $e) {
                        $result = ['error' => $e->getMessage(), 'class' => $e::class, 'creates' => 0, 'lookups' => 0];
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
                $identity = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('psikotes_runtime', $identity['role']);
                $backendIds[] = $identity['pid'];
            }
            $this->assertNotSame($backendIds[0], $backendIds[1]);
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendIds): void {
                DB::table('branches')->where('id', $this->bill->organization_id)->lockForUpdate()->first();
                foreach ($workers as $worker) {
                    fwrite($worker['socket'], "go\n");
                }
                foreach ($backendIds as $backendId) {
                    $deadline = microtime(true) + 5;
                    do {
                        $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendId]);
                        if ($waiting?->wait_event_type === 'Lock') {
                            break;
                        }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    $this->assertSame('Lock', $waiting?->wait_event_type, 'Both workers must actually overlap on the organization mutex.');
                }
            });
            $results = [];
            foreach ($workers as $worker) {
                $result = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($result)) {
                    throw new RuntimeException('Invalid worker result.');
                }
                $results[] = $result;
            }

            if (count($results) !== 2) {
                throw new RuntimeException('Both worker results are required.');
            }

            return [$results[0], $results[1]];
        } finally {
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
        }
    }

    /**
     * @param  array{organization: int}|null  $identity
     * @return array{organization: int, participant: int, package: int, attempt: int, charge: int, bill: int, payer: string, source: int, client: int}
     */
    private function createFixture(?array $identity = null): array
    {
        $fixture = Fixture::create($identity);
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

final class SyntheticIssuanceProvider implements PaymentProvider
{
    public int $creates = 0;

    public int $lookups = 0;

    public function __construct(
        private readonly string $reference,
        private readonly int $amount,
        private readonly bool $unknown = false,
    ) {}

    public function createInvoice(CreateInvoiceRequest $request): PaymentInvoice
    {
        $this->creates++;

        return $this->invoice();
    }

    public function lookupInvoice(string $merchantReference, int $amount, string $currency): PaymentInvoice
    {
        $this->lookups++;
        if ($merchantReference !== $this->reference || $amount !== $this->amount || $currency !== 'IDR') {
            throw new RuntimeException('Unexpected strict lookup input.');
        }
        if ($this->unknown) {
            throw new PaymentProviderException('Synthetic unknown lookup.');
        }

        return $this->invoice();
    }

    public function checkStatus(string $providerReference): PaymentEvent
    {
        throw new LogicException('Not used.');
    }

    public function normalizeWebhook(PaymentWebhookInput $input): PaymentEvent
    {
        throw new LogicException('Not used.');
    }

    public function expireInvoice(string $providerReference): PaymentEvent
    {
        throw new LogicException('Not used.');
    }

    private function invoice(): PaymentInvoice
    {
        return new PaymentInvoice('pg-invoice-123', 'https://invoice.xendit.co/pg-invoice-123', $this->amount, 'IDR', Date::now()->addDay());
    }
}
