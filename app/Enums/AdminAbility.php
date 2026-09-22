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
     * Approve a bridge-funding grant (item 18 -- the holding company covers
     * a DIRECT_PUBLIC participant's assessment). Deliberately separate from
     * VerifyPayments, even though both are "an admin attests money has been
     * handled outside the normal participant-pays flow" (Lead's
     * separation-of-concerns reasoning, already applied to GenerateReports
     * vs ReviewReports): widening VerifyPayments for this narrow need would
     * silently widen access to ordinary manual-transfer verification too.
     */
    case ApproveBridgeFunding = 'approve_bridge_funding';

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

    /**
     * See the EXISTENCE of a failed/not-scorable `assessment_scoring_attempts`
     * row (session, participant, instrument, attempted_at) without its
     * psychometric REASON detail (`reason_code`) -- Lead's 2026-09-23
     * decision, deliberately a separate ability from ReviewReports rather
     * than reusing GenerateReports/ViewParticipants: this is operational
     * visibility ("a participant needs re-invitation/re-administration"),
     * not report access or general participant management, and the two
     * concepts must be able to diverge independently later. A viewer with
     * ReviewReports (psychologist) sees the reason detail too via that
     * ability, not this one -- see Admin::canPerform()'s two separate
     * match arms and the scoring-failures review page, which renders the
     * reason column only when ReviewReports is also true.
     */
    case ReviewScoringFailures = 'review_scoring_failures';
}
