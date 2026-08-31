<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationBills;

use App\Enums\AdminRole;
use App\Filament\Resources\OrganizationBills\Pages\ListOrganizationBills;
use App\Filament\Resources\OrganizationBills\Pages\ViewOrganizationBill;
use App\Models\Admin;
use App\Models\AssessmentBill;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class OrganizationBillResource extends Resource
{
    protected static ?string $model = AssessmentBill::class;

    protected static ?string $slug = 'organization-bills';

    protected static ?string $recordTitleAttribute = 'public_reference';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'Asesmen Organisasi';

    protected static ?string $navigationLabel = 'Tagihan Cabang';

    protected static ?string $modelLabel = 'tagihan cabang';

    protected static ?string $pluralModelLabel = 'tagihan cabang';

    protected static bool $isGloballySearchable = false;

    // P12a-prep only. Replace this boundary after P11c and the portal contract review.
    public static function isDiscovered(): bool
    {
        return app()->environment('testing');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $admin = self::currentBranchAdmin();
        $allowed = $admin !== null && match ($action) {
            'viewAny' => true,
            'view' => $record instanceof AssessmentBill
                && $record->payer_type === 'organization'
                && $record->organization_id === $admin->branch_id
                && self::getEloquentQuery()->whereKey($record->getKey())->exists(),
            default => false,
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    /** @return Builder<AssessmentBill> */
    public static function getEloquentQuery(): Builder
    {
        $query = AssessmentBill::query()->select([
            'id', 'organization_id', 'payer_type', 'public_reference', 'amount', 'currency',
            'item_count', 'status', 'created_at', 'expires_at', 'paid_at', 'verified_at',
        ])->where('payer_type', 'organization');
        $admin = self::currentBranchAdmin();

        return $admin === null ? $query->whereRaw('1 = 0') : $query->where('organization_id', $admin->branch_id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('public_reference')->label('Referensi tagihan')->searchable()->wrap(),
                TextColumn::make('item_count')->label('Jumlah attempt')->numeric(decimalPlaces: 0),
                TextColumn::make('amount')->label('Total')->money('IDR', decimalPlaces: 0)->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? 'Status belum dikenal')
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success', 'expired', 'rejected' => 'danger', default => 'warning',
                    }),
                TextColumn::make('expires_at')->label('Jatuh tempo')->dateTime('d M Y H:i')->placeholder('Belum ditetapkan')->sortable(),
                TextColumn::make('paid_at')->label('Dibayar')->dateTime('d M Y H:i')->placeholder('Belum lunas'),
            ])
            ->filters([SelectFilter::make('status')->label('Status / riwayat')->options(self::statusLabels())])
            // Rows already come from the persisted, tenant-scoped query. Rendering a
            // navigation URL needs no per-row database authorization. The destination
            // reauthorizes on mount/hydration; a forged mounted action does so too.
            ->recordUrl(fn (AssessmentBill $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([Action::make('detail')->label('Detail')->icon('heroicon-o-eye')
                ->url(fn (AssessmentBill $record): string => self::getUrl('view', ['record' => $record]))
                ->mountUsing(fn (AssessmentBill $record) => self::authorizeView($record))])
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Belum ada tagihan yang sesuai')
            ->emptyStateDescription('Tagihan cabang Anda akan tampil di sini. Ubah pencarian atau filter bila perlu.');
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return ['reserved' => 'Direservasi', 'issuing' => 'Sedang diterbitkan', 'unknown' => 'Perlu rekonsiliasi',
            'pending' => 'Menunggu pembayaran', 'paid' => 'Lunas', 'expired' => 'Kedaluwarsa', 'rejected' => 'Ditolak'];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListOrganizationBills::route('/'), 'view' => ViewOrganizationBill::route('/{record}')];
    }

    private static function currentBranchAdmin(): ?Admin
    {
        if (! self::isDiscovered()) {
            return null;
        }
        $sessionAdmin = Filament::auth()->user();
        if (! $sessionAdmin instanceof Admin || ! $sessionAdmin->exists) {
            return null;
        }
        // Reload membership: stale Livewire/auth objects are not authorization evidence.
        $admin = Admin::query()->find($sessionAdmin->id);

        return $admin?->role === AdminRole::BranchAdmin && $admin->branch_id !== null ? $admin : null;
    }
}
