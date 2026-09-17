<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\AssessmentParticipant;
use App\Models\CheckoutHandoff;
use App\Models\IntegrationSource;
use Illuminate\Database\Eloquent\Collection;

/** One application-level source of truth for the checkout-handoff schema invariants. */
final readonly class CheckoutHandoffHistoryValidator
{
    private const string CONTRACT_VERSION = 'checkout-v2';

    private const string PURPOSE = 'checkout-handoff';

    private const string DESTINATION = 'integrated-checkout-session';

    /** @param Collection<int, CheckoutHandoff> $history */
    public function valid(Collection $history, AssessmentParticipant $attempt, IntegrationSource $source): bool
    {
        $active = 0;
        foreach ($history as $index => $handoff) {
            $scope = $handoff->assessment_participant_id === $attempt->id
                && $handoff->organization_id === $attempt->organization_id
                && $handoff->participant_id === $attempt->participant_id
                && $handoff->package_id === $attempt->package_id
                && $handoff->integration_client_id === $attempt->integration_client_id
                && $handoff->integration_source_id === $source->id
                && $handoff->source_system === $attempt->source_system
                && $handoff->contract_version === self::CONTRACT_VERSION
                && $handoff->purpose === self::PURPOSE
                && $handoff->destination === self::DESTINATION
                && $handoff->issue_number === $index + 1
                && $handoff->expires_at->greaterThan($handoff->issued_at)
                && $handoff->expires_at->lessThanOrEqualTo($handoff->issued_at->addSeconds(600));
            $lifecycle = match ($handoff->status) {
                'ISSUED' => $handoff->active_marker === true
                    && $handoff->consumed_at === null && $handoff->revoked_at === null
                    && $handoff->expired_at === null && $handoff->revocation_reason === null,
                'CONSUMED' => $handoff->active_marker === null && $handoff->consumed_at !== null
                    && $handoff->consumed_at->greaterThanOrEqualTo($handoff->issued_at)
                    && $handoff->consumed_at->lessThan($handoff->expires_at)
                    && $handoff->revoked_at === null && $handoff->expired_at === null
                    && $handoff->revocation_reason === null,
                'REVOKED' => $handoff->active_marker === null && $handoff->consumed_at === null
                    && $handoff->revoked_at !== null
                    && $handoff->revoked_at->greaterThanOrEqualTo($handoff->issued_at)
                    && $handoff->expired_at === null
                    && in_array($handoff->revocation_reason,
                        ['REISSUED', 'ATTEMPT_REVOKED', 'SOURCE_REVOKED', 'CLIENT_REVOKED'], true),
                'EXPIRED' => $handoff->active_marker === null && $handoff->consumed_at === null
                    && $handoff->revoked_at === null && $handoff->expired_at !== null
                    && $handoff->expired_at->greaterThanOrEqualTo($handoff->expires_at)
                    && $handoff->revocation_reason === null,
                default => false,
            };
            if (! $scope || ! $lifecycle) {
                return false;
            }
            if ($handoff->status === 'ISSUED') {
                $active++;
            }
        }

        return $active <= 1;
    }
}
