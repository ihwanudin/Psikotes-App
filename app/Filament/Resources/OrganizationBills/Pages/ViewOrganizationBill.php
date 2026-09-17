<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationBills\Pages;

use App\Actions\Payments\StoreAssessmentBillProof;
use App\Data\Payments\AssessmentBillProofUpload;
use App\Enums\AssessmentBillManualRejectionCode;
use App\Exceptions\AssessmentBillProofStorageException;
use App\Filament\Resources\OrganizationBills\OrganizationBillResource;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Services\Payments\AssessmentBillProofIdentity;
use App\Services\Payments\OrganizationBillProofUrlIssuer;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Locked;

/** @extends ViewRecord<AssessmentBill> */
final class ViewOrganizationBill extends ViewRecord
{
    protected static string $resource = OrganizationBillResource::class;

    protected static ?string $title = 'Detail Tagihan Cabang';

    #[Locked]
    public ?string $proofFingerprint = null;

    /** @var array{uploadedAt: string|null, mime: string|null, size: int|null, status: string, rejection: string|null} */
    #[Locked]
    public array $proofSummary = [
        'uploadedAt' => null, 'mime' => null, 'size' => null, 'status' => 'Belum diunggah', 'rejection' => null,
    ];

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->refreshProofState();
    }

    public function getSubheading(): string
    {
        return 'Daftar alokasi terkunci. Halaman ini tidak melakukan pembayaran atau verifikasi. Mengunggah bukti tidak berarti tagihan sudah lunas atau peserta sudah memenuhi syarat akses tes.';
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
            Section::make('Bukti pembayaran')->schema([
                TextEntry::make('proof_uploaded_at_safe')->label('Diunggah')
                    ->state(fn (): ?string => $this->proofSummary['uploadedAt'])->placeholder('Belum diunggah'),
                TextEntry::make('proof_mime_safe')->label('Jenis berkas')
                    ->state(fn (): ?string => $this->proofSummary['mime'])->placeholder('Belum tersedia'),
                TextEntry::make('proof_size_safe')->label('Ukuran byte')
                    ->state(fn (): ?int => $this->proofSummary['size'])->numeric()->placeholder('Belum tersedia'),
                TextEntry::make('proof_status_safe')->label('Status pembayaran')
                    ->state(fn (): string => $this->proofSummary['status']),
                TextEntry::make('proof_rejection_safe')->label('Alasan penolakan')
                    ->state(fn (): ?string => $this->proofSummary['rejection'])->placeholder('Tidak ada'),
            ])->columns(2),
            Section::make('Riwayat tagihan ini')->columns(2)->schema([
                TextEntry::make('created_at')->label('Direservasi')->dateTime('d M Y H:i')->placeholder('Tidak dicatat'),
                TextEntry::make('verified_at')->label('Verifikasi tercatat')->dateTime('d M Y H:i')->placeholder('Belum ada'),
            ]),
        ])->columns(1);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('uploadProof')->label($this->proofFingerprint === null ? 'Unggah bukti' : 'Ganti bukti')
                ->visible(fn (): bool => $this->canUploadProof())
                ->schema([FileUpload::make('proof')->label('Bukti transfer')->storeFiles(false)->required()])
                ->action(function (array $data): void {
                    $actor = Filament::auth()->user();
                    $proof = $data['proof'] ?? null;
                    if (! $actor instanceof Admin || ! $proof instanceof UploadedFile) {
                        abort(404);
                    }
                    try {
                        $receipt = app(StoreAssessmentBillProof::class)->execute(new AssessmentBillProofUpload(
                            $actor,
                            (string) $this->getRecord()->public_reference,
                            $proof,
                            $this->proofFingerprint,
                        ));
                    } catch (AssessmentBillProofStorageException) {
                        Notification::make()->title('Bukti tidak dapat disimpan. Muat ulang dan coba kembali.')->danger()->send();

                        return;
                    }
                    $this->proofFingerprint = $receipt->proofFingerprint;
                    $this->refreshProofState();
                    Notification::make()->title($receipt->replaced ? 'Bukti diganti.' : 'Bukti disimpan.')->success()->send();
                }),
            Action::make('openProof')->label('Buka bukti')->visible(fn (): bool => $this->proofFingerprint !== null)
                ->action(function (): mixed {
                    $actor = Filament::auth()->user();
                    if (! $actor instanceof Admin || $this->proofFingerprint === null) {
                        abort(404);
                    }
                    try {
                        $access = app(OrganizationBillProofUrlIssuer::class)->issue(
                            $actor,
                            (string) $this->getRecord()->public_reference,
                            $this->proofFingerprint,
                        );
                    } catch (DomainException) {
                        Notification::make()->title('Bukti tidak dapat dibuka. Muat ulang dan coba kembali.')->danger()->send();

                        return null;
                    }

                    return redirect()->away($access->url)->withHeaders([
                        'Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer',
                    ]);
                }),
        ];
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

    private function canUploadProof(): bool
    {
        $record = AssessmentBill::query()->with('paymentMethod')->find($this->getRecord()->getKey());

        return $record instanceof AssessmentBill && $record->status === 'pending'
            && $record->payer_type === 'organization' && $record->paymentMethod?->code === 'manual_transfer'
            && $record->expires_at?->isFuture() === true && $record->paid_at === null
            && $record->verified_at === null;
    }

    private function refreshProofState(): void
    {
        OrganizationBillResource::authorizeView($this->getRecord());
        $bill = AssessmentBill::query()->whereKey($this->getRecord()->getKey())
            ->where('organization_id', $this->getRecord()->organization_id)
            ->where('payer_type', 'organization')->firstOrFail();
        try {
            $this->proofFingerprint = app(AssessmentBillProofIdentity::class)->fingerprint($bill, now());
        } catch (DomainException) {
            $this->proofFingerprint = null;
        }
        $mime = match ($bill->proof_mime_type) {
            'image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'application/pdf' => 'PDF', default => null,
        };
        $status = OrganizationBillResource::statusLabels()[$bill->status] ?? 'Status belum dikenal';
        $rejection = match (AssessmentBillManualRejectionCode::tryFrom((string) $bill->rejection_reason)) {
            AssessmentBillManualRejectionCode::AmountMismatch => 'Nominal tidak sesuai',
            AssessmentBillManualRejectionCode::UnreadableProof => 'Bukti tidak terbaca',
            AssessmentBillManualRejectionCode::WrongBeneficiary => 'Tujuan transfer tidak sesuai',
            AssessmentBillManualRejectionCode::DuplicateProof => 'Bukti sudah pernah digunakan',
            AssessmentBillManualRejectionCode::OtherUnverifiable => 'Bukti tidak dapat diverifikasi',
            default => null,
        };
        $this->proofSummary = [
            'uploadedAt' => $bill->proof_uploaded_at?->utc()->format('d M Y H:i').' UTC',
            'mime' => $mime,
            'size' => $bill->proof_size_bytes,
            'status' => $status,
            'rejection' => $rejection,
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // ViewRecord exposes refreshFormData() to Livewire; never hydrate model secrets.
        return [];
    }
}
