<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ref_code
 * @property int|null $branch_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $first_seen_at
 * @property Carbon $expires_at
 */
#[Fillable([
    'ref_code',
    'branch_id',
    'participant_id',
    'ip_address',
    'user_agent',
    'first_seen_at',
    'expires_at',
])]
final class ReferralVisit extends Model
{
    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
