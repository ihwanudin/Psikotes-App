<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssessmentBillReviews\Pages;

use App\Actions\Payments\ReviewAssessmentBillFromProofAccess;
use App\Enums\AssessmentBillManualDecision;
use App\Enums\AssessmentBillManualRejectionCode;
use App\Enums\AssessmentBillManualReviewError;
use App\Exceptions\AssessmentBillManualReviewException;
use App\Filament\Resources\AssessmentBillReviews\AssessmentBillReviewResource;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Services\Payments\AssessmentBillProofUrlIssuer;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
            Action::make('approve')
                ->label('Setujui pembayaran')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->isPending())
                ->requiresConfirmation()
                ->modalHeading('Setujui pembayaran transfer manual?')
                ->modalDescription('Tagihan akan dilunasi memakai bukti yang terakhir dibuka oleh akun reviewer ini.')
                ->action(fn (): mixed => $this->processDecision(AssessmentBillManualDecision::Approve, null)),
            Action::make('reject')
                ->label('Tolak pembayaran')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->isPending())
                ->schema([
                    Select::make('rejection_code')
                        ->label('Alasan penolakan')
                        ->options([
                            AssessmentBillManualRejectionCode::AmountMismatch->value => 'Nominal tidak sesuai',
                            AssessmentBillManualRejectionCode::UnreadableProof->value => 'Bukti tidak terbaca',
                            AssessmentBillManualRejectionCode::WrongBeneficiary->value => 'Tujuan transfer tidak sesuai',
                            AssessmentBillManualRejectionCode::DuplicateProof->value => 'Bukti sudah pernah digunakan',
                            AssessmentBillManualRejectionCode::OtherUnverifiable->value => 'Bukti tidak dapat diverifikasi',
                        ])
                        ->required(),
                ])
                ->action(function (array $data): mixed {
                    $code = is_string($data['rejection_code'] ?? null)
                        ? AssessmentBillManualRejectionCode::tryFrom($data['rejection_code'])
                        : null;
                    if ($code === null) {
                        throw new DomainException('ASSESSMENT_BILL_REVIEW_REJECTION_INVALID');
                    }

                    return $this->processDecision(AssessmentBillManualDecision::Reject, $code);
                }),
        ];
    }

    private function processDecision(
        AssessmentBillManualDecision $decision,
        ?AssessmentBillManualRejectionCode $rejectionCode,
    ): mixed {
        $actor = Filament::auth()->user();
        $record = $this->getRecord();
        if (! $actor instanceof Admin || ! $record instanceof AssessmentBill) {
            abort(404);
        }

        $this->disableBackButtonCache();
        try {
            app(ReviewAssessmentBillFromProofAccess::class)->execute(
                $actor,
                (string) $record->public_reference,
                $decision,
                $rejectionCode,
            );
        } catch (AssessmentBillManualReviewException $exception) {
            if ($exception->error === AssessmentBillManualReviewError::NotFound) {
                abort(404);
            }

            $this->decisionFailed();

            return null;
        } catch (DomainException) {
            $this->decisionFailed();

            return null;
        }

        $fresh = AssessmentBillReviewResource::getEloquentQuery()->find($record->getKey());
        abort_unless($fresh instanceof AssessmentBill, 404);
        $this->record = $fresh;
        Notification::make()->title('Keputusan tersimpan.')->success()->send();

        return null;
    }

    private function decisionFailed(): void
    {
        Notification::make()
            ->title('Keputusan tidak dapat diproses. Buka bukti lalu coba kembali.')
            ->danger()
            ->send();
    }

    private function isPending(): bool
    {
        $record = $this->getRecord();

        return $record instanceof AssessmentBill && $record->status === 'pending';
    }
}
