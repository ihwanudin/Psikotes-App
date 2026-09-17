<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\TestPackage;

final class TestPackagePolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->canPerform(AdminAbility::ManageTestPackages);
    }

    public function view(Admin $admin, TestPackage $package): bool
    {
        return $admin->canPerform(AdminAbility::ManageTestPackages);
    }

    public function update(Admin $admin, TestPackage $package): bool
    {
        return $admin->canPerform(AdminAbility::ManageTestPackages);
    }

    public function create(Admin $admin): bool
    {
        return false;
    }

    public function delete(Admin $admin, TestPackage $package): bool
    {
        return false;
    }
}
