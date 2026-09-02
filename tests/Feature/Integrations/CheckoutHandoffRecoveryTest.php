<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\ConsumeCheckoutHandoff;
use App\Actions\Integrations\IdempotencyConflict;
use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffConsumeInput;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutHandoffIssueResult;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

final class CheckoutHandoffRecoveryTest extends OrganizationPaymentTestCase
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

    public function test_explicit_recovery_revokes_exact_active_session_and_issues_one_new_credential(): void
    {
        $state = $this->recoverable();
        $oldHandoff = $state['handoff']->getAttributes();
        $oldRaw = $state['rawToken'];
        $key = 'ih1_'.str_repeat('a', 32);

        $result = $this->issue($state, $key, CheckoutHandoffIntent::Recovery);
        $newRaw = $result->rawToken();

        $this->assertFalse($result->replayed);
        $this->assertFalse($result->reissueRequired);
        $this->assertSame(2, $result->issueNumber);
        $this->assertMatchesRegularExpression('/^och1_[0-9a-f]{64}$/D', $newRaw ?? '');
        $this->assertNotSame($oldRaw, $newRaw);
        $this->assertSame($oldHandoff, CheckoutHandoff::query()->findOrFail($state['handoff']->id)->getAttributes());
        $this->assertDatabaseHas('checkout_sessions', [
            'id' => $state['session']->id, 'status' => 'REVOKED', 'active_marker' => null,
            'revocation_reason' => 'RECOVERY_REISSUED',
        ]);
        $this->assertDatabaseHas('checkout_handoffs', [
            'public_id' => $result->handoffPublicId, 'issue_number' => 2,
            'status' => 'ISSUED', 'active_marker' => true,
        ]);
        $audit = DB::table('audit_logs')->where('action', 'checkout_handoff.recovery_reissued')->sole();
        $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($oldRaw, $encoded);
        $this->assertStringNotContainsString((string) $newRaw, $encoded);
        $this->assertStringNotContainsString($key, $encoded);
        $this->assertSame([
            'version', 'publicId', 'issueNumber', 'purpose', 'destination', 'sourceSystem',
            'issuedAt', 'expiresAt', 'revokedPrevious',
        ], array_keys(json_decode($audit->context, true, 512, JSON_THROW_ON_ERROR)));
        $this->assertStringNotContainsString((string) $newRaw, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('token_digest', CheckoutHandoff::query()
            ->where('public_id', $result->handoffPublicId)->firstOrFail()->toArray());

        $replay = $this->issue($state, $key, CheckoutHandoffIntent::Recovery);
        $this->assertTrue($replay->replayed);
        $this->assertTrue($replay->reissueRequired);
        $this->assertNull($replay->rawToken());
        $this->assertSame($result->handoffPublicId, $replay->handoffPublicId);
        $this->assertSame(2, DB::table('checkout_handoffs')
            ->where('assessment_participant_id', $state['attempt']->id)->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'checkout_handoff.recovery_reissued')->count());
    }

    public function test_recovery_intent_and_scope_are_part_of_idempotency_contract(): void
    {
        $state = $this->recoverable();
        $key = 'ih1_'.str_repeat('b', 32);
        $recovered = $this->issue($state, $key, CheckoutHandoffIntent::Recovery);

        try {
            $this->issue($state, $key, CheckoutHandoffIntent::Reissue);
            $this->fail('Recovery idempotency key changed intent.');
        } catch (IdempotencyConflict) {
            $this->assertSame(2, DB::table('checkout_handoffs')
                ->where('assessment_participant_id', $state['attempt']->id)->count());
        }

        $other = $this->recoverable($this->sameClientAttempt($state));
        try {
            $this->issue($other, $key, CheckoutHandoffIntent::Recovery);
            $this->fail('Recovery idempotency key crossed attempt scope.');
        } catch (IdempotencyConflict) {
            $this->assertDatabaseCount('checkout_handoffs', 3);
        }

        try {
            $this->issue($state, 'ih1_'.str_repeat('c', 32), CheckoutHandoffIntent::Recovery);
            $this->fail('A second distinct recovery implicitly reissued the new generation.');
        } catch (IntegrationContractViolation $exception) {
            $this->assertSame('HANDOFF_RECOVERY_NOT_ALLOWED', $exception->errorCode);
        }
        $this->assertDatabaseHas('checkout_handoffs', [
            'public_id' => $recovered->handoffPublicId, 'status' => 'ISSUED', 'active_marker' => true,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'checkout_handoff.recovery_reissued')->count());
    }

    public function test_missing_terminal_foreign_due_or_corrupt_session_fails_closed(): void
    {
        $cases = [
            'missing' => function (array $state): void {
                CheckoutSession::query()->whereKey($state['session']->id)->delete();
            },
            'revoked' => function (array $state): void {
                CheckoutSession::query()->whereKey($state['session']->id)->update([
                    'status' => 'REVOKED', 'active_marker' => null, 'revoked_at' => now(),
                    'revocation_reason' => 'LOGOUT',
                ]);
            },
            'expired' => function (array $state): void {
                CheckoutSession::query()->whereKey($state['session']->id)->update([
                    'status' => 'EXPIRED', 'active_marker' => null,
                    'expired_at' => $state['session']->idle_expires_at, 'revocation_reason' => null,
                ]);
            },
            'future terminal timestamp' => function (array $state): void {
                CheckoutSession::query()->whereKey($state['session']->id)->update([
                    'status' => 'REVOKED', 'active_marker' => null,
                    'revoked_at' => now()->addMinute(), 'revocation_reason' => 'LOGOUT',
                ]);
            },
            'latest issued' => function (array $state): void {
                CheckoutHandoff::query()->whereKey($state['handoff']->id)->update([
                    'status' => 'ISSUED', 'active_marker' => true, 'consumed_at' => null,
                ]);
            },
            'due active' => function (array $state): void {
                $past = CarbonImmutable::now()->subHours(3)->startOfSecond();
                CheckoutSession::query()->whereKey($state['session']->id)->update([
                    'established_at' => $past, 'last_seen_at' => $past,
                    'idle_expires_at' => $past->addMinutes(30),
                    'absolute_expires_at' => $past->addHours(2),
                ]);
            },
            'foreign scope' => function (array $state): void {
                $foreign = $this->fixture();
                Schema::disableForeignKeyConstraints();
                try {
                    DB::table('checkout_sessions')->where('id', $state['session']->id)
                        ->update(['organization_id' => $foreign['attempt']->organization_id]);
                } finally {
                    Schema::enableForeignKeyConstraints();
                }
            },
        ];

        foreach ($cases as $label => $mutate) {
            $state = $this->recoverable();
            $mutate($state);
            $beforeHandoff = DB::table('checkout_handoffs')->where('id', $state['handoff']->id)->first();
            $beforeSession = DB::table('checkout_sessions')->where('id', $state['session']->id)->first();
            try {
                $this->issue($state, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Recovery);
                $this->fail("{$label} recovery state was accepted.");
            } catch (IntegrationContractViolation $exception) {
                $this->assertSame('HANDOFF_RECOVERY_NOT_ALLOWED', $exception->errorCode, $label);
            }
            $this->assertEquals($beforeHandoff, DB::table('checkout_handoffs')
                ->where('id', $state['handoff']->id)->first(), $label);
            $this->assertEquals($beforeSession, DB::table('checkout_sessions')
                ->where('id', $state['session']->id)->first(), $label);
            $this->assertSame(0, DB::table('audit_logs')
                ->where('action', 'checkout_handoff.recovery_reissued')
                ->where('subject_id', (string) $state['attempt']->id)->count(), $label);
        }
    }

    public function test_current_authority_revocation_fails_closed_without_transition(): void
    {
        $cases = [
            'client disabled' => fn (array $s) => DB::table('integration_clients')
                ->where('id', $s['client']->id)->update(['enabled' => false]),
            'source suspended' => fn (array $s) => DB::table('integration_sources')
                ->where('integration_client_id', $s['client']->id)->update(['status' => 'SUSPENDED']),
            'attempt revoked' => fn (array $s) => DB::table('assessment_participants')
                ->where('id', $s['attempt']->id)->update(['assessment_status' => 'REVOKED', 'revoked_at' => now()]),
        ];
        foreach ($cases as $label => $mutate) {
            $state = $this->recoverable();
            $mutate($state);
            try {
                $this->issue($state, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Recovery);
                $this->fail("{$label} authorized recovery.");
            } catch (IntegrationContractViolation $exception) {
                $this->assertSame('HANDOFF_NOT_ALLOWED', $exception->errorCode, $label);
            }
            $this->assertDatabaseHas('checkout_sessions', [
                'id' => $state['session']->id, 'status' => 'ACTIVE', 'active_marker' => true,
            ]);
            $this->assertSame(1, DB::table('checkout_handoffs')
                ->where('assessment_participant_id', $state['attempt']->id)->count());
        }
    }

    public function test_recovery_audit_failure_rolls_back_session_handoff_and_unrelated_state(): void
    {
        $state = $this->recoverable();
        $session = $state['session']->getAttributes();
        $handoff = $state['handoff']->getAttributes();
        $tables = ['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements',
            'orders', 'entitlements', 'outbox_messages', 'consent_records', 'identity_verifications', 'sessions'];
        $counts = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        DB::unprepared("CREATE TRIGGER checkout_handoff_recovery_audit_failure BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'checkout_handoff.recovery_reissued'
            BEGIN SELECT RAISE(ABORT, 'synthetic recovery audit failure'); END");
        try {
            $this->issue($state, 'ih1_'.str_repeat('d', 32), CheckoutHandoffIntent::Recovery);
            $this->fail('Recovery audit failure returned a credential.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic recovery audit failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER checkout_handoff_recovery_audit_failure');
        }

        $this->assertSame($session, CheckoutSession::query()->findOrFail($state['session']->id)->getAttributes());
        $this->assertSame($handoff, CheckoutHandoff::query()->findOrFail($state['handoff']->id)->getAttributes());
        $this->assertSame(1, DB::table('checkout_handoffs')
            ->where('assessment_participant_id', $state['attempt']->id)->count());
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
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

    /** @return array{client:IntegrationClient,attempt:AssessmentParticipant,sourceSystem:string,handoff:CheckoutHandoff,session:CheckoutSession,rawToken:string} */
    private function recoverable(?array $fixture = null): array
    {
        $fixture ??= $this->fixture();
        $issued = $this->issue($fixture, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue);
        $raw = $issued->rawToken();
        $this->assertIsString($raw);
        app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));
        $handoff = CheckoutHandoff::query()->where('public_id', $issued->handoffPublicId)->firstOrFail();
        $established = $handoff->consumed_at?->toImmutable();
        $this->assertInstanceOf(CarbonImmutable::class, $established);
        $session = CheckoutSession::query()->create([
            'public_id' => (string) Str::ulid(), 'selector_digest' => hash('sha256', 'selector-'.$raw),
            'csrf_digest' => hash('sha256', 'csrf-'.$raw), 'checkout_handoff_id' => $handoff->id,
            'assessment_participant_id' => $handoff->assessment_participant_id,
            'organization_id' => $handoff->organization_id, 'participant_id' => $handoff->participant_id,
            'package_id' => $handoff->package_id, 'integration_client_id' => $handoff->integration_client_id,
            'integration_source_id' => $handoff->integration_source_id, 'source_system' => $handoff->source_system,
            'contract_version' => 'checkout-v2', 'status' => 'ACTIVE', 'active_marker' => true,
            'established_at' => $established, 'last_seen_at' => $established,
            'idle_expires_at' => $established->addMinutes(30),
            'absolute_expires_at' => $established->addHours(2),
        ]);

        return [...$fixture, 'handoff' => $handoff->fresh(), 'session' => $session->fresh(), 'rawToken' => $raw];
    }

    /** @param array{client:IntegrationClient,attempt:AssessmentParticipant,sourceSystem:string} $state
     * @return array{client:IntegrationClient,attempt:AssessmentParticipant,sourceSystem:string}
     */
    private function sameClientAttempt(array $state): array
    {
        $key = (string) Str::ulid();
        $attempt = AssessmentParticipant::query()->create([
            'organization_id' => $state['attempt']->organization_id,
            'integration_client_id' => $state['client']->id,
            'participant_id' => $state['attempt']->participant_id,
            'package_id' => $state['attempt']->package_id,
            'assessment_attempt_id' => (string) Str::ulid(),
            'source_system' => $state['sourceSystem'],
            'external_candidate_id' => $key,
            'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED',
            'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => [
                'checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY',
            ],
        ]);

        return ['client' => $state['client'], 'attempt' => $attempt, 'sourceSystem' => $state['sourceSystem']];
    }

    /** @return array{client:IntegrationClient,attempt:AssessmentParticipant,sourceSystem:string} */
    private function fixture(): array
    {
        $key = (string) Str::ulid();
        $packageCode = 'R'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => 'HANDOFF_RECOVERY_SOURCE',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
        $clientId = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        DB::table('integration_sources')->insert([
            'integration_client_id' => $clientId, 'source_system' => 'HANDOFF_RECOVERY_SOURCE',
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic', 'amount' => 100,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('package_items')->insert(['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1]);
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $clientId,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_attempt_id' => (string) Str::ulid(), 'source_system' => 'HANDOFF_RECOVERY_SOURCE',
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY',
            ], JSON_THROW_ON_ERROR),
        ]);

        return [
            'client' => IntegrationClient::query()->findOrFail($clientId),
            'attempt' => AssessmentParticipant::query()->findOrFail($attempt),
            'sourceSystem' => 'HANDOFF_RECOVERY_SOURCE',
        ];
    }
}
