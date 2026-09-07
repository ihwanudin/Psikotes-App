<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AssessmentBill;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Read-only, single-reference evidence for an operator; never performs reconciliation. */
final class InspectAssessmentInvoiceRecoveryCommand extends Command
{
    private const string TOPIC = 'assessment.bill.invoice-issuance';

    protected $signature = 'assessment-bills:inspect-invoice-recovery {reference}';

    protected $description = 'Inspect one issuing or unknown assessment bill without changing state';

    public function handle(RlsContextRunner $contexts): int
    {
        $reference = $this->argument('reference');
        if (! preg_match('/^AB_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $reference)) {
            return $this->respond(['version' => 1, 'result' => 'invalid', 'code' => 'REFERENCE_INVALID'], self::INVALID);
        }

        try {
            return $contexts->runAsService(function () use ($reference): int {
                $observedAt = CarbonImmutable::instance(now())->utc()->startOfSecond();
                $first = $this->evidence($reference);
                if ($first['bill'] === null) {
                    return $this->respond(['version' => 1, 'result' => 'unavailable', 'code' => 'REFERENCE_NOT_AVAILABLE'], self::FAILURE);
                }
                if (! in_array($first['bill']['status'], ['issuing', 'unknown'], true)) {
                    return $this->respond(['version' => 1, 'result' => 'unavailable', 'code' => 'STATE_NOT_RECOVERABLE'], self::FAILURE);
                }

                $inspection = $this->inspect($first, $observedAt);
                if ($this->canonical($first) !== $this->canonical($this->evidence($reference))) {
                    $inspection['violations'][] = 'EVIDENCE_CHANGED';
                }
                $inspection['violations'] = array_values(array_unique($inspection['violations']));
                sort($inspection['violations']);
                if ($inspection['violations'] !== []) {
                    $inspection['result'] = 'escalate';
                    $inspection['action'] = 'allocation_invariant_breach';
                }

                return $this->respond($inspection, $inspection['violations'] === [] ? self::SUCCESS : self::FAILURE);
            });
        } catch (Throwable) {
            return $this->respond(['version' => 1, 'result' => 'unavailable', 'code' => 'INSPECTION_UNAVAILABLE'], self::FAILURE);
        }
    }

    /** @return array{bill: ?array<string, mixed>, intents: list<array<string, mixed>>, claimedCount: int,
     * claimed: ?array<string, mixed>, unknownCount: int, unknown: ?array<string, mixed>, issuedCount: int,
     * issued: ?array<string, mixed>}
     */
    private function evidence(string $reference): array
    {
        $bill = DB::table('assessment_bills')->where('public_reference', $reference)->first([
            'id', 'organization_id', 'payer_type', 'payer_participant_id', 'public_reference', 'amount', 'currency',
            'item_count', 'selection_hash', 'idempotency_key', 'request_hash', 'status', 'payment_method_id',
            'gateway_ref', 'invoice_url', 'expires_at', 'paid_at', 'proof_object_key', 'proof_uploaded_at',
        ]);
        if ($bill === null) {
            return ['bill' => null, 'intents' => [], 'claimedCount' => 0, 'claimed' => null,
                'unknownCount' => 0, 'unknown' => null, 'issuedCount' => 0, 'issued' => null];
        }

        $billRow = (array) $bill;
        $subject = (string) $billRow['id'];
        $intentRows = DB::table('outbox_messages')
            ->where('topic', self::TOPIC)->where('aggregate_type', AssessmentBill::class)
            ->where('aggregate_id', $subject)->orderBy('id')->limit(2)->get([
                'id', 'message_id', 'deduplication_key', 'aggregate_type', 'aggregate_id', 'payload', 'status',
                'attempts', 'available_at', 'processed_at', 'expires_at', 'last_error', 'created_at',
                'reconciliation_lease_token', 'reconciliation_lease_expires_at', 'reconciliation_next_at',
                'reconciliation_lookup_attempts',
            ]);
        $intents = [];
        foreach ($intentRows as $intentRow) {
            $intents[] = (array) $intentRow;
        }

        $audits = [];
        foreach (['assessment_bill.invoice_claimed', 'assessment_bill.invoice_unknown', 'assessment_bill.invoice_issued'] as $action) {
            $query = DB::table('audit_logs')->where('branch_id', $billRow['organization_id'])
                ->where('subject_type', AssessmentBill::class)->where('subject_id', $subject)->where('action', $action);
            $rows = $query->orderBy('id')->limit(2)->get(['actor_type', 'actor_id', 'context', 'occurred_at']);
            $count = $rows->count();
            $row = $count === 1 ? $rows->first() : null;
            $audits[$action] = ['count' => $count, 'row' => $row === null ? null : (array) $row];
        }

        return [
            'bill' => $billRow,
            'intents' => $intents,
            'claimedCount' => $audits['assessment_bill.invoice_claimed']['count'],
            'claimed' => $audits['assessment_bill.invoice_claimed']['row'],
            'unknownCount' => $audits['assessment_bill.invoice_unknown']['count'],
            'unknown' => $audits['assessment_bill.invoice_unknown']['row'],
            'issuedCount' => $audits['assessment_bill.invoice_issued']['count'],
            'issued' => $audits['assessment_bill.invoice_issued']['row'],
        ];
    }

    /** @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function inspect(array $evidence, CarbonImmutable $observedAt): array
    {
        $bill = $evidence['bill'];
        $state = $bill['status'];
        $intent = count($evidence['intents']) === 1 ? $evidence['intents'][0] : null;
        $violations = [];
        if ($intent === null) {
            $violations[] = 'INTENT_COUNT_INVALID';
        }
        if ($bill['gateway_ref'] !== null || $bill['invoice_url'] !== null || $bill['expires_at'] !== null
            || $bill['paid_at'] !== null || $bill['proof_object_key'] !== null || $bill['proof_uploaded_at'] !== null) {
            $violations[] = 'BILL_PROVIDER_STATE_INVALID';
        }

        $snapshotValid = $intent !== null && $this->canonicalIntentValid($bill, $intent, $evidence['claimed'], $observedAt);
        if (! $snapshotValid) {
            $violations[] = 'SNAPSHOT_BINDING_INVALID';
        }
        if ($evidence['claimedCount'] !== 1 || ! $this->auditEnvelopeValid($evidence['claimed'], $bill, $observedAt)) {
            $violations[] = 'CLAIM_AUDIT_INVALID';
        }

        $expectedIntent = $state === 'issuing'
            ? ['status' => 'processing', 'last_error' => null]
            : ['status' => 'failed', 'last_error' => 'INVOICE_OUTCOME_UNKNOWN'];
        if ($intent !== null && ($intent['status'] !== $expectedIntent['status'] || $intent['last_error'] !== $expectedIntent['last_error']
            || $intent['attempts'] !== 1 || $intent['processed_at'] !== null)) {
            $violations[] = 'INTENT_STATE_INVALID';
        }
        if ($intent !== null && (! is_int($intent['reconciliation_lookup_attempts'])
            || $intent['reconciliation_lookup_attempts'] < 0 || $intent['reconciliation_lookup_attempts'] > 100)) {
            $violations[] = 'RECONCILIATION_METADATA_INVALID';
        }

        $expectedUnknownCount = $state === 'unknown' ? 1 : 0;
        if ($evidence['unknownCount'] !== $expectedUnknownCount
            || ($expectedUnknownCount === 1 && (! $this->auditEnvelopeValid($evidence['unknown'], $bill, $observedAt)
                || $this->decodeObject($evidence['unknown']['context'] ?? null) !== ['messageId' => $intent['message_id'] ?? null]))) {
            $violations[] = 'OUTCOME_AUDIT_INVALID';
        }
        if ($evidence['issuedCount'] !== 0) {
            $violations[] = 'OUTCOME_AUDIT_INVALID';
        }
        foreach ([$evidence['claimed'], $evidence['unknown'], $evidence['issued']] as $audit) {
            if ($audit !== null && ! $this->timeAtOrBefore($audit['occurred_at'] ?? null, $observedAt)) {
                $violations[] = 'AUDIT_TIME_INVALID';
            }
        }

        [$leaseState, $leaseExpiry, $nextLookup, $leaseValid] = $this->lease($intent, $observedAt);
        if (! $leaseValid) {
            $violations[] = 'LEASE_STATE_INVALID';
        }
        $action = $state === 'issuing' ? 'pause_and_escalate' : match ($leaseState) {
            'active' => 'wait_for_active_lease',
            'expired' => 'escalate_expired_lease',
            default => $nextLookup !== null && CarbonImmutable::parse($nextLookup)->greaterThan($observedAt)
                ? 'wait_for_cooldown' : 'await_reviewed_reconciliation_wiring',
        };

        $messageId = $intent['message_id'] ?? null;
        $safeMessageId = is_string($messageId) && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $messageId) === 1
            ? $messageId : null;

        return [
            'version' => 1,
            'result' => 'consistent',
            'action' => $action,
            'reference' => $bill['public_reference'],
            'organizationId' => (int) $bill['organization_id'],
            'billState' => $state,
            'messageId' => $safeMessageId,
            'intentState' => $intent['status'] ?? null,
            'attempts' => $intent['attempts'] ?? null,
            'reconciliationLookupAttempts' => $intent['reconciliation_lookup_attempts'] ?? null,
            'leaseState' => $leaseState,
            'leaseExpiresAt' => $leaseExpiry,
            'nextLookupAt' => $nextLookup,
            'snapshotBindingValid' => $snapshotValid,
            'claimedAuditCount' => $evidence['claimedCount'],
            'unknownAuditCount' => $evidence['unknownCount'],
            'issuedAuditCount' => $evidence['issuedCount'],
            'observedAt' => $this->stamp($observedAt),
            'violations' => $violations,
        ];
    }

    /** @param array<string, mixed> $bill
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $claimed
     */
    private function canonicalIntentValid(array $bill, array $intent, ?array $claimed, CarbonImmutable $observedAt): bool
    {
        $payload = $this->decodeObject($intent['payload'] ?? null);
        $messageId = $intent['message_id'] ?? null;
        $claimedAt = $this->exactStamp($payload['claimedAt'] ?? null);
        $hours = $payload['invoiceDurationHours'] ?? null;
        $snapshot = $this->canonicalSnapshot($bill);
        if ($snapshot === null || $claimedAt === null || $claimedAt->greaterThan($observedAt)
            || ! is_int($hours) || $hours < 1 || $hours > 876_000 || ! is_string($messageId)
            || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $messageId) !== 1) {
            return false;
        }
        try {
            $requestedExpiry = $claimedAt->addHours($hours);
            $retentionExpiry = $claimedAt->addYearsNoOverflow(2);
        } catch (Throwable) {
            return false;
        }
        $expected = [
            'version' => 1,
            'messageId' => $messageId,
            'snapshot' => $snapshot,
            'snapshotHash' => hash('sha256', $this->canonical($snapshot)),
            'claimedAt' => $this->stamp($claimedAt),
            'invoiceDurationHours' => $hours,
            'requestedExpiresAt' => $this->stamp($requestedExpiry),
            'description' => 'Psikotes LSI '.$bill['public_reference'],
        ];
        if ($this->canonical($payload) !== $this->canonical($expected)
            || $intent['deduplication_key'] !== hash('sha256', self::TOPIC.':v1:'.$bill['organization_id'].':'.$bill['id'])
            || ! $this->timeEquals($intent['available_at'], $claimedAt)
            || ! $this->timeEquals($intent['created_at'], $claimedAt)
            || ! $this->timeEquals($intent['expires_at'], $retentionExpiry)) {
            return false;
        }
        $context = $this->decodeObject($claimed['context'] ?? null);

        return $context === ['messageId' => $messageId, 'reference' => $bill['public_reference'],
            'snapshotHash' => $expected['snapshotHash']]
            && $this->timeEquals($claimed['occurred_at'] ?? null, $claimedAt);
    }

    /** @param array<string, mixed> $bill
     * @return array<string, mixed>|null
     */
    private function canonicalSnapshot(array $bill): ?array
    {
        if (! is_int($bill['id']) || ! is_int($bill['organization_id']) || ! is_int($bill['item_count'])
            || $bill['item_count'] < 1 || $bill['item_count'] > 500) {
            return null;
        }
        $items = DB::table('assessment_bill_items')->where('bill_id', $bill['id'])->orderBy('id')->limit(501)->get();
        if ($items->count() !== $bill['item_count']) {
            return null;
        }
        $chargeIds = $items->pluck('charge_id')->all();
        $charges = DB::table('assessment_charges')->whereIn('id', $chargeIds)->get()->keyBy('id');
        $attemptIds = $charges->pluck('assessment_participant_id')->all();
        $attempts = DB::table('assessment_participants')->whereIn('id', $attemptIds)->get()->keyBy('id');
        $participantIds = $items->pluck('participant_id')->all();
        $packageIds = $attempts->pluck('package_id')->all();
        $clientIds = $attempts->pluck('integration_client_id')->all();
        $participants = DB::table('participants')->whereIn('id', $participantIds)->get()->keyBy('id');
        $packages = DB::table('packages')->whereIn('id', $packageIds)->get()->keyBy('id');
        $clients = DB::table('integration_clients')->whereIn('id', $clientIds)->get()->keyBy('id');
        $sources = DB::table('integration_sources')->whereIn('integration_client_id', $clientIds)->get()
            ->keyBy(static fn (object $source): string => $source->integration_client_id.':'.$source->source_system);
        $method = DB::table('payment_methods')->where('id', $bill['payment_method_id'])->first();
        if ($charges->count() !== count(array_unique($chargeIds))
            || $attempts->count() !== count(array_unique($attemptIds))
            || $participants->count() !== count(array_unique($participantIds))
            || $packages->count() !== count(array_unique($packageIds))
            || $clients->count() !== count(array_unique($clientIds))
            || $method === null || $method->code !== 'xendit') {
            return null;
        }

        $links = [];
        $total = 0;
        foreach ($items as $item) {
            $charge = $charges->get($item->charge_id);
            $attempt = $charge === null ? null : $attempts->get($charge->assessment_participant_id);
            $participant = $participants->get($item->participant_id);
            $package = $attempt === null ? null : $packages->get($attempt->package_id);
            $client = $attempt === null ? null : $clients->get($attempt->integration_client_id);
            $source = $attempt === null ? null : $sources->get($attempt->integration_client_id.':'.$attempt->source_system);
            $metadata = $attempt === null ? null : $this->decodeObject($attempt->metadata);
            $price = $charge === null ? [] : $this->decodeObject($charge->price_snapshot);
            $policy = $charge === null ? [] : $this->decodeObject($charge->policy_snapshot);
            if ($charge === null || $attempt === null || $participant === null || $package === null || $client === null
                || $source === null || $price === [] || $policy === []
                || ! array_key_exists('checkout_initial_funding_mode', $metadata)
                || ! in_array($metadata['checkout_initial_funding_mode'], [null, 'COMMERCIAL_SELF_PAY', 'INVOICED_TO_ORGANIZATION'], true)
                || $item->organization_id !== $bill['organization_id'] || $charge->organization_id !== $bill['organization_id']
                || $item->participant_id !== $participant->id || $charge->participant_id !== $participant->id
                || $charge->package_id !== $package->id || $item->charge_id !== $charge->id
                || $item->payer_type !== $bill['payer_type'] || $charge->payer_type !== $bill['payer_type']
                || $item->payer_participant_id !== $bill['payer_participant_id']
                || $item->amount !== $charge->amount || $item->currency !== $charge->currency
                || $item->amount < 1 || $item->currency !== 'IDR' || $item->settled_at !== null
                || $charge->free_settled_at !== null || $total > PHP_INT_MAX - $item->amount) {
                return null;
            }
            $total += $item->amount;
            $links[] = [
                'itemId' => $item->id, 'chargeId' => $charge->id, 'assessmentParticipantId' => $attempt->id,
                'participantId' => $participant->id, 'packageId' => $package->id, 'integrationClientId' => $client->id,
                'sourceId' => $source->id, 'sourceSystem' => $source->source_system, 'amount' => $item->amount,
                'currency' => $item->currency, 'initialFundingMode' => $metadata['checkout_initial_funding_mode'],
                'attemptIdentityHash' => hash('sha256', $this->canonical([$attempt->assessment_attempt_id,
                    $attempt->external_candidate_id, $attempt->external_process_id, $attempt->external_registration_id,
                    $attempt->assessment_round_id, $attempt->logical_assessment_key, $attempt->idempotency_key,
                    $attempt->request_hash])),
                'priceSnapshot' => $price, 'policySnapshot' => $policy,
            ];
        }
        if ($total !== $bill['amount']) {
            return null;
        }

        return [
            'organizationId' => $bill['organization_id'], 'billId' => $bill['id'],
            'publicReference' => $bill['public_reference'], 'payerType' => $bill['payer_type'],
            'payerParticipantId' => $bill['payer_participant_id'], 'paymentMethodId' => $bill['payment_method_id'],
            'providerCode' => $method->code, 'amount' => $bill['amount'], 'currency' => $bill['currency'],
            'itemCount' => $bill['item_count'], 'selectionHash' => $bill['selection_hash'],
            'requestHash' => $bill['request_hash'], 'idempotencyKey' => $bill['idempotency_key'], 'items' => $links,
        ];
    }

    /** @param array<string, mixed>|null $audit
     * @param  array<string, mixed>  $bill
     */
    private function auditEnvelopeValid(?array $audit, array $bill, CarbonImmutable $observedAt): bool
    {
        return $audit !== null && $audit['actor_type'] === 'service' && $audit['actor_id'] === null
            && $this->timeAtOrBefore($audit['occurred_at'] ?? null, $observedAt);
    }

    /** @param array<string, mixed>|null $intent
     * @return array{string, ?string, ?string, bool}
     */
    private function lease(?array $intent, CarbonImmutable $observedAt): array
    {
        if ($intent === null) {
            return ['invalid', null, null, false];
        }
        $token = $intent['reconciliation_lease_token'];
        $expiryRaw = $intent['reconciliation_lease_expires_at'];
        $nextRaw = $intent['reconciliation_next_at'];
        try {
            $expiry = $expiryRaw === null ? null : CarbonImmutable::parse($expiryRaw)->utc()->startOfSecond();
            $next = $nextRaw === null ? null : CarbonImmutable::parse($nextRaw)->utc()->startOfSecond();
        } catch (Throwable) {
            return ['invalid', null, null, false];
        }
        $pair = ($token === null) === ($expiry === null);
        $valid = $pair && ($token === null || is_string($token) && Str::isUuid($token));
        $state = ! $valid ? 'invalid' : ($expiry === null ? 'none' : ($expiry->greaterThan($observedAt) ? 'active' : 'expired'));

        return [$state, $expiry === null ? null : $this->stamp($expiry), $next === null ? null : $this->stamp($next), $valid];
    }

    private function timeAtOrBefore(mixed $value, CarbonImmutable $observedAt): bool
    {
        if (! is_string($value) && ! $value instanceof \DateTimeInterface) {
            return false;
        }
        try {
            return CarbonImmutable::parse($value)->utc()->lessThanOrEqualTo($observedAt);
        } catch (Throwable) {
            return false;
        }
    }

    private function timeEquals(mixed $value, CarbonImmutable $expected): bool
    {
        if (! is_string($value) && ! $value instanceof \DateTimeInterface) {
            return false;
        }
        try {
            return CarbonImmutable::parse($value)->utc()->equalTo($expected);
        } catch (Throwable) {
            return false;
        }
    }

    private function exactStamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            return null;
        }
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $parsed !== null && $this->stamp($parsed) === $value ? $parsed : null;
    }

    /** @return array<string, mixed> */
    private function decodeObject(mixed $value): array
    {
        if (is_array($value) && ! array_is_list($value)) {
            $decoded = $value;
        } elseif (is_string($value)) {
            try {
                $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return [];
            }
        } else {
            return [];
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            return [];
        }
        ksort($decoded);

        return $decoded;
    }

    private function stamp(CarbonImmutable $value): string
    {
        return $value->format('Y-m-d\TH:i:s\Z');
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

    /** @param array<string, mixed> $payload */
    private function respond(array $payload, int $exit): int
    {
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $exit;
    }
}
