<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $public_id
 * @property int $participant_id
 * @property int $organization_id
 * @property int|null $package_id
 * @property string $origin
 * @property string|null $intended_field_snapshot
 * @property CarbonInterface $created_at
 * @property-read Participant $participant
 * @property-read Branch $organization
 * @property-read TestPackage|null $package
 * @property-read AssessmentParticipant|null $assessmentParticipant
 * @property-read Order|null $order
 */
#[Fillable(['public_id', 'participant_id', 'organization_id', 'package_id', 'origin', 'intended_field_snapshot'])]
final class AssessmentCase extends Model
{
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

    /** @return HasOne<AssessmentParticipant, $this> */
    public function assessmentParticipant(): HasOne
    {
        return $this->hasOne(AssessmentParticipant::class, 'assessment_case_id');
    }

    /** @return HasOne<Order, $this> */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class, 'assessment_case_id');
    }
}
