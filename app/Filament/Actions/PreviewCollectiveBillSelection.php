<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Payments\PreviewAssessmentBill;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentParticipant;
use App\Models\Participant;
use App\Security\RlsContextRunner;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/** P12b-prep: internal projection only, not a registered Filament action or public endpoint. */
final readonly class PreviewCollectiveBillSelection
{
    public function __construct(private PreviewAssessmentBill $preview) {}

    /**
     * No caller-supplied principal, organization, payer, price, or confirmation intent.
     *
     * @param  list<array{assessmentParticipantId: int, consultationRequested: bool}>  $selection
     * @return array<string, mixed>
     */
    public function execute(array $selection): array
    {
        if (! app()->environment('testing')) {
            throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
        }
        $sessionAdmin = Filament::auth()->user();
        if (! $sessionAdmin instanceof Admin || ! $sessionAdmin->exists) {
            throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
        }
        // Stale session attributes must not supply the role or organization.
        $admin = Admin::query()->find($sessionAdmin->id);
        if ($admin?->role !== AdminRole::BranchAdmin || $admin->branch_id === null) {
            throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
        }
        $organizationId = $admin->branch_id;

        return app(RlsContextRunner::class)->runAsService(function () use ($selection, $organizationId): array {
            $preview = $this->preview->execute($organizationId, $selection, PayerType::Organization);
            $available = array_filter($preview['items'], fn (array $item): bool => $item['status'] !== 'unavailable');
            $attempts = AssessmentParticipant::query()->where('organization_id', $organizationId)
                ->whereIn('id', array_column($available, 'assessmentParticipantId'))
                ->whereHas('participant', fn (Builder $query) => $query->where('branch_id', $organizationId))
                ->with(['participant' => fn ($query) => $query->select(['id', 'full_name'])->where('branch_id', $organizationId)])
                ->get(['id', 'participant_id', 'external_candidate_id', 'assessment_attempt_id', 'assessment_round_id'])->keyBy('id');
            $items = [];
            foreach ($preview['items'] as $item) {
                $row = ['assessmentParticipantId' => $item['assessmentParticipantId'], 'status' => $item['status'], 'reason' => $item['reason']];
                if ($item['status'] !== 'unavailable') {
                    $attempt = $attempts->get($item['assessmentParticipantId']);
                    $participant = $attempt?->getRelation('participant');
                    if ($attempt === null || ! $participant instanceof Participant) {
                        // A row disappeared or changed scope between preview and label loading.
                        throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
                    }
                    $price = $item['snapshot'];
                    $row += ['participantName' => blank($participant->full_name) ? 'Nama belum dilengkapi' : $participant->full_name,
                        'externalCandidateId' => $attempt->external_candidate_id, 'assessmentAttemptId' => $attempt->assessment_attempt_id,
                        'period' => $attempt->assessment_round_id, 'packageCode' => $price['packageCode'], 'packageName' => $price['packageName'],
                        'baseAmount' => $price['baseAmount'], 'consultationRequested' => $price['consultationRequested'],
                        'consultationAmount' => $price['consultationAmount'], 'amount' => $price['amount'], 'currency' => $price['currency']];
                }
                $items[] = $row;
            }

            // Never return the raw preview: policy/snapshot internals are not portal data.
            return ['items' => $items, 'currency' => $preview['currency'], 'totalAmount' => $preview['totalAmount'],
                'paidCount' => $preview['paidCount'], 'freeCount' => $preview['freeCount'],
                'canReserve' => $preview['canReserve'], 'selectionHash' => $preview['selectionHash']];
        });
    }
}
