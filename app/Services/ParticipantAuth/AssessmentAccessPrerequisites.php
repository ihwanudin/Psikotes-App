<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Models\Participant;
use App\Registration\ConsentDocument;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only prerequisites shared with the future settlement activation action. */
final class AssessmentAccessPrerequisites
{
    public function assertSatisfied(Participant $participant, string $testType): void
    {
        foreach (['full_name', 'education_level', 'intended_field', 'phone'] as $field) {
            $value = $participant->getAttribute($field);
            if (! is_string($value) || trim($value) === '') {
                throw new EntitlementLocked;
            }
        }
        if (! in_array($participant->getAttribute('gender'), ['male', 'female'], true)
            || $participant->getAttribute('birth_date') === null || ! $participant->birth_date->lt(today())) {
            throw new EntitlementLocked;
        }
        $types = $testType === 'dass21' ? ['psychotest', 'dass'] : ['psychotest'];
        foreach ($types as $type) {
            $document = ConsentDocument::for($type);
            $accepted = DB::table('consent_records')->where('participant_id', $participant->id)
                ->where('consent_type', $type)->where('document_version', $document->version)
                ->where('document_hash', $document->hash)->where('status', 'accepted')
                ->whereNotNull('consented_at')->where('consented_at', '<=', now())->whereNull('withdrawn_at')->exists();
            if (! $accepted) {
                throw new EntitlementLocked;
            }
        }
        $verification = DB::table('identity_verifications')->where('participant_id', $participant->id)
            ->where('checked_at', '<=', now())->first();
        if ($verification === null || $verification->manual_status === 'rejected') {
            throw new EntitlementLocked;
        }
        $manualAccepted = $verification->manual_status === 'accepted'
            && $verification->reviewed_by_admin_id !== null && $verification->reviewed_at !== null
            && CarbonImmutable::parse($verification->reviewed_at)->gte(CarbonImmutable::parse($verification->checked_at))
            && CarbonImmutable::parse($verification->reviewed_at)->lte(now());
        if (! $manualAccepted && ! ($verification->manual_status === 'pending' && $verification->outcome === 'match')) {
            throw new EntitlementLocked;
        }
        $evidenceCount = DB::table('identity_evidence')->where('participant_id', $participant->id)
            ->whereIn('type', ['identity_document', 'initial_selfie'])
            ->whereNotNull('updated_at')->where('updated_at', '<=', $verification->checked_at)->count();
        if ($evidenceCount !== 2) {
            throw new EntitlementLocked;
        }
    }
}
