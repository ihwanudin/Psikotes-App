<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Data\Integrations\CheckoutSummary;
use App\Data\Integrations\CheckoutSummaryEvidence;
use App\Models\Branch;
use App\Models\PackageItem;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AcceptedConsentReader;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrerequisiteFrame;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use DomainException;
use Illuminate\Support\Collection;
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
        private CheckoutPaymentActionProjector $paymentActions,
    ) {}

    public function compose(#[SensitiveParameter] CheckoutSummaryEvidence $evidence): CheckoutSummary
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() === 0) {
            throw new LogicException('Checkout summary composition requires its validated service transaction.');
        }
        $attempt = $evidence->attempt;
        $participant = $evidence->participant;
        $branch = $evidence->organization;
        $asOf = $evidence->asOf;
        $this->assertCanonicalEvidence($evidence);
        $timezone = date_default_timezone_get();
        $branchName = $this->branchLabel($branch);
        try {
            $this->assertMandatoryComposition($evidence->package);
            $product = $this->payments->projectAt($attempt, $asOf);
            $psychotest = $this->document('psychotest');
            if (! in_array('dass21', $product->testTypes, true)
                || array_diff($product->testTypes, ['dass21']) === []) {
                throw new DomainException('CHECKOUT_SUMMARY_UNAVAILABLE');
            }
            $dass = $this->document('dass');
            $legalReviewPending = config('consent.legal_review_pending');
            if (! is_bool($legalReviewPending)) {
                throw new DomainException('CHECKOUT_SUMMARY_UNAVAILABLE');
            }
            $frame = new AssessmentPrerequisiteFrame($asOf, $timezone, $psychotest, $dass);
            $profile = $this->profiles->map($participant, $asOf->setTimezone($timezone)->toDateTimeImmutable());
            $psychotestAccepted = $this->consents->isAcceptedForDocumentAt($participant, $psychotest, $asOf);
            $dassAccepted = $this->consents->isAcceptedForDocumentAt($participant, $dass, $asOf);
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

            $paymentAction = $this->paymentActions->project(
                $evidence->principal,
                $branch,
                $evidence->client,
                $evidence->source,
                $evidence->package,
                $attempt,
                $evidence->charge,
                $evidence->billItem,
                $evidence->bill,
                $evidence->paymentMethod,
                $evidence->paymentEvidenceCanonical,
                $asOf,
                $psychotestAccepted,
                $dassAccepted,
            );

            return new CheckoutSummary($profile, $product, $paymentAction, $branchName, $psychotest, $dass,
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

    private function assertCanonicalEvidence(CheckoutSummaryEvidence $evidence): void
    {
        $principal = $evidence->principal;
        $attempt = $evidence->attempt;
        if (! $evidence->organization->exists || ! $evidence->client->exists || ! $evidence->source->exists
            || ! $evidence->package->exists || ! $attempt->exists || ! $evidence->participant->exists
            || $evidence->organization->isDirty() || $evidence->client->isDirty() || $evidence->source->isDirty()
            || $evidence->package->isDirty() || $attempt->isDirty() || $evidence->participant->isDirty()
            || $principal->organizationId !== $evidence->organization->id
            || $principal->integrationClientId !== $evidence->client->id
            || $principal->integrationSourceId !== $evidence->source->id
            || $principal->packageId !== $evidence->package->id
            || $principal->assessmentParticipantId !== $attempt->id
            || $principal->participantId !== $evidence->participant->id
            || $principal->sourceSystem !== $evidence->source->source_system
            || $principal->assessmentStatus !== $attempt->assessment_status
            || $principal->fundingMode !== $attempt->funding_mode
            || $evidence->client->organization_id !== $evidence->organization->id
            || $evidence->source->integration_client_id !== $evidence->client->id
            || $evidence->source->contract_version !== 'checkout-v2'
            || $attempt->organization_id !== $evidence->organization->id
            || $attempt->participant_id !== $evidence->participant->id
            || $evidence->participant->branch_id !== $evidence->organization->id
            || $attempt->package_id !== $evidence->package->id
            || $attempt->integration_client_id !== $evidence->client->id
            || $attempt->source_system !== $evidence->source->source_system
            || $attempt->assessment_attempt_id !== $principal->assessmentAttemptId
            || ! $attempt->relationLoaded('package') || $attempt->getRelation('package') !== $evidence->package) {
            throw new DomainException('CHECKOUT_SUMMARY_UNAVAILABLE');
        }
    }

    private function assertMandatoryComposition(TestPackage $package): void
    {
        $items = $package->relationLoaded('items') ? $package->getRelation('items') : null;
        if (! $items instanceof Collection || $items->isEmpty()) {
            throw new DomainException('CHECKOUT_SUMMARY_UNAVAILABLE');
        }
        $types = [];
        foreach ($items as $item) {
            if (! $item instanceof PackageItem || ! $item->exists || $item->isDirty()
                || $item->package_id !== $package->id) {
                throw new DomainException('CHECKOUT_SUMMARY_UNAVAILABLE');
            }
            $types[] = $item->test_type;
        }
        TestPackage::canonicalComposition($types);
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
