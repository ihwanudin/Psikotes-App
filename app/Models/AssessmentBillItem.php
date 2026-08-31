<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bill_id
 * @property int $charge_id
 * @property int $organization_id
 * @property int $participant_id
 * @property string $payer_type
 * @property int|null $payer_participant_id
 * @property int $amount
 * @property string $currency
 * @property CarbonInterface|null $settled_at
 */
#[Fillable(['bill_id', 'charge_id', 'organization_id', 'participant_id', 'payer_type',
    'payer_participant_id', 'amount', 'currency', 'settled_at'])]
final class AssessmentBillItem extends Model
{
    /** @return BelongsTo<AssessmentBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(AssessmentBill::class, 'bill_id');
    }

    /** @return BelongsTo<AssessmentCharge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(AssessmentCharge::class, 'charge_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'settled_at' => 'immutable_datetime'];
    }
}
