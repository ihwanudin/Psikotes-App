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
 */
#[Fillable(['code', 'name', 'ref_code', 'is_default', 'is_active'])]
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
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
