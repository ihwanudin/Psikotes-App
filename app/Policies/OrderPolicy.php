<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Order;

final class OrderPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->canPerform(AdminAbility::VerifyPayments);
    }

    public function view(Admin $admin, Order $order): bool
    {
        return $this->verifyPayment($admin, $order);
    }

    public function verifyPayment(Admin $admin, Order $order): bool
    {
        if (! $admin->canPerform(AdminAbility::VerifyPayments)) {
            return false;
        }

        return $admin->role === AdminRole::SuperAdmin
            || $admin->branch_id === $order->participant->branch_id;
    }
}
