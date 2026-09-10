<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Enums\PayerType;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\OutboxMessage;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\ResolvePayerPolicy;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Internal intent only. Neither this result nor pending/0 authorizes a provider POST. */
final readonly class ClaimAssessmentBillInvoice
{
    private const TOPIC = 'assessment.bill.invoice-issuance';

    public function __construct(
        private AssessmentPriceSnapshot $prices,
        private ResolvePayerPolicy $policy,
        private RetentionPolicy $retention,
    ) {}

    /** @return array{decision: string, messageId: ?string} */
    public function execute(int $organizationId, int $billId): array
    {
        if (app(RlsContextRunner::class)->current()?->role !== 'service') {
            throw new LogicException('Invoice claim requires service RLS context.');
        }
        if ($organizationId < 1 || $billId < 1) {
            throw new DomainException('INVOICE_CLAIM_NOT_AVAILABLE');
        }

        return DB::transaction(function () use ($organizationId, $billId): array {
            // The organization mutex serializes absent intents, as in P7. Policy parents
            // precede attempts; bill/items precede attempt/participant/charge as in P8b.
            $organization = Branch::query()->lockForUpdate()->find($organizationId);
            $clients = IntegrationClient::query()->where('organization_id', $organizationId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sources = IntegrationSource::query()->whereIn('integration_client_id', $clients->keys())->orderBy('id')->lockForUpdate()->get()
                ->keyBy(fn (IntegrationSource $source): string => $source->integration_client_id.':'.$source->source_system);
            $bill = AssessmentBill::query()->where('organization_id', $organizationId)->lockForUpdate()->find($billId);
            if ($organization === null || $bill === null || config('assessment_integration.checkout.enabled') !== true) {
                throw new DomainException('INVOICE_CLAIM_NOT_AVAILABLE');
            }
            $this->assertBill($bill);
            $items = AssessmentBillItem::query()->where('bill_id', $billId)->orderBy('id')->lockForUpdate()->get();
            $attemptIds = AssessmentCharge::query()->whereIn('id', $items->pluck('charge_id'))->pluck('assessment_participant_id');
            $attempts = AssessmentParticipant::query()->where('organization_id', $organizationId)->whereIn('id', $attemptIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $participants = Participant::query()->where('branch_id', $organizationId)->whereIn('id', $attempts->pluck('participant_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $packages = TestPackage::query()->whereIn('id', $attempts->pluck('package_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $charges = AssessmentCharge::query()->whereIn('id', $items->pluck('charge_id'))->orderBy('assessment_participant_id')->lockForUpdate()->get()->keyBy('id');
            $method = PaymentMethod::query()->lockForUpdate()->find($bill->payment_method_id);
            if ($method === null || ! $method->is_active || ! in_array($method->code, ['xendit', 'manual_transfer'], true)) {
                throw new DomainException('PAYMENT_METHOD_NOT_AVAILABLE');
            }
            $payer = PayerType::tryFrom($bill->payer_type);
            if ($payer === null) {
                throw new DomainException('INVOICE_PAYER_INVALID');
            }
            $total = 0;
            $links = [];
            foreach ($items as $item) {
                $charge = $charges->get($item->charge_id);
                $attempt = $charge === null ? null : $attempts->get($charge->assessment_participant_id);
                $participant = $attempt === null ? null : $participants->get($attempt->participant_id);
                $package = $attempt === null ? null : $packages->get($attempt->package_id);
                $client = $attempt === null ? null : $clients->get($attempt->integration_client_id);
                $source = $attempt === null ? null : $sources->get($attempt->integration_client_id.':'.$attempt->source_system);
                if ($charge === null || $attempt === null || $participant === null || $package === null || $client === null || $source === null
                    || $source->contract_version !== 'checkout-v2' || $charge->organization_id !== $organizationId
                    || $charge->participant_id !== $participant->id || $charge->package_id !== $package->id
                    || $item->organization_id !== $organizationId || $item->participant_id !== $participant->id
                    || $item->payer_type !== $bill->payer_type || $charge->payer_type !== $bill->payer_type
                    || $item->payer_participant_id !== $bill->payer_participant_id
                    || ($payer === PayerType::SelfPay && $participant->id !== $bill->payer_participant_id)
                    || $item->amount < 1 || $item->amount !== $charge->amount || $item->currency !== 'IDR' || $charge->currency !== 'IDR'
                    || $item->settled_at !== null || $charge->free_settled_at !== null
                    || $attempt->assessment_status !== 'PROVISIONED' || $attempt->revoked_at !== null || $attempt->finalized_at !== null) {
                    throw new DomainException('INVOICE_ITEMS_INVALID');
                }
                $initial = $this->initialFunding($attempt, $payer);
                if ($this->policy->resolve($organization, $client, $source, $package, now(), $payer->value)->rejectionReason !== null) {
                    throw new DomainException('INVOICE_POLICY_NOT_ALLOWED');
                }
                $price = $this->prices->fromCharge($charge, $charge->consultation_requested);
                $this->assertPolicySnapshot($charge->policy_snapshot, $organizationId, $client->id, $source->id, $payer);
                if ($total > PHP_INT_MAX - $item->amount) {
                    throw new DomainException('INVOICE_TOTAL_OVERFLOW');
                }
                $total += $item->amount;
                $links[] = ['itemId' => $item->id, 'chargeId' => $charge->id, 'assessmentParticipantId' => $attempt->id,
                    'participantId' => $participant->id, 'packageId' => $package->id, 'integrationClientId' => $client->id,
                    'sourceId' => $source->id, 'sourceSystem' => $source->source_system, 'amount' => $item->amount,
                    'currency' => $item->currency, 'initialFundingMode' => $initial,
                    // Detect any replayed provisioning identity change without copying candidate PII into outbox.
                    'attemptIdentityHash' => hash('sha256', $this->canonical([$attempt->assessment_attempt_id,
                        $attempt->external_candidate_id, $attempt->external_process_id, $attempt->external_registration_id,
                        $attempt->assessment_round_id, $attempt->logical_assessment_key, $attempt->idempotency_key, $attempt->request_hash])),
                    'priceSnapshot' => $price, 'policySnapshot' => $charge->policy_snapshot];
            }
            if ($total !== $bill->amount || $items->count() !== $bill->item_count || $charges->count() !== $items->count() || $attempts->count() !== $items->count()) {
                throw new DomainException('INVOICE_TOTAL_INVALID');
            }
            $snapshot = ['organizationId' => $organizationId, 'billId' => $billId, 'publicReference' => $bill->public_reference,
                'payerType' => $bill->payer_type, 'payerParticipantId' => $bill->payer_participant_id,
                'paymentMethodId' => $method->id, 'providerCode' => $method->code, 'amount' => $bill->amount, 'currency' => $bill->currency,
                'itemCount' => $bill->item_count, 'selectionHash' => $bill->selection_hash, 'requestHash' => $bill->request_hash,
                'idempotencyKey' => $bill->idempotency_key, 'items' => $links];
            $key = hash('sha256', self::TOPIC.':v1:'.$organizationId.':'.$billId);
            // Both identities catch a changed key OR a changed topic, rather than backfilling.
            $messages = OutboxMessage::query()->where(fn ($query) => $query->where('deduplication_key', $key)
                ->orWhere(fn ($query) => $query->where('topic', self::TOPIC)->where('aggregate_type', AssessmentBill::class)->where('aggregate_id', (string) $billId)))
                ->orderBy('id')->lockForUpdate()->get();
            if ($bill->status !== 'reserved') {
                if ($method->code !== 'xendit' || $messages->count() !== 1) {
                    throw new DomainException('INVOICE_INTENT_INVALID');
                }

                return $this->replay($bill, $messages->firstOrFail(), $snapshot, $key);
            }
            if ($messages->isNotEmpty()) {
                throw new DomainException('INVOICE_INTENT_INVALID');
            }
            if ($method->code === 'manual_transfer') {
                return ['decision' => 'not_applicable', 'messageId' => null];
            }
            $at = CarbonImmutable::instance(now())->utc()->startOfSecond();
            $hours = config('assessment_billing.invoice_duration_hours');
            if (! $this->validDuration($hours, $at)) {
                throw new LogicException('Invoice duration must be positive and representable.');
            }
            $messageId = (string) Str::ulid();
            $payload = $this->payload($snapshot, $messageId, $at, $hours);
            DB::table('outbox_messages')->insert(['message_id' => $messageId, 'deduplication_key' => $key, 'topic' => self::TOPIC,
                'aggregate_type' => AssessmentBill::class, 'aggregate_id' => (string) $billId,
                'payload' => $this->canonical($payload), 'status' => 'pending', 'attempts' => 0, 'available_at' => $at,
                'expires_at' => $at->addYearsNoOverflow(2), 'created_at' => $at, 'updated_at' => $at]);
            $bill->update(['status' => 'issuing']);
            DB::table('audit_logs')->insert(['branch_id' => $organizationId, 'actor_type' => 'service', 'actor_id' => null,
                'action' => 'assessment_bill.invoice_claimed', 'subject_type' => AssessmentBill::class, 'subject_id' => (string) $billId,
                'context' => json_encode(['messageId' => $messageId, 'reference' => $bill->public_reference, 'snapshotHash' => $payload['snapshotHash']], JSON_THROW_ON_ERROR),
                'occurred_at' => $at,
                'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $at)]);

            return ['decision' => 'claimed', 'messageId' => $messageId];
        });
    }

    private function assertBill(AssessmentBill $bill): void
    {
        if (! in_array($bill->status, ['reserved', 'issuing', 'unknown'], true) || $bill->amount < 1 || $bill->item_count < 1
            || $bill->currency !== 'IDR' || ! preg_match('/^AB_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $bill->public_reference)
            || ! preg_match('/^[a-f0-9]{64}$/D', $bill->selection_hash) || ! preg_match('/^[a-f0-9]{64}$/D', $bill->request_hash)
            || ! preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $bill->idempotency_key)
            || ! in_array($bill->payer_type, ['self', 'organization'], true)
            || ($bill->payer_type === 'organization' ? $bill->payer_participant_id !== null : ($bill->payer_participant_id === null || $bill->payer_participant_id < 1 || $bill->item_count !== 1))
            || $bill->gateway_ref !== null || $bill->invoice_url !== null || $bill->expires_at !== null || $bill->paid_at !== null) {
            throw new DomainException('INVOICE_BILL_INVALID');
        }
    }

    private function initialFunding(AssessmentParticipant $attempt, PayerType $payer): ?string
    {
        $metadata = $attempt->metadata;
        $funding = $payer === PayerType::SelfPay ? 'COMMERCIAL_SELF_PAY' : 'INVOICED_TO_ORGANIZATION';
        if (($metadata['checkout_contract_version'] ?? null) !== 'checkout-v2' || ! array_key_exists('checkout_initial_funding_mode', $metadata)
            || ! in_array($metadata['checkout_initial_funding_mode'], [null, 'COMMERCIAL_SELF_PAY', 'INVOICED_TO_ORGANIZATION'], true)
            || ($metadata['checkout_initial_funding_mode'] !== null && $metadata['checkout_initial_funding_mode'] !== $funding)
            || $attempt->funding_mode !== $funding) {
            throw new DomainException('INVOICE_FUNDING_INVALID');
        }

        return $metadata['checkout_initial_funding_mode'];
    }

    /** @param array<string, mixed> $snapshot */
    private function assertPolicySnapshot(array $snapshot, int $organizationId, int $clientId, int $sourceId, PayerType $payer): void
    {
        $allowed = $snapshot['allowedPayerTypes'] ?? null;
        $locked = $snapshot['lockedPayerType'] ?? null;
        if (! is_array($allowed) || ! array_is_list($allowed) || $allowed === []) {
            throw new DomainException('INVOICE_POLICY_SNAPSHOT_INVALID');
        }
        foreach ($allowed as $value) {
            if (! in_array($value, ['self', 'organization'], true)) {
                throw new DomainException('INVOICE_POLICY_SNAPSHOT_INVALID');
            }
        }
        if (count(array_unique($allowed)) !== count($allowed) || ! in_array($payer->value, $allowed, true)
            || ($locked !== null && $locked !== $payer->value)
            || $this->canonical($snapshot) !== $this->canonical(['organizationId' => $organizationId, 'integrationClientId' => $clientId,
                'sourceId' => $sourceId, 'contractVersion' => 'checkout-v2', 'allowedPayerTypes' => $allowed,
                'payerType' => $payer->value, 'lockedPayerType' => $locked])) {
            throw new DomainException('INVOICE_POLICY_SNAPSHOT_INVALID');
        }
    }

    /** @param array<string, mixed> $snapshot
     * @return array{decision: string, messageId: ?string}
     */
    private function replay(AssessmentBill $bill, OutboxMessage $message, array $snapshot, string $key): array
    {
        $payload = $message->payload;
        $claimed = $payload['claimedAt'] ?? null;
        $parsed = is_string($claimed) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $claimed)
            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $claimed, new \DateTimeZone('UTC')) : false;
        $at = $parsed === false ? null : CarbonImmutable::instance($parsed);
        $hours = $payload['invoiceDurationHours'] ?? null;
        if ($at === null || $this->stamp($at) !== $claimed || ! $this->validDuration($hours, $at)
            || $message->deduplication_key !== $key || $message->topic !== self::TOPIC || $message->aggregate_type !== AssessmentBill::class
            || $message->aggregate_id !== (string) $bill->id || ! Str::isUlid($message->message_id)
            || ! $message->available_at->equalTo($at) || ! $message->created_at?->equalTo($at)
            || ! $message->expires_at->equalTo($at->addYearsNoOverflow(2)) || $message->processed_at !== null
            || $this->canonical($payload) !== $this->canonical($this->payload($snapshot, $message->message_id, $at, $hours))) {
            throw new DomainException('INVOICE_INTENT_INVALID');
        }
        if ($bill->status === 'issuing' && $message->status === 'pending' && $message->attempts === 0 && $message->last_error === null) {
            return ['decision' => 'replayed', 'messageId' => $message->message_id];
        }
        if ($message->attempts === 1 && (($bill->status === 'issuing' && $message->status === 'processing')
            || ($bill->status === 'unknown' && in_array($message->status, ['processing', 'failed'], true)))) {
            return ['decision' => 'recovery_required', 'messageId' => $message->message_id];
        }

        throw new DomainException('INVOICE_INTENT_INVALID');
    }

    /** @phpstan-assert-if-true positive-int $hours */
    private function validDuration(mixed $hours, CarbonImmutable $at): bool
    {
        return is_int($hours) && $hours > 0 && $at->year >= 1 && $at->year <= 9997
            && $hours <= intdiv(253402300799 - $at->getTimestamp(), 3600);
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function payload(array $snapshot, string $messageId, CarbonImmutable $at, int $hours): array
    {
        return ['version' => 1, 'messageId' => $messageId, 'snapshot' => $snapshot, 'snapshotHash' => hash('sha256', $this->canonical($snapshot)),
            'claimedAt' => $this->stamp($at), 'invoiceDurationHours' => $hours, 'requestedExpiresAt' => $this->stamp($at->addHours($hours)),
            'description' => 'Psikotes LSI '.$snapshot['publicReference']];
    }

    private function stamp(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:s\Z');
    }

    /** JSON objects can be reordered by PostgreSQL; list ordering and scalar types remain strict. */
    private function canonical(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
