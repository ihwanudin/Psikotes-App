<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $assessment_participant_id
 * @property int $organization_id
 * @property int $participant_id
 * @property int $package_id
 * @property int $integration_client_id
 * @property int $integration_source_id
 * @property string $source_system
 * @property string $contract_version
 * @property string $purpose
 * @property string $destination
 * @property string $token_digest
 * @property bool|null $active_marker
 * @property string $status
 * @property int $issue_number
 * @property string $issue_idempotency_key_digest
 * @property string $request_hash
 * @property string|null $revocation_reason
 * @property CarbonInterface $issued_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $consumed_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $expired_at
 * @property-read AssessmentParticipant $assessment
 * @property-read IntegrationClient $client
 * @property-read IntegrationSource $source
 */
#[Fillable([
    'public_id',
    'assessment_participant_id',
    'organization_id',
    'participant_id',
    'package_id',
    'integration_client_id',
    'integration_source_id',
    'source_system',
    'contract_version',
    'purpose',
    'destination',
    'token_digest',
    'active_marker',
    'status',
    'issue_number',
    'issue_idempotency_key_digest',
    'request_hash',
    'revocation_reason',
    'issued_at',
    'expires_at',
    'consumed_at',
    'revoked_at',
    'expired_at',
])]
final class CheckoutHandoff extends Model
{
    protected $hidden = [
        'token_digest',
        'issue_idempotency_key_digest',
        'request_hash',
    ];

    /** @return BelongsTo<AssessmentParticipant, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(AssessmentParticipant::class, 'assessment_participant_id');
    }

    /** @return BelongsTo<IntegrationClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(IntegrationClient::class, 'integration_client_id');
    }

    /** @return BelongsTo<IntegrationSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(IntegrationSource::class, 'integration_source_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active_marker' => 'boolean',
            'issue_number' => 'integer',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }
}
