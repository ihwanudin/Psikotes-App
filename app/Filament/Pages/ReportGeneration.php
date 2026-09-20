<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Services\ReportRendering\BladeReportRenderer;
use App\Services\ReportRendering\PdfReportRenderer;
use App\Services\ReportRendering\ReportDocumentPublisher;
use App\Services\ReportRendering\SignedHppDataset;
use App\Services\ReportRendering\SignedReportDataset;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Livewire\Attributes\Locked;
use RuntimeException;
use UnitEnum;

/**
 * Psychologist-only page that turns the latest SIGNED report snapshot into
 * an HPP PDF on the private `reports` disk and hands back a short-lived
 * link. Everything runs in-process (no self-issued HTTP). When the signed
 * data is incomplete the page lists the gap codes and offers no button:
 * there is no path from an unsigned or partial dataset to a PDF.
 */
final class ReportGeneration extends Page
{
    /** Human-readable text for each fail-closed gap code. */
    private const GAP_LABELS = [
        SignedReportDataset::SNAPSHOT_NOT_FOUND => 'Belum ada snapshot tanda tangan untuk kasus ini.',
        SignedReportDataset::SNAPSHOT_NOT_SIGNED => 'Snapshot terbaru belum berstatus SIGNED.',
        SignedReportDataset::SNAPSHOT_CORRUPT => 'Data snapshot tanda tangan tidak valid.',
        SignedReportDataset::PUBLICATION_BLOCKED => 'Keputusan terblokir untuk terbit (mis. validitas V3).',
        SignedReportDataset::PARTICIPANT_NOT_FOUND => 'Data peserta tidak ditemukan.',
        SignedReportDataset::TEST_NUMBER_MISSING => 'Nomor tes peserta belum terbit.',
        SignedReportDataset::TEST_DATE_UNAVAILABLE => 'Tanggal tes tidak dapat ditentukan dari sesi tes.',
        SignedReportDataset::NARRATIVE_CLUSTER_MISSING => 'Narasi klaster A–D pada snapshot belum lengkap.',
        SignedReportDataset::IQ_CATEGORY_UNAVAILABLE => 'Kategori IQ tidak dapat diturunkan dari versi instrumen IST.',
        SignedReportDataset::DASS_RESULT_NOT_FOUND => 'Hasil skrining DASS sebelum tanda tangan tidak ditemukan; laporan mencetak "tidak tersedia".',
        SignedReportDataset::DASS_CATEGORY_UNRECOGNIZED => 'Kategori umum DASS tersimpan tidak dikenal; laporan mencetak "tidak tersedia".',
        SignedReportDataset::PSYCHOLOGIST_NOT_FOUND => 'Akun psikolog penanda tangan tidak ditemukan.',
        SignedReportDataset::REPORT_NUMBER_UNAVAILABLE => 'Nomor laporan belum memiliki sumber data.',
        SignedReportDataset::PSYCHOLOGIST_SIPP_UNAVAILABLE => 'Nomor SIPP psikolog belum memiliki sumber data.',
        SignedReportDataset::RECOMMENDATION_RATIONALE_UNAVAILABLE => 'Alasan rekomendasi belum memiliki sumber data.',
        SignedReportDataset::ASPECT_LABELS_UNAVAILABLE => 'Label aspek dwibahasa (ID/JP) belum memiliki sumber data.',
        SignedReportDataset::DASS_TEXT_UNAVAILABLE => 'Teks narasi DASS belum memiliki sumber data; laporan mencetak "tidak tersedia".',
        SignedReportDataset::DRAFT_INVALID => 'Data gabungan gagal validasi draf HPP.',
    ];

    protected static ?string $slug = 'report-generation';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Pengujian';

    protected static ?string $navigationLabel = 'Buat PDF HPP';

    protected static ?string $title = 'Buat PDF HPP';

    /** @var view-string */
    protected string $view = 'filament.pages.report-generation';

    #[Locked]
    public string $casePublicId;

    #[Locked]
    public ?string $snapshotId = null;

    #[Locked]
    public bool $ready = false;

    /** @var list<array{code: string, label: string}> */
    #[Locked]
    public array $gaps = [];

    /** Non-blocking gaps (DASS): shown, but the PDF can still be made. */
    /** @var list<array{code: string, label: string}> */
    #[Locked]
    public array $warnings = [];

    /** @var array{url: string, expires_at: string}|null */
    #[Locked]
    public ?array $published = null;

    /**
     * The route itself carries the case identity: mount() requires it, so a
     * slug without {case} would 500 on every real request.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/'.self::getSlug($panel).'/{case}';
    }

    public static function shouldRegisterNavigation(): bool
    {
        // Case-scoped page: reached with a case id, never from the menu.
        return false;
    }

    public static function canAccess(): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof Admin
            && $admin->canPerform(AdminAbility::ReviewReports);
    }

    public function mount(string $case, SignedReportDataset $dataset): void
    {
        abort_unless(self::canAccess(), 404);
        abort_unless(preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $case) === 1, 404);

        $this->casePublicId = $case;
        $this->evaluate($dataset);
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 404);
    }

    public function generate(SignedReportDataset $dataset, ReportDocumentPublisher $publisher): void
    {
        abort_unless(self::canAccess(), 404);

        // Re-read at click time: the snapshot may have changed since mount.
        $result = $this->evaluate($dataset);
        if ($result === null) {
            Notification::make()->title('PDF belum dapat dibuat')->body('Data laporan belum lengkap.')->danger()->send();

            return;
        }

        try {
            $html = BladeReportRenderer::make()->renderHpp($result->draft());
            $pdf = PdfReportRenderer::make()->render($html);
            $document = $publisher->publish('hpp', $result->identity(), $pdf);
        } catch (RuntimeException) {
            Notification::make()->title('PDF gagal dibuat')->body('Silakan coba lagi.')->danger()->send();

            return;
        }

        $this->published = ['url' => $document['url'], 'expires_at' => $document['expires_at']];
        Notification::make()->title('PDF HPP siap diunduh')->success()->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'urlMinutes' => ReportDocumentPublisher::TEMPORARY_URL_MINUTES,
        ];
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{code: string, label: string}>
     */
    private static function describe(array $codes): array
    {
        return array_map(
            static fn (string $code): array => ['code' => $code, 'label' => self::GAP_LABELS[$code] ?? $code],
            $codes,
        );
    }

    private function evaluate(SignedReportDataset $dataset): ?SignedHppDataset
    {
        $result = $dataset->hpp($this->casePublicId);

        $this->snapshotId = $result->snapshotId;
        $this->ready = $result->isReady();
        $this->gaps = self::describe($result->missing);
        $this->warnings = self::describe($result->warnings);

        if (! $this->ready) {
            $this->published = null;

            return null;
        }

        return $result;
    }
}
