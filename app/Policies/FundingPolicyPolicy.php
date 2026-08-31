<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;

final class FundingPolicyPolicy
{
    public function update(Admin $admin): bool
    {
        return $admin->exists && ! $admin->trashed() && $admin->role === AdminRole::SuperAdmin;
    }
}
