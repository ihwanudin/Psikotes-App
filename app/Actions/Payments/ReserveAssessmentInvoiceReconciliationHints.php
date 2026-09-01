<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Data\Payments\ProvisionalAssessmentInvoiceLease;
use App\Models\AssessmentBill;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/** Internal outbox-only phase-one discovery. A provisional lease grants no provider authority. */
final readonly class ReserveAssessmentInvoiceReconciliationHints
{
    private const TOPIC = 'assessment.bill.invoice-issuance';

    public function __construct(private RlsContextRunner $contexts) {}

    /**
     * @param  array<array-key, mixed>  $excludedMessageIds
     * @return list<ProvisionalAssessmentInvoiceLease>
     */
    public function execute(int $remainingBatch, int $remainingScan, array $excludedMessageIds = []): array
    {
        $this->assertOutsideTransaction();
        $config = $this->validatedConfig();
        if ($remainingBatch < 1 || $remainingBatch > $config['batch']
            || $remainingScan < 1 || $remainingScan > $config['scan']
            || ! array_is_list($excludedMessageIds) || count($excludedMessageIds) > $config['scan']) {
            throw new DomainException('INVOICE_RECONCILIATION_HINT_LIMIT_INVALID');
        }
        $uniqueMessageIds = [];
        foreach ($excludedMessageIds as $messageId) {
            if (! is_string($messageId) || ! Str::isUlid($messageId) || isset($uniqueMessageIds[$messageId])) {
                throw new DomainException('INVOICE_RECONCILIATION_HINT_LIMIT_INVALID');
            }
            $uniqueMessageIds[$messageId] = true;
        }
        $limit = min($remainingBatch, $remainingScan);

        $leases = $this->contexts->runAsService(function () use ($config, $limit, $excludedMessageIds): array {
            $now = $this->databaseNow();
            $query = $this->eligible($now, $config['max']);
            if ($excludedMessageIds !== []) {
                $query->whereNotIn('message_id', $excludedMessageIds);
            }
            $query
                ->select(['id', 'message_id'])
                ->orderByRaw('CASE WHEN reconciliation_next_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('reconciliation_next_at')
                ->orderBy('id')
                ->limit($limit);
            if (DB::getDriverName() === 'pgsql') {
                $query->lock('FOR UPDATE SKIP LOCKED');
            } else {
                $query->lockForUpdate();
            }

            $leases = [];
            foreach ($query->get() as $hint) {
                if (! is_int($hint->id) || ! is_string($hint->message_id)) {
                    throw new RuntimeException('Invoice reconciliation hint identity is invalid.');
                }
                $token = (string) Str::uuid();
                $expiresAt = $now->addSeconds($config['lease']);
                $updated = $this->eligible($now, $config['max'])->where('id', $hint->id)->update([
                    'reconciliation_lease_token' => $token,
                    'reconciliation_lease_expires_at' => $expiresAt,
                ]);
                if ($updated !== 1) {
                    continue;
                }
                $leases[] = new ProvisionalAssessmentInvoiceLease($hint->message_id, $token, $expiresAt);
            }

            return $leases;
        });

        $this->assertOutsideTransaction();

        return $leases;
    }

    private function eligible(CarbonImmutable $now, int $maxLookups): Builder
    {
        return DB::table('outbox_messages')
            ->where('topic', self::TOPIC)
            ->where('aggregate_type', AssessmentBill::class)
            ->where('attempts', 1)
            ->whereNull('processed_at')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $pair): void {
                    $pair->where('status', 'processing')->whereNull('last_error');
                })->orWhere(function (Builder $pair): void {
                    $pair->where('status', 'failed')->where('last_error', 'INVOICE_OUTCOME_UNKNOWN');
                });
            })
            ->where('reconciliation_lookup_attempts', '<', $maxLookups)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('reconciliation_next_at')->orWhere('reconciliation_next_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $empty): void {
                    $empty->whereNull('reconciliation_lease_token')->whereNull('reconciliation_lease_expires_at');
                })->orWhere(function (Builder $expired) use ($now): void {
                    $expired->whereNotNull('reconciliation_lease_token')
                        ->whereNotNull('reconciliation_lease_expires_at')
                        ->where('reconciliation_lease_expires_at', '<=', $now);
                });
            });
    }

    /** @return array{batch: int, scan: int, lease: int, cooldown: int, max: int} */
    private function validatedConfig(): array
    {
        $batch = config('assessment_billing.invoice_reconciliation_batch_size');
        $scan = config('assessment_billing.invoice_reconciliation_scan_limit');
        $lease = config('assessment_billing.invoice_reconciliation_lease_seconds');
        $cooldown = config('assessment_billing.invoice_reconciliation_cooldown_seconds');
        $max = config('assessment_billing.invoice_reconciliation_max_lookups');
        if (! is_int($batch) || $batch < 1 || $batch > 100
            || ! is_int($scan) || $scan < $batch || $scan > 400
            || ! is_int($lease) || $lease < 30 || $lease > 300
            || ! is_int($cooldown) || $cooldown < 60 || $cooldown > 86400
            || ! is_int($max) || $max < 1 || $max > 100) {
            throw new LogicException('Invoice reconciliation configuration is invalid.');
        }

        return compact('batch', 'scan', 'lease', 'cooldown', 'max');
    }

    private function databaseNow(): CarbonImmutable
    {
        $row = (array) DB::selectOne('SELECT CURRENT_TIMESTAMP AS reconciliation_now');
        $value = $row['reconciliation_now'] ?? null;
        if (! is_string($value)) {
            throw new RuntimeException('Database clock did not return a timestamp.');
        }

        return CarbonImmutable::parse($value)->utc();
    }

    private function assertOutsideTransaction(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Invoice reconciliation hint reservation requires an empty RLS context and no ambient transaction.');
        }
    }
}
