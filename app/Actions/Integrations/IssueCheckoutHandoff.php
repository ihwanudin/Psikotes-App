<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutHandoffIssueResult;
use App\Enums\CheckoutHandoffIntent;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\CheckoutHandoff;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;

/** Internal-only P13a issuer. It neither consumes handoffs nor creates checkout sessions. */
final readonly class IssueCheckoutHandoff
{
    private const string CONTRACT_VERSION = 'checkout-v2';

    private const string PURPOSE = 'checkout-handoff';

    private const string DESTINATION = 'integrated-checkout-session';

    public function __construct(private RlsContextRunner $contexts) {}

    public function execute(#[SensitiveParameter] CheckoutHandoffIssueInput $input): CheckoutHandoffIssueResult
    {
        if ($this->contexts->current()?->role !== 'service') {
            throw new LogicException('Checkout handoff issuance requires service authority.');
        }
        $this->assertInput($input);
        $ttl = $this->ttl();

        /** @var array{replayed:bool,reissueRequired:bool,publicId:string,issueNumber:int,expiresAt:CarbonInterface,rawToken:?string} $outcome */
        $outcome = DB::transaction(fn (): array => $this->issue($input, $ttl));

        return new CheckoutHandoffIssueResult(
            $outcome['replayed'],
            $outcome['reissueRequired'],
            $outcome['publicId'],
            $outcome['issueNumber'],
            $outcome['expiresAt'],
            $outcome['rawToken'],
        );
    }

    /** @return array{replayed:bool,reissueRequired:bool,publicId:string,issueNumber:int,expiresAt:CarbonInterface,rawToken:?string} */
    private function issue(CheckoutHandoffIssueInput $input, int $ttl): array
    {
        $now = $this->databaseNow();
        $attemptHint = AssessmentParticipant::query()->where('assessment_attempt_id', $input->assessmentAttemptId)
            ->first(['organization_id', 'package_id']);
        if ($attemptHint === null || $attemptHint->organization_id !== $input->authenticatedClient->organization_id) {
            throw new IntegrationContractViolation('HANDOFF_NOT_ALLOWED');
        }

        $organization = Branch::query()->lockForUpdate()->find($attemptHint->organization_id);
        if ($organization === null || ! $organization->is_active || $organization->status !== 'ACTIVE') {
            throw new IntegrationContractViolation('HANDOFF_NOT_ALLOWED');
        }
        $client = IntegrationClient::query()->where('organization_id', $organization->id)
            ->where('client_id', $input->authenticatedClient->client_id)
            ->lockForUpdate()->find($input->authenticatedClient->id);
        if ($client === null || ! $this->sameAuthenticatedClient($input->authenticatedClient, $client)
            || ! $client->enabled || ! $this->effective($client->effective_from, $client->effective_until, $now)) {
            throw new IntegrationContractViolation('HANDOFF_NOT_ALLOWED');
        }
        $source = IntegrationSource::query()->where('integration_client_id', $client->id)
            ->where('source_system', $input->sourceSystem)->where('contract_version', self::CONTRACT_VERSION)
            ->lockForUpdate()->first();
        if ($source === null || $source->status !== 'ACTIVE'
            || ! $this->effective($source->effective_from, $source->effective_until, $now)) {
            throw new IntegrationContractViolation('HANDOFF_NOT_ALLOWED');
        }
        $package = TestPackage::query()->lockForUpdate()->find($attemptHint->package_id);
        if ($package === null || ! $package->is_active
            || ! in_array($package->code, $source->allowed_assessment_packages, true)
            || $package->items()->lockForUpdate()->first() === null) {
            throw new IntegrationContractViolation('HANDOFF_NOT_ALLOWED');
        }
        $attempt = AssessmentParticipant::query()
            ->where('integration_client_id', $client->id)->where('organization_id', $organization->id)
            ->where('package_id', $package->id)->where('source_system', $source->source_system)
            ->where('assessment_attempt_id', $input->assessmentAttemptId)->lockForUpdate()->first();
        if ($attempt === null || $attempt->assessment_status !== 'PROVISIONED' || $attempt->revoked_at !== null
            || ! is_array($attempt->metadata)
            || ($attempt->metadata['checkout_contract_version'] ?? null) !== self::CONTRACT_VERSION) {
            throw new IntegrationContractViolation('HANDOFF_NOT_ALLOWED');
        }
        $participant = Participant::withTrashed()->where('branch_id', $organization->id)
            ->lockForUpdate()->find($attempt->participant_id);
        if ($participant === null || $participant->deleted_at !== null) {
            throw new IntegrationContractViolation('HANDOFF_NOT_ALLOWED');
        }

        /** @var Collection<int, CheckoutHandoff> $handoffs */
        $handoffs = CheckoutHandoff::query()->where('assessment_participant_id', $attempt->id)
            ->where('purpose', self::PURPOSE)->where('destination', self::DESTINATION)
            ->orderBy('issue_number')->orderBy('id')->lockForUpdate()->get();
        $this->assertHistory($handoffs, $attempt, $source);

        $idempotencyDigest = hash('sha256', $input->idempotencyKey());
        $requestHash = $this->requestHash($client, $source, $attempt, $input->intent);
        $priorRequest = CheckoutHandoff::query()->where('integration_client_id', $client->id)
            ->where('issue_idempotency_key_digest', $idempotencyDigest)->lockForUpdate()->first();
        if ($priorRequest !== null) {
            if (! $this->sameRequest($priorRequest, $source, $attempt, $requestHash)) {
                throw new IdempotencyConflict;
            }

            return $this->outcome($priorRequest, null, true, true);
        }

        $active = $handoffs->filter(fn (CheckoutHandoff $handoff): bool => $handoff->status === 'ISSUED'
            && $handoff->active_marker === true);
        if ($input->intent === CheckoutHandoffIntent::Issue) {
            if ($handoffs->isNotEmpty()) {
                throw new IntegrationContractViolation('HANDOFF_REISSUE_REQUIRED', 409);
            }
            $revokedPrevious = false;
        } else {
            if ($handoffs->isEmpty() || $active->count() !== 1) {
                throw new IntegrationContractViolation('HANDOFF_STATE_INVALID', 409);
            }
            $previous = $active->sole();
            if ($now->greaterThanOrEqualTo($previous->expires_at)) {
                $revokedPrevious = false;
                $previous->update([
                    'active_marker' => null, 'status' => 'EXPIRED', 'expired_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $revokedPrevious = true;
                $previous->update([
                    'active_marker' => null, 'status' => 'REVOKED', 'revoked_at' => $now,
                    'revocation_reason' => 'REISSUED', 'updated_at' => $now,
                ]);
            }
        }

        $lastHandoff = $handoffs->last();
        $issueNumber = $lastHandoff === null ? 1 : $lastHandoff->issue_number + 1;
        $rawToken = 'och1_'.bin2hex(random_bytes(32));
        $expiresAt = $now->addSeconds($ttl);
        $handoff = CheckoutHandoff::query()->create([
            'public_id' => (string) Str::ulid(), 'assessment_participant_id' => $attempt->id,
            'organization_id' => $organization->id, 'participant_id' => $participant->id,
            'package_id' => $package->id, 'integration_client_id' => $client->id,
            'integration_source_id' => $source->id, 'source_system' => $source->source_system,
            'contract_version' => self::CONTRACT_VERSION, 'purpose' => self::PURPOSE,
            'destination' => self::DESTINATION, 'token_digest' => hash('sha256', $rawToken),
            'active_marker' => true, 'status' => 'ISSUED', 'issue_number' => $issueNumber,
            'issue_idempotency_key_digest' => $idempotencyDigest, 'request_hash' => $requestHash,
            'issued_at' => $now, 'expires_at' => $expiresAt, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->audit($handoff, $attempt, $client, $now, $revokedPrevious);

        return $this->outcome($handoff, $rawToken, false, false);
    }

    private function assertInput(CheckoutHandoffIssueInput $input): void
    {
        if (! $input->authenticatedClient->exists || $input->authenticatedClient->id < 1
            || ! preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $input->assessmentAttemptId)
            || $input->sourceSystem === '' || strlen($input->sourceSystem) > 100
            || trim($input->sourceSystem) !== $input->sourceSystem
            || ! preg_match('/^ih1_[0-9a-f]{32}$/D', $input->idempotencyKey())) {
            throw new IntegrationContractViolation('HANDOFF_REQUEST_INVALID', 422);
        }
    }

    private function ttl(): int
    {
        if (config('assessment_integration.checkout_handoff.enabled') !== true) {
            throw new IntegrationContractViolation('HANDOFF_DISABLED');
        }
        $ttl = config('assessment_integration.checkout_handoff.ttl_seconds');
        if (! is_int($ttl) || $ttl < 60 || $ttl > 600) {
            throw new IntegrationContractViolation('HANDOFF_CONFIG_INVALID');
        }

        return $ttl;
    }

    private function databaseNow(): CarbonImmutable
    {
        $row = DB::selectOne('SELECT CURRENT_TIMESTAMP AS current_time');
        if ($row === null || (! is_string($row->current_time) && ! $row->current_time instanceof \DateTimeInterface)) {
            throw new LogicException('Database clock is unavailable.');
        }

        return CarbonImmutable::parse($row->current_time)->utc()->startOfSecond();
    }

    private function sameAuthenticatedClient(IntegrationClient $authenticated, IntegrationClient $persisted): bool
    {
        return $authenticated->id === $persisted->id
            && $authenticated->organization_id === $persisted->organization_id
            && hash_equals($authenticated->client_id, $persisted->client_id)
            && hash_equals($authenticated->credential_reference, $persisted->credential_reference);
    }

    private function effective(?CarbonInterface $from, ?CarbonInterface $until, CarbonInterface $now): bool
    {
        return ($from === null || $from->lessThanOrEqualTo($now))
            && ($until === null || $until->greaterThan($now));
    }

    /** @param Collection<int, CheckoutHandoff> $handoffs */
    private function assertHistory(Collection $handoffs, AssessmentParticipant $attempt,
        IntegrationSource $source): void
    {
        $active = 0;
        foreach ($handoffs as $index => $handoff) {
            $scopeValid = $handoff->assessment_participant_id === $attempt->id
                && $handoff->organization_id === $attempt->organization_id
                && $handoff->participant_id === $attempt->participant_id
                && $handoff->package_id === $attempt->package_id
                && $handoff->integration_client_id === $attempt->integration_client_id
                && $handoff->integration_source_id === $source->id
                && $handoff->source_system === $attempt->source_system
                && $handoff->contract_version === self::CONTRACT_VERSION
                && $handoff->issue_number === $index + 1
                && $handoff->expires_at->greaterThan($handoff->issued_at)
                && $handoff->expires_at->lessThanOrEqualTo($handoff->issued_at->addSeconds(600));
            $valid = match ($handoff->status) {
                'ISSUED' => $handoff->active_marker === true && $handoff->consumed_at === null
                    && $handoff->revoked_at === null && $handoff->expired_at === null
                    && $handoff->revocation_reason === null,
                'CONSUMED' => $handoff->active_marker === null && $handoff->consumed_at !== null
                    && $handoff->revoked_at === null && $handoff->expired_at === null
                    && $handoff->revocation_reason === null
                    && $handoff->consumed_at->greaterThanOrEqualTo($handoff->issued_at)
                    && $handoff->consumed_at->lessThan($handoff->expires_at),
                'REVOKED' => $handoff->active_marker === null && $handoff->consumed_at === null
                    && $handoff->revoked_at !== null && $handoff->expired_at === null
                    && $handoff->revoked_at->greaterThanOrEqualTo($handoff->issued_at)
                    && in_array($handoff->revocation_reason,
                        ['REISSUED', 'ATTEMPT_REVOKED', 'SOURCE_REVOKED', 'CLIENT_REVOKED'], true),
                'EXPIRED' => $handoff->active_marker === null && $handoff->consumed_at === null
                    && $handoff->revoked_at === null && $handoff->expired_at !== null
                    && $handoff->revocation_reason === null
                    && $handoff->expired_at->greaterThanOrEqualTo($handoff->expires_at),
                default => false,
            };
            if (! $scopeValid || ! $valid) {
                throw new IntegrationContractViolation('HANDOFF_STATE_INVALID', 409);
            }
            if ($handoff->status === 'ISSUED') {
                $active++;
            }
        }
        if ($active > 1) {
            throw new IntegrationContractViolation('HANDOFF_STATE_INVALID', 409);
        }
    }

    private function requestHash(IntegrationClient $client, IntegrationSource $source,
        AssessmentParticipant $attempt, CheckoutHandoffIntent $intent): string
    {
        return hash('sha256', json_encode([
            'assessmentAttemptId' => $attempt->assessment_attempt_id,
            'clientId' => $client->id,
            'contractVersion' => self::CONTRACT_VERSION,
            'destination' => self::DESTINATION,
            'intent' => $intent->value,
            'purpose' => self::PURPOSE,
            'sourceId' => $source->id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function sameRequest(CheckoutHandoff $handoff, IntegrationSource $source,
        AssessmentParticipant $attempt, string $requestHash): bool
    {
        return $handoff->assessment_participant_id === $attempt->id
            && $handoff->organization_id === $attempt->organization_id
            && $handoff->participant_id === $attempt->participant_id
            && $handoff->package_id === $attempt->package_id
            && $handoff->integration_source_id === $source->id
            && $handoff->source_system === $source->source_system
            && $handoff->contract_version === self::CONTRACT_VERSION
            && $handoff->purpose === self::PURPOSE && $handoff->destination === self::DESTINATION
            && hash_equals($handoff->request_hash, $requestHash);
    }

    private function audit(CheckoutHandoff $handoff, AssessmentParticipant $attempt,
        IntegrationClient $client, CarbonImmutable $now, bool $revokedPrevious): void
    {
        DB::table('audit_logs')->insert([
            'branch_id' => $attempt->organization_id, 'actor_type' => 'integration_client',
            'actor_id' => (string) $client->id,
            'action' => $handoff->issue_number === 1 ? 'checkout_handoff.issued' : 'checkout_handoff.reissued',
            'subject_type' => AssessmentParticipant::class, 'subject_id' => (string) $attempt->id,
            'context' => json_encode([
                'version' => 1, 'publicId' => $handoff->public_id, 'issueNumber' => $handoff->issue_number,
                'purpose' => self::PURPOSE, 'destination' => self::DESTINATION,
                'sourceSystem' => $handoff->source_system,
                'issuedAt' => $handoff->issued_at->toISOString(),
                'expiresAt' => $handoff->expires_at->toISOString(),
                'revokedPrevious' => $revokedPrevious,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $now, 'expires_at' => $now->addYearsNoOverflow(2),
        ]);
    }

    /** @return array{replayed:bool,reissueRequired:bool,publicId:string,issueNumber:int,expiresAt:CarbonInterface,rawToken:?string} */
    private function outcome(CheckoutHandoff $handoff, ?string $rawToken,
        bool $replayed, bool $reissueRequired): array
    {
        return [
            'replayed' => $replayed, 'reissueRequired' => $reissueRequired,
            'publicId' => $handoff->public_id, 'issueNumber' => $handoff->issue_number,
            'expiresAt' => $handoff->expires_at, 'rawToken' => $rawToken,
        ];
    }
}
