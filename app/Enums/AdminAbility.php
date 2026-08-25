<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminAbility: string
{
    case AccessPanel = 'access_panel';
    case ManageAdmins = 'manage_admins';
    case ManageTestPackages = 'manage_test_packages';
    case ManagePaymentMethods = 'manage_payment_methods';
    case ViewParticipants = 'view_participants';
    case EditParticipants = 'edit_participants';
    case VerifyPayments = 'verify_payments';
    case ViewDass = 'view_dass';
    case ReviewReports = 'review_reports';
}
