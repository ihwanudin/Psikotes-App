<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $package_id
 * @property string $test_type
 * @property int $sort_order
 */
#[Fillable(['package_id', 'test_type', 'sort_order'])]
final class PackageItem extends Model
{
    /** @return BelongsTo<TestPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TestPackage::class, 'package_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
