<?php

declare(strict_types=1);

namespace App\Filament\Resources\TestPackages\Pages;

use App\Filament\Resources\TestPackages\TestPackageResource;
use Filament\Resources\Pages\ListRecords;

final class ListTestPackages extends ListRecords
{
    protected static string $resource = TestPackageResource::class;
}
