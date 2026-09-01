<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Payments\ReserveAssessmentBill;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentParticipant;
use App\Models\PaymentMethod;
use App\Security\RlsContextRunner;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;

/** Testing-only Filament boundary; canonical preview/reservation remain in payment actions. */
final readonly class CreateCollectiveBillAction
{
    public function __construct(private PreviewCollectiveBillSelection $preview, private ReserveAssessmentBill $reserve) {}

    /** @return list<array<string, mixed>> */
    public function choices(): array
    {
        $admin = $this->principal();
        $limit = (int) config('assessment_billing.max_items');
        $ids = AssessmentParticipant::query()->where('organization_id', $admin->branch_id)
            ->orderByDesc('id')->limit($limit)->pluck('id');

        return $ids->map(function (int $id): array {
            $item = $this->preview->execute([['assessmentParticipantId' => $id, 'consultationRequested' => false]])['items'][0];
            $enabled = $item['status'] === 'payable';

            return $item + ['enabled' => $enabled,
                'disabledReason' => $enabled ? null : 'Tidak tersedia untuk tagihan kolektif.'];
        })->values()->all();
    }

    /** @param list<array{assessmentParticipantId: int, consultationRequested: bool}> $selection */
    public function preview(array $selection): array
    {
        $this->principal();
        $preview = $this->preview->execute($selection);
        if (! $preview['canReserve'] || collect($preview['items'])->contains(fn (array $item): bool => $item['status'] !== 'payable')) {
            throw new DomainException('COLLECTIVE_SELECTION_NOT_AVAILABLE');
        }

        return $preview;
    }

    /** @param list<array{assessmentParticipantId: int, consultationRequested: bool}> $selection */
    public function confirm(array $selection, int $paymentMethodId, string $selectionHash): AssessmentBill
    {
        $admin = $this->principal();
        $key = 'p12b:'.$admin->id.':'.$selectionHash;

        return app(RlsContextRunner::class)->runAsService(
            fn (): AssessmentBill => $this->reserve->execute($admin, $selection, $paymentMethodId, $selectionHash, $key),
        );
    }

    /** @return array<int, string> */
    public function paymentMethods(): array
    {
        $this->principal();

        return PaymentMethod::query()->where('is_active', true)->whereIn('code', ['manual_transfer', 'xendit'])
            ->orderBy('id')->pluck('display_name', 'id')->all();
    }

    private function principal(): Admin
    {
        if (! app()->environment('testing')) {
            throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
        }
        $session = Filament::auth()->user();
        $admin = $session instanceof Admin && $session->exists ? Admin::query()->find($session->id) : null;
        if ($admin?->role !== AdminRole::BranchAdmin || $admin->branch_id === null || $admin->trashed()) {
            throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
        }

        return $admin;
    }
}
