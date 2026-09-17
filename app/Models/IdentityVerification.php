<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'participant_id',
    'matcher',
    'outcome',
    'confidence',
    'marker',
    'manual_status',
    'checked_at',
    'reviewed_by_admin_id',
    'reviewed_at',
])]
final class IdentityVerification extends Model
{
    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'checked_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
