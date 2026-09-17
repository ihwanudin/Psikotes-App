<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssessmentParticipants\Pages;

use App\Filament\Actions\CreateCollectiveBillAction;
use App\Filament\Resources\AssessmentParticipants\AssessmentParticipantResource;
use App\Filament\Resources\OrganizationBills\OrganizationBillResource;
use DomainException;
use Filament\Resources\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

final class CreateCollectiveBill extends Page
{
    protected static string $resource = AssessmentParticipantResource::class;

    protected static ?string $title = 'Buat tagihan kolektif';

    /** @var list<mixed> Browser-hydrated input; selection() performs strict canonicalization. */
    public array $selected = [];

    /** @var array<int|string, bool> */
    public array $consultation = [];

    public ?int $paymentMethodId = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $preview = null;

    /** @var list<array{assessmentParticipantId: int, consultationRequested: bool}> */
    #[Locked]
    public array $reviewedSelection = [];

    public function mount(): void
    {
        app(CreateCollectiveBillAction::class)->choices();
    }

    public function updatedSelected(): void
    {
        $this->clearPreview();
    }

    public function updatedConsultation(): void
    {
        $this->clearPreview();
    }

    public function review(): void
    {
        $this->clearPreview();
        try {
            $this->reviewedSelection = $this->selection();
            $this->preview = app(CreateCollectiveBillAction::class)->preview($this->reviewedSelection);
        } catch (AuthorizationException|DomainException|InvalidArgumentException) {
            $this->addError('selected', 'Pilihan tidak lagi tersedia. Perbarui daftar dan tinjau ulang.');
        }
    }

    public function confirm(): void
    {
        try {
            if ($this->preview === null || $this->reviewedSelection === [] || ! is_int($this->paymentMethodId)) {
                throw new DomainException('PREVIEW_REQUIRED');
            }
            $bill = app(CreateCollectiveBillAction::class)->confirm(
                $this->reviewedSelection, $this->paymentMethodId, (string) $this->preview['selectionHash'],
            );
            $this->redirect(OrganizationBillResource::getUrl('view', ['record' => $bill]));
        } catch (AuthorizationException|DomainException|InvalidArgumentException) {
            $this->clearPreview();
            $this->addError('selected', 'Tinjauan berubah atau tidak lagi tersedia. Tinjau ulang sebelum mengonfirmasi.');
        }
    }

    public function render(): View
    {
        $action = app(CreateCollectiveBillAction::class);

        return view('filament.resources.assessment-participants.pages.create-collective-bill', [
            'choices' => $action->choices(), 'paymentMethods' => $action->paymentMethods(),
        ])->layout($this->getLayout(), $this->getLayoutData());
    }

    /** @return list<array{assessmentParticipantId: int, consultationRequested: bool}> */
    private function selection(): array
    {
        return array_map(function (mixed $value): array {
            if (! is_int($value) && (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) {
                throw new InvalidArgumentException('INVALID_BILL_SELECTION');
            }
            $id = (int) $value;

            return ['assessmentParticipantId' => $id,
                'consultationRequested' => (bool) ($this->consultation[$id] ?? $this->consultation[(string) $id] ?? false)];
        }, $this->selected);
    }

    private function clearPreview(): void
    {
        $this->preview = null;
        $this->reviewedSelection = [];
        $this->resetValidation();
    }
}
