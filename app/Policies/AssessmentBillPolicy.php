<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentBill;

final class AssessmentBillPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $this->isActiveReviewer($admin);
    }

    public function view(Admin $admin, AssessmentBill $bill): bool
    {
        return $this->isActiveReviewer($admin);
    }

    public function viewProof(Admin $admin, AssessmentBill $bill): bool
    {
        return $this->isActiveReviewer($admin);
    }

    private function isActiveReviewer(Admin $admin): bool
    {
        $id = $admin->getKey();
        if (! is_int($id)) {
            return false;
        }
        $persisted = Admin::withTrashed()->find($id);

        return $persisted !== null && $persisted->deleted_at === null
            && $persisted->role === AdminRole::SuperAdmin;
    }
}
