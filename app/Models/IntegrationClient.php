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
 * @property int $organization_id
 * @property string $client_id
 * @property string $credential_reference
 * @property string|null $callback_base_url
 * @property string $result_delivery_mode
 * @property bool $enabled
 * @property array<string, mixed>|null $rate_limit_policy
 * @property CarbonInterface|null $effective_from
 * @property CarbonInterface|null $effective_until
 * @property-read Branch $organization
 */
#[Fillable(['organization_id', 'client_id', 'credential_reference', 'callback_base_url', 'result_delivery_mode', 'enabled', 'rate_limit_policy', 'effective_from', 'effective_until'])]
final class IntegrationClient extends Model
{
    protected $hidden = ['credential_reference'];

    /** @return BelongsTo<Branch, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'organization_id');
    }

    /** @return HasMany<IntegrationSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(IntegrationSource::class);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'rate_limit_policy' => 'array',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
        ];
    }
}
