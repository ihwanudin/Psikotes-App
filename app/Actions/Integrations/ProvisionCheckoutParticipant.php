<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\PayerType;
use App\Http\Requests\ProvisionCheckoutParticipantRequest;
use App\Models\AssessmentCase;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutContractAdapter;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Internal only. Caller authenticates the integration request before establishing service context. */
final readonly class ProvisionCheckoutParticipant
{
    public function __construct(private RlsContextRunner $runner, private CheckoutContractAdapter $contract) {}

    /** @return array{participant_id:int, assessment_attempt_id:string, assessment_status:string, replayed:bool} */
    public function handle(ProvisionCheckoutParticipantRequest $request): array
    {
        if ($this->runner->current()?->role !== 'service') {
            throw new LogicException('Checkout provisioning requires service RLS context.');
        }
        $authenticated = $request->attributes->get('integration_client');
        if (! $authenticated instanceof IntegrationClient || ! $authenticated->exists) {
            throw new IntegrationContractViolation('INTEGRATION_NOT_ALLOWED');
        }
        $key = $request->idempotencyKey();
        if ($key === null) {
            throw new IntegrationContractViolation('IDEMPOTENCY_KEY_REQUIRED', 422);
        }
        $input = $request->validated();

        return DB::transaction(function () use ($authenticated, $key, $input): array {
            // Same organization mutex/order as reservation and activation; no external work inside.
            $organization = Branch::query()->lockForUpdate()->find($authenticated->organization_id);
            $client = IntegrationClient::query()->where('organization_id', $authenticated->organization_id)
                ->where('client_id', $authenticated->client_id)->lockForUpdate()->find($authenticated->id);
            if ($organization === null || $client === null) {
                throw new IntegrationContractViolation('INTEGRATION_NOT_ALLOWED');
            }
            $client->setRelation('organization', $organization);
            $source = IntegrationSource::query()->where('integration_client_id', $client->id)
                ->where('source_system', $input['sourceSystem'])->where('contract_version', CheckoutContractAdapter::VERSION)
                ->lockForUpdate()->first();
            if ($source === null) {
                throw new IntegrationContractViolation('SOURCE_NOT_ALLOWED');
            }
            $package = TestPackage::query()->where('code', $input['assessmentPackageCode'])->lockForUpdate()->first();
            if ($package === null) {
                throw new IntegrationContractViolation('PACKAGE_NOT_ALLOWED');
            }
            $package->setRelation('items', $package->items()->lockForUpdate()->get());
            try {
                TestPackage::canonicalComposition($package->items->pluck('test_type')->values()->all());
            } catch (DomainException) {
                throw new IntegrationContractViolation('PACKAGE_NOT_ALLOWED');
            }
            $payer = $this->contract->resolve($client, $source, $package, $input);
            $funding = match ($payer->selectedPayerType) {
                PayerType::SelfPay => 'COMMERCIAL_SELF_PAY',
                PayerType::Organization => 'INVOICED_TO_ORGANIZATION',
                null => null,
            };
            $hash = $this->hash($input);
            $logical = $this->hash([CheckoutContractAdapter::VERSION, $input['sourceSystem'],
                $input['externalCandidateId'], $input['externalProcessId'] ?? null,
                $input['assessmentRoundId'] ?? null, $input['assessmentPackageCode']]);
            $matches = AssessmentParticipant::query()->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('integration_client_id', $client->id)->where('idempotency_key', $key))
                ->orWhere(fn ($query) => $query->where('organization_id', $organization->id)->where('logical_assessment_key', $logical)))
                ->orderBy('id')->lockForUpdate()->limit(2)->get();
            if ($matches->isNotEmpty()) {
                $existing = $matches->first();
                $metadata = $existing->metadata;
                if ($matches->count() !== 1 || $existing->integration_client_id !== $client->id
                    || $existing->organization_id !== $organization->id || $existing->package_id !== $package->id
                    || $existing->source_system !== $source->source_system
                    || ! is_array($metadata) || ($metadata['checkout_contract_version'] ?? null) !== CheckoutContractAdapter::VERSION
                    || ! array_key_exists('checkout_initial_funding_mode', $metadata)
                    || ! in_array($metadata['checkout_initial_funding_mode'], [null, 'COMMERCIAL_SELF_PAY', 'INVOICED_TO_ORGANIZATION'], true)
                    || $metadata['checkout_initial_funding_mode'] !== $funding
                    || ! hash_equals($existing->request_hash, $hash)) {
                    throw new IdempotencyConflict;
                }
                // Initial policy decision is immutable; the lifecycle may select an initially unresolved payer.
                $lifecyclePayer = match ($existing->funding_mode) {
                    null => null,
                    'COMMERCIAL_SELF_PAY' => PayerType::SelfPay,
                    'INVOICED_TO_ORGANIZATION' => PayerType::Organization,
                    default => throw new IdempotencyConflict,
                };
                if (($funding !== null && $existing->funding_mode !== $funding)
                    || ($lifecyclePayer !== null && ! in_array($lifecyclePayer, $payer->allowedPayerTypes, true))) {
                    throw new IdempotencyConflict;
                }
                if ($existing->revoked_at !== null || in_array($existing->assessment_status, ['REVOKED', 'VOID'], true)) {
                    throw new IntegrationContractViolation('ASSESSMENT_NOT_PROVISIONABLE');
                }
                $this->participant($existing->participant_id, $organization->id);
                $this->assertCaseBinding($existing);

                return $this->result($existing, true);
            }

            // Exact external identity only; never search by name, phone, or email.
            $identities = AssessmentParticipant::query()->where('organization_id', $organization->id)
                ->where('source_system', $source->source_system)->where('external_candidate_id', $input['externalCandidateId'])
                ->orderBy('id')->lockForUpdate()->get()->pluck('participant_id')->unique();
            if ($identities->count() > 1) {
                throw new IntegrationContractViolation('IDENTITY_MAPPING_CONFLICT');
            }
            $profile = $this->profile($input['profile']);
            if ($identities->isEmpty()) {
                $participant = Participant::query()->create([
                    ...$profile, 'branch_id' => $organization->id, 'referral_branch_id' => $organization->id,
                    'referral_source' => 'manual', 'package_id' => $package->id,
                    'source_system' => $source->source_system, 'attribution_source' => $organization->ref_code,
                ]);
            } else {
                $participant = $this->participant($identities->first(), $organization->id);
                foreach ($profile as $field => $value) {
                    $stored = $field === 'birth_date' ? $participant->birth_date?->toDateString() : $participant->getAttribute($field);
                    if ($value !== null && $stored !== null && $stored !== $value) {
                        throw new IntegrationContractViolation('IDENTITY_PROFILE_CONFLICT');
                    }
                    if ($stored === null && $value !== null) {
                        $participant->setAttribute($field, $value);
                    }
                }
                if ($participant->isDirty()) {
                    $participant->save();
                }
            }
            $attemptId = (string) Str::ulid();
            $case = AssessmentCase::query()->create([
                'public_id' => $attemptId,
                'participant_id' => $participant->id,
                'organization_id' => $organization->id,
                'package_id' => $package->id,
                'origin' => 'INTEGRATED',
                'intended_field_snapshot' => $profile['intended_field'],
            ]);
            $attempt = AssessmentParticipant::query()->create([
                'assessment_case_id' => $case->id,
                'integration_client_id' => $client->id, 'organization_id' => $organization->id,
                'participant_id' => $participant->id, 'package_id' => $package->id,
                'assessment_attempt_id' => $attemptId, 'source_system' => $source->source_system,
                'external_candidate_id' => $input['externalCandidateId'],
                'external_process_id' => $input['externalProcessId'] ?? null,
                'external_registration_id' => $input['externalRegistrationId'] ?? null,
                'assessment_round_id' => $input['assessmentRoundId'] ?? null,
                'funding_mode' => $funding, 'assessment_status' => 'PROVISIONED', 'result_version' => 0,
                'idempotency_key' => $key, 'request_hash' => $hash, 'logical_assessment_key' => $logical,
                'metadata' => [...array_intersect_key($input['metadata'] ?? [], ['cohortCode' => true]),
                    'checkout_contract_version' => CheckoutContractAdapter::VERSION, 'checkout_initial_funding_mode' => $funding],
            ]);

            return $this->result($attempt, false);
        });
    }

    private function participant(int $id, int $organizationId): Participant
    {
        $participant = Participant::query()->where('branch_id', $organizationId)->lockForUpdate()->find($id);
        if ($participant === null) {
            throw new IntegrationContractViolation('IDENTITY_MAPPING_CONFLICT');
        }

        return $participant;
    }

    private function assertCaseBinding(AssessmentParticipant $attempt): void
    {
        $case = $attempt->assessmentCase()->first();
        if ($case === null
            || ! hash_equals($case->public_id, $attempt->assessment_attempt_id)
            || $case->participant_id !== $attempt->participant_id
            || $case->organization_id !== $attempt->organization_id
            || $case->package_id !== $attempt->package_id
            || $case->origin !== 'INTEGRATED') {
            throw new IdempotencyConflict;
        }
    }

    /** @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function profile(array $profile): array
    {
        return [
            'full_name' => $profile['fullName'] ?? null, 'birth_date' => $profile['birthDate'] ?? null,
            'gender' => match ($profile['gender'] ?? null) {
                'FEMALE' => 'female', 'MALE' => 'male', null => null,
                default => throw new IntegrationContractViolation('INVALID_PROFILE', 422),
            },
            'education_level' => $profile['educationLevel'] ?? null, 'intended_field' => $profile['intendedField'] ?? null,
            'email' => $profile['email'] ?? null, 'phone' => $profile['phone'] ?? null,
        ];
    }

    /** Canonical object key order only; absent and explicit-null input remain distinct for replay.
     * @param  array<array-key, mixed>  $input
     */
    private function hash(array $input): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            ksort($value);
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $normalize($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($normalize($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{participant_id:int, assessment_attempt_id:string, assessment_status:string, replayed:bool} */
    private function result(AssessmentParticipant $attempt, bool $replayed): array
    {
        return ['participant_id' => $attempt->participant_id, 'assessment_attempt_id' => $attempt->assessment_attempt_id,
            'assessment_status' => $attempt->assessment_status, 'replayed' => $replayed];
    }
}
