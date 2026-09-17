<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentMethods extends ListRecords
{
    protected static string $resource = PaymentMethodResource::class;
}
