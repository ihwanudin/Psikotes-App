<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\AssessmentInvoicePermit;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use DomainException;

/** Single canonical decoder for invoice issuance and reconciliation permits. */
final readonly class AssessmentInvoicePermitFactory
{
    public function fromMessage(OutboxMessage $message): AssessmentInvoicePermit
    {
        $payload = $message->payload;
        $snapshot = $payload['snapshot'] ?? null;
        $rawExpiry = $payload['requestedExpiresAt'] ?? null;
        $expiry = is_string($rawExpiry) ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $rawExpiry, 'UTC') : null;
        if (! is_array($snapshot) || $expiry === null || $expiry->format('Y-m-d\TH:i:s\Z') !== $rawExpiry
            || ! is_int($snapshot['organizationId'] ?? null) || ! is_int($snapshot['billId'] ?? null)
            || ! is_string($snapshot['publicReference'] ?? null) || ! is_int($snapshot['amount'] ?? null)
            || ! is_string($snapshot['currency'] ?? null) || ! is_string($payload['description'] ?? null)) {
            throw new DomainException('INVOICE_RECONCILIATION_INVALID');
        }

        return new AssessmentInvoicePermit($message->message_id, $snapshot['organizationId'], $snapshot['billId'],
            $snapshot['publicReference'], $snapshot['amount'], $snapshot['currency'], $payload['description'], $expiry,
            hash('sha256', $this->canonical($payload)), $snapshot);
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
