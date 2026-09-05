<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Actions\Payments\ActivateSettledAssessment;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Data\Integrations\CheckoutSessionPrincipal;
use App\Data\Integrations\CheckoutZeroPriceResult;
use App\Enums\PayerType;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\PackageItem;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\ResolvePayerPolicy;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Explicit no-bill settlement for an authenticated checkout with an exact server-priced zero total. */
final readonly class SettleZeroPriceCheckout
{
    public function __construct(
        private RlsContextRunner $contexts,
        private CheckoutSessionLifecycle $sessions,
        private PreviewAssessmentBill $preview,
        private AssessmentPriceSnapshot $prices,
        private ResolvePayerPolicy $payerPolicy,
        private ActivateSettledAssessment $activation,
    ) {}

    public function execute(
        #[SensitiveParameter] CheckoutSessionMutationCredentials $credentials,
        bool $consultationRequested,
    ): CheckoutZeroPriceResult {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Zero-price checkout owns an empty context and transaction.');
        }

        try {
            return $this->contexts->runAsService(
                fn (): CheckoutZeroPriceResult => $this->settle($credentials, $consultationRequested),
            );
        } catch (InvalidCheckoutSession|DomainException|InvalidArgumentException) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
    }

    private function settle(
        #[SensitiveParameter] CheckoutSessionMutationCredentials $credentials,
        bool $consultationRequested,
    ): CheckoutZeroPriceResult {
        $principal = $this->sessions->lockMutation($credentials)->principal();
        [$organization, $client, $source, $package, $attempt, $participant] = $this->lockedGraph($principal);
        $now = $this->databaseNow();
        $this->assertCurrentConsents($participant, $now);

        $payer = match ($principal->fundingMode) {
            'COMMERCIAL_SELF_PAY' => PayerType::SelfPay,
            'INVOICED_TO_ORGANIZATION' => PayerType::Organization,
            default => throw new DomainException('ZERO_PRICE_PAYER_INVALID'),
        };
        $decision = $this->payerPolicy->resolve($organization, $client, $source, $package, $now, $payer->value);
        if ($decision->rejectionReason !== null || $decision->selectedPayerType !== $payer) {
            throw new DomainException('ZERO_PRICE_POLICY_INVALID');
        }

        $charges = AssessmentCharge::query()->where('assessment_participant_id', $attempt->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        if ($charges->count() > 1) {
            throw new DomainException('ZERO_PRICE_CHARGE_INVALID');
        }
        $charge = $charges->first();
        if ($charge instanceof AssessmentCharge) {
            return $this->replay($principal, $charge, $payer, $consultationRequested, $now);
        }
        if ($attempt->assessment_status !== 'PROVISIONED') {
            throw new DomainException('ZERO_PRICE_ATTEMPT_INVALID');
        }

        $selection = [['assessmentParticipantId' => $attempt->id,
            'consultationRequested' => $consultationRequested]];
        $preview = $this->preview->execute(
            $organization->id,
            $selection,
            $payer,
            $payer === PayerType::SelfPay ? $participant->id : null,
        );
        $item = is_array($preview['items'] ?? null) && count($preview['items']) === 1
            ? $preview['items'][0] : null;
        $snapshot = is_array($item) ? ($item['snapshot'] ?? null) : null;
        $policySnapshot = is_array($item) ? ($item['policySnapshot'] ?? null) : null;
        if (! is_array($item) || ! is_array($snapshot) || ! is_array($policySnapshot)
            || ($preview['totalAmount'] ?? null) !== ($snapshot['amount'] ?? null)) {
            throw new DomainException('ZERO_PRICE_PREVIEW_INVALID');
        }
        if ($snapshot['amount'] !== 0) {
            return new CheckoutZeroPriceResult('not_applicable', []);
        }
        if (($item['status'] ?? null) !== 'free' || ($preview['freeCount'] ?? null) !== 1
            || ($preview['paidCount'] ?? null) !== 0 || ($preview['canReserve'] ?? null) !== false
            || ($snapshot['currency'] ?? null) !== 'IDR'
            || ($snapshot['consultationRequested'] ?? null) !== $consultationRequested) {
            throw new DomainException('ZERO_PRICE_PREVIEW_INVALID');
        }

        $charge = AssessmentCharge::query()->create([
            'assessment_participant_id' => $attempt->id,
            'organization_id' => $organization->id,
            'participant_id' => $participant->id,
            'package_id' => $package->id,
            'payer_type' => $payer->value,
            'base_amount' => $snapshot['baseAmount'],
            'consultation_amount' => $snapshot['consultationAmount'],
            'consultation_requested' => $snapshot['consultationRequested'],
            'amount' => $snapshot['amount'],
            'currency' => $snapshot['currency'],
            'price_snapshot' => $snapshot,
            'policy_snapshot' => $policySnapshot,
            'free_settled_at' => $now,
        ]);
        $this->writeAudit($principal, $charge, $consultationRequested, $snapshot, $now);
        $activated = $this->activation->execute(
            new AssessmentPrincipal($participant->id, $organization->id, $attempt->id),
        );
        sort($activated);

        return new CheckoutZeroPriceResult('settled', $activated);
    }

    /** @return array{Branch,IntegrationClient,IntegrationSource,TestPackage,AssessmentParticipant,Participant} */
    private function lockedGraph(CheckoutSessionPrincipal $principal): array
    {
        $organization = Branch::query()->lockForUpdate()->find($principal->organizationId);
        $client = IntegrationClient::query()->where('organization_id', $principal->organizationId)
            ->lockForUpdate()->find($principal->integrationClientId);
        $source = IntegrationSource::query()->where('integration_client_id', $principal->integrationClientId)
            ->where('source_system', $principal->sourceSystem)->where('contract_version', 'checkout-v2')
            ->lockForUpdate()->find($principal->integrationSourceId);
        $package = TestPackage::query()->lockForUpdate()->find($principal->packageId);
        $items = PackageItem::query()->where('package_id', $principal->packageId)
            ->orderBy('id')->lockForUpdate()->get();
        $attempt = AssessmentParticipant::query()->where('organization_id', $principal->organizationId)
            ->where('participant_id', $principal->participantId)->where('package_id', $principal->packageId)
            ->where('integration_client_id', $principal->integrationClientId)
            ->where('source_system', $principal->sourceSystem)->lockForUpdate()
            ->find($principal->assessmentParticipantId);
        $participant = Participant::withTrashed()->where('branch_id', $principal->organizationId)
            ->lockForUpdate()->find($principal->participantId);
        if (! $organization instanceof Branch || ! $client instanceof IntegrationClient
            || ! $source instanceof IntegrationSource || ! $package instanceof TestPackage
            || ! $attempt instanceof AssessmentParticipant || ! $participant instanceof Participant
            || $participant->trashed() || $attempt->assessment_attempt_id !== $principal->assessmentAttemptId
            || $attempt->package_id !== $package->id || $items->isEmpty()) {
            throw new DomainException('ZERO_PRICE_SCOPE_INVALID');
        }
        $types = $items->pluck('test_type')->all();
        if (! in_array('dass21', $types, true) || array_diff($types, ['dass21']) === []) {
            throw new DomainException('ZERO_PRICE_PACKAGE_INVALID');
        }
        $package->setRelation('items', $items);

        return [$organization, $client, $source, $package, $attempt, $participant];
    }

    private function assertCurrentConsents(Participant $participant, CarbonImmutable $now): void
    {
        $records = DB::table('consent_records')->where('participant_id', $participant->id)
            ->whereIn('consent_type', ['psychotest', 'dass'])->orderBy('id')->lockForUpdate()->get();
        foreach (['psychotest', 'dass'] as $type) {
            $document = ConsentDocument::for($type);
            $matches = $records->filter(static fn (object $record): bool => $record->consent_type === $type
                && $record->status === 'accepted' && $record->document_version === $document->version
                && is_string($record->document_hash) && hash_equals($document->hash, $record->document_hash)
                && $record->consented_at !== null && CarbonImmutable::parse($record->consented_at)->lte($now)
                && $record->withdrawn_at === null);
            if ($matches->count() !== 1) {
                throw new DomainException('ZERO_PRICE_CONSENT_INVALID');
            }
        }
    }

    private function replay(CheckoutSessionPrincipal $principal, AssessmentCharge $charge, PayerType $payer,
        bool $consultationRequested, CarbonImmutable $now): CheckoutZeroPriceResult
    {
        $snapshot = $this->prices->fromCharge($charge, $consultationRequested);
        if ($charge->organization_id !== $principal->organizationId
            || $charge->participant_id !== $principal->participantId
            || $charge->package_id !== $principal->packageId
            || $charge->assessment_participant_id !== $principal->assessmentParticipantId
            || $charge->payer_type !== $payer->value || $charge->amount !== 0 || $charge->currency !== 'IDR'
            || $charge->free_settled_at === null || $charge->free_settled_at->gt($now)
            || DB::table('assessment_bill_items')->where('charge_id', $charge->id)->lockForUpdate()->exists()) {
            throw new DomainException('ZERO_PRICE_CHARGE_INVALID');
        }
        $this->assertPolicySnapshot($charge->policy_snapshot, $principal, $payer);
        $this->assertAudit($principal, $charge, $consultationRequested, $snapshot);
        $activated = $this->activation->execute(new AssessmentPrincipal(
            $principal->participantId,
            $principal->organizationId,
            $principal->assessmentParticipantId,
        ));
        sort($activated);

        return new CheckoutZeroPriceResult('settled', $activated);
    }

    /** @param array<string, mixed> $snapshot */
    private function assertPolicySnapshot(array $snapshot, CheckoutSessionPrincipal $principal, PayerType $payer): void
    {
        $allowed = $snapshot['allowedPayerTypes'] ?? null;
        if (count($snapshot) !== 7 || ($snapshot['organizationId'] ?? null) !== $principal->organizationId
            || ($snapshot['integrationClientId'] ?? null) !== $principal->integrationClientId
            || ($snapshot['sourceId'] ?? null) !== $principal->integrationSourceId
            || ($snapshot['contractVersion'] ?? null) !== 'checkout-v2'
            || ($snapshot['payerType'] ?? null) !== $payer->value || ! is_array($allowed)
            || ! array_is_list($allowed) || ! in_array($payer->value, $allowed, true)
            || count(array_unique($allowed)) !== count($allowed)
            || ! in_array($snapshot['lockedPayerType'] ?? null, [null, $payer->value], true)) {
            throw new DomainException('ZERO_PRICE_POLICY_SNAPSHOT_INVALID');
        }
        foreach ($allowed as $value) {
            if (! in_array($value, ['self', 'organization'], true)) {
                throw new DomainException('ZERO_PRICE_POLICY_SNAPSHOT_INVALID');
            }
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function writeAudit(CheckoutSessionPrincipal $principal, AssessmentCharge $charge,
        bool $consultationRequested, array $snapshot, CarbonImmutable $now): void
    {
        DB::table('audit_logs')->insert([
            'branch_id' => $principal->organizationId,
            'actor_type' => 'checkout_session',
            'actor_id' => $principal->sessionPublicId,
            'action' => 'assessment_charge.free_settled',
            'subject_type' => AssessmentCharge::class,
            'subject_id' => (string) $charge->id,
            'context' => json_encode($this->auditContext($consultationRequested, $snapshot), JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'expires_at' => $now->addYearsNoOverflow(2),
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    private function assertAudit(CheckoutSessionPrincipal $principal, AssessmentCharge $charge,
        bool $consultationRequested, array $snapshot): void
    {
        $audits = DB::table('audit_logs')->where('branch_id', $principal->organizationId)
            ->where('actor_type', 'checkout_session')->where('action', 'assessment_charge.free_settled')
            ->where('subject_type', AssessmentCharge::class)
            ->where('subject_id', (string) $charge->id)->orderBy('id')->lockForUpdate()->get();
        if ($audits->count() !== 1) {
            throw new DomainException('ZERO_PRICE_AUDIT_INVALID');
        }
        $actorId = $audits->sole()->actor_id;
        $actorSessions = is_string($actorId) ? DB::table('checkout_sessions')->where('public_id', $actorId)
            ->where('organization_id', $principal->organizationId)
            ->where('assessment_participant_id', $principal->assessmentParticipantId)
            ->where('participant_id', $principal->participantId)->where('package_id', $principal->packageId)
            ->where('integration_client_id', $principal->integrationClientId)
            ->where('integration_source_id', $principal->integrationSourceId)
            ->where('source_system', $principal->sourceSystem)->where('contract_version', 'checkout-v2')
            ->lockForUpdate()->limit(2)->get(['id']) : collect();
        if ($actorSessions->count() !== 1) {
            throw new DomainException('ZERO_PRICE_AUDIT_INVALID');
        }
        try {
            $context = json_decode((string) $audits->sole()->context, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DomainException('ZERO_PRICE_AUDIT_INVALID');
        }
        if ($context !== $this->auditContext($consultationRequested, $snapshot)) {
            throw new DomainException('ZERO_PRICE_AUDIT_INVALID');
        }
    }

    /** @param array<string, mixed> $snapshot
     * @return array{version:int,consultationRequested:bool,priceSnapshotHash:string}
     */
    private function auditContext(bool $consultationRequested, array $snapshot): array
    {
        return [
            'version' => 1,
            'consultationRequested' => $consultationRequested,
            'priceSnapshotHash' => hash('sha256', json_encode($snapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private function databaseNow(): CarbonImmutable
    {
        $row = DB::selectOne('SELECT CURRENT_TIMESTAMP AS current_time');
        $value = $row->current_time ?? null;
        if (! is_string($value)) {
            throw new LogicException('Database clock unavailable.');
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
