<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntegrationSources;

use App\Enums\AdminAbility;
use App\Filament\Actions\FundingPolicyAction;
use App\Filament\Resources\IntegrationSources\Pages\CreateIntegrationSource;
use App\Filament\Resources\IntegrationSources\Pages\EditIntegrationSource;
use App\Filament\Resources\IntegrationSources\Pages\ListIntegrationSources;
use App\Models\Admin;
use App\Models\IntegrationSource;
use App\Rules\RelativeCallbackPath;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class IntegrationSourceResource extends Resource
{
    protected static ?string $model = IntegrationSource::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Integrasi';

    protected static ?string $navigationLabel = 'Sumber Integrasi';

    protected static ?string $modelLabel = 'sumber integrasi';

    protected static ?string $pluralModelLabel = 'sumber integrasi';

    public static function canViewAny(): bool
    {
        return self::authorized();
    }

    public static function canCreate(): bool
    {
        return self::authorized();
    }

    public static function canEdit(mixed $record): bool
    {
        return self::authorized();
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('integration_client_id')->label('Klien')->relationship('client', 'client_id')->searchable()->preload()->required(),
            TextInput::make('source_system')->label('Source system')->required()->alphaDash()->maxLength(100),
            TextInput::make('contract_version')->label('Versi kontrak')->required()->maxLength(24)->default('v1'),
            Select::make('authentication_mode')->label('Autentikasi')->options(['HMAC_SHA256' => 'HMAC SHA-256'])->default('HMAC_SHA256')->required(),
            TagsInput::make('allowed_assessment_packages')->label('Kode paket diizinkan')->required()->reorderable(),
            TagsInput::make('allowed_funding_modes')->label('Funding mode diizinkan')->required()->reorderable(),
            Select::make('participant_provisioning_mode')->label('Provisioning peserta')->options(['API' => 'API', 'PORTAL' => 'Portal', 'BATCH' => 'Batch'])->default('API')->required(),
            Select::make('commercial_mode')->label('Mode komersial')->options(['CONTRACT' => 'Kontrak', 'SELF_PAY' => 'Bayar mandiri', 'MIXED' => 'Campuran'])->default('CONTRACT')->required(),
            TextInput::make('callback_path')->label('Path callback')->rules([new RelativeCallbackPath])->nullable()
                ->helperText('Path relatif, contoh /api/psychotest/events.'),
            TextInput::make('callback_configuration.reconciliationPath')->label('Path rekonsiliasi')->rules([new RelativeCallbackPath])->nullable()
                ->helperText('Opsional. Gunakan {eventId} sebagai placeholder event.'),
            Select::make('status')->label('Status')->options(['DRAFT' => 'Draft', 'ACTIVE' => 'Aktif', 'SUSPENDED' => 'Ditangguhkan', 'RETIRED' => 'Dihentikan'])->default('DRAFT')->required(),
            DateTimePicker::make('effective_from')->label('Aktif mulai')->seconds(false),
            DateTimePicker::make('effective_until')->label('Aktif sampai')->seconds(false)->after('effective_from'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('source_system')->label('Source system')->searchable()->sortable(),
            TextColumn::make('client.client_id')->label('Klien')->searchable()->sortable(),
            TextColumn::make('contract_version')->label('Versi'),
            TextColumn::make('status')->label('Status')->badge(),
            TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d M Y H:i')->sortable(),
        ])->recordActions([FundingPolicyAction::make(), EditAction::make()]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListIntegrationSources::route('/'),
            'create' => CreateIntegrationSource::route('/create'),
            'edit' => EditIntegrationSource::route('/{record}/edit'),
        ];
    }

    private static function authorized(): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof Admin && $admin->canPerform(AdminAbility::ManageIntegrations);
    }
}
