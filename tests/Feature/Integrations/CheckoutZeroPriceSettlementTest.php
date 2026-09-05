<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Actions\Integrations\SettleZeroPriceCheckout;
use App\Contracts\PaymentProvider;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Data\Integrations\CheckoutZeroPriceResult;
use App\Enums\CheckoutHandoffIntent;
use App\Models\IntegrationClient;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class CheckoutZeroPriceSettlementTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
        ]);
        config()->set('assessment_integration.checkout.enabled', true);
        $provider = $this->createMock(PaymentProvider::class);
        foreach (['createInvoice', 'lookupInvoice', 'checkStatus', 'normalizeWebhook', 'expireInvoice'] as $method) {
            $provider->expects($this->never())->method($method);
        }
        app()->instance(PaymentProvider::class, $provider);
    }

    public function test_self_zero_is_settled_and_activated_atomically_then_replays_without_duplicates(): void
    {
        $fixture = $this->established(identity: true);
        $this->acceptCurrentConsents($fixture['participant']);

        $first = $this->settle($fixture, false);
        $this->assertSame('settled', $first->state);
        $this->assertSame(['dass21', 'ist'], $first->activatedTestTypes);
        $this->assertDatabaseHas('assessment_charges', [
            'assessment_participant_id' => $fixture['attempt'], 'payer_type' => 'self',
            'amount' => 0, 'currency' => 'IDR',
        ]);
        $this->assertSame(1, DB::table('assessment_charges')->whereNotNull('free_settled_at')->count());
        $this->assertSame(1, $this->freeAuditCount());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment.activated')->count());
        $this->assertSame(1, DB::table('outbox_messages')->where('topic', 'assessment.activation')->count());
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_bill_items', 0);
        $charge = DB::table('assessment_charges')->where('assessment_participant_id', $fixture['attempt'])->first();
        $price = json_decode((string) $charge->price_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $policy = json_decode((string) $charge->policy_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $price['amount']);
        $this->assertFalse($price['consultationRequested']);
        $this->assertSame(['self'], $policy['allowedPayerTypes']);
        $this->assertSame('self', $policy['payerType']);

        $second = $this->settle($fixture, false);
        $this->assertSame('settled', $second->state);
        $this->assertSame([], $second->activatedTestTypes);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertSame(1, $this->freeAuditCount());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment.activated')->count());
        $this->assertSame(1, DB::table('outbox_messages')->where('topic', 'assessment.activation')->count());
    }

    public function test_organization_zero_settles_but_missing_identity_stays_locked(): void
    {
        $fixture = $this->established('INVOICED_TO_ORGANIZATION');
        $this->acceptCurrentConsents($fixture['participant']);

        $result = $this->settle($fixture, false);

        $this->assertSame('settled', $result->state);
        $this->assertSame([], $result->activatedTestTypes);
        $this->assertDatabaseHas('assessment_charges', [
            'assessment_participant_id' => $fixture['attempt'], 'payer_type' => 'organization',
            'amount' => 0,
        ]);
        $this->assertDatabaseHas('assessment_participants', [
            'id' => $fixture['attempt'], 'assessment_status' => 'PROVISIONED',
        ]);
        $this->assertDatabaseCount('assessment_entitlements', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_positive_consultation_is_not_applicable_and_writes_nothing(): void
    {
        $fixture = $this->established();
        $this->acceptCurrentConsents($fixture['participant']);

        $result = $this->settle($fixture, true);

        $this->assertSame('not_applicable', $result->state);
        $this->assertSame([], $result->activatedTestTypes);
        $this->assertNoPaymentWrites();
    }

    public function test_consultation_choice_is_immutable_but_positive_first_does_not_claim_zero_path(): void
    {
        $fixture = $this->established();
        $this->acceptCurrentConsents($fixture['participant']);
        $this->assertSame('settled', $this->settle($fixture, false)->state);
        $before = DB::table('assessment_charges')->where('assessment_participant_id', $fixture['attempt'])->first();
        $this->assertUnavailable(fn () => $this->settle($fixture, true));
        $this->assertEquals($before, DB::table('assessment_charges')
            ->where('assessment_participant_id', $fixture['attempt'])->first());
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertSame(1, $this->freeAuditCount());

        $positiveFirst = $this->established();
        $this->acceptCurrentConsents($positiveFirst['participant']);
        $this->assertSame('not_applicable', $this->settle($positiveFirst, true)->state);
        $this->assertSame('settled', $this->settle($positiveFirst, false)->state);
        $this->assertSame(1, DB::table('assessment_charges')
            ->where('assessment_participant_id', $positiveFirst['attempt'])->count());
        $this->assertDatabaseCount('assessment_bills', 0);
    }

    public function test_replay_rejects_current_catalog_or_policy_drift_without_rewrite(): void
    {
        foreach (['price', 'name', 'items', 'allowed-policy', 'locked-policy'] as $case) {
            $fixture = $this->established();
            $this->acceptCurrentConsents($fixture['participant']);
            $this->settle($fixture, false);
            $charge = DB::table('assessment_charges')->where('assessment_participant_id', $fixture['attempt'])->first();
            $auditCount = $this->freeAuditCount();
            if ($case === 'price') {
                DB::table('packages')->where('id', $fixture['package'])->update(['amount' => 1]);
            } elseif ($case === 'name') {
                DB::table('packages')->where('id', $fixture['package'])->update(['name' => 'Changed Catalog']);
            } elseif ($case === 'items') {
                DB::table('package_items')->insert([
                    'package_id' => $fixture['package'], 'test_type' => 'papi', 'sort_order' => 3,
                ]);
            } elseif ($case === 'allowed-policy') {
                foreach (['branches' => 'id', 'integration_sources' => 'id'] as $table => $key) {
                    $id = $table === 'branches' ? $fixture['organization'] : $fixture['source'];
                    DB::table($table)->where($key, $id)->update([
                        'allowed_payer_types' => json_encode(['self', 'organization'], JSON_THROW_ON_ERROR),
                    ]);
                }
            } else {
                DB::table('integration_sources')->where('id', $fixture['source'])
                    ->update(['locked_payer_type' => 'self']);
            }
            $this->assertUnavailable(fn () => $this->settle($fixture, false));
            $this->assertEquals($charge, DB::table('assessment_charges')
                ->where('assessment_participant_id', $fixture['attempt'])->first());
            $this->assertSame($auditCount, $this->freeAuditCount());
            $this->assertDatabaseCount('assessment_bills', 0);
        }
    }

    public function test_database_instant_drives_initial_activation_and_replay_when_php_clock_is_earlier(): void
    {
        $fixture = $this->established(identity: true);
        $this->acceptCurrentConsents($fixture['participant']);
        Carbon::setTestNow('2020-01-01 00:00:00+00:00');
        try {
            $first = $this->settle($fixture, false);
            $second = $this->settle($fixture, false);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(['dass21', 'ist'], $first->activatedTestTypes);
        $this->assertSame([], $second->activatedTestTypes);
        $this->assertDatabaseHas('assessment_participants', [
            'id' => $fixture['attempt'], 'assessment_status' => 'READY',
        ]);
        $this->assertSame(2, DB::table('assessment_entitlements')
            ->where('assessment_participant_id', $fixture['attempt'])->where('status', 'ready')->count());
        $this->assertSame(1, DB::table('outbox_messages')->where('topic', 'assessment.activation')->count());
    }

    public function test_recovered_checkout_session_replays_the_attempt_scoped_settlement(): void
    {
        $fixture = $this->established();
        $this->acceptCurrentConsents($fixture['participant']);
        $this->assertSame('settled', $this->settle($fixture, false)->state);
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::query()->findOrFail($fixture['client']),
                $fixture['attemptPublicId'], $fixture['sourceSystem'], 'ih1_'.bin2hex(random_bytes(16)),
                CheckoutHandoffIntent::Recovery)));
        $recovered = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($issued->rawToken()));
        $fixture['selector'] = $recovered->rawSelector();
        $fixture['csrf'] = $recovered->rawCsrfToken();

        $this->assertSame('settled', $this->settle($fixture, false)->state);
        $this->assertSame(1, $this->freeAuditCount());
        $this->assertDatabaseCount('assessment_charges', 1);
    }

    public function test_both_exact_current_consents_are_required_separately(): void
    {
        foreach (['missing-psychotest', 'missing-dass', 'withdrawn-dass', 'wrong-hash',
            'wrong-version', 'future-dass'] as $case) {
            $fixture = $this->established();
            $this->acceptCurrentConsents($fixture['participant']);
            if ($case === 'missing-psychotest') {
                DB::table('consent_records')->where('participant_id', $fixture['participant'])
                    ->where('consent_type', 'psychotest')->delete();
            } elseif ($case === 'missing-dass') {
                DB::table('consent_records')->where('participant_id', $fixture['participant'])
                    ->where('consent_type', 'dass')->delete();
            } elseif ($case === 'withdrawn-dass') {
                DB::table('consent_records')->where('participant_id', $fixture['participant'])
                    ->where('consent_type', 'dass')->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
            } elseif ($case === 'wrong-hash') {
                DB::table('consent_records')->where('participant_id', $fixture['participant'])
                    ->where('consent_type', 'psychotest')->update(['document_hash' => str_repeat('0', 64)]);
            } elseif ($case === 'wrong-version') {
                DB::table('consent_records')->where('participant_id', $fixture['participant'])
                    ->where('consent_type', 'psychotest')->update(['document_version' => 'obsolete']);
            } else {
                DB::table('consent_records')->where('participant_id', $fixture['participant'])
                    ->where('consent_type', 'dass')->update(['consented_at' => now()->addDay()]);
            }

            $this->assertUnavailable(fn () => $this->settle($fixture, false));
            $this->assertNoPaymentWrites();
        }
    }

    public function test_stale_scope_policy_and_terminal_attempt_fail_without_writes(): void
    {
        foreach (['policy', 'session', 'revoked', 'finalized', 'missing-dass-item'] as $case) {
            $fixture = $this->established();
            $this->acceptCurrentConsents($fixture['participant']);
            match ($case) {
                'policy' => DB::table('branches')->where('id', $fixture['organization'])
                    ->update(['allowed_payer_types' => json_encode(['organization'], JSON_THROW_ON_ERROR)]),
                'session' => DB::table('checkout_sessions')->where('assessment_participant_id', $fixture['attempt'])
                    ->update(['status' => 'REVOKED', 'active_marker' => null, 'revoked_at' => now(),
                        'revocation_reason' => 'REPLACED']),
                'revoked' => DB::table('assessment_participants')->where('id', $fixture['attempt'])
                    ->update(['assessment_status' => 'REVOKED', 'revoked_at' => now()]),
                'finalized' => DB::table('assessment_participants')->where('id', $fixture['attempt'])
                    ->update(['assessment_status' => 'COMPLETED', 'finalized_at' => now()]),
                'missing-dass-item' => DB::table('package_items')->where('package_id', $fixture['package'])
                    ->where('test_type', 'dass21')->delete(),
            };
            $this->assertUnavailable(fn () => $this->settle($fixture, false));
            $this->assertNoPaymentWrites();
        }
    }

    public function test_corrupt_replay_is_never_repaired(): void
    {
        foreach (['snapshot', 'policy-snapshot', 'payer', 'marker', 'audit', 'audit-context', 'bill-item'] as $case) {
            $fixture = $this->established();
            $this->acceptCurrentConsents($fixture['participant']);
            $this->settle($fixture, false);
            $charge = (int) DB::table('assessment_charges')->where('assessment_participant_id', $fixture['attempt'])->value('id');
            if ($case === 'snapshot') {
                DB::table('assessment_charges')->where('id', $charge)
                    ->update(['price_snapshot' => json_encode(['version' => 1], JSON_THROW_ON_ERROR)]);
            } elseif ($case === 'policy-snapshot') {
                DB::table('assessment_charges')->where('id', $charge)
                    ->update(['policy_snapshot' => json_encode(['payerType' => 'self'], JSON_THROW_ON_ERROR)]);
            } elseif ($case === 'payer') {
                DB::table('assessment_charges')->where('id', $charge)->update(['payer_type' => 'organization']);
            } elseif ($case === 'marker') {
                DB::table('assessment_charges')->where('id', $charge)->update(['free_settled_at' => null]);
            } elseif ($case === 'audit') {
                DB::table('audit_logs')->where('action', 'assessment_charge.free_settled')->delete();
            } elseif ($case === 'audit-context') {
                DB::table('audit_logs')->where('action', 'assessment_charge.free_settled')
                    ->update(['context' => json_encode(['version' => 99], JSON_THROW_ON_ERROR)]);
            } else {
                $method = DB::table('payment_methods')->insertGetId([
                    'code' => 'synthetic-'.$charge, 'display_name' => 'Synthetic', 'is_active' => true,
                ]);
                $bill = DB::table('assessment_bills')->insertGetId([
                    'organization_id' => $fixture['organization'], 'payer_type' => 'self',
                    'payer_participant_id' => $fixture['participant'], 'public_reference' => 'AB_'.Str::ulid(),
                    'amount' => 1, 'currency' => 'IDR', 'item_count' => 1,
                    'selection_hash' => hash('sha256', 'selection'.$charge), 'idempotency_key' => 'corrupt-'.$charge,
                    'request_hash' => hash('sha256', 'request'.$charge), 'status' => 'reserved',
                    'payment_method_id' => $method,
                ]);
                DB::table('assessment_bill_items')->insert([
                    'bill_id' => $bill, 'charge_id' => $charge, 'organization_id' => $fixture['organization'],
                    'participant_id' => $fixture['participant'], 'payer_type' => 'self',
                    'payer_participant_id' => $fixture['participant'], 'amount' => 0, 'currency' => 'IDR',
                ]);
            }
            $before = DB::table('assessment_charges')->where('id', $charge)->first();
            $this->assertUnavailable(fn () => $this->settle($fixture, false));
            $this->assertEquals($before, DB::table('assessment_charges')->where('id', $charge)->first());
        }
    }

    public function test_audit_or_outbox_failure_rolls_back_free_settlement_and_activation(): void
    {
        foreach (['free-audit', 'activation-outbox'] as $case) {
            $fixture = $this->established(identity: true);
            $this->acceptCurrentConsents($fixture['participant']);
            if ($case === 'free-audit') {
                DB::unprepared("CREATE TRIGGER zero_failure BEFORE INSERT ON audit_logs
                    WHEN NEW.action = 'assessment_charge.free_settled' BEGIN SELECT RAISE(ABORT, 'synthetic zero failure'); END");
            } else {
                DB::unprepared("CREATE TRIGGER zero_failure BEFORE INSERT ON outbox_messages
                    WHEN NEW.topic = 'assessment.activation' BEGIN SELECT RAISE(ABORT, 'synthetic zero failure'); END");
            }
            try {
                $this->settle($fixture, false);
                $this->fail('Injected zero-price failure did not propagate.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('synthetic zero failure', $exception->getMessage());
            } finally {
                DB::unprepared('DROP TRIGGER zero_failure');
            }
            $this->assertNoPaymentWrites();
            $this->assertDatabaseCount('assessment_entitlements', 0);
            $this->assertDatabaseHas('assessment_participants', [
                'id' => $fixture['attempt'], 'assessment_status' => 'PROVISIONED',
            ]);
        }
    }

    public function test_action_rejects_ambient_context_and_transaction(): void
    {
        $fixture = $this->established();
        $this->acceptCurrentConsents($fixture['participant']);
        foreach ([
            fn () => app(RlsContextRunner::class)->runAsService(fn () => $this->settle($fixture, false)),
            fn () => DB::transaction(fn () => $this->settle($fixture, false)),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Ambient execution was accepted.');
            } catch (LogicException $exception) {
                $this->assertSame('Zero-price checkout owns an empty context and transaction.', $exception->getMessage());
            }
        }
        $this->assertNoPaymentWrites();
    }

    /** @param array<string, mixed> $fixture */
    private function settle(array $fixture, bool $consultationRequested): CheckoutZeroPriceResult
    {
        return app(SettleZeroPriceCheckout::class)->execute(
            new CheckoutSessionMutationCredentials($fixture['selector'], $fixture['csrf']),
            $consultationRequested,
        );
    }

    private function acceptCurrentConsents(int $participant): void
    {
        foreach (['psychotest', 'dass'] as $type) {
            $document = ConsentDocument::for($type);
            DB::table('consent_records')->insert([
                'participant_id' => $participant, 'consent_type' => $type, 'status' => 'accepted',
                'document_version' => $document->version, 'document_hash' => $document->hash,
                'consented_at' => now(), 'withdrawn_at' => null,
            ]);
        }
    }

    private function assertUnavailable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unavailable zero-price checkout was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('CHECKOUT_PAYMENT_UNAVAILABLE', $exception->getMessage());
        }
    }

    private function freeAuditCount(): int
    {
        return DB::table('audit_logs')->where('action', 'assessment_charge.free_settled')->count();
    }

    private function assertNoPaymentWrites(): void
    {
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_bill_items', 0);
        $this->assertSame(0, $this->freeAuditCount());
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,sourceSystem:string,selector:string,csrf:string} */
    private function established(string $funding = 'COMMERCIAL_SELF_PAY', bool $identity = false): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'ZEROPAY_'.$key;
        $packageCode = 'ZP'.$key;
        $payer = $funding === 'COMMERCIAL_SELF_PAY' ? 'self' : 'organization';
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
            'allowed_payer_types' => json_encode([$payer], JSON_THROW_ON_ERROR),
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => 'Synthetic Person', 'birth_date' => '2000-01-02', 'gender' => 'female',
            'education_level' => 'SMA_SMK', 'intended_field' => 'KAIGO', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'allowed_payer_types' => json_encode([$payer], JSON_THROW_ON_ERROR),
            'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic Zero', 'amount' => 0,
            'consultation_amount' => 30, 'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('participants')->where('id', $participant)->update(['package_id' => $package]);
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptPublicId = (string) Str::ulid();
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => $funding,
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => $funding,
            ], JSON_THROW_ON_ERROR),
        ]);
        if ($identity) {
            foreach (['identity_document', 'initial_selfie'] as $type) {
                $publicId = (string) Str::ulid();
                DB::table('identity_evidence')->insert([
                    'public_id' => $publicId, 'participant_id' => $participant, 'type' => $type,
                    'disk' => 'local', 'object_key' => 'synthetic/'.$publicId, 'mime_type' => 'image/jpeg',
                    'size_bytes' => 100, 'width' => 10, 'height' => 10,
                    'checksum_sha256' => hash('sha256', $publicId), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('identity_verifications')->insert([
                'participant_id' => $participant, 'matcher' => 'synthetic', 'outcome' => 'match',
                'manual_status' => 'pending', 'checked_at' => now(),
            ]);
        }
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::query()->findOrFail($client),
                $attemptPublicId, $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $established = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($issued->rawToken()));

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt',
            'attemptPublicId', 'sourceSystem')
            + ['selector' => $established->rawSelector(), 'csrf' => $established->rawCsrfToken()];
    }
}
