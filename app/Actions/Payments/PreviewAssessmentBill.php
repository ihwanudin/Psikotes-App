<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Data\Payments\AssessmentBillSelection;
use App\Enums\PayerType;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationSource;
use App\Models\Participant;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\ResolvePayerPolicy;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final readonly class PreviewAssessmentBill
{
    public function __construct(private AssessmentPriceSnapshot $prices, private ResolvePayerPolicy $policy) {}

    /**
     * Server-mapped principal only. No persistence, reservation locks, or external calls.
     *
     * @param  list<array{assessmentParticipantId: int, consultationRequested: bool}>  $selection
     * @return array<string, mixed>
     */
    public function execute(int $organizationId, array $selection, PayerType $payer, ?int $participantId = null): array
    {
        if (app(RlsContextRunner::class)->current()?->role !== 'service') {
            throw new LogicException('Preview requires service RLS context.');
        }
        $selection = (new AssessmentBillSelection($organizationId, $selection, $payer, $participantId))->items;
        $ids = array_column($selection, 'assessmentParticipantId');
        $organization = Branch::query()->find($organizationId);
        if ($organization === null) {
            throw new InvalidArgumentException('ORGANIZATION_NOT_AVAILABLE');
        }
        $attempts = AssessmentParticipant::query()->where('organization_id', $organizationId)->whereIn('id', $ids)
            ->with(['client', 'participant', 'package.items'])->get()->keyBy('id');
        $sources = IntegrationSource::query()->whereIn('integration_client_id', $attempts->pluck('integration_client_id'))
            ->whereIn('source_system', $attempts->pluck('source_system'))->get()
            ->keyBy(fn (IntegrationSource $source): string => $source->integration_client_id.':'.$source->source_system);
        $charges = AssessmentCharge::query()->where('organization_id', $organizationId)
            ->whereIn('assessment_participant_id', $ids)->get()->keyBy('assessment_participant_id');
        $claimed = DB::table('assessment_bill_items')->whereIn('charge_id', $charges->pluck('id'))->pluck('charge_id')->all();
        $at = now();
        $items = [];
        $total = $paidCount = $freeCount = 0;
        $unavailable = false;
        foreach ($selection as $selected) {
            $id = $selected['assessmentParticipantId'];
            $item = ['assessmentParticipantId' => $id, 'status' => 'unavailable', 'reason' => null,
                'snapshot' => null, 'snapshotHash' => null, 'policySnapshot' => null];
            try {
                $attempt = $attempts->get($id);
                $participant = $attempt?->getRelation('participant');
                if ($attempt === null || ! $participant instanceof Participant || $participant->branch_id !== $organizationId
                    || ($payer === PayerType::SelfPay && $attempt->participant_id !== $participantId)) {
                    throw new DomainException('ASSESSMENT_NOT_AVAILABLE');
                }
                if (($attempt->metadata['checkout_contract_version'] ?? null) !== 'checkout-v2') {
                    throw new DomainException('CHECKOUT_ATTEMPT_REQUIRED');
                }
                if ($attempt->assessment_status !== 'PROVISIONED' || $attempt->revoked_at !== null || $attempt->finalized_at !== null) {
                    throw new DomainException('ASSESSMENT_NOT_BILLABLE');
                }
                $source = $sources->get($attempt->integration_client_id.':'.$attempt->source_system);
                if ($source === null || $source->contract_version !== 'checkout-v2') {
                    throw new DomainException('CHECKOUT_SOURCE_REQUIRED');
                }
                $decision = $this->policy->resolve($organization, $attempt->client, $source, $attempt->package, $at, $payer->value);
                if ($decision->rejectionReason !== null) {
                    throw new DomainException($decision->rejectionReason);
                }
                $charge = $charges->get($id);
                if ($charge !== null && in_array($charge->id, $claimed, true)) {
                    throw new DomainException('CHARGE_ALREADY_BILLED');
                }
                if ($charge?->free_settled_at !== null) {
                    throw new DomainException('CHARGE_ALREADY_SETTLED');
                }
                if ($charge !== null && $charge->payer_type !== $payer->value) {
                    throw new DomainException('CHARGE_PAYER_LOCKED');
                }
                $snapshot = $charge === null
                    ? $this->prices->capture($attempt->package, $selected['consultationRequested'])
                    : $this->prices->fromCharge($charge, $selected['consultationRequested']);
                $amount = $snapshot['amount'];
                if (! is_int($amount)) {
                    throw new LogicException('Snapshot amount must be an integer.');
                }
                $item['status'] = $amount === 0 ? 'free' : 'payable';
                $item['snapshot'] = $snapshot;
                $item['snapshotHash'] = $this->hash($snapshot);
                $item['policySnapshot'] = ['organizationId' => $organizationId, 'integrationClientId' => $attempt->integration_client_id,
                    'sourceId' => $source->id, 'contractVersion' => $source->contract_version,
                    'allowedPayerTypes' => array_map(fn (PayerType $type): string => $type->value, $decision->allowedPayerTypes),
                    'payerType' => $decision->selectedPayerType?->value, 'lockedPayerType' => $decision->lockedPayerType?->value];
            } catch (DomainException $exception) {
                $item['reason'] = $exception->getMessage();
                $unavailable = true;
            }
            $amount = $item['snapshot']['amount'] ?? null;
            if (is_int($amount)) {
                // An overflow rejects the whole preview, not just the last item.
                if ($total > PHP_INT_MAX - $amount) {
                    throw new DomainException('TOTAL_OVERFLOW');
                }
                $total += $amount;
                if ($amount === 0) {
                    $freeCount++;
                } else {
                    $paidCount++;
                }
            }
            $items[] = $item;
        }

        return ['items' => $items, 'currency' => 'IDR', 'totalAmount' => $unavailable ? null : $total,
            'paidCount' => $paidCount, 'freeCount' => $freeCount, 'canReserve' => ! $unavailable && $paidCount > 0,
            'selectionHash' => $unavailable ? null : $this->hash(['organizationId' => $organizationId,
                'payerType' => $payer->value, 'participantId' => $participantId, 'items' => $items])];
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
