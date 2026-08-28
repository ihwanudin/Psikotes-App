<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $participant_id
 * @property int|null $payment_method_id
 * @property PaymentMethod|null $paymentMethod
 * @property OrderStatus $status
 * @property int $amount
 * @property string $currency
 * @property string|null $gateway_ref
 * @property string|null $proof_object_key
 * @property int|null $verified_by_admin_id
 * @property string|null $rejection_reason
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $verified_at
 */
#[Fillable([
    'public_id',
    'participant_id',
    'payment_method_id',
    'status',
    'amount',
    'currency',
    'gateway_ref',
    'invoice_url',
    'proof_object_key',
    'expires_at',
    'paid_at',
    'verified_at',
    'verified_by_admin_id',
    'rejection_reason',
    'metadata',
])]
final class Order extends Model
{
    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return HasMany<Entitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'amount' => 'integer',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
