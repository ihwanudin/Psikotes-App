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
use App\Data\Integrations\CheckoutSessionMutationScope;
use App\Enums\CheckoutHandoffIntent;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class CheckoutSessionMutationScopeTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = $this->artisan('migrate', ['--force' => true]);
        if (is_int($migration)) {
            $this->fail('Migration wrapper unavailable.');
        }
        $migration->assertExitCode(0);
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
        ]);
    }

    public function test_scope_is_available_only_inside_its_exact_service_transaction(): void
    {
        $fixture = $this->established();
        $credentials = $this->credentials($fixture);
        try {
            app(CheckoutSessionLifecycle::class)->lockMutation($credentials);
            $this->fail('Mutation scope was issued without a service transaction.');
        } catch (LogicException $exception) {
            $this->assertSame('Checkout session mutation requires an active service transaction.', $exception->getMessage());
        }

        $scope = app(RlsContextRunner::class)->runAsService(function () use ($credentials, $fixture): CheckoutSessionMutationScope {
            $this->assertSame('service', app(RlsContextRunner::class)->current()?->role);
            $this->assertSame(1, DB::transactionLevel());
            $scope = app(CheckoutSessionLifecycle::class)->lockMutation($credentials);
            $principal = $scope->principal();
            $this->assertSame($fixture['attempt'], $principal->assessmentParticipantId);
            $this->assertSame($fixture['attemptPublicId'], $principal->assessmentAttemptId);
            $this->assertSame('COMMERCIAL_SELF_PAY', $principal->fundingMode);

            return $scope;
        });
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
        try {
            $scope->principal();
            $this->fail('Committed mutation authority remained usable.');
        } catch (LogicException $exception) {
            $this->assertSame('Checkout session mutation scope is no longer active.', $exception->getMessage());
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    #[DataProvider('staleAuthorityChanges')]
    public function test_stale_hydration_is_rejected_after_authority_changes(string $change): void
    {
        $fixture = $this->established();
        $credentials = $this->credentials($fixture);
        $snapshot = app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery($credentials);
        $this->assertSame($fixture['attempt'], $snapshot->assessmentParticipantId);
        $this->changeAuthority($change, $fixture);

        try {
            app(RlsContextRunner::class)->runAsService(
                fn () => app(CheckoutSessionLifecycle::class)->lockMutation($credentials),
            );
            $this->fail("Stale {$change} authority issued a mutation scope.");
        } catch (InvalidCheckoutSession $exception) {
            $this->assertSame('CHECKOUT_SESSION_INVALID', $exception->getMessage());
        }
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
    }

    public static function staleAuthorityChanges(): iterable
    {
        yield 'session revoked' => ['session'];
        yield 'recovery generation' => ['recovery'];
        yield 'payer policy changed' => ['policy'];
        yield 'client disabled' => ['client'];
        yield 'source disabled' => ['source'];
        yield 'package disabled' => ['package'];
        yield 'participant deleted' => ['participant'];
        yield 'attempt revoked' => ['revoked'];
        yield 'attempt finalized' => ['finalized'];
    }

    public function test_foreign_pair_and_rollback_are_closed_and_restore_context(): void
    {
        $fixture = $this->established();
        $foreign = $this->established();
        $mixed = new CheckoutSessionMutationCredentials($fixture['selector'], $foreign['csrf']);
        try {
            app(RlsContextRunner::class)->runAsService(
                fn () => app(CheckoutSessionLifecycle::class)->lockMutation($mixed),
            );
            $this->fail('Foreign credential pair issued a mutation scope.');
        } catch (InvalidCheckoutSession) {
            $this->addToAssertionCount(1);
        }

        $before = CheckoutSession::query()->findOrFail($fixture['session'])->getAttributes();
        $captured = null;
        try {
            app(RlsContextRunner::class)->runAsService(function () use ($fixture, &$captured): void {
                $captured = app(CheckoutSessionLifecycle::class)->lockMutation($this->credentials($fixture));
                $this->assertSame($fixture['attempt'], $captured->principal()->assessmentParticipantId);
                throw new RuntimeException('synthetic-mutation-rollback');
            });
            $this->fail('Synthetic rollback did not execute.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic-mutation-rollback', $exception->getMessage());
        }
        $this->assertInstanceOf(CheckoutSessionMutationScope::class, $captured);
        try {
            $captured->principal();
            $this->fail('Rolled-back mutation authority remained usable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, CheckoutSession::query()->findOrFail($fixture['session'])->getAttributes());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_queries_follow_the_existing_canonical_lock_order(): void
    {
        $fixture = $this->established();
        $tables = [];
        DB::listen(function (QueryExecuted $query) use (&$tables): void {
            foreach (['branches', 'integration_clients', 'integration_sources', 'packages', 'package_items',
                'assessment_participants', 'participants', 'checkout_handoffs', 'checkout_sessions'] as $table) {
                if (str_contains($query->sql, '"'.$table.'"')) {
                    $tables[] = $table;
                }
            }
        });
        app(RlsContextRunner::class)->runAsService(
            fn () => app(CheckoutSessionLifecycle::class)->lockMutation($this->credentials($fixture))->principal(),
        );
        $first = [];
        foreach ($tables as $index => $table) {
            $first[$table] ??= $index;
        }
        $this->assertLessThan($first['integration_clients'], $first['branches']);
        $this->assertLessThan($first['integration_sources'], $first['integration_clients']);
        $this->assertLessThan($first['packages'], $first['integration_sources']);
        $this->assertLessThan($first['assessment_participants'], $first['package_items']);
        $this->assertLessThan($first['participants'], $first['assessment_participants']);
        $this->assertLessThan($first['checkout_handoffs'], $first['participants']);
        $this->assertContains('checkout_sessions', $tables);
    }

    /** @param array<string, mixed> $fixture */
    private function changeAuthority(string $change, array $fixture): void
    {
        match ($change) {
            'session' => DB::table('checkout_sessions')->where('id', $fixture['session'])->update([
                'status' => 'REVOKED', 'active_marker' => null, 'revoked_at' => now(),
                'revocation_reason' => 'REPLACED', 'updated_at' => now(),
            ]),
            'recovery' => app(RlsContextRunner::class)->runAsService(fn () => app(IssueCheckoutHandoff::class)
                ->execute(new CheckoutHandoffIssueInput(IntegrationClient::findOrFail($fixture['client']),
                    $fixture['attemptPublicId'], IntegrationSource::findOrFail($fixture['source'])->source_system,
                    'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Recovery))),
            'policy' => DB::table('branches')->where('id', $fixture['organization'])->update([
                'allowed_payer_types' => json_encode(['organization'], JSON_THROW_ON_ERROR),
            ]),
            'client' => DB::table('integration_clients')->where('id', $fixture['client'])->update(['enabled' => false]),
            'source' => DB::table('integration_sources')->where('id', $fixture['source'])->update(['status' => 'INACTIVE']),
            'package' => DB::table('packages')->where('id', $fixture['package'])->update(['is_active' => false]),
            'participant' => DB::table('participants')->where('id', $fixture['participant'])->update(['deleted_at' => now()]),
            'revoked' => DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'assessment_status' => 'REVOKED', 'revoked_at' => now(),
            ]),
            'finalized' => DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'assessment_status' => 'COMPLETED', 'finalized_at' => now(),
            ]),
            default => throw new RuntimeException('Unknown synthetic authority change.'),
        };
    }

    /** @param array<string, mixed> $fixture */
    private function credentials(array $fixture): CheckoutSessionMutationCredentials
    {
        return new CheckoutSessionMutationCredentials($fixture['selector'], $fixture['csrf']);
    }

    /** @return array<string, int|string> */
    private function established(): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'MUTATION_'.$key;
        $packageCode = 'M'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
            'allowed_payer_types' => json_encode(['self'], JSON_THROW_ON_ERROR),
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
            'allowed_funding_modes' => '[]', 'allowed_payer_types' => json_encode(['self'], JSON_THROW_ON_ERROR),
            'status' => 'ACTIVE',
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
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $attemptPublicId, 'participant_id' => $participant,
            'organization_id' => $organization, 'package_id' => $package,
            'origin' => 'INTEGRATED', 'intended_field_snapshot' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_case_id' => $case,
            'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY'], JSON_THROW_ON_ERROR),
        ]);
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::findOrFail($client), $attemptPublicId,
                $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $raw = $issued->rawToken();
        if (! is_string($raw)) {
            $this->fail('Synthetic handoff unavailable.');
        }
        $result = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($raw));
        $session = CheckoutSession::query()->where('public_id', $result->sessionPublicId)->value('id');
        if (! is_int($session)) {
            $this->fail('Synthetic session unavailable.');
        }

        return ['organization' => $organization, 'participant' => $participant, 'client' => $client,
            'source' => $source, 'package' => $package, 'attempt' => $attempt,
            'attemptPublicId' => $attemptPublicId, 'session' => $session,
            'selector' => $result->rawSelector(), 'csrf' => $result->rawCsrfToken()];
    }
}
