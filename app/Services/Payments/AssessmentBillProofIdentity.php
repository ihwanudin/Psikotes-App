<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\AssessmentBill;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Throwable;

/** Canonical identity shared by proof storage, reviewer access, and manual decision. */
final class AssessmentBillProofIdentity
{
    public function fingerprint(AssessmentBill $bill, CarbonInterface $now): ?string
    {
        $rawUploadedAt = $bill->getRawOriginal('proof_uploaded_at');
        $values = [$bill->proof_object_key, $bill->proof_checksum_sha256, $bill->proof_mime_type,
            $bill->proof_size_bytes, $rawUploadedAt];
        if (array_filter($values, static fn (mixed $value): bool => $value !== null) === []) {
            return null;
        }
        if (! is_string($bill->proof_object_key) || ! is_string($bill->proof_checksum_sha256)
            || ! is_string($bill->proof_mime_type) || ! is_int($bill->proof_size_bytes)
            || $rawUploadedAt === null) {
            $this->invalid();
        }

        try {
            $uploadedAt = $this->dateAttribute($bill);
        } catch (Throwable) {
            $this->invalid();
        }
        if ($uploadedAt === null) {
            $this->invalid();
        }

        return $this->fromValues($bill->proof_object_key, $bill->proof_checksum_sha256,
            $bill->proof_mime_type, $bill->proof_size_bytes, $uploadedAt, $now);
    }

    public function fromValues(string $key, string $checksum, string $mime, int $size,
        CarbonInterface $uploadedAt, CarbonInterface $now): string
    {
        $canonicalUploadedAt = CarbonImmutable::instance($uploadedAt)->utc();
        if (! preg_match('/^assessment-bills\/[a-z0-9]{2}\/[a-z0-9]{62}\.(jpg|png|pdf)$/D', $key)
            || ! preg_match('/^[0-9a-f]{64}$/D', $checksum)
            || ! in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)
            || $size < 1 || $size > 5_120_000 || $canonicalUploadedAt->isAfter($now)) {
            $this->invalid();
        }

        return hash('sha256', implode("\0", [
            $key, $checksum, $mime, (string) $size,
            $canonicalUploadedAt->format('Y-m-d\TH:i:s.u\Z'),
        ]));
    }

    /** @throws Throwable when the Eloquent cast cannot parse persisted data. */
    private function dateAttribute(AssessmentBill $bill): ?CarbonInterface
    {
        $value = $bill->getAttribute('proof_uploaded_at');

        return $value instanceof CarbonInterface ? $value : null;
    }

    private function invalid(): never
    {
        throw new DomainException('ASSESSMENT_BILL_PROOF_IDENTITY_INVALID');
    }
}
