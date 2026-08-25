<?php

declare(strict_types=1);

namespace App\Filament\Resources\TestPackages;

use App\Filament\Resources\TestPackages\Pages\EditTestPackage;
use App\Filament\Resources\TestPackages\Pages\ListTestPackages;
use App\Models\TestPackage;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class TestPackageResource extends Resource
{
    protected static ?string $model = TestPackage::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|UnitEnum|null $navigationGroup = 'Konfigurasi';

    protected static ?string $navigationLabel = 'Paket Tes';

    protected static ?string $modelLabel = 'paket tes';

    protected static ?string $pluralModelLabel = 'paket tes';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('name')
                    ->label('Jenis tes')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('amount')
                    ->label('Harga')
                    ->prefix('Rp')
                    ->integer()
                    ->minValue(1)
                    ->required(fn (Get $get): bool => (bool) $get('is_active'))
                    ->helperText('Isi nominal Rupiah tanpa tanda titik atau koma.'),
                TextInput::make('currency')
                    ->label('Mata uang')
                    ->disabled()
                    ->dehydrated(false),
                Toggle::make('is_active')
                    ->label('Aktif untuk pendaftaran')
                    ->helperText('Paket OFF tidak akan tampil dan tidak dapat dipilih peserta.')
                    ->live()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Jenis tes')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Harga')
                    ->money('IDR', locale: 'id_ID', decimalPlaces: 0)
                    ->placeholder('Belum diisi')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListTestPackages::route('/'),
            'edit' => EditTestPackage::route('/{record}/edit'),
        ];
    }
}
