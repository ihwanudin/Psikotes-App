<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $ref_code
 * @property string|null $organization_code
 * @property string $organization_type
 * @property string|null $display_name
 * @property string $status
 * @property array<string, mixed>|null $capabilities
 * @property list<string>|null $allowed_funding_modes
 * @property list<string>|null $allowed_payer_types
 */
#[Fillable(['code', 'name', 'ref_code', 'organization_code', 'organization_type', 'display_name', 'status', 'capabilities', 'allowed_funding_modes', 'allowed_payer_types', 'is_default', 'is_active'])]
final class Branch extends Model
{
    /** @return HasMany<Admin, $this> */
    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }

    /** @return HasMany<Participant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'allowed_funding_modes' => 'array',
            'allowed_payer_types' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
