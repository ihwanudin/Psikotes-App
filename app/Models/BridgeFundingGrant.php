<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $participant_id
 * @property int $branch_id
 * @property int $amount
 * @property string $currency
 * @property string $management_reference
 * @property int $approved_by_admin_id
 * @property CarbonInterface $approved_at
 * @property string $status
 * @property CarbonInterface|null $collected_at
 */
#[Fillable([
    'order_id',
    'participant_id',
    'branch_id',
    'amount',
    'currency',
    'management_reference',
    'approved_by_admin_id',
    'approved_at',
    'status',
    'collected_at',
])]
final class BridgeFundingGrant extends Model
{
    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'approved_at' => 'immutable_datetime',
            'collected_at' => 'immutable_datetime',
        ];
    }
}
