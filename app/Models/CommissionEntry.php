<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $participant_id
 * @property string $source_type
 * @property int $source_id
 * @property string $period_month
 * @property int $commission_amount
 * @property string $status
 */
#[Fillable([])]
final class CommissionEntry extends Model
{
    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** @return HasMany<WithdrawalRequestItem, $this> */
    public function withdrawalItems(): HasMany
    {
        return $this->hasMany(WithdrawalRequestItem::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_month' => 'immutable_date',
            'paid_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'calculation_snapshot' => 'array',
        ];
    }
}
