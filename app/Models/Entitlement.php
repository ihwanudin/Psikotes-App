<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $participant_id
 * @property int $order_id
 * @property string $test_type
 * @property string $status
 * @property CarbonInterface|null $ready_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 */
#[Fillable(['participant_id', 'order_id', 'test_type', 'status', 'ready_at', 'started_at', 'completed_at'])]
final class Entitlement extends Model
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
            'ready_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
