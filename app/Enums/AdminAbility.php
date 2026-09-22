<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminAbility: string
{
    case AccessPanel = 'access_panel';
    case ManageAdmins = 'manage_admins';
    case ManageTestPackages = 'manage_test_packages';
    case ManagePaymentMethods = 'manage_payment_methods';
    case ManageIntegrations = 'manage_integrations';
    case ViewParticipants = 'view_participants';
    case EditParticipants = 'edit_participants';
    case VerifyPayments = 'verify_payments';
    case ViewDass = 'view_dass';
    case ReviewReports = 'review_reports';

    /**
     * Download an already-SIGNED HPP report. Deliberately separate from
     * ReviewReports, which also gates signing, narrative editing, and
     * eligibility decisions (Lead's 2026-09-21 review): widening
     * ReviewReports for a PDF-download need would have opened all of
     * those too. NEVER reuse this ability to gate the 'internal' document
     * type once it exists — Lembar Kerja Internal stays psychologist +
     * participant only per CLAUDE.md.
     */
    case GenerateReports = 'generate_reports';

    /**
     * Authorize a specific retest attempt beyond
     * config('assessment_retests.free_attempt_limit') (item 19). Not
     * "authorize retests" generally -- attempts within the free limit need
     * no ability check at all, since they need no admin action. No upper
     * bound past the free limit; the owner's explicit decision is that
     * repeated individual human approval is the deterrent, not a fixed
     * ceiling.
     */
    case AuthorizeRetestBeyondLimit = 'authorize_retest_beyond_limit';
}
