<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Models\Entitlement;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;

final class ParticipantEntitlementGate
{
    public function assertReady(int $participantId, string $testType): Entitlement
    {
        $entitlement = Entitlement::query()
            ->where('participant_id', $participantId)
            ->where('test_type', $testType)
            ->first();

        if ($entitlement === null || $entitlement->status !== 'ready') {
            throw new EntitlementLocked;
        }

        return $entitlement;
    }
}
