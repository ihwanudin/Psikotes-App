<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Models\Participant;
use App\Registration\ConsentDocument;
use Carbon\CarbonImmutable;
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
        $asOf = CarbonImmutable::instance(now());

        return $this->isAcceptedForDocumentAt($participant, $document, $asOf);
    }

    /** Captured server document and evaluation time; current evidence, not historical withdrawal reconstruction. */
    public function isAcceptedForDocumentAt(
        #[SensitiveParameter] Participant $participant,
        ConsentDocument $document,
        CarbonImmutable $asOf,
    ): bool {
        return DB::table('consent_records')->where('participant_id', $participant->id)
            ->where('consent_type', $document->type)->where('document_version', $document->version)
            ->where('document_hash', $document->hash)->where('status', 'accepted')
            ->whereNotNull('consented_at')->where('consented_at', '<=', $asOf)->whereNull('withdrawn_at')->exists();
    }
}
