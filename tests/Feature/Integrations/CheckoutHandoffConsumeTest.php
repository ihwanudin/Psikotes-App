<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\ConsumeCheckoutHandoff;
use App\Actions\Integrations\InvalidCheckoutHandoff;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffConsumeInput;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionScope;
use App\Enums\CheckoutHandoffIntent;
use App\Models\AssessmentParticipant;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use ReflectionMethod;
use SensitiveParameter;
use Tests\OrganizationPaymentTestCase;

final class CheckoutHandoffConsumeTest extends OrganizationPaymentTestCase
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

    public function test_surface_accepts_only_a_private_sensitive_raw_bearer(): void
    {
        $constructor = new ReflectionMethod(CheckoutHandoffConsumeInput::class, '__construct');
        $this->assertSame(['rawToken'], array_map(
            static fn ($parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        ));
        $this->assertCount(1, $constructor->getParameters()[0]->getAttributes(SensitiveParameter::class));
        $this->assertSame(['input'], array_map(
            static fn ($parameter): string => $parameter->getName(),
            (new ReflectionMethod(ConsumeCheckoutHandoff::class, 'execute'))->getParameters(),
        ));
        $raw = 'och1_'.str_repeat('a', 64);
        $this->assertSame('{}', json_encode(new CheckoutHandoffConsumeInput($raw), JSON_THROW_ON_ERROR));
    }

    public function test_happy_path_consumes_once_and_returns_safe_checkout_scope(): void
    {
        $fixture = $this->fixture();
        $raw = $this->issue($fixture);
        $unrelatedBefore = $this->unrelatedCounts();
        $scope = app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));

        $this->assertInstanceOf(CheckoutSessionScope::class, $scope);
        $this->assertSame($fixture['attempt']->id, $scope->assessmentParticipantId);
        $this->assertSame($fixture['attempt']->assessment_attempt_id, $scope->assessmentAttemptId);
        $this->assertSame($fixture['attempt']->organization_id, $scope->organizationId);
        $this->assertSame($fixture['attempt']->participant_id, $scope->participantId);
        $this->assertSame($fixture['attempt']->package_id, $scope->packageId);
        $this->assertStringNotContainsString($raw, json_encode($scope, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($raw, json_encode($scope->descriptor(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('checkout_handoffs', [
            'assessment_participant_id' => $fixture['attempt']->id,
            'status' => 'CONSUMED', 'active_marker' => null,
        ]);
        $audit = DB::table('audit_logs')->where('action', 'checkout_handoff.consumed')->sole();
        $context = (string) $audit->context;
        $this->assertStringNotContainsString($raw, $context);
        $this->assertStringNotContainsString(hash('sha256', $raw), $context);
        $contextKeys = array_keys(json_decode($context, true, flags: JSON_THROW_ON_ERROR));
        sort($contextKeys);
        $this->assertSame([
            'consumedAt', 'destination', 'integrationClientId', 'issueNumber', 'publicId',
            'purpose', 'sourceSystem', 'version',
        ], $contextKeys);
        $this->assertSame($unrelatedBefore, $this->unrelatedCounts());

        $this->expectException(InvalidCheckoutHandoff::class);
        app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));
    }

    public function test_unknown_and_malformed_tokens_share_one_generic_outcome_without_fallback(): void
    {
        $before = [
            'branches' => DB::table('branches')->count(),
            'participants' => DB::table('participants')->count(),
            'handoffs' => DB::table('checkout_handoffs')->count(),
            'audits' => DB::table('audit_logs')->count(),
        ];
        foreach (['', 'och1_'.str_repeat('a', 63), 'och1_'.str_repeat('A', 64),
            'och1_'.str_repeat('a', 65), 'assessment-start-token',
            'och1_'.str_repeat('0', 64)] as $raw) {
            try {
                app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));
                $this->fail('Invalid checkout handoff was consumed.');
            } catch (InvalidCheckoutHandoff $exception) {
                $this->assertSame('CHECKOUT_HANDOFF_INVALID', $exception->getMessage());
                if ($raw !== '') {
                    $this->assertStringNotContainsString($raw, $exception->getMessage());
                }
            }
        }
        $this->assertSame($before['branches'], DB::table('branches')->count());
        $this->assertSame($before['participants'], DB::table('participants')->count());
        $this->assertSame($before['handoffs'], DB::table('checkout_handoffs')->count());
        $this->assertSame($before['audits'], DB::table('audit_logs')->count());
    }

    public function test_expired_token_is_terminalized_using_database_time_and_remains_generic(): void
    {
        $fixture = $this->fixture();
        $raw = $this->issue($fixture);
        DB::table('checkout_handoffs')->where('token_digest', hash('sha256', $raw))->update([
            'issued_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinutes(10),
        ]);

        try {
            app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));
            $this->fail('Expired handoff was consumed.');
        } catch (InvalidCheckoutHandoff $exception) {
            $this->assertSame('CHECKOUT_HANDOFF_INVALID', $exception->getMessage());
        }

        $this->assertDatabaseHas('checkout_handoffs', [
            'token_digest' => hash('sha256', $raw),
            'status' => 'EXPIRED',
            'active_marker' => null,
        ]);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout_handoff.consumed')->count());
    }

    public function test_persisted_authority_is_reloaded_and_revocation_is_fail_closed(): void
    {
        foreach (['organization', 'client', 'source', 'package', 'attempt', 'participant'] as $revocation) {
            $fixture = $this->fixture();
            $raw = $this->issue($fixture);
            match ($revocation) {
                'organization' => DB::table('branches')->where('id', $fixture['attempt']->organization_id)
                    ->update(['is_active' => false, 'status' => 'INACTIVE']),
                'client' => DB::table('integration_clients')->where('id', $fixture['client']->id)
                    ->update(['enabled' => false]),
                'source' => DB::table('integration_sources')->where('integration_client_id', $fixture['client']->id)
                    ->update(['status' => 'REVOKED']),
                'package' => DB::table('packages')->where('id', $fixture['attempt']->package_id)
                    ->update(['is_active' => false]),
                'attempt' => DB::table('assessment_participants')->where('id', $fixture['attempt']->id)
                    ->update(['revoked_at' => now()]),
                'participant' => DB::table('participants')->where('id', $fixture['attempt']->participant_id)
                    ->update(['deleted_at' => now()]),
            };

            try {
                app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));
                $this->fail("Revoked {$revocation} authority consumed a handoff.");
            } catch (InvalidCheckoutHandoff $exception) {
                $this->assertSame('CHECKOUT_HANDOFF_INVALID', $exception->getMessage());
            }
            $this->assertDatabaseHas('checkout_handoffs', [
                'token_digest' => hash('sha256', $raw), 'status' => 'ISSUED', 'active_marker' => true,
            ]);
        }
    }

    public function test_non_canonical_historical_package_compositions_do_not_consume_or_repair_the_token(): void
    {
        foreach (['without dass21', 'dass21 only'] as $composition) {
            $fixture = $this->fixture();
            $raw = $this->issue($fixture);
            $items = DB::table('package_items')->where('package_id', $fixture['attempt']->package_id);
            if ($composition === 'without dass21') {
                $items->where('test_type', 'dass21')->delete();
                $expectedTypes = ['ist'];
            } else {
                $items->where('test_type', '!=', 'dass21')->delete();
                $expectedTypes = ['dass21'];
            }
            $before = DB::table('checkout_handoffs')->where('token_digest', hash('sha256', $raw))->sole();

            try {
                app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));
                $this->fail("{$composition} package consumed a handoff credential.");
            } catch (InvalidCheckoutHandoff $exception) {
                $this->assertSame('CHECKOUT_HANDOFF_INVALID', $exception->getMessage(), $composition);
            }

            $this->assertEquals($before, DB::table('checkout_handoffs')
                ->where('token_digest', hash('sha256', $raw))->sole(), $composition);
            $this->assertSame($expectedTypes, DB::table('package_items')
                ->where('package_id', $fixture['attempt']->package_id)
                ->orderBy('sort_order')->pluck('test_type')->all(), $composition);
            $this->assertSame(0, DB::table('checkout_sessions')
                ->where('assessment_participant_id', $fixture['attempt']->id)->count(), $composition);
            $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout_handoff.consumed')
                ->where('subject_id', (string) $fixture['attempt']->id)->count(), $composition);
        }
    }

    public function test_disabled_boundary_and_ambient_transactions_fail_before_consumption(): void
    {
        $fixture = $this->fixture();
        $raw = $this->issue($fixture);
        config()->set('assessment_integration.checkout_handoff.enabled', false);

        try {
            app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));
            $this->fail('Disabled handoff boundary consumed a token.');
        } catch (InvalidCheckoutHandoff $exception) {
            $this->assertSame('CHECKOUT_HANDOFF_INVALID', $exception->getMessage());
        }
        config()->set('assessment_integration.checkout_handoff.enabled', true);

        foreach ([null, '600', 59, 601] as $ttl) {
            config()->set('assessment_integration.checkout_handoff.ttl_seconds', $ttl);
            try {
                app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));
                $this->fail('Invalid handoff configuration consumed a token.');
            } catch (InvalidCheckoutHandoff $exception) {
                $this->assertSame('CHECKOUT_HANDOFF_INVALID', $exception->getMessage());
            }
        }
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);

        DB::beginTransaction();
        try {
            $this->expectException(LogicException::class);
            app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));
        } finally {
            DB::rollBack();
        }
    }

    public function test_audit_failure_rolls_back_consumption_atomically(): void
    {
        $fixture = $this->fixture();
        $raw = $this->issue($fixture);
        DB::unprepared("CREATE TRIGGER fail_checkout_consume_audit BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'checkout_handoff.consumed' BEGIN SELECT RAISE(ABORT, 'synthetic consume audit failure'); END");

        try {
            app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));
            $this->fail('Synthetic audit failure did not abort consumption.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('synthetic consume audit failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('checkout_handoffs', [
            'token_digest' => hash('sha256', $raw), 'status' => 'ISSUED', 'active_marker' => true,
            'consumed_at' => null,
        ]);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout_handoff.consumed')->count());
    }

    /** @param array{client:IntegrationClient,attempt:AssessmentParticipant,sourceSystem:string} $fixture */
    private function issue(array $fixture): string
    {
        $result = app(RlsContextRunner::class)->run(
            new RlsContext('service'),
            fn () => app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
                $fixture['client'], $fixture['attempt']->assessment_attempt_id, $fixture['sourceSystem'],
                'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue,
            )),
        );
        $raw = $result->rawToken();
        if ($raw === null) {
            $this->fail('Synthetic issuance did not return a raw token.');
        }

        return $raw;
    }

    /** @return array{client:IntegrationClient,attempt:AssessmentParticipant,sourceSystem:string} */
    private function fixture(): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'P13B_'.$key;
        $packageCode = 'P13B'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'P13b Synthetic', 'organization_code' => $key,
            'display_name' => 'P13b Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization, 'referral_source' => 'manual',
            'source_system' => $sourceSystem, 'full_name' => 'P13b Synthetic', 'phone' => '620000000000',
        ]);
        $clientId = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        DB::table('integration_sources')->insert([
            'integration_client_id' => $clientId, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'P13b Synthetic', 'amount' => 100,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptId = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $clientId,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_attempt_id' => (string) Str::ulid(), 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY'], JSON_THROW_ON_ERROR),
        ]);

        return [
            'client' => IntegrationClient::query()->findOrFail($clientId),
            'attempt' => AssessmentParticipant::query()->findOrFail($attemptId),
            'sourceSystem' => $sourceSystem,
        ];
    }

    /** @return array<string, int> */
    private function unrelatedCounts(): array
    {
        $counts = [];
        foreach (['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements',
            'orders', 'entitlements', 'outbox_messages', 'identity_evidence', 'identity_verifications', 'sessions'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
