<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'client_id',
    'external_candidate_id',
    'selection_round_id',
    'registration_id',
    'participant_id',
    'assessment_case_id',
    'idempotency_key',
    'request_hash',
])]
final class SelectionParticipant extends Model
{
    /** @return BelongsTo<AssessmentCase, $this> */
    public function assessmentCase(): BelongsTo
    {
        return $this->belongsTo(AssessmentCase::class);
    }

    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
