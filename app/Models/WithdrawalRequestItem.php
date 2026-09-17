<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
final class WithdrawalRequestItem extends Model
{
    /** @return BelongsTo<WithdrawalRequest, $this> */
    public function withdrawalRequest(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class);
    }

    /** @return BelongsTo<CommissionEntry, $this> */
    public function commissionEntry(): BelongsTo
    {
        return $this->belongsTo(CommissionEntry::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
