<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\InvalidCheckoutHandoff;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\EstablishedCheckoutSession;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Enums\CheckoutHandoffIntent;
use App\Models\AssessmentParticipant;
use App\Models\CheckoutHandoff;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use ReflectionMethod;
use SensitiveParameter;
use Tests\OrganizationPaymentTestCase;

final class CheckoutSessionEstablishmentTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
        $this->assertFalse(config('assessment_integration.checkout_session.enabled'));
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        $this->enableSession();
    }

    public function test_typed_surface_contains_only_sensitive_handoff_bearer(): void
    {
        $input = new ReflectionMethod(CheckoutSessionExchangeInput::class, '__construct');
        $this->assertSame(['rawHandoffToken'], array_map(
            static fn ($parameter): string => $parameter->getName(), $input->getParameters(),
        ));
        $this->assertCount(1, $input->getParameters()[0]->getAttributes(SensitiveParameter::class));
        $execute = new ReflectionMethod(EstablishCheckoutSession::class, 'execute');
        $this->assertSame(['input'], array_map(
            static fn ($parameter): string => $parameter->getName(), $execute->getParameters(),
        ));
        $this->assertCount(1, $execute->getParameters()[0]->getAttributes(SensitiveParameter::class));

        $constructor = new ReflectionMethod(EstablishedCheckoutSession::class, '__construct');
        $parameters = $constructor->getParameters();
        $this->assertCount(1, $parameters[count($parameters) - 2]->getAttributes(SensitiveParameter::class));
        $this->assertCount(1, $parameters[count($parameters) - 1]->getAttributes(SensitiveParameter::class));
    }

    public function test_establish_consumes_and_creates_one_session_with_digest_only_and_safe_audits(): void
    {
        $fixture = $this->issued();
        $handoffBefore = DB::table('checkout_handoffs')->where('id', $fixture['handoff'])
            ->sole(['issued_at', 'expires_at']);
        $result = $this->establish($fixture['raw']);
        $selector = $result->rawSelector();
        $csrf = $result->rawCsrfToken();

        $this->assertMatchesRegularExpression('/^ocs1_[0-9a-f]{64}$/D', $selector);
        $this->assertMatchesRegularExpression('/^ocsrf1_[0-9a-f]{64}$/D', $csrf);
        $this->assertDatabaseHas('checkout_handoffs', [
            'id' => $fixture['handoff'], 'status' => 'CONSUMED', 'active_marker' => null,
        ]);
        $session = CheckoutSession::query()->where('public_id', $result->sessionPublicId)->firstOrFail();
        $this->assertSame(hash('sha256', $selector), $session->selector_digest);
        $this->assertSame(hash('sha256', $csrf), $session->csrf_digest);
        $this->assertSame($fixture['handoff'], $session->checkout_handoff_id);
        $this->assertSame('ACTIVE', $session->status);
        $this->assertTrue($session->active_marker);
        $this->assertTrue($session->established_at->equalTo($session->last_seen_at));
        $this->assertSame(30.0, $session->established_at->diffInMinutes($session->idle_expires_at));
        $this->assertSame(120.0, $session->established_at->diffInMinutes($session->absolute_expires_at));
        $this->assertEquals($handoffBefore, DB::table('checkout_handoffs')->where('id', $fixture['handoff'])
            ->sole(['issued_at', 'expires_at']));
        $this->assertArrayNotHasKey('selector_digest', $session->toArray());
        $this->assertArrayNotHasKey('csrf_digest', $session->toArray());

        $descriptor = json_encode($result->descriptor(), JSON_THROW_ON_ERROR);
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        foreach ([$selector, $csrf, hash('sha256', $selector), hash('sha256', $csrf)] as $secret) {
            $this->assertStringNotContainsString($secret, $descriptor);
            $this->assertStringNotContainsString($secret, $serialized);
            $this->assertStringNotContainsString($secret, json_encode(
                DB::table('audit_logs')->whereIn('action', [
                    'checkout_handoff.consumed', 'checkout_session.established',
                ])->get(), JSON_THROW_ON_ERROR,
            ));
        }
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout_handoff.consumed')->count());
        $audit = DB::table('audit_logs')->where('action', 'checkout_session.established')->sole();
        $this->assertSame([
            'version', 'sessionPublicId', 'handoffPublicId', 'sourceSystem',
            'establishedAt', 'idleExpiresAt', 'absoluteExpiresAt',
        ], array_keys(json_decode($audit->context, true, 512, JSON_THROW_ON_ERROR)));
    }

    public function test_replay_is_generic_invalid_and_cannot_create_a_second_session(): void
    {
        $fixture = $this->issued();
        $this->establish($fixture['raw']);
        $auditExpiry = DB::table('audit_logs')
            ->where('action', 'checkout_session.established')->sole()->expires_at;
        try {
            $this->establish($fixture['raw']);
            $this->fail('Consumed bearer established twice.');
        } catch (InvalidCheckoutHandoff $exception) {
            $this->assertSame('CHECKOUT_HANDOFF_INVALID', $exception->getMessage());
        }
        $this->assertDatabaseCount('checkout_sessions', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout_handoff.consumed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout_session.established')->count());
        $this->assertSame($auditExpiry, DB::table('audit_logs')
            ->where('action', 'checkout_session.established')->sole()->expires_at);
    }

    public function test_established_audit_retention_uses_occurrence_anchor_without_extending_session_ttl(): void
    {
        config()->set('assessment_integration.checkout_session.idle_minutes', 120);
        config()->set('assessment_integration.checkout_session.absolute_minutes', 1440);
        $fixture = $this->issued();

        $this->establish($fixture['raw']);

        $session = CheckoutSession::query()->sole();
        $audit = DB::table('audit_logs')->where('action', 'checkout_session.established')->sole();
        $anchor = CarbonImmutable::parse($audit->occurred_at)->utc();
        $auditExpiry = CarbonImmutable::parse($audit->expires_at)->utc();
        $absoluteExpiry = CarbonImmutable::instance($session->absolute_expires_at)->utc();

        $this->assertTrue(CarbonImmutable::instance($session->established_at)->utc()->equalTo($anchor));
        $this->assertSame(120.0, $anchor->diffInMinutes($session->idle_expires_at));
        $this->assertSame(1440.0, $anchor->diffInMinutes($absoluteExpiry));
        $this->assertTrue($auditExpiry->equalTo(
            app(RetentionPolicy::class)->expiresAt(RetentionDataClass::Audit, $anchor),
        ));
        $this->assertFalse($auditExpiry->equalTo(
            app(RetentionPolicy::class)->expiresAt(RetentionDataClass::Audit, $absoluteExpiry),
        ));
        $this->assertSame(
            '2029-02-28 03:15:00.000000+00:00',
            app(RetentionPolicy::class)->expiresAt(
                RetentionDataClass::Audit,
                CarbonImmutable::parse('2024-02-29 10:15:00+07:00')->utc(),
            )->format('Y-m-d H:i:s.uP'),
        );
    }

    public function test_config_and_ambient_authority_fail_closed_before_consumption(): void
    {
        $cases = [
            'disabled' => ['enabled', false],
            'idle type' => ['idle_minutes', '30'],
            'idle low' => ['idle_minutes', 0],
            'idle high' => ['idle_minutes', 121],
            'absolute type' => ['absolute_minutes', 120.0],
            'absolute low' => ['absolute_minutes', 0],
            'absolute high' => ['absolute_minutes', 1441],
            'retention type' => ['terminal_retention_days', '30'],
            'retention low' => ['terminal_retention_days', 0],
            'retention high' => ['terminal_retention_days', 366],
        ];
        foreach ($cases as $label => [$key, $value]) {
            $this->enableSession();
            config()->set("assessment_integration.checkout_session.{$key}", $value);
            $fixture = $this->issued();
            try {
                $this->establish($fixture['raw']);
                $this->fail("{$label} config established a session.");
            } catch (LogicException $exception) {
                $this->assertSame('Checkout session establishment is unavailable.', $exception->getMessage(), $label);
            }
            $this->assertDatabaseHas('checkout_handoffs', [
                'id' => $fixture['handoff'], 'status' => 'ISSUED', 'active_marker' => true,
            ]);
        }

        $this->enableSession();
        config()->set('assessment_integration.checkout_session.idle_minutes', 60);
        config()->set('assessment_integration.checkout_session.absolute_minutes', 30);
        $fixture = $this->issued();
        try {
            $this->establish($fixture['raw']);
            $this->fail('Idle duration exceeded absolute duration.');
        } catch (LogicException) {
            $this->assertDatabaseHas('checkout_handoffs', ['id' => $fixture['handoff'], 'status' => 'ISSUED']);
        }

        $this->enableSession();
        $fixture = $this->issued();
        $input = new CheckoutSessionExchangeInput($fixture['raw']);
        foreach ([new RlsContext('service'), new RlsContext('participant', $fixture['organization'], $fixture['participant'])] as $context) {
            try {
                app(RlsContextRunner::class)->run($context, fn () => app(EstablishCheckoutSession::class)->execute($input));
                $this->fail('Ambient RLS context established a session.');
            } catch (LogicException) {
                $this->assertDatabaseHas('checkout_handoffs', ['id' => $fixture['handoff'], 'status' => 'ISSUED']);
            }
        }
        DB::transaction(function () use ($input, $fixture): void {
            try {
                app(EstablishCheckoutSession::class)->execute($input);
                $this->fail('Ambient transaction established a session.');
            } catch (LogicException) {
                $this->assertDatabaseHas('checkout_handoffs', ['id' => $fixture['handoff'], 'status' => 'ISSUED']);
            }
        });
    }

    public function test_insert_and_audit_failures_roll_back_consume_session_and_both_audits(): void
    {
        foreach (['insert', 'audit'] as $failure) {
            $fixture = $this->issued();
            $initialAuditCount = DB::table('audit_logs')
                ->where('subject_id', (string) $fixture['attempt'])->count();
            $trigger = $failure === 'insert'
                ? "CREATE TRIGGER checkout_session_insert_failure BEFORE INSERT ON checkout_sessions
                    BEGIN SELECT RAISE(ABORT, 'synthetic session insert failure'); END"
                : "CREATE TRIGGER checkout_session_audit_failure BEFORE INSERT ON audit_logs
                    WHEN NEW.action = 'checkout_session.established'
                    BEGIN SELECT RAISE(ABORT, 'synthetic session audit failure'); END";
            DB::unprepared($trigger);
            try {
                $this->establish($fixture['raw']);
                $this->fail("{$failure} failure returned credentials.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString("synthetic session {$failure} failure", $exception->getMessage());
            } finally {
                DB::unprepared('DROP TRIGGER checkout_session_'.$failure.'_failure');
            }
            $this->assertDatabaseHas('checkout_handoffs', [
                'id' => $fixture['handoff'], 'status' => 'ISSUED', 'active_marker' => true,
            ]);
            $this->assertDatabaseCount('checkout_sessions', 0);
            $this->assertSame($initialAuditCount, DB::table('audit_logs')
                ->where('subject_id', (string) $fixture['attempt'])->count());
            $this->assertSame(0, DB::table('audit_logs')->where('subject_id', (string) $fixture['attempt'])
                ->whereIn('action', ['checkout_handoff.consumed', 'checkout_session.established'])->count());
        }
    }

    public function test_establish_has_no_unrelated_side_effects_and_system_failure_is_not_generic_invalid(): void
    {
        $fixture = $this->issued();
        $tables = ['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements',
            'orders', 'entitlements', 'outbox_messages', 'consent_records', 'identity_verifications', 'sessions'];
        $counts = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        $attempt = AssessmentParticipant::query()->findOrFail($fixture['attempt'])->getAttributes();
        $this->establish($fixture['raw']);
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
        $this->assertSame($attempt, AssessmentParticipant::query()->findOrFail($fixture['attempt'])->getAttributes());
    }

    private function enableSession(): void
    {
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
        ]);
    }

    private function establish(string $raw): EstablishedCheckoutSession
    {
        return app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($raw));
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,handoff:int,raw:string} */
    private function issued(): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'SESSION_ESTABLISH_SOURCE';
        $packageCode = 'S'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic', 'amount' => 100,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('package_items')->insert(['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1]);
        DB::table('package_items')->insert(['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2]);
        $attemptPublicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $attemptPublicId,
            'participant_id' => $participant,
            'organization_id' => $organization,
            'package_id' => $package,
            'origin' => 'INTEGRATED',
            'intended_field_snapshot' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_case_id' => $case,
            'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY',
            ], JSON_THROW_ON_ERROR),
        ]);
        $assessmentAttemptId = DB::table('assessment_participants')
            ->where('id', $attempt)->value('assessment_attempt_id');
        if (! is_string($assessmentAttemptId)) {
            $this->fail('Synthetic assessment attempt identifier is unavailable.');
        }
        $result = app(RlsContextRunner::class)->run(
            new RlsContext('service'),
            fn () => app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
                IntegrationClient::query()->findOrFail($client),
                $assessmentAttemptId,
                $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue,
            )),
        );
        $raw = $result->rawToken();
        $this->assertIsString($raw);
        $handoff = CheckoutHandoff::query()->where('public_id', $result->handoffPublicId)->value('id');
        if (! is_int($handoff)) {
            $this->fail('Synthetic checkout handoff identifier is unavailable.');
        }

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt', 'handoff', 'raw');
    }
}
