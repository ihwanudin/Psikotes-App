<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Actions\Payments\VerifyManualTransfer;
use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Admin;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use UnitEnum;

final class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Pembayaran';

    protected static ?string $navigationLabel = 'Transfer Manual';

    protected static ?string $modelLabel = 'transfer manual';

    protected static ?string $pluralModelLabel = 'transfer manual';

    protected static ?string $recordTitleAttribute = 'public_id';

    /** @return Builder<Order> */
    public static function getEloquentQuery(): Builder
    {
        $query = Order::query()
            ->with(['participant', 'paymentMethod'])
            ->whereHas('paymentMethod', fn (Builder $query): Builder => $query
                ->where('code', 'manual_transfer'));
        $admin = Filament::auth()->user();

        if (! $admin instanceof Admin || ! $admin->canPerform(AdminAbility::VerifyPayments)) {
            return $query->whereRaw('1 = 0');
        }

        if ($admin->role !== AdminRole::SuperAdmin) {
            $query->whereHas(
                'participant',
                fn (Builder $query): Builder => $query->where('branch_id', $admin->branch_id),
            );
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('public_id')
                    ->label('Order')
                    ->limit(14)
                    ->tooltip(fn (Order $record): string => $record->public_id)
                    ->searchable(),
                TextColumn::make('participant.full_name')
                    ->label('Peserta')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR', locale: 'id_ID', decimalPlaces: 0)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => match ($state) {
                        OrderStatus::Pending => 'Menunggu',
                        OrderStatus::Paid => 'Disetujui',
                        OrderStatus::Rejected => 'Ditolak',
                        OrderStatus::Expired => 'Kedaluwarsa',
                        OrderStatus::Cancelled => 'Dibatalkan',
                    })
                    ->color(fn (OrderStatus $state): string => match ($state) {
                        OrderStatus::Pending => 'warning',
                        OrderStatus::Paid => 'success',
                        OrderStatus::Rejected => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('proof_object_key')
                    ->label('Bukti')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Belum ada' : 'Tersedia')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'info'),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        OrderStatus::Pending->value => 'Menunggu',
                        OrderStatus::Paid->value => 'Disetujui',
                        OrderStatus::Rejected->value => 'Ditolak',
                    ]),
            ])
            ->recordActions([
                Action::make('openProof')
                    ->label('Lihat bukti')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->authorize('verifyPayment')
                    ->visible(fn (Order $record): bool => is_string($record->proof_object_key))
                    ->url(fn (Order $record): string => route(
                        'admin.manual-payment-proofs.open',
                        ['order' => $record->public_id],
                    ))
                    ->openUrlInNewTab(),
                Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->authorize('verifyPayment')
                    ->visible(fn (Order $record): bool => self::isPendingWithProof($record))
                    ->requiresConfirmation()
                    ->modalHeading('Setujui transfer manual?')
                    ->modalDescription('Order akan ditandai lunas dan seluruh entitlement paket dibuka.')
                    ->action(function (Order $record): void {
                        app(VerifyManualTransfer::class)->approve(
                            self::currentAdmin(),
                            $record->id,
                            (string) $record->proof_object_key,
                        );

                        Notification::make()
                            ->title('Transfer disetujui')
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->authorize('verifyPayment')
                    ->visible(fn (Order $record): bool => self::isPendingWithProof($record))
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Alasan penolakan')
                            ->required()
                            ->maxLength(500)
                            ->rows(4),
                    ])
                    ->action(function (array $data, Order $record): void {
                        app(VerifyManualTransfer::class)->reject(
                            self::currentAdmin(),
                            $record->id,
                            (string) $record->proof_object_key,
                            (string) $data['rejection_reason'],
                        );

                        Notification::make()
                            ->title('Transfer ditolak')
                            ->danger()
                            ->send();
                    }),
            ])
            ->recordUrl(null)
            ->defaultSort('created_at', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
        ];
    }

    private static function currentAdmin(): Admin
    {
        $admin = Filament::auth()->user();

        if (! $admin instanceof Admin) {
            throw new LogicException('An authenticated administrator is required.');
        }

        return $admin;
    }

    private static function isPendingWithProof(Order $order): bool
    {
        return $order->status === OrderStatus::Pending
            && is_string($order->proof_object_key);
    }
}
