<?php

declare(strict_types=1);

namespace App\Filament\Resources\WithdrawalRequests\Pages;

use App\Actions\Commissions\SubmitBranchWithdrawalRequest;
use App\Filament\Resources\WithdrawalRequests\WithdrawalRequestResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListWithdrawalRequests extends ListRecords
{
    protected static string $resource = WithdrawalRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submitWithdrawal')
                ->label('Ajukan pencairan')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => WithdrawalRequestResource::branchAdminCanSubmit())
                ->schema([
                    DatePicker::make('period_month')
                        ->label('Periode')
                        ->required()
                        ->native(false)
                        ->displayFormat('F Y')
                        ->default(now('Asia/Jakarta')->startOfMonth()),
                ])
                ->action(function (array $data): void {
                    app(SubmitBranchWithdrawalRequest::class)->execute(
                        WithdrawalRequestResource::currentAdminOrFail(),
                        (string) $data['period_month'],
                    );
                    Notification::make()->title('Request pencairan diajukan')->success()->send();
                }),
        ];
    }
}
