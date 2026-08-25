<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Participant;

final class ParticipantPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->canPerform(AdminAbility::ViewParticipants);
    }

    public function view(Admin $admin, Participant $participant): bool
    {
        return $this->isCentralOrPsychologist($admin)
            || $admin->branch_id === $participant->branch_id;
    }

    public function update(Admin $admin, Participant $participant): bool
    {
        if (! $admin->canPerform(AdminAbility::EditParticipants)) {
            return false;
        }

        return $admin->role === AdminRole::SuperAdmin
            || $admin->branch_id === $participant->branch_id;
    }

    public function delete(Admin $admin, Participant $participant): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }

    private function isCentralOrPsychologist(Admin $admin): bool
    {
        return in_array($admin->role, [AdminRole::SuperAdmin, AdminRole::Psychologist], true);
    }
}
