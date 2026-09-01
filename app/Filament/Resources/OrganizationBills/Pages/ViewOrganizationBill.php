<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationBills\Pages;

use App\Filament\Resources\OrganizationBills\OrganizationBillResource;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** @extends ViewRecord<AssessmentBill> */
final class ViewOrganizationBill extends ViewRecord
{
    protected static string $resource = OrganizationBillResource::class;

    protected static ?string $title = 'Detail Tagihan Cabang';

    public function getSubheading(): string
    {
        return 'Daftar alokasi terkunci. Pratinjau ini tidak menyediakan pembayaran, unggah bukti, atau verifikasi. Lunas tidak otomatis berarti peserta sudah memenuhi syarat akses tes.';
    }

    /** @return array<string> */
    public function getBreadcrumbs(): array
    {
        return [
            OrganizationBillResource::getUrl() => OrganizationBillResource::getBreadcrumb(),
            OrganizationBillResource::getUrl('view', ['record' => $this->getRecord()]) => 'Tagihan terpilih',
            $this->getBreadcrumb(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ringkasan tagihan')->columns(2)->schema([
                TextEntry::make('public_reference')->label('Referensi tagihan'),
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => OrganizationBillResource::statusLabels()[$state] ?? 'Status belum dikenal'),
                TextEntry::make('item_count')->label('Jumlah attempt (peserta/tes)'),
                TextEntry::make('amount')->label('Total tagihan')->money('IDR', decimalPlaces: 0),
                TextEntry::make('expires_at')->label('Jatuh tempo')->dateTime('d M Y H:i')->placeholder('Belum ditetapkan'),
                TextEntry::make('paid_at')->label('Pembayaran tercatat')->dateTime('d M Y H:i')->placeholder('Belum lunas'),
                TextEntry::make('terminal_notice')->label('Penanganan tagihan')
                    ->state('Hubungi petugas ONCAM untuk rekonsiliasi. Tagihan ini tidak dapat ditagih ulang secara otomatis.')
                    ->visible(fn (AssessmentBill $record): bool => in_array($record->status, ['expired', 'rejected', 'unknown'], true))
                    ->columnSpanFull(),
            ]),
            Section::make('Alokasi per attempt')->description('Paket dan biaya mengikuti snapshot saat reservasi, bukan harga katalog terbaru.')->schema([
                RepeatableEntry::make('allocations')->label('Daftar peserta dan attempt')
                    ->state(fn (): array => $this->allocations())
                    ->columns(2)->schema([
                        TextEntry::make('participant')->label('Peserta'),
                        TextEntry::make('attempt')->label('ID attempt'),
                        TextEntry::make('period')->label('Periode')->placeholder('Tidak dicatat'),
                        TextEntry::make('package')->label('Paket snapshot'),
                        TextEntry::make('base_amount')->label('Biaya paket')->money('IDR', decimalPlaces: 0),
                        TextEntry::make('consultation_amount')->label('Biaya konsultasi')->money('IDR', decimalPlaces: 0),
                        TextEntry::make('amount')->label('Alokasi')->money('IDR', decimalPlaces: 0),
                        TextEntry::make('settled_at')->label('Pelunasan alokasi')->dateTime('d M Y H:i')->placeholder('Belum dialokasikan lunas'),
                    ]),
            ]),
            Section::make('Riwayat tagihan ini')->columns(2)->schema([
                TextEntry::make('created_at')->label('Direservasi')->dateTime('d M Y H:i')->placeholder('Tidak dicatat'),
                TextEntry::make('verified_at')->label('Verifikasi tercatat')->dateTime('d M Y H:i')->placeholder('Belum ada'),
            ]),
        ])->columns(1);
    }

    /** @return list<array<string, mixed>> */
    private function allocations(): array
    {
        $bill = $this->getRecord();
        OrganizationBillResource::authorizeView($bill);

        return array_values(AssessmentBillItem::query()
            ->where('bill_id', $bill->id)->where('organization_id', $bill->organization_id)
            ->where('payer_type', 'organization')
            ->with([
                'charge' => fn ($query) => $query->select(['id', 'assessment_participant_id', 'participant_id',
                    'organization_id', 'price_snapshot', 'base_amount', 'consultation_amount'])
                    ->where('organization_id', $bill->organization_id),
                'charge.participant' => fn ($query) => $query->select(['id', 'full_name'])->where('branch_id', $bill->organization_id),
                'charge.assessmentParticipant' => fn ($query) => $query->select(['id', 'assessment_attempt_id', 'assessment_round_id'])
                    ->where('organization_id', $bill->organization_id),
            ])
            ->orderBy('id')->get()->map(function (AssessmentBillItem $item): array {
                $charge = $item->charge;
                $packageName = $charge?->price_snapshot['packageName'] ?? null;

                return [
                    'participant' => $charge?->participant->full_name ?? 'Tidak tersedia',
                    'attempt' => $charge?->assessmentParticipant->assessment_attempt_id ?? 'Tidak tersedia',
                    'period' => $charge?->assessmentParticipant?->assessment_round_id,
                    'package' => is_string($packageName) ? $packageName : 'Snapshot nama tidak tersedia',
                    'base_amount' => $charge?->base_amount,
                    'consultation_amount' => $charge?->consultation_amount,
                    'amount' => $item->amount,
                    'settled_at' => $item->settled_at,
                ];
            })->all());
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // ViewRecord exposes refreshFormData() to Livewire; never hydrate model secrets.
        return [];
    }
}
