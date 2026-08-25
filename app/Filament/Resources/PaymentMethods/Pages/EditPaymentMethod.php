<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Actions\Payments\SetPaymentMethodActivation;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Models\Admin;
use App\Models\PaymentMethod;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

final class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof PaymentMethod) {
            throw new LogicException('Payment method record is required.');
        }

        $admin = Filament::auth()->user();

        if (! $admin instanceof Admin) {
            throw new AuthorizationException('An administrator is required.');
        }

        $active = $data['is_active'] ?? null;

        if (! is_bool($active)) {
            throw ValidationException::withMessages([
                'is_active' => 'Status metode pembayaran tidak valid.',
            ]);
        }

        return app(SetPaymentMethodActivation::class)->handle($admin, $record->id, $active);
    }
}
