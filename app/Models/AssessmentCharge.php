<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $assessment_participant_id
 * @property int $organization_id
 * @property int $participant_id
 * @property int $package_id
 * @property string $payer_type
 * @property int $base_amount
 * @property int $consultation_amount
 * @property bool $consultation_requested
 * @property int $amount
 * @property string $currency
 * @property array<string, mixed> $price_snapshot
 * @property array<string, mixed> $policy_snapshot
 * @property CarbonInterface|null $free_settled_at
 */
#[Fillable(['assessment_participant_id', 'organization_id', 'participant_id', 'package_id', 'payer_type',
    'base_amount', 'consultation_amount', 'consultation_requested', 'amount', 'currency',
    'price_snapshot', 'policy_snapshot', 'free_settled_at'])]
final class AssessmentCharge extends Model
{
    /** @return BelongsTo<AssessmentParticipant, $this> */
    public function assessmentParticipant(): BelongsTo
    {
        return $this->belongsTo(AssessmentParticipant::class);
    }

    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'organization_id');
    }

    /** @return BelongsTo<TestPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TestPackage::class, 'package_id');
    }

    protected function casts(): array
    {
        return ['base_amount' => 'integer', 'consultation_amount' => 'integer', 'amount' => 'integer',
            'consultation_requested' => 'boolean', 'price_snapshot' => 'array', 'policy_snapshot' => 'array',
            'free_settled_at' => 'immutable_datetime'];
    }
}
