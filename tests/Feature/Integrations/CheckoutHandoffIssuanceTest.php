<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\IdempotencyConflict;
use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutHandoffIssueResult;
use App\Enums\CheckoutHandoffIntent;
use App\Models\AssessmentParticipant;
use App\Models\CheckoutHandoff;
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
use Tests\Support\AssessmentBillingFixture;

final class CheckoutHandoffIssuanceTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
    }

    public function test_surface_has_no_caller_controlled_scope_or_purpose_parameters(): void
    {
        $constructor = new ReflectionMethod(CheckoutHandoffIssueInput::class, '__construct');
        $this->assertSame(
            ['authenticatedClient', 'assessmentAttemptId', 'sourceSystem', 'idempotencyKey', 'intent'],
            array_map(static fn ($parameter): string => $parameter->getName(), $constructor->getParameters()),
        );
        $this->assertSame(['input'], array_map(
            static fn ($parameter): string => $parameter->getName(),
            (new ReflectionMethod(IssueCheckoutHandoff::class, 'execute'))->getParameters(),
        ));
        $inputKey = $constructor->getParameters()[3];
        $resultToken = (new ReflectionMethod(CheckoutHandoffIssueResult::class, '__construct'))->getParameters()[5];
        $this->assertCount(1, $inputKey->getAttributes(SensitiveParameter::class));
        $this->assertCount(1, $resultToken->getAttributes(SensitiveParameter::class));
        $fixture = $this->fixture();
        $key = 'ih1_'.str_repeat('9', 32);
        $input = new CheckoutHandoffIssueInput(
            $fixture['client'], $fixture['attempt']->assessment_attempt_id,
            $fixture['sourceSystem'], $key, CheckoutHandoffIntent::Issue,
        );
        $this->assertStringNotContainsString($key, json_encode($input, JSON_THROW_ON_ERROR));
    }

    public function test_first_issue_persists_digest_only_and_safe_audit_then_exact_replay_is_noop(): void
    {
        $fixture = $this->fixture();
        $key = 'ih1_'.str_repeat('a', 32);
        $first = $this->issue($fixture, $key, CheckoutHandoffIntent::Issue);
        $raw = $first->rawToken();

        $this->assertFalse($first->replayed);
        $this->assertFalse($first->reissueRequired);
        $this->assertMatchesRegularExpression('/^och1_[0-9a-f]{64}$/D', $raw ?? '');
        $this->assertSame(1, $first->issueNumber);
        $this->assertDatabaseHas('checkout_handoffs', [
            'public_id' => $first->handoffPublicId,
            'token_digest' => hash('sha256', (string) $raw),
            'issue_idempotency_key_digest' => hash('sha256', $key),
            'purpose' => 'checkout-handoff',
            'destination' => 'integrated-checkout-session',
            'contract_version' => 'checkout-v2',
            'status' => 'ISSUED',
        ]);
        $this->assertStringNotContainsString(
            (string) $raw,
            json_encode(DB::table('checkout_handoffs')->first(), JSON_THROW_ON_ERROR),
        );
        $audit = DB::table('audit_logs')->where('action', 'checkout_handoff.issued')->sole();
        $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $raw, $encoded);
        $this->assertStringNotContainsString($key, $encoded);
        $context = json_decode($audit->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([
            'version', 'publicId', 'issueNumber', 'purpose', 'destination', 'sourceSystem',
            'issuedAt', 'expiresAt', 'revokedPrevious',
        ], array_keys($context));
        $this->assertSame($fixture['attempt']->organization_id, $audit->branch_id);
        $this->assertSame(AssessmentParticipant::class, $audit->subject_type);
        $this->assertSame((string) $fixture['attempt']->id, $audit->subject_id);
        $this->assertStringNotContainsString((string) $raw, json_encode($first->descriptor(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString((string) $raw, json_encode($first, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('token_digest', CheckoutHandoff::query()->firstOrFail()->toArray());

        $replay = $this->issue($fixture, $key, CheckoutHandoffIntent::Issue);
        $this->assertTrue($replay->replayed);
        $this->assertTrue($replay->reissueRequired);
        $this->assertNull($replay->rawToken());
        $this->assertSame($first->handoffPublicId, $replay->handoffPublicId);
        $this->assertDatabaseCount('checkout_handoffs', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout_handoff.issued')->count());
    }

    public function test_explicit_reissue_revokes_the_active_generation_and_returns_a_new_raw_token_once(): void
    {
        $fixture = $this->fixture();
        $first = $this->issue($fixture, 'ih1_'.str_repeat('b', 32), CheckoutHandoffIntent::Issue);
        $second = $this->issue($fixture, 'ih1_'.str_repeat('c', 32), CheckoutHandoffIntent::Reissue);

        $this->assertNotSame($first->rawToken(), $second->rawToken());
        $this->assertSame(2, $second->issueNumber);
        $this->assertDatabaseHas('checkout_handoffs', [
            'public_id' => $first->handoffPublicId, 'status' => 'REVOKED',
            'active_marker' => null, 'revocation_reason' => 'REISSUED',
        ]);
        $this->assertDatabaseHas('checkout_handoffs', [
            'public_id' => $second->handoffPublicId, 'status' => 'ISSUED', 'active_marker' => true,
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout_handoff.reissued')->count());
        $context = json_decode(
            DB::table('audit_logs')->where('action', 'checkout_handoff.reissued')->value('context'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertTrue($context['revokedPrevious']);
    }

    public function test_actor_and_authenticated_client_guards_fail_closed(): void
    {
        $fixture = $this->fixture();
        $input = new CheckoutHandoffIssueInput(
            $fixture['client'], $fixture['attempt']->assessment_attempt_id, $fixture['sourceSystem'],
            'ih1_'.str_repeat('d', 32), CheckoutHandoffIntent::Issue,
        );
        try {
            app(IssueCheckoutHandoff::class)->execute($input);
            $this->fail('No-context caller issued a handoff.');
        } catch (LogicException $exception) {
            $this->assertSame('Checkout handoff issuance requires service authority.', $exception->getMessage());
        }
        foreach (['participant', 'branch_admin', 'staff', 'psychologist', 'super_admin'] as $role) {
            $context = new RlsContext(
                $role,
                in_array($role, ['participant', 'branch_admin', 'staff'], true)
                    ? $fixture['attempt']->organization_id : null,
                $role === 'participant' ? $fixture['attempt']->participant_id : null,
            );
            try {
                app(RlsContextRunner::class)->run(
                    $context,
                    fn () => app(IssueCheckoutHandoff::class)->execute($input),
                );
                $this->fail("{$role} context issued a handoff.");
            } catch (LogicException) {
                $this->assertDatabaseCount('checkout_handoffs', 0);
            }
        }

        $unpersisted = new IntegrationClient([
            'organization_id' => $fixture['client']->organization_id,
            'client_id' => 'unpersisted', 'credential_reference' => 'not-authority', 'enabled' => true,
        ]);
        try {
            $this->issue([...$fixture, 'client' => $unpersisted], 'ih1_'.str_repeat('e', 32), CheckoutHandoffIntent::Issue);
            $this->fail('Unpersisted client issued a handoff.');
        } catch (IntegrationContractViolation $exception) {
            $this->assertSame('HANDOFF_REQUEST_INVALID', $exception->errorCode);
        }
        $this->assertDatabaseCount('checkout_handoffs', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_deleted_resources_and_invalid_selectors_are_denied_without_side_effects(): void
    {
        foreach (['source', 'attempt', 'client'] as $deleted) {
            $fixture = $this->fixture();
            if ($deleted === 'source') {
                DB::table('integration_sources')->where('integration_client_id', $fixture['client']->id)->delete();
            } elseif ($deleted === 'attempt') {
                DB::table('assessment_participants')->where('id', $fixture['attempt']->id)->delete();
            } else {
                DB::table('assessment_participants')->where('id', $fixture['attempt']->id)->delete();
                DB::table('integration_sources')->where('integration_client_id', $fixture['client']->id)->delete();
                DB::table('integration_clients')->where('id', $fixture['client']->id)->delete();
            }
            try {
                $this->issue($fixture, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue);
                $this->fail("Deleted {$deleted} issued a handoff.");
            } catch (IntegrationContractViolation $exception) {
                $this->assertSame('HANDOFF_NOT_ALLOWED', $exception->errorCode);
            }
        }

        $fixture = $this->fixture();
        foreach ([['not-a-ulid', $fixture['sourceSystem']],
            [$fixture['attempt']->assessment_attempt_id, ' FOREIGN_SOURCE'],
            [$fixture['attempt']->assessment_attempt_id, str_repeat('S', 101)]] as [$attemptId, $sourceSystem]) {
            $input = new CheckoutHandoffIssueInput(
                $fixture['client'], $attemptId, $sourceSystem,
                'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue,
            );
            try {
                app(RlsContextRunner::class)->run(
                    new RlsContext('service'),
                    fn () => app(IssueCheckoutHandoff::class)->execute($input),
                );
                $this->fail('Invalid selector issued a handoff.');
            } catch (IntegrationContractViolation $exception) {
                $this->assertSame('HANDOFF_REQUEST_INVALID', $exception->errorCode);
            }
        }
        $this->assertDatabaseCount('checkout_handoffs', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_config_is_strict_and_ttl_boundaries_use_the_database_clock(): void
    {
        foreach ([false, 1, 'true', null] as $enabled) {
            config()->set('assessment_integration.checkout_handoff.enabled', $enabled);
            $fixture = $this->fixture();
            try {
                $this->issue($fixture, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue);
                $this->fail('Non-boolean enabled config issued a handoff.');
            } catch (IntegrationContractViolation $exception) {
                $this->assertSame('HANDOFF_DISABLED', $exception->errorCode);
            }
        }
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        foreach ([59, 601, '600', null] as $ttl) {
            config()->set('assessment_integration.checkout_handoff.ttl_seconds', $ttl);
            $fixture = $this->fixture();
            try {
                $this->issue($fixture, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue);
                $this->fail('Invalid TTL issued a handoff.');
            } catch (IntegrationContractViolation $exception) {
                $this->assertSame('HANDOFF_CONFIG_INVALID', $exception->errorCode);
            }
        }
        foreach ([60, 600] as $ttl) {
            config()->set('assessment_integration.checkout_handoff.ttl_seconds', $ttl);
            $fixture = $this->fixture();
            $result = $this->issue($fixture, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue);
            $row = CheckoutHandoff::query()->where('public_id', $result->handoffPublicId)->firstOrFail();
            $this->assertSame((float) $ttl, $row->issued_at->diffInSeconds($row->expires_at));
            $databaseNow = DB::selectOne('SELECT CURRENT_TIMESTAMP AS current_time');
            $this->assertLessThanOrEqual(2, abs($row->issued_at->diffInSeconds((string) $databaseNow->current_time, false)));
        }
    }

    public function test_malformed_and_pii_like_idempotency_keys_are_rejected_without_leakage(): void
    {
        $fixture = $this->fixture();
        foreach (['', 'ih1_'.str_repeat('a', 31), 'ih1_'.str_repeat('A', 32),
            'ih1_'.str_repeat('a', 33), 'person@example.test', '+628123456789', 'Jane Doe',
            ' ih1_'.str_repeat('a', 32), 'ih1_'.str_repeat('a', 31).'_'] as $key) {
            try {
                $this->issue($fixture, $key, CheckoutHandoffIntent::Issue);
                $this->fail('Malformed idempotency key was accepted.');
            } catch (IntegrationContractViolation $exception) {
                $this->assertSame('HANDOFF_REQUEST_INVALID', $exception->errorCode);
                if ($key !== '') {
                    $this->assertStringNotContainsString($key, $exception->getMessage());
                }
            }
        }
        $this->assertDatabaseCount('checkout_handoffs', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_authoritative_graph_is_reloaded_and_inactive_stale_or_cross_scope_state_is_denied(): void
    {
        $cases = [
            'organization status' => fn (array $f) => DB::table('branches')->where('id', $f['attempt']->organization_id)
                ->update(['status' => 'SUSPENDED']),
            'organization inactive' => fn (array $f) => DB::table('branches')->where('id', $f['attempt']->organization_id)
                ->update(['is_active' => false]),
            'client disabled' => fn (array $f) => DB::table('integration_clients')->where('id', $f['client']->id)
                ->update(['enabled' => false]),
            'client future' => fn (array $f) => DB::table('integration_clients')->where('id', $f['client']->id)
                ->update(['effective_from' => now()->addMinute()]),
            'client expired' => fn (array $f) => DB::table('integration_clients')->where('id', $f['client']->id)
                ->update(['effective_until' => now()->subMinute()]),
            'stale credential' => fn (array $f) => DB::table('integration_clients')->where('id', $f['client']->id)
                ->update(['credential_reference' => 'rotated']),
            'source suspended' => fn (array $f) => DB::table('integration_sources')
                ->where('integration_client_id', $f['client']->id)->update(['status' => 'SUSPENDED']),
            'source expired' => fn (array $f) => DB::table('integration_sources')
                ->where('integration_client_id', $f['client']->id)->update(['effective_until' => now()->subMinute()]),
            'source future' => fn (array $f) => DB::table('integration_sources')
                ->where('integration_client_id', $f['client']->id)->update(['effective_from' => now()->addMinute()]),
            'package not allowed' => fn (array $f) => DB::table('integration_sources')
                ->where('integration_client_id', $f['client']->id)->update(['allowed_assessment_packages' => '[]']),
            'package inactive' => fn (array $f) => DB::table('packages')->where('id', $f['attempt']->package_id)
                ->update(['is_active' => false]),
            'package amount missing' => fn (array $f) => DB::table('packages')->where('id', $f['attempt']->package_id)
                ->update(['amount' => null]),
            'package amount negative' => fn (array $f) => DB::table('packages')->where('id', $f['attempt']->package_id)
                ->update(['amount' => -1]),
            'package currency' => fn (array $f) => DB::table('packages')->where('id', $f['attempt']->package_id)
                ->update(['currency' => 'USD']),
            'package consultation negative' => fn (array $f) => DB::table('packages')->where('id', $f['attempt']->package_id)
                ->update(['consultation_amount' => -1]),
            'package empty' => fn (array $f) => DB::table('package_items')->where('package_id', $f['attempt']->package_id)
                ->delete(),
            'attempt revoked' => fn (array $f) => DB::table('assessment_participants')->where('id', $f['attempt']->id)
                ->update(['assessment_status' => 'REVOKED', 'revoked_at' => now()]),
            'attempt ready' => fn (array $f) => DB::table('assessment_participants')->where('id', $f['attempt']->id)
                ->update(['assessment_status' => 'READY']),
            'contract metadata' => fn (array $f) => DB::table('assessment_participants')->where('id', $f['attempt']->id)
                ->update(['metadata' => json_encode(['checkout_contract_version' => 'v1'], JSON_THROW_ON_ERROR)]),
            'participant deleted' => fn (array $f) => DB::table('participants')->where('id', $f['attempt']->participant_id)
                ->update(['deleted_at' => now()]),
        ];
        foreach ($cases as $label => $mutate) {
            $fixture = $this->fixture();
            $mutate($fixture);
            try {
                $this->issue($fixture, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue);
                $this->fail("{$label} state issued a handoff.");
            } catch (IntegrationContractViolation $exception) {
                $this->assertSame('HANDOFF_NOT_ALLOWED', $exception->errorCode, $label);
            }
        }

        $fixture = $this->fixture();
        $packageCode = DB::table('packages')->where('id', $fixture['attempt']->package_id)->value('code');
        DB::table('integration_sources')->insert([
            'integration_client_id' => $fixture['client']->id, 'source_system' => 'FOREIGN_SOURCE',
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        try {
            $this->issue([...$fixture, 'sourceSystem' => 'FOREIGN_SOURCE'],
                'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue);
            $this->fail('Foreign source issued against the attempt.');
        } catch (IntegrationContractViolation $exception) {
            $this->assertSame('HANDOFF_NOT_ALLOWED', $exception->errorCode);
        }
        $this->assertDatabaseCount('checkout_handoffs', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_non_canonical_historical_package_compositions_are_denied_without_repair_or_credential(): void
    {
        foreach (['without dass21', 'dass21 only'] as $composition) {
            $fixture = $this->fixture();
            $items = DB::table('package_items')->where('package_id', $fixture['attempt']->package_id);
            if ($composition === 'without dass21') {
                $items->where('test_type', 'dass21')->delete();
                $expectedTypes = ['ist'];
            } else {
                $items->where('test_type', '!=', 'dass21')->delete();
                $expectedTypes = ['dass21'];
            }

            try {
                $this->issue(
                    $fixture,
                    'ih1_'.bin2hex(random_bytes(16)),
                    CheckoutHandoffIntent::Issue,
                );
                $this->fail("{$composition} package issued a handoff credential.");
            } catch (IntegrationContractViolation $exception) {
                $this->assertSame('HANDOFF_NOT_ALLOWED', $exception->errorCode, $composition);
            }

            $this->assertSame($expectedTypes, DB::table('package_items')
                ->where('package_id', $fixture['attempt']->package_id)
                ->orderBy('sort_order')->pluck('test_type')->all(), $composition);
            $this->assertSame(0, DB::table('checkout_handoffs')
                ->where('assessment_participant_id', $fixture['attempt']->id)->count(), $composition);
            $this->assertSame(0, DB::table('audit_logs')
                ->where('subject_id', (string) $fixture['attempt']->id)->count(), $composition);
        }
    }

    public function test_same_key_with_different_intent_conflicts_and_issue_never_silently_revokes(): void
    {
        $fixture = $this->fixture();
        $key = 'ih1_'.str_repeat('f', 32);
        $first = $this->issue($fixture, $key, CheckoutHandoffIntent::Issue);
        try {
            $this->issue($fixture, $key, CheckoutHandoffIntent::Reissue);
            $this->fail('Same key with a different intent replayed.');
        } catch (IdempotencyConflict $exception) {
            $this->assertSame('', $exception->getMessage());
        }
        try {
            $this->issue($fixture, 'ih1_'.str_repeat('1', 32), CheckoutHandoffIntent::Issue);
            $this->fail('New ISSUE silently revoked an active handoff.');
        } catch (IntegrationContractViolation $exception) {
            $this->assertSame('HANDOFF_REISSUE_REQUIRED', $exception->errorCode);
        }
        $otherAttemptPublicId = (string) Str::ulid();
        $otherCase = AssessmentBillingFixture::createExactIntegratedCase(
            $fixture['attempt']->participant_id,
            $fixture['attempt']->organization_id,
            $fixture['attempt']->package_id,
            $otherAttemptPublicId,
        );
        $otherAttempt = AssessmentParticipant::query()->create([
            'integration_client_id' => $fixture['attempt']->integration_client_id,
            'organization_id' => $fixture['attempt']->organization_id,
            'participant_id' => $fixture['attempt']->participant_id,
            'package_id' => $fixture['attempt']->package_id,
            'assessment_case_id' => $otherCase,
            'assessment_attempt_id' => $otherAttemptPublicId,
            'source_system' => $fixture['attempt']->source_system,
            'external_candidate_id' => (string) Str::ulid(),
            'funding_mode' => $fixture['attempt']->funding_mode,
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => (string) Str::ulid(),
            'request_hash' => hash('sha256', 'other-request'),
            'logical_assessment_key' => hash('sha256', 'other-logical'),
            'metadata' => $fixture['attempt']->metadata,
        ]);
        try {
            $this->issue([...$fixture, 'attempt' => $otherAttempt], $key, CheckoutHandoffIntent::Issue);
            $this->fail('Same key authorized a different attempt scope.');
        } catch (IdempotencyConflict) {
            $this->assertDatabaseCount('checkout_handoffs', 1);
        }
        $this->assertDatabaseHas('checkout_handoffs', [
            'public_id' => $first->handoffPublicId, 'status' => 'ISSUED', 'active_marker' => true,
        ]);
        $this->assertDatabaseCount('checkout_handoffs', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_reissue_observes_expired_active_and_corrupt_history_fails_closed(): void
    {
        $fixture = $this->fixture();
        $first = $this->issue($fixture, 'ih1_'.str_repeat('2', 32), CheckoutHandoffIntent::Issue);
        DB::table('checkout_handoffs')->where('public_id', $first->handoffPublicId)->update([
            'issued_at' => now()->subMinutes(11), 'expires_at' => now()->subMinute(),
        ]);
        $second = $this->issue($fixture, 'ih1_'.str_repeat('3', 32), CheckoutHandoffIntent::Reissue);
        $this->assertDatabaseHas('checkout_handoffs', [
            'public_id' => $first->handoffPublicId, 'status' => 'EXPIRED', 'active_marker' => null,
            'revocation_reason' => null,
        ]);
        $this->assertSame(2, $second->issueNumber);
        $context = json_decode(
            DB::table('audit_logs')->where('action', 'checkout_handoff.reissued')->value('context'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertFalse($context['revokedPrevious']);

        DB::table('checkout_handoffs')->where('public_id', $second->handoffPublicId)->update([
            'status' => 'REVOKED', 'active_marker' => null, 'revoked_at' => null, 'revocation_reason' => null,
        ]);
        try {
            $this->issue($fixture, 'ih1_'.str_repeat('4', 32), CheckoutHandoffIntent::Reissue);
            $this->fail('Corrupt terminal history permitted reissue.');
        } catch (IntegrationContractViolation $exception) {
            $this->assertSame('HANDOFF_STATE_INVALID', $exception->errorCode);
        }
        $this->assertDatabaseCount('checkout_handoffs', 2);
        $this->assertSame(2, DB::table('audit_logs')->count());
    }

    public function test_insert_or_audit_failure_rolls_back_prior_mutation_and_never_returns_raw_token(): void
    {
        $fixture = $this->fixture();
        $first = $this->issue($fixture, 'ih1_'.str_repeat('5', 32), CheckoutHandoffIntent::Issue);
        DB::unprepared("CREATE TRIGGER checkout_handoff_insert_failure BEFORE INSERT ON checkout_handoffs
            WHEN NEW.issue_number = 2 BEGIN SELECT RAISE(ABORT, 'synthetic handoff insert failure'); END");
        try {
            $this->issue($fixture, 'ih1_'.str_repeat('6', 32), CheckoutHandoffIntent::Reissue);
            $this->fail('Injected insert failure returned a result.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic handoff insert failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('checkout_handoffs', [
            'public_id' => $first->handoffPublicId, 'status' => 'ISSUED', 'active_marker' => true,
        ]);
        $this->assertDatabaseCount('checkout_handoffs', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        DB::unprepared('DROP TRIGGER checkout_handoff_insert_failure');

        $oldActive = CheckoutHandoff::query()->where('public_id', $first->handoffPublicId)
            ->firstOrFail()->getAttributes();
        DB::unprepared("CREATE TRIGGER checkout_handoff_reissue_audit_failure BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'checkout_handoff.reissued' BEGIN SELECT RAISE(ABORT, 'synthetic reissue audit failure'); END");
        try {
            $this->issue($fixture, 'ih1_'.str_repeat('a', 32), CheckoutHandoffIntent::Reissue);
            $this->fail('Injected reissue audit failure returned a result.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic reissue audit failure', $exception->getMessage());
        }
        $this->assertSame($oldActive, CheckoutHandoff::query()->where('public_id', $first->handoffPublicId)
            ->firstOrFail()->getAttributes());
        $this->assertDatabaseCount('checkout_handoffs', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        DB::unprepared('DROP TRIGGER checkout_handoff_reissue_audit_failure');

        $fresh = $this->fixture();
        DB::unprepared("CREATE TRIGGER checkout_handoff_audit_failure BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'checkout_handoff.issued' BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END");
        try {
            $this->issue($fresh, 'ih1_'.str_repeat('7', 32), CheckoutHandoffIntent::Issue);
            $this->fail('Injected audit failure returned a result.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('checkout_handoffs')
            ->where('assessment_participant_id', $fresh['attempt']->id)->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('subject_id', (string) $fresh['attempt']->id)->count());
    }

    public function test_application_clock_is_not_issuance_authority(): void
    {
        $fixture = $this->fixture();
        CarbonImmutable::setTestNow('2040-01-01T00:00:00+00:00');
        try {
            $result = $this->issue($fixture, 'ih1_'.str_repeat('b', 32), CheckoutHandoffIntent::Issue);
        } finally {
            CarbonImmutable::setTestNow();
        }
        $row = CheckoutHandoff::query()->where('public_id', $result->handoffPublicId)->firstOrFail();
        $databaseNow = DB::selectOne('SELECT CURRENT_TIMESTAMP AS current_time');
        $this->assertLessThanOrEqual(2, abs($row->issued_at->diffInSeconds((string) $databaseNow->current_time, false)));
        $this->assertNotSame('2040', $row->issued_at->format('Y'));
    }

    public function test_issuance_has_no_billing_access_identity_session_or_outbox_side_effects(): void
    {
        $fixture = $this->fixture();
        $tables = ['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements',
            'orders', 'entitlements', 'outbox_messages', 'identity_evidence', 'identity_verifications', 'sessions'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }
        $attempt = AssessmentParticipant::query()->findOrFail($fixture['attempt']->id)->getAttributes();
        $participant = DB::table('participants')->where('id', $fixture['attempt']->participant_id)->first();

        $this->issue($fixture, 'ih1_'.str_repeat('8', 32), CheckoutHandoffIntent::Issue);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
        $this->assertSame($attempt, AssessmentParticipant::query()->findOrFail($fixture['attempt']->id)->getAttributes());
        $this->assertEquals($participant, DB::table('participants')->where('id', $fixture['attempt']->participant_id)->first());
    }

    /** @param array{client:IntegrationClient,attempt:AssessmentParticipant,sourceSystem:string} $fixture */
    private function issue(array $fixture, string $key, CheckoutHandoffIntent $intent): CheckoutHandoffIssueResult
    {
        return app(RlsContextRunner::class)->run(
            new RlsContext('service'),
            fn () => app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
                $fixture['client'], $fixture['attempt']->assessment_attempt_id,
                $fixture['sourceSystem'], $key, $intent,
            )),
        );
    }

    /** @return array{client:IntegrationClient,attempt:AssessmentParticipant,sourceSystem:string} */
    private function fixture(): array
    {
        $key = (string) Str::ulid();
        $packageCode = 'H'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization, 'referral_source' => 'manual',
            'source_system' => 'HANDOFF_SOURCE', 'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
        $clientId = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        DB::table('integration_sources')->insert([
            'integration_client_id' => $clientId, 'source_system' => 'HANDOFF_SOURCE',
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic', 'amount' => 100, 'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptPublicId = (string) Str::ulid();
        $case = AssessmentBillingFixture::createExactIntegratedCase(
            $participant, $organization, $package, $attemptPublicId,
        );
        $attemptId = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $clientId,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_case_id' => $case, 'assessment_attempt_id' => $attemptPublicId, 'source_system' => 'HANDOFF_SOURCE',
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY',
            ], JSON_THROW_ON_ERROR),
        ]);

        return [
            'client' => IntegrationClient::query()->findOrFail($clientId),
            'attempt' => AssessmentParticipant::query()->findOrFail($attemptId),
            'sourceSystem' => 'HANDOFF_SOURCE',
        ];
    }
}
