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
 * @property string $selector_digest
 * @property string $csrf_digest
 * @property int $checkout_handoff_id
 * @property int $assessment_participant_id
 * @property int $organization_id
 * @property int $participant_id
 * @property int $package_id
 * @property int $integration_client_id
 * @property int $integration_source_id
 * @property string $source_system
 * @property string $contract_version
 * @property string $status
 * @property bool|null $active_marker
 * @property CarbonInterface $established_at
 * @property CarbonInterface $last_seen_at
 * @property CarbonInterface $idle_expires_at
 * @property CarbonInterface $absolute_expires_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $expired_at
 * @property string|null $revocation_reason
 * @property-read CheckoutHandoff $handoff
 * @property-read AssessmentParticipant $assessment
 * @property-read IntegrationClient $client
 * @property-read IntegrationSource $source
 */
#[Fillable([
    'public_id',
    'selector_digest',
    'csrf_digest',
    'checkout_handoff_id',
    'assessment_participant_id',
    'organization_id',
    'participant_id',
    'package_id',
    'integration_client_id',
    'integration_source_id',
    'source_system',
    'contract_version',
    'status',
    'active_marker',
    'established_at',
    'last_seen_at',
    'idle_expires_at',
    'absolute_expires_at',
    'revoked_at',
    'expired_at',
    'revocation_reason',
])]
final class CheckoutSession extends Model
{
    protected $hidden = [
        'selector_digest',
        'csrf_digest',
    ];

    /** @return BelongsTo<CheckoutHandoff, $this> */
    public function handoff(): BelongsTo
    {
        return $this->belongsTo(CheckoutHandoff::class, 'checkout_handoff_id');
    }

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
            'established_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'idle_expires_at' => 'immutable_datetime',
            'absolute_expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }
}
