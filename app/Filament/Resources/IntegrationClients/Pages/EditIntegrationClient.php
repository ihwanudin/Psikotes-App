<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntegrationClients\Pages;

use App\Filament\Resources\IntegrationClients\IntegrationClientResource;
use Filament\Resources\Pages\EditRecord;

final class EditIntegrationClient extends EditRecord
{
    protected static string $resource = IntegrationClientResource::class;
}
