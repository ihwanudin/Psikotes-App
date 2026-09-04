<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\CheckoutSessionLifecycle;
use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\InvalidCheckoutSession;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Data\Integrations\CheckoutSummary;
use App\Enums\CheckoutHandoffIntent;
use App\Models\AssessmentParticipant;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class CheckoutSummaryLifecycleTest extends OrganizationPaymentTestCase
{
    private array $fixture;

    private CheckoutSessionMutationCredentials $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('Migration wrapper unavailable.');
        }
        $command->assertExitCode(0);
        unset($command);
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', ['enabled' => true, 'idle_minutes' => 30,
            'absolute_minutes' => 120, 'terminal_retention_days' => 30]);
        $this->fixture = Fixture::create();
        DB::table('branches')->update(['status' => 'ACTIVE', 'is_active' => true]);
        DB::table('integration_clients')->update(['enabled' => true]);
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED', 'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}']);
        $attempt = AssessmentParticipant::findOrFail($this->fixture['attempt']);
        DB::table('integration_sources')->insert(['integration_client_id' => $attempt->integration_client_id,
            'source_system' => $attempt->source_system, 'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
            'allowed_assessment_packages' => json_encode([DB::table('packages')->where('id', $attempt->package_id)->value('code')], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]']);
        $issued = $this->issue(CheckoutHandoffIntent::Issue);
        $exchanged = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($issued));
        $this->credentials = new CheckoutSessionMutationCredentials($exchanged->rawSelector(), $exchanged->rawCsrfToken());
        DB::table('assessment_participants')->update(['assessment_status' => 'READY']);
        $this->age();
    }

    public function test_credential_only_summary_uses_one_transaction_clock_and_locked_catalog_graph(): void
    {
        $method = new ReflectionMethod(CheckoutSessionLifecycle::class, 'readSummary');
        $this->assertCount(1, $method->getParameters());
        $this->assertSame(CheckoutSessionMutationCredentials::class, (string) $method->getParameters()[0]->getType());
        $this->assertCount(1, $method->getParameters()[0]->getAttributes(SensitiveParameter::class));
        $this->assertSame(CheckoutSummary::class, (string) $method->getReturnType());
        $before = CheckoutSession::firstOrFail()->getAttributes();
        $business = $this->rows();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $summary = $this->read();
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'AS current_time')));
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_starts_with($sql, 'update "checkout_sessions"')));
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'from "packages"')));
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'from "package_items"')));
        $this->assertSame('paid', $summary['payment']['state']);
        $this->assertSame('ready', $summary['access']['state']);
        $this->assertNotSame($before, CheckoutSession::firstOrFail()->getAttributes());
        $this->assertSame($business, $this->rows());
        $this->assertCleanContext();
    }

    public function test_no_charge_uses_attached_locked_items_without_lazy_reload(): void
    {
        DB::table('assessment_entitlements')->delete();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->delete();
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED', 'funding_mode' => null]);
        $summary = $this->read();
        $this->assertSame('catalog', $summary['packageSource']);
        $this->assertNull($summary['payment']['amountIdr']);
        $this->assertSame('locked', $summary['access']['state']);
    }

    public function test_collective_bill_exposes_only_own_price_and_legal_false_does_not_change_gate(): void
    {
        for ($i = 1; $i < 10; $i++) {
            $other = Fixture::create(identity: ['organization' => $this->fixture['organization']]);
            DB::table('assessment_bill_items')->where('id', $other['item'])->update(['bill_id' => $this->fixture['bill']]);
        }
        DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update(['amount' => 1000, 'item_count' => 10,
            'gateway_ref' => 'PRIVATE_GATEWAY', 'invoice_url' => 'https://synthetic.invalid/PRIVATE_INVOICE', 'proof_object_key' => 'PRIVATE_PROOF']);
        config()->set('consent.legal_review_pending', false);
        $summary = $this->read();
        $this->assertSame(100, $summary['payment']['amountIdr']);
        $this->assertSame('ready', $summary['access']['state']);
        $this->assertFalse($summary['consents']['legalReviewPending']);
        $json = json_encode($summary, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_GATEWAY', 'PRIVATE_INVOICE', 'PRIVATE_PROOF', $this->credentials->rawSelector(),
            $this->credentials->rawCsrfToken(), hash('sha256', $this->credentials->rawSelector())] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $keys = [];
        array_walk_recursive($summary, function ($value, $key) use (&$keys): void {
            $keys[] = $key;
        });
        foreach (['id', 'participantId', 'assessmentAttemptId', 'billId', 'invoice', 'count', 'total', 'formKey',
            'confidence', 'score', 'credential', 'csrf', 'sessionPublicId'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys);
        }
    }

    public function test_logout_keeps_old_summary_credentials_terminal_without_duplicate_audit(): void
    {
        app(CheckoutSessionLifecycle::class)->logout($this->credentials);
        $before = $this->rows(true);
        try {
            $this->read();
            $this->fail('Logged out session read summary.');
        } catch (InvalidCheckoutSession $error) {
            $this->assertSame('CHECKOUT_SESSION_INVALID', $error->getMessage());
        }
        $this->assertSame($before, $this->rows(true));
        $this->assertCleanContext();
    }

    #[DataProvider('componentFailures')]
    public function test_component_failure_rolls_back_idle_touch_and_business_state(string $failure): void
    {
        match ($failure) {
            'profile' => DB::table('participants')->update(['full_name' => ' ']),
            'payment' => DB::table('assessment_charges')->update(['price_snapshot' => '{}']),
            'legal' => config()->set('consent.legal_review_pending', 'false'),
            'document' => config()->set('consent.documents.psychotest.title', ' '),
            'branch' => DB::table('branches')->update(['name' => '', 'display_name' => ' ']),
        };
        $before = $this->rows(true);
        try {
            $this->read();
            $this->fail('Partial summary escaped.');
        } catch (DomainException $error) {
            $this->assertSame('CHECKOUT_SUMMARY_UNAVAILABLE', $error->getMessage());
        }
        $this->assertSame($before, $this->rows(true));
        $this->assertCleanContext();
    }

    public static function componentFailures(): iterable
    {
        foreach (['profile', 'payment', 'legal', 'document', 'branch'] as $case) {
            yield [$case];
        }
    }

    public function test_unexpected_final_gate_failure_propagates_and_rolls_back_observed_idle_touch(): void
    {
        $before = $this->rows(true);
        $seen = DB::table('checkout_sessions')->value('last_seen_at');
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = clone $original;
        $connection->setEventDispatcher($events);
        $hit = false;
        $events->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$hit, $seen): void {
            if (! $hit && str_starts_with($event->sql, 'select') && str_contains($event->sql, 'from "assessment_entitlements"')) {
                $hit = true;
                $this->assertSame(1, DB::transactionLevel());
                $this->assertSame('service', app(RlsContextRunner::class)->current()?->role);
                $this->assertNotSame($seen, DB::table('checkout_sessions')->value('last_seen_at'));
                throw new RuntimeException('Synthetic late component failure');
            }
        });
        try {
            $this->read();
            $this->fail('Unexpected failure was swallowed.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic late component failure', $error->getMessage());
        } finally {
            $connection->setEventDispatcher($original);
        }
        $this->assertTrue($hit);
        $this->assertSame($before, $this->rows(true));
        $this->assertCleanContext();
    }

    public function test_malformed_foreign_credentials_and_recovery_cannot_read_or_touch_old_session(): void
    {
        $before = $this->rows(true);
        foreach ([new CheckoutSessionMutationCredentials('invalid', 'invalid'),
            new CheckoutSessionMutationCredentials($this->credentials->rawSelector(), 'ocsrf1_'.str_repeat('0', 64)),
            new CheckoutSessionMutationCredentials('ocs1_'.str_repeat('0', 64), $this->credentials->rawCsrfToken())] as $credentials) {
            try {
                app(CheckoutSessionLifecycle::class)->readSummary($credentials);
                $this->fail('Foreign pair accepted.');
            } catch (InvalidCheckoutSession $error) {
                $this->assertSame('CHECKOUT_SESSION_INVALID', $error->getMessage());
            }
        }
        $this->assertSame($before, $this->rows(true));
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED']);
        $this->issue(CheckoutHandoffIntent::Recovery);
        $this->expectException(InvalidCheckoutSession::class);
        $this->read();
    }

    #[DataProvider('terminalCauses')]
    public function test_expiry_or_scope_revocation_commits_terminal_once(string $cause): void
    {
        if ($cause === 'expiry') {
            DB::table('checkout_sessions')->update(['idle_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-1 second')")]);
        } elseif ($cause === 'deleted') {
            DB::table('participants')->update(['deleted_at' => DB::raw('CURRENT_TIMESTAMP')]);
        } else {
            DB::table('integration_clients')->update(['enabled' => false]);
        }
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->read();
                $this->fail('Terminal scope admitted.');
            } catch (InvalidCheckoutSession $error) {
                $this->assertSame('CHECKOUT_SESSION_INVALID', $error->getMessage());
            }
        }
        $this->assertSame($cause === 'expiry' ? 'EXPIRED' : 'REVOKED', CheckoutSession::firstOrFail()->status);
        $this->assertSame(1, DB::table('audit_logs')->whereIn('action', ['checkout_session.expired', 'checkout_session.revoked'])->count());
        $this->assertCleanContext();
    }

    public static function terminalCauses(): iterable
    {
        yield ['expiry'];
        yield ['scope'];
        yield ['deleted'];
    }

    public function test_ambient_roles_and_outer_transaction_cannot_invoke_summary(): void
    {
        foreach ([null, 'service', 'super_admin', 'participant'] as $role) {
            try {
                $call = fn () => $this->read();
                $role === null ? DB::transaction($call) : app(RlsContextRunner::class)->run(
                    new RlsContext($role, $this->fixture['organization'], $this->fixture['participant']), $call);
                $this->fail('Ambient context admitted.');
            } catch (LogicException $error) {
                $this->assertSame('Checkout session lifecycle owns its service transaction.', $error->getMessage());
            }
            $this->assertCleanContext();
        }
    }

    public function test_database_time_not_application_clock_controls_entire_summary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2001-01-01 UTC'));
        $summary = $this->read();
        $this->assertSame('paid', $summary['payment']['state']);
        $this->assertSame('accepted', $summary['consents']['psychotest']['state']);
        $this->assertSame('ready', $summary['access']['state']);
        $today = (string) DB::scalar('SELECT CURRENT_DATE');
        DB::table('participants')->update(['birth_date' => $today]);
        $this->travelTo(CarbonImmutable::parse('2099-01-01 UTC'));
        $this->expectExceptionMessage('CHECKOUT_SUMMARY_UNAVAILABLE');
        $this->read();
    }

    private function issue(CheckoutHandoffIntent $intent): string
    {
        $attempt = AssessmentParticipant::findOrFail($this->fixture['attempt']);
        $issued = app(RlsContextRunner::class)->runAsService(fn () => app(IssueCheckoutHandoff::class)->execute(
            new CheckoutHandoffIssueInput(IntegrationClient::findOrFail($attempt->integration_client_id), $attempt->assessment_attempt_id,
                $attempt->source_system, 'ih1_'.bin2hex(random_bytes(16)), $intent)));

        return $issued->rawToken() ?? throw new RuntimeException('Synthetic token unavailable');
    }

    private function age(): void
    {
        DB::table('checkout_handoffs')->update(['issued_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-3 minutes')"),
            'consumed_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-2 minutes')"), 'expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+5 minutes')")]);
        DB::table('checkout_sessions')->update(['established_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-2 minutes')"),
            'last_seen_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-1 minute')"), 'idle_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+5 minutes')")]);
    }

    private function read(): array
    {
        return app(CheckoutSessionLifecycle::class)->readSummary($this->credentials)->toArray();
    }

    private function assertCleanContext(): void
    {
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function rows(bool $sessions = false): array
    {
        $rows = [];
        foreach (['participants', 'assessment_participants', 'assessment_charges', 'assessment_bills', 'assessment_bill_items',
            'assessment_entitlements', 'audit_logs', 'outbox_messages', ...($sessions ? ['checkout_sessions'] : [])] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
