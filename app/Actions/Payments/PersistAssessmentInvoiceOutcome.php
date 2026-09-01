<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Data\Payments\AssessmentInvoicePermit;
use App\Data\Payments\AssessmentInvoiceReconciliationPermit;
use App\Data\Payments\PaymentInvoice;
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
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/** Shared late-state fence for issuance and single-intent reconciliation. */
final readonly class PersistAssessmentInvoiceOutcome
{
    private const TOPIC = 'assessment.bill.invoice-issuance';

    public function __construct(private RlsContextRunner $contexts) {}

    /** @return array{decision: string, messageId: string} */
    public function execute(AssessmentInvoicePermit $permit, ?PaymentInvoice $invoice): array
    {
        return $this->contexts->runAsService(fn (): array => $this->persist($permit, $invoice));
    }

    /** @return array{decision: string, messageId: string} */
    public function executeReconciliation(AssessmentInvoiceReconciliationPermit $permit, ?PaymentInvoice $invoice,
        int $cooldownSeconds): array
    {
        if ($cooldownSeconds < 60 || $cooldownSeconds > 86400) {
            throw new LogicException('Invoice reconciliation configuration is invalid.');
        }

        return $this->contexts->runAsService(
            fn (): array => $this->persist($permit->invoice, $invoice, $permit, $cooldownSeconds),
        );
    }

    /** @return array{decision: string, messageId: string} */
    private function persist(AssessmentInvoicePermit $permit, ?PaymentInvoice $invoice,
        ?AssessmentInvoiceReconciliationPermit $reconciliation = null, ?int $cooldownSeconds = null): array
    {
        $organization = Branch::query()->lockForUpdate()->find($permit->organizationId);
        $clients = IntegrationClient::query()->where('organization_id', $permit->organizationId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $sources = IntegrationSource::query()->whereIn('integration_client_id', $clients->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $bill = AssessmentBill::query()->where('organization_id', $permit->organizationId)->lockForUpdate()->find($permit->billId);
        if ($organization === null || $bill === null) {
            throw new DomainException('INVOICE_RESULT_CONFLICT');
        }
        $items = AssessmentBillItem::query()->where('bill_id', $bill->id)->orderBy('id')->lockForUpdate()->get();
        $links = $permit->snapshot['items'] ?? null;
        if (! is_array($links) || ! array_is_list($links)) {
            throw new DomainException('INVOICE_RESULT_CONFLICT');
        }
        $attemptIds = array_column($links, 'assessmentParticipantId');
        $attempts = AssessmentParticipant::query()->where('organization_id', $permit->organizationId)->whereIn('id', $attemptIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $participants = Participant::query()->where('branch_id', $permit->organizationId)->whereIn('id', $attempts->pluck('participant_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $packages = TestPackage::query()->whereIn('id', $attempts->pluck('package_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $charges = AssessmentCharge::query()->whereIn('id', $items->pluck('charge_id'))->orderBy('assessment_participant_id')->lockForUpdate()->get()->keyBy('id');
        $method = PaymentMethod::query()->lockForUpdate()->find($bill->payment_method_id);
        $message = OutboxMessage::query()->where('message_id', $permit->messageId)->lockForUpdate()->sole();

        $this->assertPersistedState($permit, $bill, $items, $attempts, $participants, $packages, $charges, $clients, $sources, $method, $message);
        $processing = $bill->status === 'issuing' && $message->status === 'processing'
            && $message->last_error === null;
        $unknown = $bill->status === 'unknown' && $message->status === 'failed'
            && $message->last_error === 'INVOICE_OUTCOME_UNKNOWN';
        $at = now();
        $nextAt = null;
        if ($reconciliation !== null) {
            $databaseNow = $this->databaseNow();
            $expiry = $message->reconciliation_lease_expires_at;
            if (! Str::isUuid($reconciliation->leaseToken) || $reconciliation->lookupGeneration < 1
                || $message->reconciliation_lease_token !== $reconciliation->leaseToken
                || $message->reconciliation_lookup_attempts !== $reconciliation->lookupGeneration
                || $expiry === null || ! $expiry->equalTo($reconciliation->leaseExpiresAt->startOfSecond())
                || ! $expiry->greaterThan($databaseNow) || $cooldownSeconds === null) {
                throw new DomainException('INVOICE_RESULT_CONFLICT');
            }
            $nextAt = $databaseNow->addSeconds($cooldownSeconds);
        }
        if ($invoice === null) {
            if ($unknown) {
                if ($reconciliation !== null) {
                    DB::table('outbox_messages')->where('id', $message->id)->update([
                        'reconciliation_lease_token' => null,
                        'reconciliation_lease_expires_at' => null,
                        'reconciliation_next_at' => $nextAt,
                    ]);
                }

                return ['decision' => 'unknown', 'messageId' => $permit->messageId];
            }
            if (! $processing) {
                throw new DomainException('INVOICE_RESULT_CONFLICT');
            }
            $bill->update(['status' => 'unknown']);
            $message->forceFill(['status' => 'failed', 'last_error' => 'INVOICE_OUTCOME_UNKNOWN', 'updated_at' => $at,
                ...($reconciliation === null ? [] : ['reconciliation_lease_token' => null,
                    'reconciliation_lease_expires_at' => null, 'reconciliation_next_at' => $nextAt])])->save();
            $this->audit($permit, 'assessment_bill.invoice_unknown', $at);

            return ['decision' => 'unknown', 'messageId' => $permit->messageId];
        }
        if ((! $processing && ! $unknown) || $bill->paid_at !== null
            || $bill->gateway_ref !== null || $bill->invoice_url !== null) {
            throw new DomainException('INVOICE_RESULT_CONFLICT');
        }
        $bill->update(['status' => 'pending', 'gateway_ref' => $invoice->providerReference,
            'invoice_url' => $invoice->paymentUrl, 'expires_at' => $invoice->expiresAt]);
        $message->forceFill(['status' => 'processed', 'processed_at' => $at, 'last_error' => null, 'updated_at' => $at,
            'reconciliation_lease_token' => null, 'reconciliation_lease_expires_at' => null,
            'reconciliation_next_at' => null])->save();
        $this->audit($permit, 'assessment_bill.invoice_issued', $at,
            ['providerReferenceHash' => hash('sha256', $invoice->providerReference)]);

        return ['decision' => 'issued', 'messageId' => $permit->messageId];
    }

    private function databaseNow(): CarbonImmutable
    {
        $row = (array) DB::selectOne('SELECT CURRENT_TIMESTAMP AS reconciliation_now');
        $value = $row['reconciliation_now'] ?? null;
        if (! is_string($value)) {
            throw new RuntimeException('Database clock did not return a timestamp.');
        }

        return CarbonImmutable::parse($value)->utc()->startOfSecond();
    }

    /** @param Collection<int, AssessmentBillItem> $items
     * @param  Collection<int, AssessmentParticipant>  $attempts
     * @param  Collection<int, Participant>  $participants
     * @param  Collection<int, TestPackage>  $packages
     * @param  Collection<int, AssessmentCharge>  $charges
     * @param  Collection<int, IntegrationClient>  $clients
     * @param  Collection<int, IntegrationSource>  $sources
     */
    private function assertPersistedState(AssessmentInvoicePermit $permit, AssessmentBill $bill, Collection $items,
        Collection $attempts, Collection $participants, Collection $packages, Collection $charges, Collection $clients,
        Collection $sources, ?PaymentMethod $method, OutboxMessage $message): void
    {
        $snapshot = $permit->snapshot;
        if ($message->topic !== self::TOPIC || $message->aggregate_type !== AssessmentBill::class
            || $message->aggregate_id !== (string) $bill->id || $message->attempts !== 1 || $message->processed_at !== null
            || hash('sha256', $this->canonical($message->payload)) !== $permit->payloadDigest
            || $bill->organization_id !== $permit->organizationId || $bill->id !== $permit->billId
            || $bill->public_reference !== $permit->merchantReference || $bill->amount !== $permit->amount
            || $bill->currency !== $permit->currency || $bill->item_count !== ($snapshot['itemCount'] ?? null)
            || $bill->payment_method_id !== ($snapshot['paymentMethodId'] ?? null) || $method?->code !== 'xendit'
            || $bill->payer_type !== ($snapshot['payerType'] ?? null) || $bill->payer_participant_id !== ($snapshot['payerParticipantId'] ?? null)
            || $items->count() !== $bill->item_count || $attempts->count() !== $items->count()
            || $charges->count() !== $items->count()) {
            throw new DomainException('INVOICE_RESULT_CONFLICT');
        }
        $rawLinks = $snapshot['items'] ?? null;
        if (! is_array($rawLinks) || ! array_is_list($rawLinks)) {
            throw new DomainException('INVOICE_RESULT_CONFLICT');
        }
        $links = [];
        foreach ($rawLinks as $link) {
            if (! is_array($link) || ! is_int($link['itemId'] ?? null) || isset($links[$link['itemId']])) {
                throw new DomainException('INVOICE_RESULT_CONFLICT');
            }
            $links[$link['itemId']] = $link;
        }
        $sum = 0;
        foreach ($items as $item) {
            $link = $links[$item->id] ?? null;
            $charge = $charges->get($item->charge_id);
            $attempt = is_array($link) ? $attempts->get($link['assessmentParticipantId'] ?? null) : null;
            $participant = $attempt === null ? null : $participants->get($attempt->participant_id);
            $package = $attempt === null ? null : $packages->get($attempt->package_id);
            $client = $attempt === null ? null : $clients->get($attempt->integration_client_id);
            $source = $attempt === null ? null : $sources->first(fn (IntegrationSource $row): bool => $row->integration_client_id === $attempt->integration_client_id && $row->source_system === $attempt->source_system);
            $metadata = $attempt?->metadata;
            $initial = is_array($metadata) && ($metadata['checkout_contract_version'] ?? null) === 'checkout-v2'
                && array_key_exists('checkout_initial_funding_mode', $metadata)
                ? $metadata['checkout_initial_funding_mode'] : false;
            if (! is_array($link) || $charge === null || $attempt === null || $participant === null || $package === null || $client === null || $source === null
                || ! in_array($initial, [null, 'COMMERCIAL_SELF_PAY', 'INVOICED_TO_ORGANIZATION'], true)
                || $item->organization_id !== $permit->organizationId || $item->participant_id !== $participant->id
                || $item->payer_type !== $bill->payer_type || $item->payer_participant_id !== $bill->payer_participant_id
                || $charge->organization_id !== $permit->organizationId || $charge->participant_id !== $participant->id
                || $charge->package_id !== $package->id || $charge->payer_type !== $bill->payer_type
                || $charge->amount !== $item->amount || $charge->currency !== $item->currency
                || $this->canonical($link) !== $this->canonical(['itemId' => $item->id, 'chargeId' => $charge->id,
                    'assessmentParticipantId' => $attempt->id, 'participantId' => $participant->id, 'packageId' => $package->id,
                    'integrationClientId' => $client->id, 'sourceId' => $source->id, 'sourceSystem' => $source->source_system,
                    'amount' => $item->amount, 'currency' => $item->currency, 'initialFundingMode' => $initial,
                    'attemptIdentityHash' => $this->attemptHash($attempt), 'priceSnapshot' => $charge->price_snapshot,
                    'policySnapshot' => $charge->policy_snapshot])
                || $item->settled_at !== null || $charge->free_settled_at !== null) {
                throw new DomainException('INVOICE_RESULT_CONFLICT');
            }
            if ($sum > PHP_INT_MAX - $item->amount) {
                throw new DomainException('INVOICE_RESULT_CONFLICT');
            }
            $sum += $item->amount;
        }
        if ($sum !== $bill->amount || count($links) !== $items->count()) {
            throw new DomainException('INVOICE_RESULT_CONFLICT');
        }
    }

    private function attemptHash(AssessmentParticipant $attempt): string
    {
        return hash('sha256', $this->canonical([$attempt->assessment_attempt_id, $attempt->external_candidate_id,
            $attempt->external_process_id, $attempt->external_registration_id, $attempt->assessment_round_id,
            $attempt->logical_assessment_key, $attempt->idempotency_key, $attempt->request_hash]));
    }

    /** @param array<string, mixed> $extra */
    private function audit(AssessmentInvoicePermit $permit, string $action, mixed $at, array $extra = []): void
    {
        DB::table('audit_logs')->insert(['branch_id' => $permit->organizationId, 'actor_type' => 'service', 'actor_id' => null,
            'action' => $action, 'subject_type' => AssessmentBill::class, 'subject_id' => (string) $permit->billId,
            'context' => json_encode(['messageId' => $permit->messageId, ...$extra], JSON_THROW_ON_ERROR),
            'occurred_at' => $at, 'expires_at' => $at->copy()->addYears(2)]);
    }

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
