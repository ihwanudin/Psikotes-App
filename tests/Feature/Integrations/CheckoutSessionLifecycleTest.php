<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\CheckoutSessionLifecycle;
use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\InvalidCheckoutSession;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutPaymentFacts;
use App\Data\Integrations\CheckoutProfile;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Data\Integrations\CheckoutSessionPrincipal;
use App\Data\Integrations\CheckoutSessionSelector;
use App\Enums\CheckoutHandoffIntent;
use App\Models\AssessmentParticipant;
use App\Models\CheckoutHandoff;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutHandoffHistoryValidator;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use ReflectionMethod;
use ReflectionProperty;
use SensitiveParameter;
use Tests\OrganizationPaymentTestCase;

final class CheckoutSessionLifecycleTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('Migration wrapper unavailable.');
        }
        $command->assertExitCode(0);
        $this->configure();
    }

    public function test_credentials_are_private_sensitive_and_hydrate_accepts_no_csrf(): void
    {
        $selector = new ReflectionMethod(CheckoutSessionSelector::class, '__construct');
        $this->assertSame(['rawSelector'], array_map(static fn ($p): string => $p->getName(), $selector->getParameters()));
        $this->assertCount(1, $selector->getParameters()[0]->getAttributes(SensitiveParameter::class));
        $this->assertTrue((new ReflectionProperty(CheckoutSessionSelector::class, 'rawSelector'))->isPrivate());

        $mutation = new ReflectionMethod(CheckoutSessionMutationCredentials::class, '__construct');
        $this->assertSame(['rawSelector', 'rawCsrfToken'], array_map(static fn ($p): string => $p->getName(), $mutation->getParameters()));
        foreach ($mutation->getParameters() as $parameter) {
            $this->assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        }
        $hydrate = new ReflectionMethod(CheckoutSessionLifecycle::class, 'hydrate');
        $this->assertSame(CheckoutSessionSelector::class, (string) $hydrate->getParameters()[0]->getType());
    }

    public function test_hydrate_reloads_own_scope_and_refreshes_idle_without_exposing_secrets_or_pii(): void
    {
        $fixture = $this->established();
        $handoff = DB::table('checkout_sessions')->where('id', $fixture['session'])->value('checkout_handoff_id');
        DB::table('checkout_handoffs')->where('id', $handoff)->update([
            'issued_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-2 minutes')"),
            'consumed_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-1 minute')"),
            'expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+8 minutes')"),
        ]);
        DB::table('checkout_sessions')->where('id', $fixture['session'])->update([
            'established_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-1 minute')"),
            'last_seen_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-1 minute')"),
            'idle_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+5 minutes')"),
            'absolute_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+10 minutes')"),
        ]);
        $before = CheckoutSession::query()->findOrFail($fixture['session']);
        $principal = $this->hydrate($fixture['selector']);
        $after = CheckoutSession::query()->findOrFail($fixture['session']);

        $this->assertInstanceOf(CheckoutSessionPrincipal::class, $principal);
        $this->assertSame($fixture['attemptPublicId'], $principal->assessmentAttemptId);
        $this->assertSame('PROVISIONED', $principal->assessmentStatus);
        $this->assertTrue($after->last_seen_at->greaterThan($before->last_seen_at));
        $this->assertTrue($after->idle_expires_at->greaterThan($before->idle_expires_at));
        $this->assertTrue($after->idle_expires_at->equalTo($after->absolute_expires_at));
        $json = json_encode($principal->descriptor(), JSON_THROW_ON_ERROR);
        foreach ([$fixture['selector'], $fixture['csrf'], hash('sha256', $fixture['selector']),
            'Synthetic Person', '620000000000', 'synthetic-only'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        foreach (['bill', 'invoice', 'reference', 'total', 'batch', 'credential', 'phone', 'fullName'] as $key) {
            $this->assertArrayNotHasKey($key, $principal->descriptor());
        }
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout_session.touched')->count());
    }

    public function test_invalid_stale_and_foreign_selectors_are_one_generic_failure_without_mutation(): void
    {
        $first = $this->established();
        $second = $this->established();
        $before = CheckoutSession::query()->findOrFail($first['session'])->getAttributes();
        foreach (['', 'ocs1_'.str_repeat('g', 64), 'ocs1_'.str_repeat('0', 64), $second['selector'].'00'] as $raw) {
            try {
                $this->hydrate($raw);
                $this->fail('Invalid selector hydrated.');
            } catch (InvalidCheckoutSession $exception) {
                $this->assertSame('CHECKOUT_SESSION_INVALID', $exception->getMessage());
            }
        }
        $this->assertSame($before, CheckoutSession::query()->findOrFail($first['session'])->getAttributes());
    }

    public function test_csrf_delivery_hydration_is_canonical_and_requires_the_exact_pair(): void
    {
        $fixture = $this->established();
        try {
            app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery(
                new CheckoutSessionMutationCredentials($fixture['selector'], 'ocsrf1_'.str_repeat('0', 64)),
            );
            $this->fail('Foreign CSRF delivery hydrated.');
        } catch (InvalidCheckoutSession $exception) {
            $this->assertSame('CHECKOUT_SESSION_INVALID', $exception->getMessage());
        }

        $principal = app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery(
            new CheckoutSessionMutationCredentials($fixture['selector'], $fixture['csrf']),
        );
        $this->assertSame($fixture['attemptPublicId'], $principal->assessmentAttemptId);
    }

    public function test_logout_requires_exact_csrf_and_is_terminal_without_replay_audit(): void
    {
        $fixture = $this->established();
        $foreign = $this->established();
        foreach (['', 'ocsrf1_'.str_repeat('0', 64), 'ocsrf1_'.str_repeat('g', 64), $foreign['csrf']] as $csrf) {
            try {
                $this->logout($fixture['selector'], $csrf);
                $this->fail('Invalid CSRF logged out.');
            } catch (InvalidCheckoutSession) {
                $this->assertDatabaseHas('checkout_sessions', ['id' => $fixture['session'], 'status' => 'ACTIVE']);
            }
        }

        $this->logout($fixture['selector'], $fixture['csrf']);
        $this->assertDatabaseHas('checkout_sessions', [
            'id' => $fixture['session'], 'status' => 'REVOKED', 'active_marker' => null,
            'revocation_reason' => 'LOGOUT', 'expired_at' => null,
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout_session.revoked')
            ->where('subject_id', (string) $fixture['attempt'])->count());
        try {
            $this->logout($fixture['selector'], $fixture['csrf']);
            $this->fail('Logout replay succeeded.');
        } catch (InvalidCheckoutSession) {
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout_session.revoked')
                ->where('subject_id', (string) $fixture['attempt'])->count());
        }
    }

    public function test_idle_and_absolute_boundaries_expire_deterministically_without_csrf(): void
    {
        foreach (['idle_expires_at', 'absolute_expires_at'] as $boundary) {
            $fixture = $this->established();
            $handoff = DB::table('checkout_sessions')->where('id', $fixture['session'])->value('checkout_handoff_id');
            DB::table('checkout_handoffs')->where('id', $handoff)->update([
                'issued_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-21 minutes')"),
                'consumed_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-20 minutes')"),
                'expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-11 minutes')"),
            ]);
            $values = [
                'established_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-20 minutes')"),
                'last_seen_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-10 minutes')"),
                'idle_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+10 minutes')"),
                'absolute_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+20 minutes')"),
            ];
            $values[$boundary] = DB::raw('CURRENT_TIMESTAMP');
            if ($boundary === 'absolute_expires_at') {
                $values['idle_expires_at'] = DB::raw('CURRENT_TIMESTAMP');
            }
            DB::table('checkout_sessions')->where('id', $fixture['session'])->update($values);
            try {
                $this->hydrate($fixture['selector']);
                $this->fail("{$boundary} boundary hydrated.");
            } catch (InvalidCheckoutSession) {
                $this->assertDatabaseHas('checkout_sessions', [
                    'id' => $fixture['session'], 'status' => 'EXPIRED', 'active_marker' => null,
                    'revocation_reason' => null, 'revoked_at' => null,
                ]);
            }
        }
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'checkout_session.expired')->count());
    }

    public function test_persisted_scope_revocation_terminalizes_session_without_oracle(): void
    {
        $cases = [
            'client' => fn (array $f) => DB::table('integration_clients')->where('id', $f['client'])->update(['enabled' => false]),
            'source' => fn (array $f) => DB::table('integration_sources')->where('id', $f['source'])->update(['status' => 'REVOKED']),
            'attempt' => fn (array $f) => DB::table('assessment_participants')->where('id', $f['attempt'])->update([
                'assessment_status' => 'REVOKED', 'revoked_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]),
            'participant' => fn (array $f) => DB::table('participants')->where('id', $f['participant'])->update([
                'deleted_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]),
        ];
        foreach ($cases as $label => $revoke) {
            $fixture = $this->established();
            $revoke($fixture);
            try {
                $this->hydrate($fixture['selector']);
                $this->fail("{$label} revocation hydrated.");
            } catch (InvalidCheckoutSession $exception) {
                $this->assertSame('CHECKOUT_SESSION_INVALID', $exception->getMessage());
                $this->assertDatabaseHas('checkout_sessions', [
                    'id' => $fixture['session'], 'status' => 'REVOKED',
                    'revocation_reason' => 'SCOPE_REVOKED', 'active_marker' => null,
                ]);
            }
        }
    }

    public function test_corrupt_handoff_history_fails_closed_without_touch_or_audit(): void
    {
        $fixture = $this->established();
        $session = CheckoutSession::query()->findOrFail($fixture['session']);
        $before = $session->getAttributes();
        DB::table('checkout_handoffs')->where('id', $session->checkout_handoff_id)->update(['issue_number' => 2]);

        try {
            $this->hydrate($fixture['selector']);
            $this->fail('Corrupt history hydrated.');
        } catch (InvalidCheckoutSession $exception) {
            $this->assertSame('CHECKOUT_SESSION_INVALID', $exception->getMessage());
        }
        $this->assertSame($before, CheckoutSession::query()->findOrFail($fixture['session'])->getAttributes());
        $this->assertSame(0, DB::table('audit_logs')->whereIn('action', [
            'checkout_session.expired', 'checkout_session.revoked',
        ])->where('subject_id', (string) $fixture['attempt'])->count());
    }

    public function test_shared_handoff_validator_rejects_reason_and_temporal_corruption(): void
    {
        $attempt = (new AssessmentParticipant)->forceFill([
            'id' => 41, 'organization_id' => 42, 'participant_id' => 43,
            'package_id' => 44, 'integration_client_id' => 45, 'source_system' => 'VALIDATOR_SOURCE',
        ]);
        $source = (new IntegrationSource)->forceFill([
            'id' => 46, 'integration_client_id' => 45, 'source_system' => 'VALIDATOR_SOURCE',
        ]);
        $validator = app(CheckoutHandoffHistoryValidator::class);
        $this->assertTrue($validator->valid(new Collection([$this->handoffModel()]), $attempt, $source));

        $issued = CarbonImmutable::parse('2026-09-02T01:00:00Z');
        $expires = $issued->addMinutes(10);
        $cases = [
            'issued reason' => ['status' => 'ISSUED', 'active_marker' => true,
                'consumed_at' => null, 'revocation_reason' => 'REISSUED'],
            'consumed reason' => ['revocation_reason' => 'REISSUED'],
            'consumed before issue' => ['consumed_at' => $issued->subSecond()],
            'consumed at expiry' => ['consumed_at' => $expires],
            'revoked reason' => ['status' => 'REVOKED', 'consumed_at' => null,
                'revoked_at' => $issued, 'revocation_reason' => 'UNKNOWN'],
            'revoked before issue' => ['status' => 'REVOKED', 'consumed_at' => null,
                'revoked_at' => $issued->subSecond(), 'revocation_reason' => 'REISSUED'],
            'expired reason' => ['status' => 'EXPIRED', 'consumed_at' => null,
                'expired_at' => $expires, 'revocation_reason' => 'REISSUED'],
            'expired before expiry' => ['status' => 'EXPIRED', 'consumed_at' => null,
                'expired_at' => $expires->subSecond()],
        ];
        foreach ($cases as $label => $overrides) {
            $this->assertFalse($validator->valid(
                new Collection([$this->handoffModel($overrides)]), $attempt, $source,
            ), $label);
        }
    }

    public function test_logout_audit_failure_rolls_back_terminal_transition(): void
    {
        $fixture = $this->established();
        DB::unprepared("CREATE TRIGGER checkout_session_logout_audit_failure BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'checkout_session.revoked'
            BEGIN SELECT RAISE(ABORT, 'synthetic checkout logout audit failure'); END");
        try {
            $this->logout($fixture['selector'], $fixture['csrf']);
            $this->fail('Audit failure logged out.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic checkout logout audit failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER checkout_session_logout_audit_failure');
        }
        $this->assertDatabaseHas('checkout_sessions', [
            'id' => $fixture['session'], 'status' => 'ACTIVE', 'active_marker' => true,
        ]);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout_session.revoked')
            ->where('subject_id', (string) $fixture['attempt'])->count());
    }

    public function test_config_and_ambient_context_fail_before_touch(): void
    {
        $fixture = $this->established();
        $before = CheckoutSession::query()->findOrFail($fixture['session'])->getAttributes();
        config()->set('assessment_integration.checkout_session.enabled', false);
        foreach ([null, new RlsContext('service'), new RlsContext('participant', $fixture['organization'], $fixture['participant'])] as $context) {
            try {
                if ($context === null) {
                    DB::transaction(fn () => $this->hydrate($fixture['selector']));
                } else {
                    app(RlsContextRunner::class)->run($context, fn () => $this->hydrate($fixture['selector']));
                }
                $this->fail('Ambient/config invalid hydrated.');
            } catch (LogicException) {
                $this->assertSame($before, CheckoutSession::query()->findOrFail($fixture['session'])->getAttributes());
            }
        }
    }

    public function test_profile_read_accepts_only_credentials_and_returns_current_own_allowlisted_facts(): void
    {
        $fixture = $this->established();
        $foreign = $this->established();
        $stale = $this->hydrate($fixture['selector']);
        DB::table('participants')->where('id', $foreign['participant'])->update(['full_name' => 'FOREIGN_SENTINEL']);
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => 'Current Synthetic']);
        $before = $this->profileBusinessSnapshot();

        $method = new ReflectionMethod(CheckoutSessionLifecycle::class, 'readProfile');
        $this->assertCount(1, $method->getParameters());
        $this->assertSame(CheckoutSessionMutationCredentials::class, (string) $method->getParameters()[0]->getType());
        $this->assertSame(CheckoutProfile::class, (string) $method->getReturnType());
        $this->assertCount(1, $method->getParameters()[0]->getAttributes(SensitiveParameter::class));
        $profile = $this->readProfile($fixture['selector'], $fixture['csrf']);
        $this->assertSame('Current Synthetic', $profile->fullName);
        $this->assertNull($profile->birthDate);
        $this->assertNull($profile->intendedField);
        $this->assertSame(['profile'], array_keys($profile->toArray()));
        $this->assertSame(['fullName', 'birthDate', 'gender', 'educationLevel', 'intendedField', 'email', 'phone'],
            array_column($profile->toArray()['profile'], 'key'));
        foreach ($profile->toArray()['profile'] as $row) {
            $this->assertSame($row['state'] === 'locked'
                ? ['key', 'label', 'state', 'required', 'displayValue']
                : ['key', 'label', 'state', 'required'], array_keys($row));
        }
        $json = json_encode($profile, JSON_THROW_ON_ERROR);
        foreach (['FOREIGN_SENTINEL', $fixture['selector'], $fixture['csrf'], $stale->assessmentAttemptId,
            'synthetic-only', 'checkout_initial_funding_mode'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertSame($before, $this->profileBusinessSnapshot());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_profile_read_rejects_malformed_missing_and_foreign_credential_pairs_without_touch(): void
    {
        $fixture = $this->established();
        $foreign = $this->established();
        $before = DB::table('checkout_sessions')->orderBy('id')->get()->toJson();
        foreach ([['', $fixture['csrf']], ['ocs1_'.str_repeat('g', 64), $fixture['csrf']],
            ['ocs1_'.str_repeat('0', 64), $fixture['csrf']], [$fixture['selector'], ''],
            [$fixture['selector'], 'ocsrf1_'.str_repeat('0', 64)],
            [$fixture['selector'], $foreign['csrf']], [$foreign['selector'], $fixture['csrf']]] as [$selector, $csrf]) {
            $this->assertProfileRejected($selector, $csrf);
        }
        $this->assertSame($before, DB::table('checkout_sessions')->orderBy('id')->get()->toJson());
    }

    public function test_profile_read_revalidates_recovery_generation_and_logout_despite_old_principal(): void
    {
        $fixture = $this->established();
        $this->hydrate($fixture['selector']);
        $issued = app(RlsContextRunner::class)->runAsService(fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::query()->findOrFail($fixture['client']),
                $fixture['attemptPublicId'], IntegrationSource::query()->findOrFail($fixture['source'])->source_system,
                'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Recovery)));
        $this->assertProfileRejected($fixture['selector'], $fixture['csrf']);
        $raw = $issued->rawToken();
        $this->assertIsString($raw);
        $fresh = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($raw));
        $this->assertSame('Synthetic Person', $this->readProfile($fresh->rawSelector(), $fresh->rawCsrfToken())->fullName);
        $this->assertProfileRejected($fixture['selector'], $fixture['csrf']);
        $this->logout($fresh->rawSelector(), $fresh->rawCsrfToken());
        $this->assertProfileRejected($fresh->rawSelector(), $fresh->rawCsrfToken());
    }

    public function test_profile_read_reloads_revocations_and_rejects_corrupt_history(): void
    {
        foreach (['client', 'source', 'attempt', 'participant', 'history'] as $case) {
            $fixture = $this->established();
            $this->hydrate($fixture['selector']);
            match ($case) {
                'client' => DB::table('integration_clients')->where('id', $fixture['client'])->update(['enabled' => false]),
                'source' => DB::table('integration_sources')->where('id', $fixture['source'])->update(['allowed_assessment_packages' => '[]']),
                'attempt' => DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                    'assessment_status' => 'REVOKED', 'revoked_at' => DB::raw('CURRENT_TIMESTAMP')]),
                'participant' => DB::table('participants')->where('id', $fixture['participant'])->update(['deleted_at' => DB::raw('CURRENT_TIMESTAMP')]),
                'history' => DB::table('checkout_handoffs')->where('assessment_participant_id', $fixture['attempt'])->update(['issue_number' => 2]),
            };
            $before = $this->profileBusinessSnapshot();
            $this->assertProfileRejected($fixture['selector'], $fixture['csrf']);
            $this->assertSame($before, $this->profileBusinessSnapshot());
            $this->assertDatabaseHas('checkout_sessions', ['id' => $fixture['session'],
                'status' => $case === 'history' ? 'ACTIVE' : 'REVOKED']);
        }
    }

    public function test_profile_read_commits_expiry_without_projecting_or_duplicate_audit(): void
    {
        $fixture = $this->established();
        $this->ageProfileSession($fixture['session']);
        DB::table('checkout_sessions')->where('id', $fixture['session'])->update(['idle_expires_at' => DB::raw('CURRENT_TIMESTAMP')]);
        $before = $this->profileBusinessSnapshot();
        $this->assertProfileRejected($fixture['selector'], $fixture['csrf']);
        $this->assertProfileRejected($fixture['selector'], $fixture['csrf']);
        $this->assertDatabaseHas('checkout_sessions', ['id' => $fixture['session'], 'status' => 'EXPIRED']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout_session.expired')->count());
        $this->assertSame($before, $this->profileBusinessSnapshot());
    }

    public function test_profile_read_rejects_ambient_roles_transactions_and_disabled_config(): void
    {
        $fixture = $this->established();
        $before = DB::table('checkout_sessions')->get()->toJson();
        foreach ([null, new RlsContext('service'), new RlsContext('branch_admin', $fixture['organization']),
            new RlsContext('super_admin'), new RlsContext('staff', $fixture['organization']), new RlsContext('psychologist'),
            new RlsContext('participant', $fixture['organization'], $fixture['participant'])] as $context) {
            try {
                $call = fn () => $this->readProfile($fixture['selector'], $fixture['csrf']);
                $context === null ? DB::transaction($call) : app(RlsContextRunner::class)->run($context, $call);
                $this->fail('Ambient profile read succeeded.');
            } catch (LogicException $exception) {
                $this->assertSame('Checkout session lifecycle owns its service transaction.', $exception->getMessage());
            }
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame(0, DB::transactionLevel());
        }
        config()->set('assessment_integration.checkout_session.enabled', false);
        try {
            $this->readProfile($fixture['selector'], $fixture['csrf']);
            $this->fail('Disabled profile read succeeded.');
        } catch (LogicException $exception) {
            $this->assertSame('Checkout session lifecycle is unavailable.', $exception->getMessage());
        }
        $this->assertSame($before, DB::table('checkout_sessions')->get()->toJson());
    }

    public function test_profile_mapper_exception_rolls_back_idle_touch_and_restores_context(): void
    {
        $fixture = $this->established();
        $this->ageProfileSession($fixture['session']);
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => '']);
        $before = DB::table('checkout_sessions')->get()->toJson();
        $business = $this->profileBusinessSnapshot();
        $audits = DB::table('audit_logs')->get()->toJson();
        try {
            $this->readProfile($fixture['selector'], $fixture['csrf']);
            $this->fail('Invalid persisted profile projected.');
        } catch (DomainException $exception) {
            $this->assertSame('CHECKOUT_PROFILE_UNAVAILABLE', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame($before, DB::table('checkout_sessions')->get()->toJson());
        $this->assertSame($business, $this->profileBusinessSnapshot());
        $this->assertSame($audits, DB::table('audit_logs')->get()->toJson());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_profile_read_uses_database_calendar_and_preserves_idle_lifecycle(): void
    {
        $fixture = $this->established();
        $this->ageProfileSession($fixture['session']);
        DB::table('participants')->where('id', $fixture['participant'])->update(['birth_date' => DB::raw("date(CURRENT_TIMESTAMP, '-1 day')")]);
        $before = CheckoutSession::query()->findOrFail($fixture['session']);
        Date::setTestNow('1970-01-01');
        try {
            $this->assertNotNull($this->readProfile($fixture['selector'], $fixture['csrf'])->birthDate);
            $after = CheckoutSession::query()->findOrFail($fixture['session']);
            $this->assertTrue($after->last_seen_at->greaterThan($before->last_seen_at));
            $this->assertTrue($after->idle_expires_at->equalTo($after->absolute_expires_at));
            DB::table('participants')->where('id', $fixture['participant'])->update(['birth_date' => DB::raw('CURRENT_DATE')]);
            Date::setTestNow('2099-01-01');
            try {
                $this->readProfile($fixture['selector'], $fixture['csrf']);
                $this->fail('Future application clock authorized today as a birth date.');
            } catch (DomainException $exception) {
                $this->assertSame('CHECKOUT_PROFILE_UNAVAILABLE', $exception->getMessage());
            }
        } finally {
            Date::setTestNow();
        }
    }

    public function test_payment_read_is_credential_only_and_has_no_catalog_price_or_business_mutation(): void
    {
        $fixture = $this->established();
        $method = new ReflectionMethod(CheckoutSessionLifecycle::class, 'readPayment');
        $this->assertCount(1, $method->getParameters());
        $this->assertSame(CheckoutSessionMutationCredentials::class, (string) $method->getParameters()[0]->getType());
        $this->assertSame(CheckoutPaymentFacts::class, (string) $method->getReturnType());
        DB::table('branches')->where('id', $fixture['organization'])->update(['allowed_payer_types' => '[]']);
        $before = $this->profileBusinessSnapshot();
        $facts = app(CheckoutSessionLifecycle::class)->readPayment(new CheckoutSessionMutationCredentials($fixture['selector'], $fixture['csrf']));
        $this->assertSame('unpaid', $facts->state);
        $this->assertNull($facts->amountIdr);
        $this->assertNull($facts->consultationRequested);
        $this->assertFalse($facts->actionAvailable);
        $this->assertSame($before, $this->profileBusinessSnapshot());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_payment_read_revalidates_credentials_scope_and_recovery(): void
    {
        $fixture = $this->established();
        $other = $this->established();
        foreach (['', $other['csrf']] as $csrf) {
            try {
                app(CheckoutSessionLifecycle::class)->readPayment(new CheckoutSessionMutationCredentials($fixture['selector'], $csrf));
                $this->fail('Wrong payment credential pair accepted.');
            } catch (InvalidCheckoutSession $exception) {
                $this->assertSame('CHECKOUT_SESSION_INVALID', $exception->getMessage());
            }
        }
        $this->hydrate($fixture['selector']);
        app(RlsContextRunner::class)->runAsService(fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::findOrFail($fixture['client']),
                $fixture['attemptPublicId'], IntegrationSource::findOrFail($fixture['source'])->source_system,
                'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Recovery)));
        $this->expectException(InvalidCheckoutSession::class);
        app(CheckoutSessionLifecycle::class)->readPayment(new CheckoutSessionMutationCredentials($fixture['selector'], $fixture['csrf']));
    }

    public function test_payment_reader_failure_rolls_back_idle_and_restores_context(): void
    {
        $fixture = $this->established();
        $this->ageProfileSession($fixture['session']);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'metadata' => '{"checkout_contract_version":"checkout-v2"}',
        ]);
        $before = DB::table('checkout_sessions')->get()->toJson();
        try {
            app(CheckoutSessionLifecycle::class)->readPayment(new CheckoutSessionMutationCredentials($fixture['selector'], $fixture['csrf']));
            $this->fail('Missing initial snapshot projected.');
        } catch (DomainException $exception) {
            $this->assertSame('CHECKOUT_PAYMENT_UNAVAILABLE', $exception->getMessage());
        }
        $this->assertSame($before, DB::table('checkout_sessions')->get()->toJson());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_payment_read_rejects_ambient_context_and_transaction_without_elevation(): void
    {
        $fixture = $this->established();
        foreach ([null, new RlsContext('service'), new RlsContext('super_admin'),
            new RlsContext('participant', $fixture['organization'], $fixture['participant'])] as $context) {
            try {
                $call = fn () => app(CheckoutSessionLifecycle::class)->readPayment(new CheckoutSessionMutationCredentials($fixture['selector'], $fixture['csrf']));
                $context === null ? DB::transaction($call) : app(RlsContextRunner::class)->run($context, $call);
                $this->fail('Ambient payment read accepted.');
            } catch (LogicException $exception) {
                $this->assertSame('Checkout session lifecycle owns its service transaction.', $exception->getMessage());
            }
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    private function readProfile(string $selector, string $csrf): CheckoutProfile
    {
        return app(CheckoutSessionLifecycle::class)->readProfile(new CheckoutSessionMutationCredentials($selector, $csrf));
    }

    private function assertProfileRejected(string $selector, string $csrf): void
    {
        try {
            $this->readProfile($selector, $csrf);
            $this->fail('Invalid credentials/graph projected a profile.');
        } catch (InvalidCheckoutSession $exception) {
            $this->assertSame('CHECKOUT_SESSION_INVALID', $exception->getMessage());
        }
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    /** @return array<string, string> */
    private function profileBusinessSnapshot(): array
    {
        $snapshot = [];
        foreach (['participants', 'assessment_participants', 'assessment_bills', 'assessment_bill_items',
            'assessment_charges', 'assessment_entitlements', 'outbox_messages', 'consent_records'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    private function ageProfileSession(int $session): void
    {
        $handoff = DB::table('checkout_sessions')->where('id', $session)->value('checkout_handoff_id');
        DB::table('checkout_handoffs')->where('id', $handoff)->update([
            'issued_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-2 minutes')"),
            'consumed_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-1 minute')"),
            'expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+8 minutes')"),
        ]);
        DB::table('checkout_sessions')->where('id', $session)->update([
            'established_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-1 minute')"),
            'last_seen_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-1 minute')"),
            'idle_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+5 minutes')"),
            'absolute_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+10 minutes')"),
        ]);
    }

    private function configure(): void
    {
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function handoffModel(array $overrides = []): CheckoutHandoff
    {
        $issued = CarbonImmutable::parse('2026-09-02T01:00:00Z');

        return (new CheckoutHandoff)->forceFill(array_replace([
            'id' => 47, 'public_id' => (string) Str::ulid(),
            'assessment_participant_id' => 41, 'organization_id' => 42,
            'participant_id' => 43, 'package_id' => 44, 'integration_client_id' => 45,
            'integration_source_id' => 46, 'source_system' => 'VALIDATOR_SOURCE',
            'contract_version' => 'checkout-v2', 'purpose' => 'checkout-handoff',
            'destination' => 'integrated-checkout-session', 'issue_number' => 1,
            'status' => 'CONSUMED', 'active_marker' => null,
            'issued_at' => $issued, 'expires_at' => $issued->addMinutes(10),
            'consumed_at' => $issued->addMinute(), 'revoked_at' => null,
            'expired_at' => null, 'revocation_reason' => null,
        ], $overrides));
    }

    private function hydrate(string $selector): CheckoutSessionPrincipal
    {
        return app(CheckoutSessionLifecycle::class)->hydrate(new CheckoutSessionSelector($selector));
    }

    private function logout(string $selector, string $csrf): void
    {
        app(CheckoutSessionLifecycle::class)->logout(new CheckoutSessionMutationCredentials($selector, $csrf));
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,session:int,selector:string,csrf:string} */
    private function established(): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'SESSION_LIFECYCLE_'.$key;
        $packageCode = 'L'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => 'Synthetic Person', 'phone' => '620000000000',
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
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptPublicId = (string) Str::ulid();
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY'], JSON_THROW_ON_ERROR),
        ]);
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::query()->findOrFail($client),
                $attemptPublicId, $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $raw = $issued->rawToken();
        if (! is_string($raw)) {
            $this->fail('Synthetic handoff bearer unavailable.');
        }
        $result = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($raw));
        $session = CheckoutSession::query()->where('public_id', $result->sessionPublicId)->value('id');
        if (! is_int($session)) {
            $this->fail('Synthetic checkout session unavailable.');
        }
        $selector = $result->rawSelector();
        $csrf = $result->rawCsrfToken();

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt',
            'attemptPublicId', 'session', 'selector', 'csrf');
    }
}
