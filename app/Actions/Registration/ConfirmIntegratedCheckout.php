<?php

declare(strict_types=1);

namespace App\Actions\Registration;

use App\Actions\Payments\ActivateSettledAssessment;
use App\Data\Integrations\IntegratedCheckoutConfirmationInput;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\CheckoutHandoff;
use App\Models\CheckoutSession;
use App\Models\ConsentRecord;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutHandoffHistoryValidator;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal P15 writer; the HTTP adapter and production route remain unavailable. */
final readonly class ConfirmIntegratedCheckout
{
    private const string CONTRACT_VERSION = 'checkout-v2';

    public function __construct(
        private RlsContextRunner $contexts,
        private ActivateSettledAssessment $activation,
        private CheckoutHandoffHistoryValidator $history,
        private RetentionPolicy $retention,
    ) {}

    /** @return array{replayed:bool,profileFieldsCompleted:int,consentsRecorded:int,activatedTestTypes:list<string>} */
    public function execute(IntegratedCheckoutConfirmationInput $input): array
    {
        if (config('assessment_integration.checkout_session.http.confirmation.writer_enabled') !== true
            || $this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Integrated checkout confirmation is unavailable.');
        }
        $documents = ['psychotest' => ConsentDocument::for('psychotest'), 'dass' => ConsentDocument::for('dass')];
        foreach ($documents as $type => $document) {
            $supplied = $input->consents[$type] ?? null;
            if (trim($document->version) === '' || trim($document->title) === '' || trim($document->text) === ''
                || ($supplied !== null && (! hash_equals($document->version, $supplied['documentVersion'])
                    || ! hash_equals($document->hash, $supplied['documentHash'])))) {
                throw new DomainException('CHECKOUT_CONFIRMATION_CONFLICT');
            }
        }

        $written = $this->contexts->run(new RlsContext('service'), function () use ($input, $documents): array {
            $principal = $input->principal;
            $organization = Branch::query()->lockForUpdate()->find($principal->organizationId);
            $client = IntegrationClient::query()->where('organization_id', $principal->organizationId)
                ->lockForUpdate()->find($principal->integrationClientId);
            $source = IntegrationSource::query()->where('integration_client_id', $principal->integrationClientId)
                ->where('source_system', $principal->sourceSystem)->where('contract_version', self::CONTRACT_VERSION)
                ->lockForUpdate()->find($principal->integrationSourceId);
            $package = TestPackage::query()->lockForUpdate()->find($principal->packageId);
            $rawTypes = $package?->items()->orderBy('id')->lockForUpdate()->pluck('test_type')->all() ?? [];
            $types = [];
            foreach ($rawTypes as $type) {
                if (! is_string($type)) {
                    throw new DomainException('CHECKOUT_CONFIRMATION_CONFLICT');
                }
                $types[] = $type;
            }
            $attempt = AssessmentParticipant::query()->where('organization_id', $principal->organizationId)
                ->where('participant_id', $principal->participantId)->where('package_id', $principal->packageId)
                ->where('integration_client_id', $principal->integrationClientId)
                ->where('source_system', $principal->sourceSystem)->lockForUpdate()
                ->find($principal->assessmentParticipantId);
            $participant = Participant::withTrashed()->where('branch_id', $principal->organizationId)
                ->lockForUpdate()->find($principal->participantId);
            $handoffs = CheckoutHandoff::query()->where('assessment_participant_id', $principal->assessmentParticipantId)
                ->where('purpose', 'checkout-handoff')->where('destination', 'integrated-checkout-session')
                ->orderBy('issue_number')->orderBy('id')->lockForUpdate()->get();
            $handoff = $handoffs->firstWhere('public_id', $principal->handoffPublicId);
            $session = CheckoutSession::query()->where('public_id', $principal->sessionPublicId)
                ->where('checkout_handoff_id', $handoff?->id)->lockForUpdate()->first();
            $now = $this->databaseNow();
            if (! $this->validScope($input, $organization, $client, $source, $package, $types,
                $attempt, $participant, $handoff, $session, $now)
                || ! $attempt instanceof AssessmentParticipant || ! $source instanceof IntegrationSource
                || ! $this->history->valid($handoffs, $attempt, $source)) {
                throw new DomainException('CHECKOUT_CONFIRMATION_CONFLICT');
            }

            $consentRecords = ConsentRecord::query()->where('participant_id', $participant->id)
                ->whereIn('consent_type', array_keys($documents))->orderBy('id')->lockForUpdate()->get();
            $auditHistory = DB::table('audit_logs')->where('branch_id', $organization->id)
                ->whereIn('action', ['checkout_session.established', 'checkout.confirmed'])
                ->where('subject_type', AssessmentParticipant::class)
                ->where('subject_id', (string) $attempt->id)->orderBy('id')->lockForUpdate()->get();
            $establishedActors = $this->establishedSessionActors(
                $auditHistory->where('action', 'checkout_session.established')->values(),
                $handoffs->map(
                    static fn (CheckoutHandoff $checkoutHandoff): string => $checkoutHandoff->public_id,
                )->values()->all(),
                $source->source_system,
                $now,
            );
            $requestHash = $input->requestHash();
            $history = $this->confirmationHistory(
                $auditHistory->where('action', 'checkout.confirmed')->values(), $establishedActors, $now);
            if ($history['latestRequestHash'] !== null
                && hash_equals($history['latestRequestHash'], $requestHash)
                && $this->missingProfile($participant) === []
                && $this->replayProfileMatches($participant, $input)) {
                $canonical = true;
                foreach ($documents as $type => $document) {
                    if (! $this->acceptedConsentRecord($consentRecords, $type, $document, $now)) {
                        $canonical = false;
                        break;
                    }
                }
                if ($canonical) {
                    return ['replayed' => true, 'profileFieldsCompleted' => 0, 'consentsRecorded' => 0,
                        'principal' => new AssessmentPrincipal($participant->id, $organization->id, $attempt->id)];
                }
            }

            $missing = $this->missingProfile($participant);
            $supplied = array_keys($input->profile);
            sort($supplied);
            if ($supplied !== $missing) {
                throw new DomainException('CHECKOUT_CONFIRMATION_CONFLICT');
            }
            $missingConsentTypes = [];
            foreach ($documents as $type => $document) {
                if (! $this->acceptedConsentRecord($consentRecords, $type, $document, $now)) {
                    $missingConsentTypes[] = $type;
                }
            }
            $suppliedConsentTypes = array_keys($input->consents);
            sort($missingConsentTypes);
            sort($suppliedConsentTypes);
            if ($suppliedConsentTypes !== $missingConsentTypes
                || ($missing === [] && $missingConsentTypes === [])) {
                throw new DomainException('CHECKOUT_CONFIRMATION_CONFLICT');
            }
            $attributes = $this->profileAttributes($input->profile);
            if ($attributes !== []) {
                $participant->fill($attributes);
                $participant->save();
            }
            $recorded = 0;
            foreach ($input->consents as $type => $_consent) {
                $document = $documents[$type];
                $record = $consentRecords->first(fn (ConsentRecord $candidate): bool => $candidate->consent_type === $type
                    && $candidate->document_version === $document->version);
                if ($record === null) {
                    ConsentRecord::query()->create(['participant_id' => $participant->id, 'consent_type' => $type,
                        'status' => 'accepted', 'document_version' => $document->version,
                        'document_hash' => $document->hash, 'consented_at' => $now, 'withdrawn_at' => null]);
                    $recorded++;
                } elseif (! $this->reacceptableConsentRecord($record, $document, $now)) {
                    throw new DomainException('CHECKOUT_CONFIRMATION_CONFLICT');
                } else {
                    $this->auditConsentReaccepted($organization->id, $session->public_id, $record, $now);
                    $record->forceFill([
                        'status' => 'accepted', 'consented_at' => $now, 'withdrawn_at' => null,
                    ])->save();
                    $recorded++;
                }
            }
            DB::table('audit_logs')->insert(['branch_id' => $organization->id, 'actor_type' => 'checkout_session',
                'actor_id' => $session->public_id, 'action' => 'checkout.confirmed',
                'subject_type' => AssessmentParticipant::class, 'subject_id' => (string) $attempt->id,
                'context' => json_encode($this->auditContext($input, $history['nextGeneration']), JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $now)]);

            return ['replayed' => false, 'profileFieldsCompleted' => count($missing), 'consentsRecorded' => $recorded,
                'principal' => new AssessmentPrincipal($participant->id, $organization->id, $attempt->id)];
        });

        $activated = $this->contexts->run(new RlsContext('service'),
            fn (): array => $this->activation->execute($written['principal']));
        sort($activated);

        return ['replayed' => $written['replayed'],
            'profileFieldsCompleted' => $written['profileFieldsCompleted'],
            'consentsRecorded' => $written['consentsRecorded'], 'activatedTestTypes' => $activated];
    }

    /** @param list<string> $types */
    private function validScope(IntegratedCheckoutConfirmationInput $input, ?Branch $organization,
        ?IntegrationClient $client, ?IntegrationSource $source, ?TestPackage $package, array $types,
        ?AssessmentParticipant $attempt, ?Participant $participant, ?CheckoutHandoff $handoff,
        ?CheckoutSession $session, CarbonImmutable $now): bool
    {
        $principal = $input->principal;
        $allowedPackages = $source?->getAttribute('allowed_assessment_packages');

        return $organization !== null && $organization->status === 'ACTIVE' && $organization->is_active
            && $client !== null && $client->enabled && $this->effective($client->effective_from, $client->effective_until, $now)
            && $source !== null && $source->status === 'ACTIVE' && $this->effective($source->effective_from, $source->effective_until, $now)
            && $package !== null && $package->is_active && is_int($package->amount) && $package->amount >= 0
            && $package->currency === 'IDR' && is_array($allowedPackages)
            && in_array($package->code, $allowedPackages, true)
            && in_array('dass21', $types, true) && array_diff($types, ['dass21']) !== []
            && $attempt !== null && $attempt->assessment_attempt_id === $principal->assessmentAttemptId
            && $attempt->funding_mode === $principal->fundingMode
            && is_array($attempt->metadata) && ($attempt->metadata['checkout_contract_version'] ?? null) === self::CONTRACT_VERSION
            && in_array($attempt->assessment_status, ['PROVISIONED', 'READY'], true)
            && $attempt->revoked_at === null && $attempt->finalized_at === null
            && $participant !== null && $participant->deleted_at === null && $participant->package_id === $package->id
            && $handoff !== null && $handoff->status === 'CONSUMED' && $handoff->consumed_at !== null
            && $handoff->organization_id === $organization->id && $handoff->participant_id === $participant->id
            && $handoff->package_id === $package->id && $handoff->integration_client_id === $client->id
            && $handoff->integration_source_id === $source->id && $handoff->source_system === $source->source_system
            && $handoff->contract_version === self::CONTRACT_VERSION
            && $session !== null && $session->status === 'ACTIVE' && $session->active_marker === true
            && $session->revoked_at === null && $session->expired_at === null && $session->revocation_reason === null
            && $session->assessment_participant_id === $attempt->id && $session->organization_id === $organization->id
            && $session->participant_id === $participant->id && $session->package_id === $package->id
            && $session->integration_client_id === $client->id && $session->integration_source_id === $source->id
            && $session->source_system === $source->source_system && $session->contract_version === self::CONTRACT_VERSION
            && $now->lessThan($session->idle_expires_at) && $now->lessThan($session->absolute_expires_at);
    }

    /** @return list<string> */
    private function missingProfile(Participant $participant): array
    {
        $map = ['fullName' => 'full_name', 'birthDate' => 'birth_date', 'gender' => 'gender',
            'educationLevel' => 'education_level', 'intendedField' => 'intended_field', 'phone' => 'phone'];
        $missing = [];
        foreach ($map as $input => $attribute) {
            if ($participant->getAttribute($attribute) === null) {
                $missing[] = $input;
            }
        }
        sort($missing);

        return $missing;
    }

    /** @param array<string, string> $profile
     * @return array<string, string>
     */
    private function profileAttributes(array $profile): array
    {
        $mapped = [];
        foreach (['fullName' => 'full_name', 'birthDate' => 'birth_date', 'educationLevel' => 'education_level',
            'intendedField' => 'intended_field', 'phone' => 'phone'] as $input => $attribute) {
            if (isset($profile[$input])) {
                $mapped[$attribute] = $profile[$input];
            }
        }
        if (isset($profile['gender'])) {
            $mapped['gender'] = match ($profile['gender']) {
                'FEMALE' => 'female', 'MALE' => 'male', default => throw new DomainException('CHECKOUT_CONFIRMATION_CONFLICT'),
            };
        }

        return $mapped;
    }

    /** @return array{version:int,generation:int,sessionPublicId:string,requestHash:string} */
    private function auditContext(IntegratedCheckoutConfirmationInput $input, int $generation): array
    {
        return ['version' => 2, 'generation' => $generation, 'sessionPublicId' => $input->principal->sessionPublicId,
            'requestHash' => $input->requestHash()];
    }

    /** @param Collection<int, \stdClass> $audits
     * @param  array<string, array{establishedAt: CarbonImmutable, absoluteExpiresAt: CarbonImmutable}>  $establishedActors
     * @return array{nextGeneration:int,latestRequestHash:?string}
     */
    private function confirmationHistory(Collection $audits, array $establishedActors, CarbonImmutable $now): array
    {
        $generation = 0;
        $latestHash = null;
        $previousAt = null;
        foreach ($audits as $audit) {
            $generation++;
            $row = (array) $audit;
            $actorId = $row['actor_id'] ?? null;
            $sessionInterval = is_string($actorId) ? ($establishedActors[$actorId] ?? null) : null;
            $occurredAt = $this->auditTimestamp($row['occurred_at'] ?? null);
            try {
                $context = json_decode((string) ($row['context'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new DomainException('CHECKOUT_CONFIRMATION_CONFLICT');
            }
            if (($row['actor_type'] ?? null) !== 'checkout_session' || ! is_string($actorId)
                || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $actorId) !== 1
                || $sessionInterval === null
                || ! is_array($context)
                || array_keys($context) !== ['version', 'generation', 'sessionPublicId', 'requestHash']
                || ($context['version'] ?? null) !== 2 || ($context['generation'] ?? null) !== $generation
                || ($context['sessionPublicId'] ?? null) !== $actorId
                || ! is_string($context['requestHash'] ?? null)
                || preg_match('/^[0-9a-f]{64}$/D', $context['requestHash']) !== 1
                || $occurredAt === null || $occurredAt->greaterThan($now)
                || $occurredAt->lessThan($sessionInterval['establishedAt'])
                || $occurredAt->greaterThanOrEqualTo($sessionInterval['absoluteExpiresAt'])
                || ($previousAt !== null && $occurredAt->lessThan($previousAt))) {
                throw new DomainException('CHECKOUT_CONFIRMATION_CONFLICT');
            }
            $previousAt = $occurredAt;
            $latestHash = $context['requestHash'];
        }

        return ['nextGeneration' => $generation + 1, 'latestRequestHash' => $latestHash];
    }

    /** @param Collection<int, \stdClass> $audits
     * @param  array<int, string>  $handoffPublicIds
     * @return array<string, array{establishedAt: CarbonImmutable, absoluteExpiresAt: CarbonImmutable}>
     */
    private function establishedSessionActors(Collection $audits, array $handoffPublicIds, string $sourceSystem,
        CarbonImmutable $now): array
    {
        $actors = [];
        $handoffs = [];
        foreach ($audits as $audit) {
            $row = (array) $audit;
            $actorId = $row['actor_id'] ?? null;
            $occurredAt = $this->auditTimestamp($row['occurred_at'] ?? null);
            try {
                $context = json_decode((string) ($row['context'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new DomainException('CHECKOUT_CONFIRMATION_CONFLICT');
            }
            $handoffPublicId = is_array($context) ? ($context['handoffPublicId'] ?? null) : null;
            $establishedAt = is_array($context) ? $this->auditTimestamp($context['establishedAt'] ?? null) : null;
            $idleExpiresAt = is_array($context) ? $this->auditTimestamp($context['idleExpiresAt'] ?? null) : null;
            $absoluteExpiresAt = is_array($context) ? $this->auditTimestamp($context['absoluteExpiresAt'] ?? null) : null;
            if (($row['actor_type'] ?? null) !== 'checkout_session' || ! is_string($actorId)
                || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $actorId) !== 1
                || isset($actors[$actorId]) || ! is_array($context)
                || array_keys($context) !== ['version', 'sessionPublicId', 'handoffPublicId', 'sourceSystem',
                    'establishedAt', 'idleExpiresAt', 'absoluteExpiresAt']
                || ($context['version'] ?? null) !== 1 || ($context['sessionPublicId'] ?? null) !== $actorId
                || ! is_string($handoffPublicId)
                || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $handoffPublicId) !== 1
                || ! in_array($handoffPublicId, $handoffPublicIds, true) || isset($handoffs[$handoffPublicId])
                || ($context['sourceSystem'] ?? null) !== $sourceSystem
                || $occurredAt === null || $establishedAt === null || ! $occurredAt->equalTo($establishedAt)
                || $establishedAt->greaterThan($now) || $idleExpiresAt === null || $absoluteExpiresAt === null
                || ! $idleExpiresAt->greaterThan($establishedAt)
                || $absoluteExpiresAt->lessThan($idleExpiresAt)) {
                throw new DomainException('CHECKOUT_CONFIRMATION_CONFLICT');
            }
            $actors[$actorId] = [
                'establishedAt' => $establishedAt,
                'absoluteExpiresAt' => $absoluteExpiresAt,
            ];
            $handoffs[$handoffPublicId] = true;
        }

        return $actors;
    }

    private function auditTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! $value instanceof \DateTimeInterface) {
            return null;
        }
        if (is_string($value)) {
            if (preg_match(
                '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})[ T](?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)?$/D',
                $value,
                $parts,
            ) !== 1
                || ! checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])
                || (int) $parts['hour'] > 23 || (int) $parts['minute'] > 59 || (int) $parts['second'] > 59) {
                return null;
            }
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function replayProfileMatches(Participant $participant, IntegratedCheckoutConfirmationInput $input): bool
    {
        foreach ($this->profileAttributes($input->profile) as $attribute => $expected) {
            $stored = $attribute === 'birth_date' ? $participant->birth_date?->toDateString()
                : $participant->getAttribute($attribute);
            if ($stored !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @param Collection<int, ConsentRecord> $records */
    private function acceptedConsentRecord(Collection $records, string $type, ConsentDocument $document,
        CarbonInterface $now): bool
    {
        $record = $records->first(fn (ConsentRecord $candidate): bool => $candidate->consent_type === $type
            && $candidate->document_version === $document->version);
        $consentedAt = $record === null ? null : $this->consentRecordTimestamp($record, 'consented_at');

        return $record !== null && $record->status === 'accepted'
            && hash_equals($document->hash, $record->document_hash)
            && $consentedAt !== null && $consentedAt->lessThanOrEqualTo($now)
            && $record->getAttribute('withdrawn_at') === null;
    }

    private function reacceptableConsentRecord(ConsentRecord $record, ConsentDocument $document,
        CarbonInterface $now): bool
    {
        if (! hash_equals($document->hash, $record->document_hash)) {
            return false;
        }
        if ($record->status === 'declined') {
            return $record->getAttribute('consented_at') === null && $record->getAttribute('withdrawn_at') === null;
        }

        $consentedAt = $this->consentRecordTimestamp($record, 'consented_at');
        $withdrawnAt = $this->consentRecordTimestamp($record, 'withdrawn_at');

        return $record->status === 'withdrawn' && $consentedAt !== null && $withdrawnAt !== null
            && $consentedAt->lessThanOrEqualTo($withdrawnAt)
            && $withdrawnAt->lessThanOrEqualTo($now);
    }

    private function auditConsentReaccepted(int $organizationId, string $sessionPublicId, ConsentRecord $record,
        CarbonImmutable $now): void
    {
        DB::table('audit_logs')->insert([
            'branch_id' => $organizationId,
            'actor_type' => 'checkout_session',
            'actor_id' => $sessionPublicId,
            'action' => 'checkout.consent_reaccepted',
            'subject_type' => ConsentRecord::class,
            'subject_id' => (string) $record->id,
            'context' => json_encode([
                'version' => 1,
                'previousStatus' => $record->status,
                'previousConsentedAt' => $this->consentTimestamp(
                    $this->consentRecordTimestamp($record, 'consented_at')),
                'previousWithdrawnAt' => $this->consentTimestamp(
                    $this->consentRecordTimestamp($record, 'withdrawn_at')),
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $now),
        ]);
    }

    private function consentTimestamp(?CarbonInterface $timestamp): ?string
    {
        return $timestamp?->toImmutable()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function consentRecordTimestamp(ConsentRecord $record, string $attribute): ?CarbonImmutable
    {
        $value = $record->getAttribute($attribute);
        if ($value === null) {
            return null;
        }
        if (! is_string($value) && ! $value instanceof \DateTimeInterface) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function effective(?CarbonInterface $from, ?CarbonInterface $until, CarbonInterface $now): bool
    {
        return ($from === null || $from->lessThanOrEqualTo($now))
            && ($until === null || $until->greaterThan($now));
    }

    private function databaseNow(): CarbonImmutable
    {
        $clock = DB::getDriverName() === 'pgsql' ? 'clock_timestamp()::timestamptz(0)' : 'CURRENT_TIMESTAMP';
        $row = DB::selectOne("SELECT {$clock} AS current_time");
        if ($row === null || (! is_string($row->current_time) && ! $row->current_time instanceof \DateTimeInterface)) {
            throw new LogicException('Database clock is unavailable.');
        }

        return CarbonImmutable::parse($row->current_time)->utc()->startOfSecond();
    }
}
