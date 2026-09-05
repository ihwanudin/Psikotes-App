<?php

declare(strict_types=1);

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int|null $amount
 * @property int|null $consultation_amount
 * @property string $currency
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'description', 'amount', 'consultation_amount', 'currency', 'is_active'])]
final class TestPackage extends Model
{
    private const array SUPPORTED_TEST_TYPES = ['ist', 'papi', 'rmib', 'kraepelin', 'dass21'];

    protected $table = 'packages';

    /** @return HasMany<PackageItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PackageItem::class, 'package_id')->orderBy('sort_order');
    }

    /** @param Builder<TestPackage> $query */
    public function scopeAvailableForRegistration(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereNotNull('amount')
            ->where('amount', '>=', 0)
            ->where('currency', 'IDR')
            ->whereHas('items', fn (Builder $items): Builder => $items->where('test_type', 'dass21'))
            ->whereHas('items', fn (Builder $items): Builder => $items->where('test_type', '!=', 'dass21'));
    }

    /**
     * @param  array<array-key, mixed>  $testTypes
     * @return list<string>
     */
    public static function canonicalComposition(array $testTypes): array
    {
        if (! array_is_list($testTypes) || $testTypes === []) {
            throw new DomainException('PACKAGE_COMPOSITION_INVALID');
        }

        foreach ($testTypes as $testType) {
            if (! is_string($testType) || ! in_array($testType, self::SUPPORTED_TEST_TYPES, true)) {
                throw new DomainException('PACKAGE_COMPOSITION_INVALID');
            }
        }

        if (count(array_unique($testTypes)) !== count($testTypes)
            || ! in_array('dass21', $testTypes, true)
            || count($testTypes) < 2) {
            throw new DomainException('PACKAGE_COMPOSITION_INVALID');
        }

        sort($testTypes);

        return $testTypes;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'consultation_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
