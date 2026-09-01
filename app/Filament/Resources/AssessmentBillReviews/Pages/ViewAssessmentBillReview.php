<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssessmentBillReviews\Pages;

use App\Filament\Resources\AssessmentBillReviews\AssessmentBillReviewResource;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Services\Payments\AssessmentBillProofUrlIssuer;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewAssessmentBillReview extends ViewRecord
{
    protected static string $resource = AssessmentBillReviewResource::class;

    public function mount(int|string $record): void
    {
        abort_unless(AssessmentBillReviewResource::canViewAny(), 404);

        parent::mount($record);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(AssessmentBillReviewResource::canView($this->getRecord()), 404);
    }

    public function hydrate(): void
    {
        $key = $this->getRecord()->getKey();
        $record = AssessmentBillReviewResource::getEloquentQuery()->find($key);
        abort_unless($record instanceof AssessmentBill, 404);
        $this->record = $record;
        $this->authorizeAccess();
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('openProof')
                ->label('Buka bukti')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->action(function (): mixed {
                    $actor = Filament::auth()->user();
                    $record = $this->getRecord();
                    if (! $actor instanceof Admin || ! $record instanceof AssessmentBill) {
                        abort(404);
                    }

                    $this->disableBackButtonCache();

                    try {
                        $access = app(AssessmentBillProofUrlIssuer::class)
                            ->issue($actor, (string) $record->public_reference);
                    } catch (DomainException) {
                        Notification::make()
                            ->title('Bukti tidak dapat dibuka.')
                            ->danger()
                            ->send();

                        return null;
                    }

                    return redirect()->away($access->url)->withHeaders([
                        'Cache-Control' => 'no-store, private',
                        'Pragma' => 'no-cache',
                        'Referrer-Policy' => 'no-referrer',
                    ]);
                }),
        ];
    }
}
