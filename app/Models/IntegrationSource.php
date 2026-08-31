<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $integration_client_id
 * @property string $source_system
 * @property string $contract_version
 * @property list<string> $allowed_assessment_packages
 * @property list<string> $allowed_funding_modes
 * @property list<string>|null $allowed_payer_types
 * @property string|null $locked_payer_type
 * @property string|null $callback_path
 * @property array<string, mixed>|null $callback_configuration
 * @property string $status
 * @property CarbonInterface|null $effective_from
 * @property CarbonInterface|null $effective_until
 */
#[Fillable(['integration_client_id', 'source_system', 'contract_version', 'authentication_mode', 'allowed_assessment_packages', 'participant_provisioning_mode', 'commercial_mode', 'allowed_funding_modes', 'allowed_payer_types', 'locked_payer_type', 'callback_path', 'callback_configuration', 'status', 'effective_from', 'effective_until'])]
final class IntegrationSource extends Model
{
    /** @return BelongsTo<IntegrationClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(IntegrationClient::class, 'integration_client_id');
    }

    protected function casts(): array
    {
        return [
            'allowed_assessment_packages' => 'array',
            'allowed_funding_modes' => 'array',
            'allowed_payer_types' => 'array',
            'callback_configuration' => 'array',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
        ];
    }
}
