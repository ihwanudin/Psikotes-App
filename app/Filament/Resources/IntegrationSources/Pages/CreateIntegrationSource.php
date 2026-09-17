<?php

declare(strict_types=1);

namespace App\Filament\Resources\IntegrationSources\Pages;

use App\Filament\Resources\IntegrationSources\IntegrationSourceResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateIntegrationSource extends CreateRecord
{
    protected static string $resource = IntegrationSourceResource::class;
}
