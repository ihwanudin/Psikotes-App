<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntegrationClients;

use App\Enums\AdminAbility;
use App\Filament\Actions\FundingPolicyAction;
use App\Filament\Resources\IntegrationClients\Pages\CreateIntegrationClient;
use App\Filament\Resources\IntegrationClients\Pages\EditIntegrationClient;
use App\Filament\Resources\IntegrationClients\Pages\ListIntegrationClients;
use App\Models\Admin;
use App\Models\IntegrationClient;
use App\Rules\PublicHttpsUrl;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class IntegrationClientResource extends Resource
{
    protected static ?string $model = IntegrationClient::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|UnitEnum|null $navigationGroup = 'Integrasi';

    protected static ?string $navigationLabel = 'Klien Integrasi';

    protected static ?string $modelLabel = 'klien integrasi';

    protected static ?string $pluralModelLabel = 'klien integrasi';

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
            Select::make('organization_id')->label('Organisasi')->relationship('organization', 'display_name')->searchable()->preload()->required(),
            TextInput::make('client_id')->label('Client ID')->required()->alphaDash()->maxLength(100)->unique(ignoreRecord: true),
            TextInput::make('credential_reference')->label('Referensi kredensial')->required()->alphaDash()->maxLength(160)
                ->helperText('Nama referensi konfigurasi secret. Nilai secret tidak disimpan di database.'),
            Select::make('result_delivery_mode')->label('Pengiriman hasil')->options([
                'CALLBACK' => 'Callback', 'POLL' => 'Polling', 'CALLBACK_AND_POLL' => 'Callback dan polling',
                'PORTAL_ONLY' => 'Portal saja', 'NONE' => 'Tidak ada',
            ])->required(),
            TextInput::make('callback_base_url')->label('Base URL callback')->url()->rules([new PublicHttpsUrl])->nullable()
                ->helperText('Wajib HTTPS publik; tanpa kredensial, query, atau fragmen.'),
            TextInput::make('rate_limit_policy.requestsPerMinute')->label('Batas permintaan/menit')->integer()->minValue(1)->maxValue(1000)->default(120)->required(),
            DateTimePicker::make('effective_from')->label('Aktif mulai')->seconds(false),
            DateTimePicker::make('effective_until')->label('Aktif sampai')->seconds(false)->after('effective_from'),
            Toggle::make('enabled')->label('Aktif')->default(false)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('client_id')->label('Client ID')->searchable()->sortable(),
            TextColumn::make('organization.display_name')->label('Organisasi')->searchable()->sortable(),
            TextColumn::make('result_delivery_mode')->label('Pengiriman')->badge(),
            IconColumn::make('enabled')->label('Aktif')->boolean(),
            TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d M Y H:i')->sortable(),
        ])->recordActions([FundingPolicyAction::make(), EditAction::make()]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListIntegrationClients::route('/'),
            'create' => CreateIntegrationClient::route('/create'),
            'edit' => EditIntegrationClient::route('/{record}/edit'),
        ];
    }

    private static function authorized(): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof Admin && $admin->canPerform(AdminAbility::ManageIntegrations);
    }
}
