<?php

declare(strict_types=1);

namespace Tests\Support;

final class AssessmentEntitlementBlockedStates
{
    public static function cases(): iterable
    {
        yield 'pending bill' => ['assessment_bills', ['status' => 'pending', 'paid_at' => null]];
        yield 'expired bill' => ['assessment_bills', ['status' => 'expired', 'paid_at' => null]];
        yield 'rejected bill' => ['assessment_bills', ['status' => 'rejected', 'paid_at' => null]];
        yield 'wrong total' => ['assessment_bills', ['amount' => 200]];
        yield 'wrong count' => ['assessment_bills', ['item_count' => 2]];
        yield 'future payment' => ['assessment_bills', ['paid_at' => '2099-01-01 00:00:00']];
        yield 'future settlement' => ['assessment_bill_items', ['settled_at' => '2099-01-01 00:00:00']];
        yield 'legacy marker' => ['assessment_participants', ['metadata' => null]];
        yield 'revoked' => ['assessment_participants', ['revoked_at' => '2026-01-01 00:00:00']];
        yield 'void' => ['assessment_participants', ['assessment_status' => 'VOID']];
        yield 'not activated' => ['assessment_participants', ['assessment_status' => 'PROVISIONED']];
        yield 'finalized' => ['assessment_participants', ['finalized_at' => '2026-01-01 00:00:00']];
        yield 'locked entitlement' => ['assessment_entitlements', ['status' => 'locked', 'ready_at' => null]];
        yield 'already started' => ['assessment_entitlements', ['status' => 'in_progress', 'started_at' => '2026-01-01 00:00:00']];
        yield 'future ready' => ['assessment_entitlements', ['ready_at' => '2099-01-01 00:00:00']];
        yield 'deleted participant' => ['participants', ['deleted_at' => '2026-01-01 00:00:00']];
        yield 'profile missing' => ['participants', ['full_name' => '']];
        yield 'invalid birthday' => ['participants', ['birth_date' => '2099-01-01']];
        yield 'identity pending' => ['identity_verifications', ['outcome' => 'pending']];
        yield 'identity mismatch' => ['identity_verifications', ['outcome' => 'mismatch']];
        yield 'identity error' => ['identity_verifications', ['outcome' => 'error']];
        yield 'manual reject overrides match' => ['identity_verifications', ['manual_status' => 'rejected']];
        yield 'manual accepted without reviewer' => ['identity_verifications', ['manual_status' => 'accepted']];
        yield 'future identity' => ['identity_verifications', ['checked_at' => '2099-01-01 00:00:00']];
        yield 'new evidence unverified' => ['identity_evidence', ['updated_at' => '2099-01-01 00:00:00']];
        yield 'wrong consent hash' => ['consent_records', ['document_hash' => str_repeat('a', 64)]];
        yield 'old consent version' => ['consent_records', ['document_version' => 'old']];
        yield 'declined consent' => ['consent_records', ['status' => 'declined', 'consented_at' => null]];
        yield 'future consent' => ['consent_records', ['consented_at' => '2099-01-01 00:00:00']];
        yield 'snapshot invalid' => ['assessment_charges', ['price_snapshot' => '{}']];
    }
}
