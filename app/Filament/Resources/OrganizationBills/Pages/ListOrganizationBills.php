<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationBills\Pages;

use App\Filament\Resources\OrganizationBills\OrganizationBillResource;
use Filament\Resources\Pages\ListRecords;

final class ListOrganizationBills extends ListRecords
{
    protected static string $resource = OrganizationBillResource::class;

    protected static ?string $title = 'Tagihan Cabang';

    public function getSubheading(): string
    {
        return 'Pratinjau baca-saja. Filter Lunas menampilkan riwayat dari tagihan yang sama. Jumlah dihitung per attempt; seorang peserta dapat memiliki lebih dari satu attempt.';
    }

    protected function authorizeAccess(): void
    {
        OrganizationBillResource::authorizeViewAny();
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }
}
