<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string|null $registration_token
 * @property string|null $registration_payload_hash
 * @property int|null $package_id
 * @property int $branch_id
 * @property int $referral_branch_id
 */
#[Fillable([
    'branch_id',
    'referral_branch_id',
    'referral_source',
    'full_name',
    'gender',
    'birth_date',
    'education_level',
    'intended_field',
    'phone',
    'email',
    'test_number',
    'purge_after',
])]
final class Participant extends Model
{
    use SoftDeletes;

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<TestPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TestPackage::class, 'package_id');
    }

    /** @return HasMany<IdentityEvidence, $this> */
    public function identityEvidence(): HasMany
    {
        return $this->hasMany(IdentityEvidence::class);
    }

    /** @return HasOne<IdentityVerification, $this> */
    public function identityVerification(): HasOne
    {
        return $this->hasOne(IdentityVerification::class);
    }

    /** @return HasMany<Entitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'purge_after' => 'immutable_datetime',
        ];
    }
}
