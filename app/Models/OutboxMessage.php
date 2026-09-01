<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $message_id
 * @property string|null $deduplication_key
 * @property string $topic
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property CarbonInterface $available_at
 * @property CarbonInterface|null $processed_at
 * @property CarbonInterface $expires_at
 * @property string|null $last_error
 * @property string|null $reconciliation_lease_token
 * @property CarbonInterface|null $reconciliation_lease_expires_at
 * @property CarbonInterface|null $reconciliation_next_at
 * @property int $reconciliation_lookup_attempts
 * @property CarbonInterface $updated_at
 */
final class OutboxMessage extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'reconciliation_lease_expires_at' => 'immutable_datetime',
            'reconciliation_next_at' => 'immutable_datetime',
            'reconciliation_lookup_attempts' => 'integer',
        ];
    }
}
