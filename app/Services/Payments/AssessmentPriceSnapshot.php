<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use DomainException;
use LogicException;

final class AssessmentPriceSnapshot
{
    /** @return array<string, mixed> Versioned, canonical snapshot; see ASSESSMENT_BILL_PREVIEW.md. */
    public function capture(TestPackage $package, bool $consultation): array
    {
        if (! $package->relationLoaded('items')) {
            throw new LogicException('Snapshot requires preloaded package items.');
        }
        if (! $package->is_active || $package->items->isEmpty()) {
            throw new DomainException('PACKAGE_NOT_ALLOWED');
        }
        $base = $package->amount;
        $addon = $consultation ? $package->consultation_amount : 0;
        if (! is_int($base) || $base < 0 || $package->currency !== 'IDR') {
            throw new DomainException('PRICE_INVALID');
        }
        if (! is_int($addon) || $addon < 0 || ($consultation && $addon === 0)) {
            throw new DomainException('CONSULTATION_NOT_AVAILABLE');
        }
        if ($base > PHP_INT_MAX - $addon) {
            throw new DomainException('PRICE_OVERFLOW');
        }
        $types = TestPackage::canonicalComposition($package->items->pluck('test_type')->values()->all());
        $snapshot = ['version' => 1, 'packageId' => $package->id, 'packageCode' => $package->code,
            'packageName' => $package->name, 'testTypes' => $types, 'baseAmount' => $base,
            'consultationRequested' => $consultation, 'consultationAmount' => $addon,
            'amount' => $base + $addon, 'currency' => 'IDR'];
        $this->assertValid($snapshot);

        return $snapshot;
    }

    /** @return array<string, mixed> */
    public function fromCharge(AssessmentCharge $charge, bool $consultation): array
    {
        if ($charge->consultation_requested !== $consultation) {
            throw new DomainException('CONSULTATION_LOCKED');
        }
        $snapshot = $charge->price_snapshot;
        $this->assertValid($snapshot);
        foreach (['packageId' => 'package_id', 'baseAmount' => 'base_amount', 'consultationAmount' => 'consultation_amount',
            'consultationRequested' => 'consultation_requested', 'amount' => 'amount', 'currency' => 'currency'] as $key => $column) {
            if ($snapshot[$key] !== $charge->getAttribute($column)) {
                throw new DomainException('PRICE_SNAPSHOT_INVALID');
            }
        }

        // Fixed key order makes hashes stable even if JSON storage reordered object keys.
        return array_replace(array_fill_keys($this->keys(), null), $snapshot);
    }

    /** @param array<string, mixed> $snapshot */
    private function assertValid(array $snapshot): void
    {
        if (count($snapshot) !== count($this->keys()) || array_diff($this->keys(), array_keys($snapshot)) !== []) {
            throw new DomainException('PRICE_SNAPSHOT_INVALID');
        }
        foreach (['packageId', 'baseAmount', 'consultationAmount', 'amount'] as $field) {
            if (! is_int($snapshot[$field]) || $snapshot[$field] < 0) {
                throw new DomainException('PRICE_SNAPSHOT_INVALID');
            }
        }
        $types = $snapshot['testTypes'];
        if ($snapshot['version'] !== 1 || $snapshot['packageId'] < 1 || $snapshot['currency'] !== 'IDR'
            || ! is_string($snapshot['packageCode']) || trim($snapshot['packageCode']) === ''
            || ! is_string($snapshot['packageName']) || trim($snapshot['packageName']) === ''
            || ! is_bool($snapshot['consultationRequested']) || ! is_array($types) || ! array_is_list($types) || $types === []) {
            throw new DomainException('PRICE_SNAPSHOT_INVALID');
        }
        try {
            $canonicalTypes = TestPackage::canonicalComposition($types);
        } catch (DomainException) {
            throw new DomainException('PRICE_SNAPSHOT_INVALID');
        }
        if ($canonicalTypes !== $types || $snapshot['baseAmount'] > PHP_INT_MAX - $snapshot['consultationAmount']
            || $snapshot['amount'] !== $snapshot['baseAmount'] + $snapshot['consultationAmount']
            || ($snapshot['consultationRequested'] ? $snapshot['consultationAmount'] <= 0 : $snapshot['consultationAmount'] !== 0)) {
            throw new DomainException('PRICE_SNAPSHOT_INVALID');
        }
    }

    /** @return list<string> */
    private function keys(): array
    {
        return ['version', 'packageId', 'packageCode', 'packageName', 'testTypes', 'baseAmount',
            'consultationRequested', 'consultationAmount', 'amount', 'currency'];
    }
}
