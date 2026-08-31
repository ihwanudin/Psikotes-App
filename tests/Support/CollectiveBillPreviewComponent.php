<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Filament\Actions\PreviewCollectiveBillSelection;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Synthetic server-supplied choices only. Never registered by an application provider. */
final class CollectiveBillPreviewComponent extends Component
{
    /** @var list<int> */
    #[Locked]
    public array $attemptIds = [];

    /** @var list<array{assessmentParticipantId: int, consultationRequested: bool}> */
    public array $selection = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $preview = null;

    public function boot(): void
    {
        if (! app()->environment('testing')) {
            throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
        }
    }

    /** @param list<int> $attemptIds IDs from the synthetic server fixture, not a public list query. */
    public function mount(array $attemptIds): void
    {
        $this->attemptIds = $attemptIds;
    }

    public function hydrate(): void
    {
        // A preview is only the result of the current Tinjau request. Never resume
        // an old hash/price from the browser snapshot on refresh or another action.
        $this->preview = null;
    }

    public function updatedSelection(): void
    {
        $this->preview = null;
        $this->resetValidation();
    }

    public function toggleAttempt(int $id): void
    {
        // data_get also tolerates malformed hydrated rows; keep those rows intact
        // so review still sends them to the adapter's strict validation.
        $remaining = array_values(array_filter($this->selection,
            fn ($item): bool => data_get($item, 'assessmentParticipantId') !== $id));
        if (count($remaining) === count($this->selection)) {
            $remaining[] = ['assessmentParticipantId' => $id, 'consultationRequested' => false];
        }
        $this->selection = $remaining;
        $this->updatedSelection();
    }

    public function review(): void
    {
        $this->preview = null;
        $this->resetValidation();
        try {
            // Do not sanitize duplicates, IDs, booleans, scope or totals into a
            // valid request. The existing adapter owns validation/auth/policy/price.
            $this->preview = app(PreviewCollectiveBillSelection::class)->execute($this->selection);
        } catch (InvalidArgumentException|DomainException) {
            $this->addError('selection', 'Pilihan tidak valid. Periksa peserta dan konsultasi, lalu tinjau ulang.');
        }
    }

    public function render(): View
    {
        // Reload labels through the authorized projection on every render; locked
        // fixture IDs alone are not authorization for a changed membership/role.
        $choices = app(PreviewCollectiveBillSelection::class)->execute(array_map(
            fn (int $id): array => ['assessmentParticipantId' => $id, 'consultationRequested' => false],
            $this->attemptIds,
        ));

        return view()->file(__DIR__.'/views/collective-bill-preview.blade.php', ['choices' => $choices['items']]);
    }
}
