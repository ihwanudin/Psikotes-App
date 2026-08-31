<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Data\Payments\AssessmentBillSelection;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Security\RlsContextRunner;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Internal only: the caller authenticates the principal before entering service context. */
final readonly class ReserveAssessmentBill
{
    public function __construct(private PreviewAssessmentBill $preview) {}

    /** @param list<array{assessmentParticipantId: int, consultationRequested: bool}> $selection */
    public function execute(Admin|Participant $principal, array $selection, int $paymentMethodId, string $selectionHash, string $idempotencyKey): AssessmentBill
    {
        if (app(RlsContextRunner::class)->current()?->role !== 'service') {
            throw new LogicException('Reservation requires service RLS context.');
        }
        if (! $principal->exists || $principal->trashed()) {
            throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
        }
        if ($paymentMethodId < 1 || ! preg_match('/^[a-f0-9]{64}$/D', $selectionHash)
            || ! preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $idempotencyKey)) {
            throw new InvalidArgumentException('INVALID_BILL_REQUEST');
        }

        return DB::transaction(function () use ($principal, $selection, $paymentMethodId, $selectionHash, $idempotencyKey): AssessmentBill {
            // Match funding-policy's admin -> organization order. Self identities lock after organization.
            $actor = $principal instanceof Admin
                ? Admin::query()->lockForUpdate()->find($principal->id)
                : Participant::query()->find($principal->id);
            if ($actor === null || ($actor instanceof Admin && $actor->role !== AdminRole::BranchAdmin)
                || $actor->branch_id === null) {
                throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
            }
            $input = new AssessmentBillSelection($actor->branch_id, $selection,
                $actor instanceof Admin ? PayerType::Organization : PayerType::SelfPay,
                $actor instanceof Participant ? $actor->id : null);
            $organization = Branch::query()->lockForUpdate()->find($input->organizationId);
            if ($organization === null) {
                throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
            }
            if ($actor instanceof Participant) {
                $actor = Participant::query()->lockForUpdate()->find($actor->id);
                if ($actor === null || $actor->branch_id !== $input->organizationId) {
                    throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
                }
            }
            $requestHash = hash('sha256', json_encode([
                'organizationId' => $input->organizationId, 'payerType' => $input->payer->value,
                'participantId' => $input->participantId, 'selection' => $input->items,
                'selectionHash' => $selectionHash, 'paymentMethodId' => $paymentMethodId,
            ], JSON_THROW_ON_ERROR));
            // Organization lock serializes absent-key claims too; DB unique indexes are the final guard.
            $existing = AssessmentBill::query()->where('organization_id', $input->organizationId)
                ->where('payer_type', $input->payer->value)->where('payer_participant_id', $input->participantId)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }
            $this->lockSelection($input);
            $method = PaymentMethod::query()->lockForUpdate()->find($paymentMethodId);
            if ($method === null || ! $method->is_active || ! in_array($method->code, ['xendit', 'manual_transfer'], true)) {
                throw new DomainException('PAYMENT_METHOD_NOT_AVAILABLE');
            }
            $preview = $this->preview->execute($input->organizationId, $input->items, $input->payer, $input->participantId);
            if ($preview['selectionHash'] === null || ! hash_equals($selectionHash, $preview['selectionHash'])) {
                throw new DomainException('PREVIEW_CHANGED');
            }
            if (! $preview['canReserve']) {
                throw new DomainException('FREE_CHECKOUT_REQUIRED');
            }

            return $this->persist($input, $preview, $actor, $paymentMethodId, $idempotencyKey, $requestHash);
        });
    }

    private function lockSelection(AssessmentBillSelection $input): void
    {
        // Lock policy parents first, as UpdateFundingPolicy does. The org lock is deliberately coarse.
        $clients = DB::table('integration_clients')->where('organization_id', $input->organizationId)
            ->orderBy('id')->lockForUpdate()->pluck('id');
        DB::table('integration_sources')->whereIn('integration_client_id', $clients)->orderBy('id')->lockForUpdate()->get(['id']);
        $attempts = AssessmentParticipant::query()->where('organization_id', $input->organizationId)
            ->whereIn('id', array_column($input->items, 'assessmentParticipantId'))->orderBy('id')->lockForUpdate()->get();
        DB::table('participants')->where('branch_id', $input->organizationId)->whereIn('id', $attempts->pluck('participant_id'))
            ->orderBy('id')->lockForUpdate()->get(['id']);
        // Parent FOR UPDATE also blocks FK-based insertion of package items until commit.
        DB::table('packages')->whereIn('id', $attempts->pluck('package_id'))->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table('package_items')->whereIn('package_id', $attempts->pluck('package_id'))->orderBy('id')->lockForUpdate()->get(['id']);
        AssessmentCharge::query()->where('organization_id', $input->organizationId)
            ->whereIn('assessment_participant_id', $attempts->pluck('id'))->orderBy('assessment_participant_id')->lockForUpdate()->get();
    }

    /** @param array<string, mixed> $preview */
    private function persist(AssessmentBillSelection $input, array $preview, Admin|Participant $actor, int $method, string $key, string $requestHash): AssessmentBill
    {
        $bill = AssessmentBill::create(['organization_id' => $input->organizationId, 'payer_type' => $input->payer->value,
            'payer_participant_id' => $input->participantId, 'public_reference' => 'AB_'.Str::ulid(),
            'amount' => $preview['totalAmount'], 'currency' => 'IDR', 'item_count' => $preview['paidCount'],
            'selection_hash' => $preview['selectionHash'], 'idempotency_key' => $key, 'request_hash' => $requestHash,
            'status' => 'reserved', 'payment_method_id' => $method]);
        $attempts = AssessmentParticipant::query()->where('organization_id', $input->organizationId)
            ->whereIn('id', array_column($input->items, 'assessmentParticipantId'))->get()->keyBy('id');
        $charges = AssessmentCharge::query()->where('organization_id', $input->organizationId)
            ->whereIn('assessment_participant_id', $attempts->keys())->get()->keyBy('assessment_participant_id');
        foreach ($preview['items'] as $item) {
            if ($item['status'] === 'free') {
                continue; // Separate explicit free-settlement flow; no entitlement or settlement here.
            }
            $attempt = $attempts->get($item['assessmentParticipantId']);
            if ($attempt === null) {
                throw new LogicException('Locked assessment disappeared.');
            }
            $price = $item['snapshot'];
            $charge = $charges->get($attempt->id);
            if ($charge === null) {
                $charge = AssessmentCharge::create(['assessment_participant_id' => $attempt->id,
                    'organization_id' => $input->organizationId, 'participant_id' => $attempt->participant_id, 'package_id' => $attempt->package_id,
                    'payer_type' => $input->payer->value, 'base_amount' => $price['baseAmount'],
                    'consultation_amount' => $price['consultationAmount'], 'consultation_requested' => $price['consultationRequested'],
                    'amount' => $price['amount'], 'currency' => 'IDR', 'price_snapshot' => $price, 'policy_snapshot' => $item['policySnapshot']]);
            }
            AssessmentBillItem::create(['bill_id' => $bill->id, 'charge_id' => $charge->id,
                'organization_id' => $input->organizationId, 'participant_id' => $attempt->participant_id,
                'payer_type' => $input->payer->value, 'payer_participant_id' => $input->participantId,
                'amount' => $charge->amount, 'currency' => 'IDR']);
        }
        $at = now();
        DB::table('audit_logs')->insert(['branch_id' => $input->organizationId,
            'actor_type' => $actor instanceof Admin ? 'admin' : 'participant', 'actor_id' => (string) $actor->id,
            'action' => 'assessment_bill.reserved', 'subject_type' => AssessmentBill::class, 'subject_id' => (string) $bill->id,
            'context' => json_encode(['reference' => $bill->public_reference, 'selectionHash' => $bill->selection_hash,
                'amount' => $bill->amount, 'currency' => $bill->currency, 'itemCount' => $bill->item_count,
                'payerType' => $bill->payer_type, 'paymentMethodId' => $method], JSON_THROW_ON_ERROR),
            'occurred_at' => $at, 'expires_at' => $at->copy()->addYears(2)]);

        return $bill;
    }
}
