<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $integration_client_id
 * @property int $organization_id
 * @property int $participant_id
 * @property int $package_id
 * @property string $assessment_attempt_id
 * @property string $source_system
 * @property string $external_candidate_id
 * @property string|null $external_process_id
 * @property string|null $external_registration_id
 * @property string|null $assessment_round_id
 * @property string $funding_mode
 * @property string $assessment_status
 * @property string|null $recommendation
 * @property int $result_version
 * @property CarbonInterface|null $finalized_at
 * @property CarbonInterface|null $revoked_at
 * @property string $idempotency_key
 * @property string $request_hash
 * @property string $logical_assessment_key
 * @property array<string, mixed>|null $metadata
 * @property-read IntegrationClient $client
 * @property-read Participant $participant
 */
#[Fillable(['integration_client_id', 'organization_id', 'participant_id', 'package_id', 'assessment_attempt_id', 'source_system', 'external_candidate_id', 'external_process_id', 'external_registration_id', 'assessment_round_id', 'funding_mode', 'assessment_status', 'recommendation', 'result_version', 'finalized_at', 'revoked_at', 'idempotency_key', 'request_hash', 'logical_assessment_key', 'metadata'])]
final class AssessmentParticipant extends Model
{
    /** @return BelongsTo<IntegrationClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(IntegrationClient::class, 'integration_client_id');
    }

    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** @return BelongsTo<TestPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TestPackage::class, 'package_id');
    }

    /** @return HasMany<AssessmentInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(AssessmentInvitation::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'result_version' => 'integer',
            'finalized_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
