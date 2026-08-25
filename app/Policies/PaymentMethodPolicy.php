<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\PaymentMethod;

final class PaymentMethodPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->canPerform(AdminAbility::ManagePaymentMethods);
    }

    public function view(Admin $admin, PaymentMethod $method): bool
    {
        return $admin->canPerform(AdminAbility::ManagePaymentMethods);
    }

    public function update(Admin $admin, PaymentMethod $method): bool
    {
        return $admin->canPerform(AdminAbility::ManagePaymentMethods);
    }

    public function create(Admin $admin): bool
    {
        return false;
    }

    public function delete(Admin $admin, PaymentMethod $method): bool
    {
        return false;
    }
}
