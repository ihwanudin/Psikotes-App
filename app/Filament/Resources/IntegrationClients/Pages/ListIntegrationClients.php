<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntegrationClients\Pages;

use App\Filament\Resources\IntegrationClients\IntegrationClientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListIntegrationClients extends ListRecords
{
    protected static string $resource = IntegrationClientResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
