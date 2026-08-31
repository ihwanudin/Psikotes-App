<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $payer_type
 * @property int|null $payer_participant_id
 * @property string $public_reference
 * @property int $amount
 * @property string $currency
 * @property int $item_count
 * @property string $selection_hash
 * @property string $idempotency_key
 * @property string $request_hash
 * @property string $status
 * @property int $payment_method_id
 * @property string|null $gateway_ref
 * @property string|null $invoice_url
 * @property string|null $proof_object_key
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $verified_at
 * @property int|null $verified_by_admin_id
 * @property string|null $rejection_reason
 */
#[Fillable(['organization_id', 'payer_type', 'payer_participant_id', 'public_reference', 'amount', 'currency',
    'item_count', 'selection_hash', 'idempotency_key', 'request_hash', 'status', 'payment_method_id',
    'gateway_ref', 'invoice_url', 'proof_object_key', 'expires_at', 'paid_at', 'verified_at',
    'verified_by_admin_id', 'rejection_reason'])]
final class AssessmentBill extends Model
{
    /** @return BelongsTo<Branch, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'organization_id');
    }

    /** @return BelongsTo<Participant, $this> */
    public function payerParticipant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'payer_participant_id');
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<Admin, $this> */
    public function verifiedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by_admin_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'item_count' => 'integer', 'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime'];
    }
}
