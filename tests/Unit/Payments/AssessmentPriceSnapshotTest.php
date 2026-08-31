<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Models\AssessmentCharge;
use App\Models\PackageItem;
use App\Models\TestPackage;
use App\Services\Payments\AssessmentPriceSnapshot;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssessmentPriceSnapshotTest extends TestCase
{
    private function package(): TestPackage
    {
        $package = new TestPackage;
        $package->setRawAttributes(['id' => 1, 'code' => 'SYNTHETIC', 'name' => 'Synthetic', 'amount' => 123,
            'consultation_amount' => 45, 'currency' => 'IDR', 'is_active' => true]);
        $package->setRelation('items', new Collection([new PackageItem(['test_type' => 'papi']), new PackageItem(['test_type' => 'ist'])]));

        return $package;
    }

    public function test_integer_prices_and_test_types_come_from_package(): void
    {
        $snapshot = (new AssessmentPriceSnapshot)->capture($this->package(), true);
        $this->assertSame(168, $snapshot['amount']);
        $this->assertSame(45, $snapshot['consultationAmount']);
        $this->assertSame(['ist', 'papi'], $snapshot['testTypes']);
        $this->assertSame('IDR', $snapshot['currency']);
    }

    public function test_free_package_and_optional_consultation(): void
    {
        $package = $this->package();
        $package->amount = 0;
        $this->assertSame(0, (new AssessmentPriceSnapshot)->capture($package, false)['amount']);
        $this->assertSame(45, (new AssessmentPriceSnapshot)->capture($package, true)['amount']);
    }

    #[DataProvider('invalidPrices')]
    public function test_invalid_catalog_is_rejected(array $override, bool $consultation): void
    {
        $package = $this->package();
        foreach ($override as $field => $value) {
            $package->setAttribute($field, $value);
        }
        $this->expectException(DomainException::class);
        (new AssessmentPriceSnapshot)->capture($package, $consultation);
    }

    public static function invalidPrices(): iterable
    {
        yield [['amount' => null], false];
        yield [['amount' => -1], false];
        yield [['currency' => 'USD'], false];
        yield [['is_active' => false], false];
        yield [['consultation_amount' => null], true];
        yield [['consultation_amount' => 0], true];
        yield [['amount' => PHP_INT_MAX, 'consultation_amount' => 1], true];
    }

    public function test_empty_package_is_rejected(): void
    {
        $package = $this->package()->setRelation('items', new Collection);
        $this->expectException(DomainException::class);
        (new AssessmentPriceSnapshot)->capture($package, false);
    }

    public function test_existing_charge_snapshot_does_not_follow_catalog_changes(): void
    {
        $service = new AssessmentPriceSnapshot;
        $snapshot = $service->capture($this->package(), true);
        $charge = new AssessmentCharge(['package_id' => 1, 'base_amount' => 123, 'consultation_amount' => 45,
            'consultation_requested' => true, 'amount' => 168, 'currency' => 'IDR', 'price_snapshot' => $snapshot]);
        $this->assertSame($snapshot, $service->fromCharge($charge, true));
        $charge->amount = 999;
        $this->expectException(DomainException::class);
        $service->fromCharge($charge, true);
    }
}
