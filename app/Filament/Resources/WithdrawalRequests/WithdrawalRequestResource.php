<?php

declare(strict_types=1);

namespace App\Filament\Resources\WithdrawalRequests;

use App\Actions\Commissions\ReviewBranchWithdrawalRequest;
use App\Enums\AdminRole;
use App\Filament\Resources\WithdrawalRequests\Pages\ListWithdrawalRequests;
use App\Models\Admin;
use App\Models\WithdrawalRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use UnitEnum;

final class WithdrawalRequestResource extends Resource
{
    protected static ?string $model = WithdrawalRequest::class;

    protected static ?string $slug = 'withdrawal-requests';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string|UnitEnum|null $navigationGroup = 'Komisi Cabang';

    protected static ?string $navigationLabel = 'Pencairan Komisi';

    protected static ?string $modelLabel = 'pencairan komisi';

    protected static ?string $pluralModelLabel = 'pencairan komisi';

    public static function canViewAny(): bool
    {
        return self::currentAdmin() !== null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    /** @return Builder<WithdrawalRequest> */
    public static function getEloquentQuery(): Builder
    {
        $query = WithdrawalRequest::query()->with(['branch', 'requestedByAdmin', 'items']);
        $admin = self::currentAdmin();

        if ($admin === null) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array($admin->role, [AdminRole::BranchAdmin, AdminRole::Staff], true)) {
            return $query->where('branch_id', $admin->branch_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('public_reference')->label('Referensi')->searchable()->wrap(),
                TextColumn::make('branch.name')->label('Cabang')->searchable()->sortable(),
                TextColumn::make('period_month')->label('Periode')->date('M Y')->sortable(),
                TextColumn::make('requested_amount')->label('Diajukan')->money('IDR', decimalPlaces: 0)->sortable(),
                TextColumn::make('approved_amount')->label('Disetujui')->money('IDR', decimalPlaces: 0)->placeholder('-'),
                TextColumn::make('items_count')->label('Item')->counts('items')->numeric(decimalPlaces: 0),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'submitted' => 'warning',
                        'approved' => 'info',
                        'paid' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('submitted_at')->label('Diajukan')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    'submitted' => 'Submitted',
                    'approved' => 'Approved',
                    'paid' => 'Paid',
                    'rejected' => 'Rejected',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WithdrawalRequest $record): bool => self::canCentralReview($record, ['submitted']))
                    ->requiresConfirmation()
                    ->action(function (WithdrawalRequest $record): void {
                        app(ReviewBranchWithdrawalRequest::class)->approve(self::currentAdminOrFail(), $record->id);
                        Notification::make()->title('Request pencairan disetujui')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WithdrawalRequest $record): bool => self::canCentralReview($record, ['submitted', 'approved']))
                    ->schema([
                        TextInput::make('rejection_reason')->label('Alasan penolakan')->required()->maxLength(500),
                    ])
                    ->action(function (array $data, WithdrawalRequest $record): void {
                        app(ReviewBranchWithdrawalRequest::class)->reject(
                            self::currentAdminOrFail(),
                            $record->id,
                            (string) $data['rejection_reason'],
                        );
                        Notification::make()->title('Request pencairan ditolak')->danger()->send();
                    }),
                Action::make('markPaid')
                    ->label('Tandai dibayar')
                    ->icon('heroicon-o-banknotes')
                    ->color('info')
                    ->visible(fn (WithdrawalRequest $record): bool => self::canCentralReview($record, ['approved']))
                    ->schema([
                        TextInput::make('payout_reference')->label('Referensi payout')->maxLength(160),
                    ])
                    ->action(function (array $data, WithdrawalRequest $record): void {
                        app(ReviewBranchWithdrawalRequest::class)->markPaid(
                            self::currentAdminOrFail(),
                            $record->id,
                            $data['payout_reference'] ?? null,
                        );
                        Notification::make()->title('Request pencairan ditandai dibayar')->success()->send();
                    }),
            ])
            ->recordUrl(null)
            ->defaultSort('submitted_at', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListWithdrawalRequests::route('/')];
    }

    public static function branchAdminCanSubmit(): bool
    {
        $admin = self::currentAdmin();

        return $admin?->role === AdminRole::BranchAdmin && $admin->branch_id !== null;
    }

    private static function canCentralReview(WithdrawalRequest $record, array $statuses): bool
    {
        $admin = self::currentAdmin();

        return $admin?->role === AdminRole::SuperAdmin && in_array($record->status, $statuses, true);
    }

    public static function currentAdminOrFail(): Admin
    {
        $admin = Filament::auth()->user();
        if (! $admin instanceof Admin) {
            throw new LogicException('An authenticated administrator is required.');
        }

        return $admin;
    }

    private static function currentAdmin(): ?Admin
    {
        $admin = Filament::auth()->user();
        if (! $admin instanceof Admin || ! $admin->exists || $admin->trashed()) {
            return null;
        }

        return in_array($admin->role, [AdminRole::SuperAdmin, AdminRole::BranchAdmin, AdminRole::Staff], true)
            ? $admin
            : null;
    }
}
