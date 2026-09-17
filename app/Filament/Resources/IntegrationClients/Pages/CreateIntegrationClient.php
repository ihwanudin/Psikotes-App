<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntegrationClients\Pages;

use App\Filament\Resources\IntegrationClients\IntegrationClientResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateIntegrationClient extends CreateRecord
{
    protected static string $resource = IntegrationClientResource::class;
}
