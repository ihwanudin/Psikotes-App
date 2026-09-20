<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\ProvidesRlsContext;
use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Security\RlsContext;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property int|null $branch_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property AdminRole $role
 * @property bool $can_verify_payments
 */
#[Fillable(['branch_id', 'name', 'email', 'password', 'role', 'can_verify_payments'])]
#[Hidden(['password', 'remember_token'])]
final class Admin extends Authenticatable implements FilamentUser, ProvidesRlsContext
{
    use Notifiable, SoftDeletes;

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->canPerform(AdminAbility::AccessPanel);
    }

    public function canPerform(AdminAbility $ability): bool
    {
        return match ($ability) {
            AdminAbility::AccessPanel,
            AdminAbility::ViewParticipants => true,
            AdminAbility::ManageAdmins,
            AdminAbility::ManageTestPackages,
            AdminAbility::ManagePaymentMethods,
            AdminAbility::ManageIntegrations => $this->role === AdminRole::SuperAdmin,
            AdminAbility::EditParticipants => in_array(
                $this->role,
                [AdminRole::SuperAdmin, AdminRole::BranchAdmin, AdminRole::Staff],
                true,
            ),
            AdminAbility::VerifyPayments => $this->role === AdminRole::SuperAdmin
                || ($this->can_verify_payments
                    && in_array($this->role, [AdminRole::BranchAdmin, AdminRole::Staff], true)),
            AdminAbility::ViewDass => $this->role === AdminRole::Psychologist,
            AdminAbility::ReviewReports => $this->role === AdminRole::Psychologist,
        };
    }

    public function rlsContext(): RlsContext
    {
        return match ($this->role) {
            AdminRole::SuperAdmin => new RlsContext('super_admin'),
            AdminRole::BranchAdmin => new RlsContext('branch_admin', $this->requiredBranchId()),
            AdminRole::Staff => new RlsContext('staff', $this->requiredBranchId()),
            AdminRole::Psychologist => new RlsContext('psychologist'),
        };
    }

    private function requiredBranchId(): int
    {
        if ($this->branch_id === null) {
            throw new \LogicException('Branch-scoped administrators require a branch identifier.');
        }

        return (int) $this->branch_id;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => AdminRole::class,
            'can_verify_payments' => 'boolean',
        ];
    }
}
