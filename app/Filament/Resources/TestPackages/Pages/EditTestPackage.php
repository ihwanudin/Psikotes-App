<?php

declare(strict_types=1);

namespace App\Filament\Resources\TestPackages\Pages;

use App\Filament\Resources\TestPackages\TestPackageResource;
use Filament\Resources\Pages\EditRecord;

final class EditTestPackage extends EditRecord
{
    protected static string $resource = TestPackageResource::class;
}
