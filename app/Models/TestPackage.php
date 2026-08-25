<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property string $currency
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'description', 'amount', 'currency', 'is_active'])]
final class TestPackage extends Model
{
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
            ->where('amount', '>', 0)
            ->where('currency', 'IDR')
            ->whereHas('items');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
