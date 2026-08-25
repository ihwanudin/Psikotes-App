<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case BranchAdmin = 'branch_admin';
    case Staff = 'staff';
    case Psychologist = 'psychologist';
}
