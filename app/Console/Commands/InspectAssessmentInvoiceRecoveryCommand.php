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
            'id', 'organization_id', 'public_reference', 'status', 'gateway_ref', 'invoice_url', 'expires_at',
            'paid_at', 'proof_object_key', 'proof_uploaded_at',
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
                'id', 'message_id', 'aggregate_type', 'aggregate_id', 'payload', 'status', 'attempts',
                'processed_at', 'last_error', 'reconciliation_lease_token', 'reconciliation_lease_expires_at',
                'reconciliation_next_at', 'reconciliation_lookup_attempts',
            ]);
        $intents = [];
        foreach ($intentRows as $intentRow) {
            $intents[] = (array) $intentRow;
        }

        $audits = [];
        foreach (['assessment_bill.invoice_claimed', 'assessment_bill.invoice_unknown', 'assessment_bill.invoice_issued'] as $action) {
            $query = DB::table('audit_logs')->where('branch_id', $billRow['organization_id'])
                ->where('subject_type', AssessmentBill::class)->where('subject_id', $subject)->where('action', $action);
            $count = $query->count();
            $row = $count === 1 ? $query->first(['actor_type', 'actor_id', 'context', 'occurred_at']) : null;
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

        $snapshotValid = $intent !== null && $this->snapshotBindingValid($bill, $intent, $evidence['claimed']);
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

        return [
            'version' => 1,
            'result' => 'consistent',
            'action' => $action,
            'reference' => $bill['public_reference'],
            'organizationId' => (int) $bill['organization_id'],
            'billState' => $state,
            'messageId' => $intent['message_id'] ?? null,
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
    private function snapshotBindingValid(array $bill, array $intent, ?array $claimed): bool
    {
        $payload = $this->decodeObject($intent['payload'] ?? null);
        $snapshot = $payload['snapshot'] ?? null;
        $snapshotHash = $payload['snapshotHash'] ?? null;
        $messageId = $intent['message_id'] ?? null;
        if (! is_array($snapshot) || array_is_list($snapshot) || ! is_string($snapshotHash)
            || ! preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) || ! is_string($messageId)
            || ! preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $messageId)
            || ($payload['messageId'] ?? null) !== $messageId
            || ($payload['version'] ?? null) !== 1
            || ($snapshot['billId'] ?? null) !== $bill['id']
            || ($snapshot['organizationId'] ?? null) !== $bill['organization_id']
            || ($snapshot['publicReference'] ?? null) !== $bill['public_reference']
            || hash('sha256', $this->canonical($snapshot)) !== $snapshotHash) {
            return false;
        }
        $context = $this->decodeObject($claimed['context'] ?? null);

        return $context === ['messageId' => $messageId, 'reference' => $bill['public_reference'], 'snapshotHash' => $snapshotHash];
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
