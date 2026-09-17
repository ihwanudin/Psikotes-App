<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $charge_id
 * @property int $assessment_participant_id
 * @property int $organization_id
 * @property int $participant_id
 * @property string $test_type
 * @property string $status
 * @property CarbonInterface|null $ready_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 */
#[Fillable(['charge_id', 'assessment_participant_id', 'organization_id', 'participant_id',
    'test_type', 'status', 'ready_at', 'started_at', 'completed_at'])]
final class AssessmentEntitlement extends Model
{
    /** @return BelongsTo<AssessmentCharge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(AssessmentCharge::class, 'charge_id');
    }

    /** @return BelongsTo<AssessmentParticipant, $this> */
    public function assessmentParticipant(): BelongsTo
    {
        return $this->belongsTo(AssessmentParticipant::class);
    }

    protected function casts(): array
    {
        return ['ready_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
