<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\IdempotencyConflict;
use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\ProvisionCheckoutParticipant;
use App\Http\Requests\ProvisionCheckoutParticipantRequest;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class CheckoutProvisioningTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private IntegrationClient $client;

    private IntegrationSource $source;

    private TestPackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Branch::create(['code' => 'P9', 'ref_code' => 'P9', 'name' => 'P9',
            'organization_code' => 'P9', 'display_name' => 'P9', 'allowed_payer_types' => ['self', 'organization']]);
        $this->client = IntegrationClient::create(['organization_id' => $org->id,
            'client_id' => 'p9-client', 'credential_reference' => 'synthetic', 'enabled' => true])->refresh();
        $this->source = IntegrationSource::create(['integration_client_id' => $this->client->id,
            'source_system' => 'P9_SOURCE', 'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
            'allowed_assessment_packages' => ['P9_PACKAGE'], 'allowed_funding_modes' => ['SPONSORED'],
            'allowed_payer_types' => ['self', 'organization']])->refresh();
        $this->package = TestPackage::create(['code' => 'P9_PACKAGE', 'name' => 'P9', 'amount' => 1000,
            'currency' => 'IDR', 'is_active' => true]);
        $this->package->items()->create(['test_type' => 'ist', 'sort_order' => 1]);
        $this->package->items()->create(['test_type' => 'dass21', 'sort_order' => 2]);
        config()->set('assessment_integration.checkout.enabled', true);
    }

    public function test_partial_profile_has_no_placeholder_or_rights(): void
    {
        $result = $this->provision();
        $this->assertFalse($result['replayed']);
        $this->assertSame('PROVISIONED', $result['assessment_status']);
        $p = Participant::findOrFail($result['participant_id']);
        foreach (['full_name', 'gender', 'birth_date', 'education_level', 'intended_field', 'phone', 'email',
            'test_number', 'registration_token', 'registration_payload_hash'] as $field) {
            $this->assertNull($p->getAttribute($field), $field);
        }
        $a = AssessmentParticipant::sole();
        $this->assertNull($a->funding_mode);
        $this->assertSame(['checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => null], $a->metadata);
        $this->assertSame($this->client->organization_id, $p->branch_id);
        $this->assertSame($p->branch_id, $p->referral_branch_id);
        $this->assertNoSideEffects();
    }

    #[DataProvider('genderAndPayer')]
    public function test_complete_profile_and_selected_payer_are_stored_without_authority(string $gender, string $stored, string $payer, string $funding): void
    {
        $input = [...$this->payload(), 'payerType' => $payer, 'profile' => [
            'fullName' => 'Peserta Sintetis', 'birthDate' => '2000-01-01', 'gender' => $gender,
            'educationLevel' => 'SMA', 'intendedField' => 'KAIGO', 'phone' => '628123456789', 'email' => 'p9@example.test',
        ], 'metadata' => ['cohortCode' => 'BATCH-1']];
        $this->provision($input);
        $p = Participant::sole();
        $this->assertSame($stored, $p->gender);
        $this->assertSame('KAIGO', $p->intended_field);
        $this->assertSame('2000-01-01', $p->birth_date->toDateString());
        $this->assertSame('Peserta Sintetis', $p->full_name);
        $this->assertSame('SMA', $p->education_level);
        $this->assertSame('628123456789', $p->phone);
        $this->assertSame('p9@example.test', $p->getAttribute('email'));
        $this->assertSame($funding, AssessmentParticipant::sole()->funding_mode);
        $this->assertSame(['cohortCode' => 'BATCH-1', 'checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => $funding], AssessmentParticipant::sole()->metadata);
        $this->assertNoSideEffects();
    }

    public static function genderAndPayer(): iterable
    {
        yield ['MALE', 'male', 'self', 'COMMERCIAL_SELF_PAY'];
        yield ['FEMALE', 'female', 'organization', 'INVOICED_TO_ORGANIZATION'];
    }

    public function test_replay_and_new_round_reuse_exact_identity_without_erasing_completed_profile(): void
    {
        $first = $this->provision();
        Participant::findOrFail($first['participant_id'])->update(['full_name' => 'Completed Elsewhere']);
        $retry = $this->provision();
        $logicalRetry = $this->provision(key: 'other-key');
        $this->assertTrue($retry['replayed']);
        $this->assertSame($retry, $logicalRetry);
        $this->assertSame($first['assessment_attempt_id'], $retry['assessment_attempt_id']);
        $next = $this->provision([...$this->payload(), 'assessmentRoundId' => 'ROUND-2', 'profile' => ['fullName' => null]], 'round-2');
        $this->assertSame($first['participant_id'], $next['participant_id']);
        $this->assertNotSame($first['assessment_attempt_id'], $next['assessment_attempt_id']);
        $this->assertSame('Completed Elsewhere', Participant::sole()->full_name);
        $this->assertDatabaseCount('assessment_participants', 2);
    }

    #[DataProvider('conflicts')]
    public function test_replay_rejects_changed_payload(array $override): void
    {
        $this->provision();
        $this->expectException(IdempotencyConflict::class);
        $this->provision([...$this->payload(), ...$override]);
    }

    public static function conflicts(): iterable
    {
        yield [['profile' => ['fullName' => 'Changed']]];
        yield [['payerType' => 'self']];
        yield [['externalCandidateId' => 'OTHER']];
        yield [['externalRegistrationId' => 'REG-2']];
        yield [['metadata' => ['cohortCode' => 'OTHER']]];
    }

    #[DataProvider('changedRegistry')]
    public function test_replay_reloads_persisted_registry(string $target, array $changes, string $error): void
    {
        $this->provision();
        $request = $this->request();
        match ($target) {
            'client' => IntegrationClient::whereKey($this->client->id)->update($changes),
            'source' => $this->source->update($changes),
            'package' => $this->package->update($changes),
            'organization' => $this->client->organization->update($changes),
            'flag' => config()->set('assessment_integration.checkout.enabled', false),
        };
        $this->expectException(IntegrationContractViolation::class);
        $this->expectExceptionMessage($error);
        app(RlsContextRunner::class)->runAsService(fn () => app(ProvisionCheckoutParticipant::class)->handle($request));
    }

    public static function changedRegistry(): iterable
    {
        yield ['client', ['enabled' => false], 'INTEGRATION_NOT_ALLOWED'];
        yield ['source', ['status' => 'SUSPENDED'], 'SOURCE_NOT_ALLOWED'];
        yield ['source', ['allowed_payer_types' => []], 'PAYER_NOT_ALLOWED'];
        yield ['package', ['is_active' => false], 'PACKAGE_NOT_ALLOWED'];
        yield ['organization', ['status' => 'SUSPENDED'], 'ORGANIZATION_NOT_ALLOWED'];
        yield ['flag', [], 'CHECKOUT_NOT_ENABLED'];
    }

    #[DataProvider('roles')]
    public function test_non_service_callers_cannot_elevate(string $role): void
    {
        $request = $this->request();
        $call = fn () => app(ProvisionCheckoutParticipant::class)->handle($request);
        $this->expectException(LogicException::class);
        if ($role === 'none') {
            $call();
        } else {
            app(RlsContextRunner::class)->run(new RlsContext($role, $this->client->organization_id, 1), $call);
        }
    }

    public static function roles(): iterable
    {
        foreach (['none', 'participant', 'branch_admin', 'super_admin', 'staff', 'psychologist'] as $role) {
            yield [$role];
        }
    }

    public function test_failure_after_attempt_insert_rolls_back_even_if_caller_catches(): void
    {
        $once = true;
        DB::listen(function (QueryExecuted $query) use (&$once): void {
            if ($once && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'assessment_participants')) {
                $once = false;
                throw new RuntimeException('synthetic provisioning crash');
            }
        });
        app(RlsContextRunner::class)->runAsService(function (): void {
            try {
                app(ProvisionCheckoutParticipant::class)->handle($this->request());
                $this->fail('Crash expected');
            } catch (RuntimeException $e) {
                $this->assertSame('synthetic provisioning crash', $e->getMessage());
            }
        });
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('assessment_participants', 0);
        $this->assertFalse($this->provision()['replayed']);
    }

    public function test_client_scope_change_or_forged_identity_is_rejected(): void
    {
        $request = $this->request();
        $forged = clone $this->client;
        $forged->client_id = 'foreign';
        $request->attributes->set('integration_client', $forged);
        $this->expectException(IntegrationContractViolation::class);
        app(RlsContextRunner::class)->runAsService(fn () => app(ProvisionCheckoutParticipant::class)->handle($request));
    }

    public function test_metadata_authority_is_rejected_by_existing_request(): void
    {
        $this->expectException(ValidationException::class);
        $this->provision([...$this->payload(), 'metadata' => ['checkout_contract_version' => 'checkout-v2', 'paid' => true]]);
    }

    public function test_same_contact_in_other_organization_or_source_is_not_merged(): void
    {
        $input = [...$this->payload(), 'profile' => ['email' => 'same@example.test', 'phone' => '628123456789']];
        $first = $this->provision($input);
        $otherSource = $this->source->replicate();
        $otherSource->source_system = 'SECOND';
        $otherSource->save();
        $second = $this->provision([...$input, 'sourceSystem' => 'SECOND'], 'source-2');
        $org = Branch::create(['code' => 'OTHER', 'ref_code' => 'OTHER', 'name' => 'Other',
            'organization_code' => 'OTHER', 'display_name' => 'Other', 'allowed_payer_types' => ['self', 'organization']]);
        $client = $this->client->replicate();
        $client->organization_id = $org->id;
        $client->client_id = 'other-client';
        $client->save();
        $source = $this->source->replicate();
        $source->integration_client_id = $client->id;
        $source->save();
        $this->client = $client;
        $third = $this->provision([...$input, 'organizationCode' => 'OTHER']);
        $this->assertCount(3, array_unique([$first['participant_id'], $second['participant_id'], $third['participant_id']]));
        $this->assertDatabaseCount('participants', 3);
    }

    public function test_same_organization_source_identity_survives_client_rotation_for_new_round_only(): void
    {
        $first = $this->provision();
        $client = $this->client->replicate();
        $client->client_id = 'rotated';
        $client->save();
        $source = $this->source->replicate();
        $source->integration_client_id = $client->id;
        $source->save();
        $this->client = $client;
        $next = $this->provision([...$this->payload(), 'assessmentRoundId' => 'ROUND-2'], 'next');
        $this->assertSame($first['participant_id'], $next['participant_id']);
        $this->expectException(IdempotencyConflict::class);
        $this->provision();
    }

    public function test_authorized_package_or_source_change_cannot_reuse_idempotency_key(): void
    {
        $this->provision();
        $source = $this->source->replicate();
        $source->source_system = 'SECOND';
        $source->save();
        $package = $this->package->replicate();
        $package->code = 'SECOND';
        $package->save();
        $package->items()->create(['test_type' => 'ist', 'sort_order' => 1]);
        $package->items()->create(['test_type' => 'dass21', 'sort_order' => 2]);
        $this->source->update(['allowed_assessment_packages' => ['P9_PACKAGE', 'SECOND']]);
        foreach ([['sourceSystem' => 'SECOND'], ['assessmentPackageCode' => 'SECOND']] as $override) {
            try {
                $this->provision([...$this->payload(), ...$override]);
                $this->fail('Conflict expected');
            } catch (IdempotencyConflict) {
                $this->assertDatabaseCount('participants', 1);
                $this->assertDatabaseCount('assessment_participants', 1);
            }
        }
    }

    public function test_selected_payer_change_due_to_policy_is_not_silent_replay(): void
    {
        $this->provision();
        $this->source->update(['locked_payer_type' => 'organization']);
        $this->expectException(IdempotencyConflict::class);
        $this->provision();
    }

    #[DataProvider('selectedLifecycleStates')]
    public function test_identical_provisioning_retry_preserves_later_payer_profile_and_status(string $funding, string $status): void
    {
        $first = $this->provision();
        $attempt = AssessmentParticipant::sole();
        $this->assertNull($attempt->funding_mode);
        $originalHash = $attempt->request_hash;
        Participant::sole()->update([
            'full_name' => 'Completed Person', 'gender' => 'female', 'birth_date' => '2000-01-01',
            'education_level' => 'SMA', 'intended_field' => 'KAIGO', 'phone' => '628123456789',
            'email' => 'completed@example.test',
        ]);
        // Synthetic persisted lifecycle state, not a new payer/settlement/session writer.
        $attempt->update(['funding_mode' => $funding, 'assessment_status' => $status]);
        $storedAttempt = $attempt->fresh()->getAttributes();
        $storedParticipant = Participant::sole()->getAttributes();

        try {
            $retry = $this->provision();
        } catch (IdempotencyConflict) {
            $this->fail('Identical provisioning retry conflicts with a later persisted payer selection.');
        }

        $this->assertTrue($retry['replayed']);
        $this->assertSame($first['participant_id'], $retry['participant_id']);
        $this->assertSame($first['assessment_attempt_id'], $retry['assessment_attempt_id']);
        $this->assertSame($status, $retry['assessment_status']);
        $this->assertSame($originalHash, AssessmentParticipant::sole()->request_hash);
        $this->assertSame($storedAttempt, AssessmentParticipant::sole()->getAttributes());
        $this->assertSame($storedParticipant, Participant::sole()->getAttributes());
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('assessment_participants', 1);
        $this->assertNoSideEffects();
    }

    public static function selectedLifecycleStates(): iterable
    {
        foreach (['COMMERCIAL_SELF_PAY', 'INVOICED_TO_ORGANIZATION'] as $funding) {
            foreach (['PROVISIONED', 'READY', 'IN_PROGRESS', 'COMPLETED', 'UNDER_REVIEW', 'FINALIZED'] as $status) {
                yield $funding.' '.$status => [$funding, $status];
            }
        }
    }

    public function test_later_payer_selection_does_not_allow_changed_provisioning_payload(): void
    {
        $this->provision();
        AssessmentParticipant::sole()->update(['funding_mode' => 'COMMERCIAL_SELF_PAY']);
        $this->expectException(IdempotencyConflict::class);
        $this->provision([...$this->payload(), 'payerType' => 'self']);
    }

    public function test_later_payer_selection_does_not_bypass_disabled_source(): void
    {
        $this->provision();
        AssessmentParticipant::sole()->update(['funding_mode' => 'COMMERCIAL_SELF_PAY']);
        $this->source->update(['status' => 'SUSPENDED']);
        $this->expectException(IntegrationContractViolation::class);
        $this->expectExceptionMessage('SOURCE_NOT_ALLOWED');
        $this->provision();
    }

    public function test_initial_snapshot_distinguishes_initial_policy_from_later_choice(): void
    {
        DB::beginTransaction();
        try {
            $this->provision();
            $attempt = AssessmentParticipant::sole();
            $this->assertNull($attempt->funding_mode);
            $attempt->update(['funding_mode' => 'COMMERCIAL_SELF_PAY']);
            $this->source->update(['locked_payer_type' => 'self']);
            $afterLaterChoice = [
                'attempt' => $attempt->only(['request_hash', 'funding_mode', 'metadata']),
                'policy' => $this->source->fresh()->only(['allowed_payer_types', 'locked_payer_type']),
            ];
        } finally {
            DB::rollBack();
        }

        $this->source->refresh()->update(['locked_payer_type' => 'self']);
        $this->provision();
        $initialPolicyChoice = [
            'attempt' => AssessmentParticipant::sole()->only(['request_hash', 'funding_mode', 'metadata']),
            'policy' => $this->source->fresh()->only(['allowed_payer_types', 'locked_payer_type']),
        ];
        $this->assertSame($afterLaterChoice['policy'], $initialPolicyChoice['policy']);
        $this->assertSame($afterLaterChoice['attempt']['request_hash'], $initialPolicyChoice['attempt']['request_hash']);
        $this->assertSame($afterLaterChoice['attempt']['funding_mode'], $initialPolicyChoice['attempt']['funding_mode']);
        $this->assertArrayHasKey('checkout_initial_funding_mode', $afterLaterChoice['attempt']['metadata']);
        $this->assertNull($afterLaterChoice['attempt']['metadata']['checkout_initial_funding_mode']);
        $this->assertSame('COMMERCIAL_SELF_PAY', $initialPolicyChoice['attempt']['metadata']['checkout_initial_funding_mode']);
    }

    public function test_later_payer_selection_does_not_bypass_invalid_payer_policy(): void
    {
        $this->provision();
        AssessmentParticipant::sole()->update(['funding_mode' => 'COMMERCIAL_SELF_PAY']);
        $this->source->update(['allowed_payer_types' => []]);
        $this->expectException(IntegrationContractViolation::class);
        $this->expectExceptionMessage('PAYER_NOT_ALLOWED');
        $this->provision();
    }

    public function test_later_payer_selection_does_not_reopen_revoked_attempt(): void
    {
        $this->provision();
        $attempt = AssessmentParticipant::sole();
        $attempt->update(['funding_mode' => 'COMMERCIAL_SELF_PAY', 'assessment_status' => 'REVOKED', 'revoked_at' => now()]);
        $stored = $attempt->fresh()->getAttributes();
        try {
            $this->provision();
            $this->fail('Revoked attempt accepted');
        } catch (IntegrationContractViolation $exception) {
            $this->assertSame('ASSESSMENT_NOT_PROVISIONABLE', $exception->errorCode);
            $this->assertSame($stored, AssessmentParticipant::sole()->getAttributes());
            $this->assertDatabaseCount('participants', 1);
            $this->assertDatabaseCount('assessment_participants', 1);
            $this->assertNoSideEffects();
        }
    }

    public function test_lifecycle_matching_new_policy_does_not_hide_changed_initial_decision(): void
    {
        $this->provision();
        AssessmentParticipant::sole()->update(['funding_mode' => 'COMMERCIAL_SELF_PAY']);
        $this->source->update(['locked_payer_type' => 'self']);
        $this->expectException(IdempotencyConflict::class);
        $this->provision();
    }

    #[DataProvider('invalidInitialMetadata')]
    public function test_snapshot_missing_invalid_or_wrong_version_fails_without_mutation(?array $metadata): void
    {
        $input = [...$this->payload(), 'payerType' => 'self'];
        $this->provision($input);
        $attempt = AssessmentParticipant::sole();
        $attempt->update(['metadata' => $metadata]);
        $before = $attempt->fresh()->getAttributes();
        try {
            $this->provision($input);
            $this->fail('Invalid initial snapshot accepted');
        } catch (IdempotencyConflict) {
            $this->assertSame($before, AssessmentParticipant::sole()->getAttributes());
            $this->assertDatabaseCount('participants', 1);
            $this->assertDatabaseCount('assessment_participants', 1);
        }
    }

    public static function invalidInitialMetadata(): iterable
    {
        yield 'null metadata' => [null];
        yield 'missing snapshot' => [['checkout_contract_version' => 'checkout-v2']];
        yield 'missing version' => [['checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY']];
        yield 'wrong version' => [['checkout_contract_version' => 'v1', 'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY']];
        foreach (['self', 'SPONSORED', '', 0, true, false, [], ['funding' => 'COMMERCIAL_SELF_PAY']] as $index => $value) {
            yield 'invalid snapshot '.$index => [['checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => $value]];
        }
    }

    #[DataProvider('changedSelectedFunding')]
    public function test_initially_selected_funding_cannot_be_changed_or_cleared(string $initial, ?string $later): void
    {
        $input = [...$this->payload(), 'payerType' => $initial];
        $this->provision($input);
        AssessmentParticipant::sole()->update(['funding_mode' => $later]);
        $this->expectException(IdempotencyConflict::class);
        $this->provision($input);
    }

    public static function changedSelectedFunding(): iterable
    {
        yield ['self', null];
        yield ['self', 'INVOICED_TO_ORGANIZATION'];
        yield ['organization', null];
        yield ['organization', 'COMMERCIAL_SELF_PAY'];
    }

    public function test_initial_snapshot_cannot_be_supplied_by_input(): void
    {
        $this->expectException(ValidationException::class);
        $this->provision([...$this->payload(), 'metadata' => ['checkout_initial_funding_mode' => null]]);
    }

    public function test_unresolved_initial_snapshot_does_not_accept_legacy_funding_as_lifecycle_payer(): void
    {
        $this->provision();
        AssessmentParticipant::sole()->update(['funding_mode' => 'SPONSORED']);
        $this->expectException(IdempotencyConflict::class);
        $this->provision();
    }

    #[DataProvider('selectedPayers')]
    public function test_initial_selected_snapshot_is_preserved_by_valid_replay(string $payer, string $funding): void
    {
        $input = [...$this->payload(), 'payerType' => $payer];
        $first = $this->provision($input);
        AssessmentParticipant::sole()->update(['assessment_status' => 'COMPLETED']);
        $before = AssessmentParticipant::sole()->getAttributes();
        $this->assertSame($funding, AssessmentParticipant::sole()->metadata['checkout_initial_funding_mode']);
        $retry = $this->provision($input);
        $this->assertTrue($retry['replayed']);
        $this->assertSame($first['assessment_attempt_id'], $retry['assessment_attempt_id']);
        $this->assertSame('COMPLETED', $retry['assessment_status']);
        $this->assertSame($before, AssessmentParticipant::sole()->getAttributes());
    }

    public static function selectedPayers(): iterable
    {
        yield ['self', 'COMMERCIAL_SELF_PAY'];
        yield ['organization', 'INVOICED_TO_ORGANIZATION'];
    }

    #[DataProvider('badScopes')]
    public function test_invalid_scope_cannot_create_any_rows(array $override, string $error): void
    {
        try {
            $this->provision([...$this->payload(), ...$override]);
            $this->fail('Scope rejection expected');
        } catch (IntegrationContractViolation $e) {
            $this->assertSame($error, $e->errorCode);
            $this->assertDatabaseCount('participants', 0);
            $this->assertDatabaseCount('assessment_participants', 0);
        }
    }

    #[DataProvider('invalidPackageCompositions')]
    public function test_invalid_package_composition_cannot_provision(array $types): void
    {
        $this->package->items()->delete();
        foreach ($types as $index => $type) {
            $this->package->items()->create(['test_type' => $type, 'sort_order' => $index + 1]);
        }

        try {
            $this->provision();
            $this->fail('Package composition rejection expected');
        } catch (IntegrationContractViolation $exception) {
            $this->assertSame('PACKAGE_NOT_ALLOWED', $exception->errorCode);
            $this->assertDatabaseCount('participants', 0);
            $this->assertDatabaseCount('assessment_participants', 0);
        }
    }

    public static function invalidPackageCompositions(): iterable
    {
        yield 'missing DASS-21' => [['ist']];
        yield 'DASS-21 only' => [['dass21']];
    }

    public static function badScopes(): iterable
    {
        yield [['organizationCode' => 'OTHER'], 'INTEGRATION_CONTEXT_INVALID'];
        yield [['sourceSystem' => 'OTHER'], 'SOURCE_NOT_ALLOWED'];
        yield [['assessmentPackageCode' => 'OTHER'], 'PACKAGE_NOT_ALLOWED'];
    }

    public function test_deleted_participant_mapping_is_not_recreated(): void
    {
        $this->provision();
        Participant::sole()->delete();
        $this->expectException(IntegrationContractViolation::class);
        $this->expectExceptionMessage('IDENTITY_MAPPING_CONFLICT');
        $this->provision();
    }

    public function test_missing_key_and_missing_authenticated_client_fail_closed(): void
    {
        foreach (['key', 'client'] as $missing) {
            $request = $this->request();
            $missing === 'key' ? $request->headers->remove('Idempotency-Key') : $request->attributes->remove('integration_client');
            try {
                app(RlsContextRunner::class)->runAsService(fn () => app(ProvisionCheckoutParticipant::class)->handle($request));
                $this->fail('Missing authority accepted');
            } catch (IntegrationContractViolation) {
                $this->assertDatabaseCount('participants', 0);
            }
        }
    }

    public function test_new_attempt_fills_missing_values_but_cannot_replace_known_identity(): void
    {
        $this->provision();
        $input = [...$this->payload(), 'assessmentRoundId' => 'ROUND-2', 'profile' => ['fullName' => 'Known Person']];
        $this->provision($input, 'next');
        $this->assertSame('Known Person', Participant::sole()->full_name);
        $this->expectException(IntegrationContractViolation::class);
        $this->expectExceptionMessage('IDENTITY_PROFILE_CONFLICT');
        $this->provision([...$input, 'assessmentRoundId' => 'ROUND-3', 'profile' => ['fullName' => 'Different Person']], 'third');
    }

    public function test_reordered_payload_is_replay_but_colliding_key_and_logical_attempt_are_rejected(): void
    {
        $first = $this->provision();
        $reordered = array_reverse($this->payload(), true);
        $this->assertSame($first['assessment_attempt_id'], $this->provision($reordered)['assessment_attempt_id']);
        $second = [...$this->payload(), 'externalCandidateId' => 'SECOND'];
        $this->provision($second, 'second');
        $this->expectException(IdempotencyConflict::class);
        $this->provision($second);
    }

    public function test_authenticated_client_reassigned_to_another_tenant_is_rejected(): void
    {
        $request = $this->request();
        $other = Branch::create(['code' => 'OTHER', 'ref_code' => 'OTHER', 'name' => 'Other',
            'organization_code' => 'OTHER', 'display_name' => 'Other']);
        IntegrationClient::whereKey($this->client->id)->update(['organization_id' => $other->id]);
        $this->expectException(IntegrationContractViolation::class);
        $this->expectExceptionMessage('INTEGRATION_NOT_ALLOWED');
        app(RlsContextRunner::class)->runAsService(fn () => app(ProvisionCheckoutParticipant::class)->handle($request));
    }

    public function test_deleted_authenticated_client_is_rejected(): void
    {
        $request = $this->request();
        IntegrationClient::whereKey($this->client->id)->delete();
        $this->expectException(IntegrationContractViolation::class);
        $this->expectExceptionMessage('INTEGRATION_NOT_ALLOWED');
        app(RlsContextRunner::class)->runAsService(fn () => app(ProvisionCheckoutParticipant::class)->handle($request));
    }

    public function test_revoked_attempt_is_not_recreated_or_replayed(): void
    {
        $this->provision();
        AssessmentParticipant::sole()->update(['assessment_status' => 'REVOKED', 'revoked_at' => now()]);
        $this->expectException(IntegrationContractViolation::class);
        $this->expectExceptionMessage('ASSESSMENT_NOT_PROVISIONABLE');
        $this->provision();
    }

    private function assertNoSideEffects(): void
    {
        foreach (['entitlements', 'assessment_entitlements', 'assessment_charges', 'assessment_bills', 'assessment_bill_items',
            'orders', 'outbox_messages', 'consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function payload(): array
    {
        return ['contractVersion' => 'checkout-v2', 'sourceSystem' => 'P9_SOURCE', 'organizationCode' => 'P9',
            'externalCandidateId' => 'CANDIDATE-1', 'assessmentPackageCode' => 'P9_PACKAGE', 'profile' => []];
    }

    private function request(?array $input = null, string $key = 'p9-key'): ProvisionCheckoutParticipantRequest
    {
        $request = ProvisionCheckoutParticipantRequest::create('/_internal', 'POST', $input ?? $this->payload());
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->attributes->set('integration_client', $this->client);
        $request->headers->set('Idempotency-Key', $key);
        $request->validateResolved();

        return $request;
    }

    private function provision(?array $input = null, string $key = 'p9-key'): array
    {
        $request = $this->request($input, $key);

        return app(RlsContextRunner::class)->runAsService(fn () => app(ProvisionCheckoutParticipant::class)->handle($request));
    }
}
