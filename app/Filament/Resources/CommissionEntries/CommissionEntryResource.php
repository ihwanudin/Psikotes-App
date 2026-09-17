<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommissionEntries;

use App\Enums\AdminRole;
use App\Filament\Resources\CommissionEntries\Pages\ListCommissionEntries;
use App\Models\Admin;
use App\Models\CommissionEntry;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class CommissionEntryResource extends Resource
{
    protected static ?string $model = CommissionEntry::class;

    protected static ?string $slug = 'commission-entries';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|UnitEnum|null $navigationGroup = 'Komisi Cabang';

    protected static ?string $navigationLabel = 'Ledger Komisi';

    protected static ?string $modelLabel = 'ledger komisi';

    protected static ?string $pluralModelLabel = 'ledger komisi';

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

    /** @return Builder<CommissionEntry> */
    public static function getEloquentQuery(): Builder
    {
        $query = CommissionEntry::query()->with(['branch', 'participant']);
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
                TextColumn::make('branch.name')->label('Cabang')->searchable()->sortable(),
                TextColumn::make('participant.full_name')->label('Peserta')->placeholder('-')->searchable(),
                TextColumn::make('period_month')->label('Periode')->date('M Y')->sortable(),
                TextColumn::make('source_type')->label('Sumber')->badge(),
                TextColumn::make('gross_amount')->label('Nilai transaksi')->money('IDR', decimalPlaces: 0)->sortable(),
                TextColumn::make('commission_amount')->label('Komisi')->money('IDR', decimalPlaces: 0)->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'accrued' => 'success',
                        'withdrawal_pending' => 'warning',
                        'withdrawn' => 'info',
                        'void' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('paid_at')->label('Dibayar')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    'accrued' => 'Accrued',
                    'withdrawal_pending' => 'Menunggu pencairan',
                    'withdrawn' => 'Dicairkan',
                    'void' => 'Void',
                ]),
                SelectFilter::make('source_type')->label('Sumber')->options([
                    'direct_order' => 'Direct order',
                    'assessment_bill_item' => 'Assessment bill item',
                ]),
            ])
            ->recordUrl(null)
            ->defaultSort('paid_at', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListCommissionEntries::route('/')];
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
