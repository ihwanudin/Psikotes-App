<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssessmentBillReviews;

use App\Filament\Resources\AssessmentBillReviews\Pages\ListAssessmentBillReviews;
use App\Filament\Resources\AssessmentBillReviews\Pages\ViewAssessmentBillReview;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\Branch;
use App\Policies\AssessmentBillPolicy;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class AssessmentBillReviewResource extends Resource
{
    protected static ?string $model = AssessmentBill::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static string|UnitEnum|null $navigationGroup = 'Pembayaran';

    protected static ?string $navigationLabel = 'Review tagihan asesmen';

    protected static ?string $modelLabel = 'review tagihan asesmen';

    protected static ?string $pluralModelLabel = 'review tagihan asesmen';

    protected static ?string $recordTitleAttribute = 'public_reference';

    public static function isDiscovered(): bool
    {
        return app()->environment('testing');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return app()->environment('testing') && self::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof Admin && app(AssessmentBillPolicy::class)->viewAny($admin);
    }

    public static function canView(Model $record): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof Admin
            && $record instanceof AssessmentBill
            && app(AssessmentBillPolicy::class)->view($admin, $record);
    }

    /** @return Builder<AssessmentBill> */
    public static function getEloquentQuery(): Builder
    {
        $query = AssessmentBill::query()
            ->select([
                'assessment_bills.id',
                'assessment_bills.public_reference',
                'assessment_bills.amount',
                'assessment_bills.item_count',
                'assessment_bills.status',
                'assessment_bills.proof_uploaded_at',
                'assessment_bills.verified_at',
                'assessment_bills.rejection_reason',
            ])
            ->addSelect(['organization_name' => Branch::query()
                ->select('name')
                ->whereColumn('branches.id', 'assessment_bills.organization_id')
                ->limit(1)])
            ->whereHas('paymentMethod', fn (Builder $method): Builder => $method
                ->where('code', 'manual_transfer'))
            ->where('assessment_bills.currency', 'IDR')
            ->whereNotNull('assessment_bills.proof_object_key')
            ->whereNotNull('assessment_bills.proof_checksum_sha256')
            ->whereNotNull('assessment_bills.proof_mime_type')
            ->whereNotNull('assessment_bills.proof_size_bytes')
            ->whereNotNull('assessment_bills.proof_uploaded_at');

        return self::canViewAny() ? $query : $query->whereRaw('1 = 0');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('public_reference')->label('Tagihan')->searchable()->sortable(),
                TextColumn::make('organization_name')->label('Organisasi')->searchable()->sortable(),
                TextColumn::make('amount')->label('Nominal')->money('IDR', locale: 'id_ID', decimalPlaces: 0)->sortable(),
                TextColumn::make('item_count')->label('Peserta')->numeric()->sortable(),
                TextColumn::make('status')->label('Status')->badge()->sortable(),
                TextColumn::make('proof_uploaded_at')->label('Bukti diunggah')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('verified_at')->label('Diverifikasi')->dateTime('d M Y H:i')->placeholder('Belum'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Menunggu review',
                    'paid' => 'Lunas',
                    'rejected' => 'Ditolak',
                ]),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('proof_uploaded_at', 'asc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('public_reference')->label('Tagihan'),
            TextEntry::make('organization_name')->label('Organisasi'),
            TextEntry::make('amount')->label('Nominal')->money('IDR', locale: 'id_ID', decimalPlaces: 0),
            TextEntry::make('item_count')->label('Jumlah peserta')->numeric(),
            TextEntry::make('status')->label('Status')->badge(),
            TextEntry::make('proof_uploaded_at')->label('Bukti diunggah')->dateTime('d M Y H:i'),
            TextEntry::make('verified_at')->label('Diverifikasi')->dateTime('d M Y H:i')->placeholder('Belum'),
            TextEntry::make('rejection_reason')->label('Alasan penolakan')->placeholder('Tidak ada'),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListAssessmentBillReviews::route('/'),
            'view' => ViewAssessmentBillReview::route('/{record}'),
        ];
    }
}
