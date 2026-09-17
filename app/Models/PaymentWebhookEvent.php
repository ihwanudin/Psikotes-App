<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PaymentWebhookEvent extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
