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
 * @property string $public_reference
 * @property string $status
 * @property int $requested_amount
 * @property int|null $approved_amount
 */
#[Fillable([])]
final class WithdrawalRequest extends Model
{
    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Admin, $this> */
    public function requestedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by_admin_id');
    }

    /** @return BelongsTo<Admin, $this> */
    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by_admin_id');
    }

    /** @return HasMany<WithdrawalRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WithdrawalRequestItem::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_month' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
