<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $assessment_participant_id
 * @property int|null $issued_by_admin_id
 * @property string $token_hash
 * @property bool|null $active_marker
 * @property string $status
 * @property int $issue_number
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $consumed_at
 * @property-read AssessmentParticipant $assessment
 */
#[Fillable(['public_id', 'assessment_participant_id', 'issued_by_admin_id', 'token_hash', 'active_marker', 'status', 'issue_number', 'expires_at', 'consumed_at'])]
#[Hidden(['token_hash'])]
final class AssessmentInvitation extends Model
{
    /** @return BelongsTo<AssessmentParticipant, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(AssessmentParticipant::class, 'assessment_participant_id');
    }

    protected function casts(): array
    {
        return [
            'active_marker' => 'boolean',
            'issue_number' => 'integer',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
