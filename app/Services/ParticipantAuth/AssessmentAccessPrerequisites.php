<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Models\Participant;
use App\Registration\ConsentDocument;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/** Read-only prerequisites shared with the future settlement activation action. */
final class AssessmentAccessPrerequisites
{
    public function __construct(private readonly AcceptedConsentReader $consents) {}

    public function assertSatisfied(Participant $participant, string $testType): void
    {
        $asOf = CarbonImmutable::instance(now());
        $this->assertUsing($participant, $testType, $asOf,
            $asOf->setTimezone(date_default_timezone_get())->toDateString(),
            fn (string $type): ConsentDocument => ConsentDocument::for($type));
    }

    public function assertSatisfiedAt(Participant $participant, string $testType, AssessmentPrerequisiteFrame $frame): void
    {
        $this->assertUsing($participant, $testType, $frame->asOf, $frame->serverDate, $frame->documentFor(...));
    }

    /** @param Closure(string): ConsentDocument $documentFor */
    private function assertUsing(Participant $participant, string $testType, CarbonImmutable $asOf, string $serverDate, Closure $documentFor): void
    {
        foreach (['full_name', 'education_level', 'intended_field', 'phone'] as $field) {
            $value = $participant->getAttribute($field);
            if (! is_string($value) || trim($value) === '') {
                throw new EntitlementLocked;
            }
        }
        if (! in_array($participant->getAttribute('gender'), ['male', 'female'], true)
            || $participant->getAttribute('birth_date') === null || $participant->birth_date->toDateString() >= $serverDate) {
            throw new EntitlementLocked;
        }
        $types = $testType === 'dass21' ? ['psychotest', 'dass'] : ['psychotest'];
        foreach ($types as $type) {
            if (! $this->consents->isAcceptedForDocumentAt($participant, $documentFor($type), $asOf)) {
                throw new EntitlementLocked;
            }
        }
        $verification = DB::table('identity_verifications')->where('participant_id', $participant->id)
            ->where('checked_at', '<=', $asOf)->first();
        if ($verification === null || $verification->manual_status === 'rejected') {
            throw new EntitlementLocked;
        }
        $manualAccepted = $verification->manual_status === 'accepted'
            && $verification->reviewed_by_admin_id !== null && $verification->reviewed_at !== null
            && CarbonImmutable::parse($verification->reviewed_at, $asOf->getTimezone())->gte(CarbonImmutable::parse($verification->checked_at, $asOf->getTimezone()))
            && CarbonImmutable::parse($verification->reviewed_at, $asOf->getTimezone())->lte($asOf);
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
