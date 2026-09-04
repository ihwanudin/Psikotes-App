<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Data\Integrations\CheckoutSummary;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\Participant;
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AcceptedConsentReader;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrerequisiteFrame;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Internal composition only. The credential lifecycle owns graph validation, locks and time.
 * Supplied models/time are not authorization; no transaction, context elevation or lazy graph reload here.
 */
final readonly class CheckoutSummaryComposer
{
    public function __construct(
        private CheckoutProfileMapper $profiles,
        private CheckoutPaymentFactsReader $payments,
        private AcceptedConsentReader $consents,
        private AssessmentEntitlementGate $gate,
        private RlsContextRunner $contexts,
    ) {}

    public function compose(AssessmentParticipant $attempt, #[SensitiveParameter] Participant $participant,
        Branch $branch, CarbonImmutable $asOf): CheckoutSummary
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() === 0) {
            throw new LogicException('Checkout summary composition requires its validated service transaction.');
        }
        if (! $branch->exists || ! $participant->exists || $attempt->organization_id !== $branch->id
            || $attempt->participant_id !== $participant->id || $participant->branch_id !== $branch->id) {
            throw new DomainException('CHECKOUT_SUMMARY_UNAVAILABLE');
        }
        $timezone = date_default_timezone_get();
        $branchName = $this->branchLabel($branch);
        try {
            $product = $this->payments->projectAt($attempt, $asOf);
            $psychotest = $this->document('psychotest');
            $dass = in_array('dass21', $product->testTypes, true) ? $this->document('dass') : null;
            $legalReviewPending = config('consent.legal_review_pending');
            if (! is_bool($legalReviewPending)) {
                throw new DomainException('CHECKOUT_SUMMARY_UNAVAILABLE');
            }
            $frame = new AssessmentPrerequisiteFrame($asOf, $timezone, $psychotest, $dass);
            $profile = $this->profiles->map($participant, $asOf->setTimezone($timezone)->toDateTimeImmutable());
            $psychotestAccepted = $this->consents->isAcceptedForDocumentAt($participant, $psychotest, $asOf);
            $dassAccepted = $dass !== null && $this->consents->isAcceptedForDocumentAt($participant, $dass, $asOf);
            $principal = new AssessmentPrincipal($participant->id, $branch->id, $attempt->id);
            $readyByType = [];
            foreach ($product->testTypes as $type) {
                try {
                    $this->gate->assertReadyAt($principal, $type, $frame);
                    $readyByType[$type] = true;
                } catch (EntitlementLocked) {
                    $readyByType[$type] = false;
                }
            }

            return new CheckoutSummary($profile, $product, $branchName, $psychotest, $dass,
                $psychotestAccepted, $dassAccepted, $legalReviewPending, $readyByType);
        } catch (DomainException|InvalidArgumentException) {
            throw new DomainException('CHECKOUT_SUMMARY_UNAVAILABLE');
        }
    }

    private function document(string $type): ConsentDocument
    {
        $document = ConsentDocument::for($type);
        foreach ([$document->version, $document->title, $document->text] as $value) {
            if (trim($value) === '') {
                throw new DomainException('CHECKOUT_SUMMARY_UNAVAILABLE');
            }
        }

        return $document;
    }

    private function branchLabel(Branch $branch): string
    {
        foreach (['display_name', 'name'] as $field) {
            $label = $branch->getAttribute($field);
            if (is_string($label) && trim($label) !== '') {
                return $label;
            }
        }

        throw new DomainException('CHECKOUT_SUMMARY_UNAVAILABLE');
    }
}
