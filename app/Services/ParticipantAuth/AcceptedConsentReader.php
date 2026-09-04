<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Models\Participant;
use App\Registration\ConsentDocument;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Internal participant-bound evidence, not authorization, attempt consent or legal approval.
 * Caller supplies the authorized persisted participant in its existing RLS context/transaction.
 * Never accept request-selected participant IDs here. No context elevation, locks or writes;
 * existing callers retain their scope guards. Configuration exceptions deliberately propagate.
 */
final class AcceptedConsentReader
{
    public function isAccepted(#[SensitiveParameter] Participant $participant, string $type): bool
    {
        $document = ConsentDocument::for($type);

        return DB::table('consent_records')->where('participant_id', $participant->id)
            ->where('consent_type', $type)->where('document_version', $document->version)
            ->where('document_hash', $document->hash)->where('status', 'accepted')
            ->whereNotNull('consented_at')->where('consented_at', '<=', now())->whereNull('withdrawn_at')->exists();
    }
}
