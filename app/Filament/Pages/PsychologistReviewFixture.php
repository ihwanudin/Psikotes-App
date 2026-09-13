<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Domain\Review\G7AspectResolution;
use App\Domain\Review\ProfessionalOverridePolicy;
use App\Enums\AdminAbility;
use App\Models\Admin;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use UnitEnum;

final class PsychologistReviewFixture extends Page
{
    /** @var list<string> */
    private const ASPECTS = [
        'A1', 'A2', 'B1', 'B2', 'B3', 'B4',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7',
        'D1', 'D2', 'D3', 'D4', 'D5',
    ];

    /** @var array<string, string> */
    private const ASPECT_LABELS = [
        'A1' => 'Kemampuan umum',
        'A2' => 'Analisis dan sintesis',
        'B1' => 'Konsentrasi dan ingatan',
        'B2' => 'Kecepatan dan ketelitian',
        'B3' => 'Kemampuan belajar',
        'B4' => 'Sistematika kerja',
        'C1' => 'Kematangan dan percaya diri',
        'C2' => 'Komunikasi dan tanggung jawab',
        'C3' => 'Inisiatif dan sosial',
        'C4' => 'Stres dan stabilitas',
        'C5' => 'Ketahanan kerja',
        'C6' => 'Keuletan',
        'C7' => 'Arah dan gaya kerja',
        'D1' => 'Minat luar ruang',
        'D2' => 'Minat mekanik',
        'D3' => 'Minat praktis',
        'D4' => 'Minat medis',
        'D5' => 'Minat pelayanan sosial',
    ];

    protected static ?string $slug = 'psychologist-review-fixture';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Pengujian';

    protected static ?string $navigationLabel = 'Fixture review psikolog';

    protected static ?string $title = 'Fixture review psikolog';

    protected string $view = 'filament.pages.psychologist-review-fixture';

    #[Locked]
    public string $scenario = 'review';

    public string $activePanel = 'hpp';

    public int $g6FinalLevel = 3;

    public string $g6Reason = '';

    public ?int $g7FinalLevel = null;

    public string $g7Reason = '';

    /** @var list<string> */
    public array $blockingCodes = [];

    public string $readinessMessage = 'Belum dapat ditandatangani';

    public static function isDiscovered(): bool
    {
        return app()->environment('testing');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::isDiscovered() && self::canAccess();
    }

    public static function canAccess(): bool
    {
        if (! app()->environment('testing')) {
            return false;
        }

        $admin = self::currentReviewer();

        return $admin instanceof Admin
            && $admin->canPerform(AdminAbility::ReviewReports)
            && $admin->canPerform(AdminAbility::ViewDass);
    }

    public function mount(?string $scenario = null): void
    {
        $this->authorizeReviewer();
        $requestedScenario = $scenario ?? request()->query('scenario', 'review');
        abort_unless(is_string($requestedScenario) && in_array($requestedScenario, ['review', 'v3'], true), 404);

        $this->scenario = $requestedScenario;
        $this->blockingCodes = $requestedScenario === 'v3'
            ? ['VALIDITY_V3', 'PERSISTENCE_AUTHORITY_UNBOUND']
            : ['G7_ASPECTS_UNRESOLVED', 'PERSISTENCE_AUTHORITY_UNBOUND'];
    }

    public function hydrate(): void
    {
        $this->authorizeReviewer();
    }

    public function showPanel(string $panel): void
    {
        $this->authorizeReviewer();
        abort_unless($this->scenario !== 'v3' && in_array($panel, ['hpp', 'internal'], true), 404);

        $this->activePanel = $panel;
    }

    public function updatedG6FinalLevel(): void
    {
        $this->authorizeReviewer();
        if ($this->g6FinalLevel === 3) {
            $this->g6Reason = '';
        }
    }

    public function updatedG7FinalLevel(): void
    {
        $this->authorizeReviewer();
        if ($this->g7FinalLevel === 3) {
            $this->g7Reason = '';
        }
    }

    public function validateDraft(): void
    {
        $this->authorizeReviewer();
        $this->resetValidation();

        if ($this->scenario === 'v3') {
            $this->blockingCodes = ['VALIDITY_V3', 'PERSISTENCE_AUTHORITY_UNBOUND'];
            $this->readinessMessage = 'STOP — laporan tidak dibuat';

            return;
        }

        $blocking = [];

        try {
            (new ProfessionalOverridePolicy)->levelOverride([
                'aspect' => 'A2',
                'system_level' => 3,
                'final_level' => $this->g6FinalLevel,
                'reason' => $this->g6FinalLevel === 3 ? null : $this->g6Reason,
            ]);
        } catch (InvalidArgumentException) {
            $blocking[] = 'OVERRIDE_REASON_MIN_LENGTH';
            $this->addError('g6Reason', 'Perubahan level memerlukan alasan minimal 20 karakter.');
        }

        if ($this->g7FinalLevel === null) {
            $blocking[] = 'G7_ASPECTS_UNRESOLVED';
            $this->addError('g7FinalLevel', 'Aspek C4 harus ditetapkan sebelum siap ditandatangani.');
        } else {
            try {
                G7AspectResolution::resolved(
                    $this->g7Discrepancy(),
                    3,
                    $this->g7FinalLevel,
                    $this->g7FinalLevel === 3 ? null : $this->g7Reason,
                );
            } catch (InvalidArgumentException) {
                $blocking[] = 'OVERRIDE_REASON_MIN_LENGTH';
                $this->addError('g7Reason', 'Perubahan hasil G7 memerlukan alasan minimal 20 karakter.');
            }
        }

        $blocking[] = 'PERSISTENCE_AUTHORITY_UNBOUND';
        $this->blockingCodes = array_values(array_unique($blocking));
        $this->readinessMessage = 'Belum dapat ditandatangani';

        Notification::make()
            ->title('Validasi sintetis diperbarui.')
            ->body('Tidak ada data yang disimpan dan tidak ada tanda tangan yang dibuat.')
            ->info()
            ->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['fixture' => $this->fixture()];
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $v3 = $this->scenario === 'v3';

        return [
            'fixtureId' => 'F5-SYNTHETIC-REVIEW-001',
            'case' => [
                'publicId' => '01SYNTHETICCASE000000000001',
                'origin' => 'DIRECT_PUBLIC',
                'organizationLabel' => 'Cabang Sintetis Salatiga',
                'packageLabel' => 'Paket Psikotes Sintetis',
                'intendedField' => 'KAIGO',
            ],
            'participant' => [
                'testNumber' => 'TEST-SYNTHETIC-001',
                'displayName' => 'Nadia Peserta Contoh',
            ],
            'report' => [
                'publicId' => '01SYNTHETICREPORT0000000001',
                'version' => 1,
                'state' => 'UNDER_REVIEW',
                'expectedSnapshotHash' => null,
            ],
            'validity' => [
                'status' => $v3 ? 'V3' : 'V1',
                'procedureNote' => null,
                'findings' => $v3
                    ? ['Identitas sintetis tidak dapat diverifikasi.']
                    : ['Pemeriksaan sintetis lengkap.'],
            ],
            'eligibility' => [
                'publicationBlocked' => $v3,
                'standardVersion' => 'synthetic-standard-v1',
                'sourceVersions' => [
                    'ist' => 'synthetic-ist-v1',
                    'papi' => 'synthetic-papi-v1',
                    'kraepelin' => 'synthetic-kraepelin-v1',
                    'rmib' => 'synthetic-rmib-v1',
                    'reporting' => 'synthetic-reporting-v1',
                ],
                'iq' => 104,
                'aspects' => $this->aspects(),
                'systemLabel' => $v3 ? null : 'DIPERTIMBANGKAN',
                'recalculatedLabel' => $v3 ? null : 'DIPERTIMBANGKAN',
                'finalLabel' => $v3 ? null : 'DIPERTIMBANGKAN',
                'guardrails' => $v3 ? ['G3 — VALIDITY_V3'] : ['G7 — SOURCE_LEVEL_SPREAD'],
            ],
            'hpp' => [
                'clusters' => [
                    'A' => ['id' => 'Kemampuan umum berada pada taraf cukup.', 'jp' => '一般能力は十分な水準です。'],
                    'B' => ['id' => 'Cara kerja menunjukkan pola yang cukup terarah.', 'jp' => '作業方法は概ね体系的です。'],
                    'C' => ['id' => 'Aspek C4 menunggu tinjauan profesional.', 'jp' => 'C4項目は専門家の確認待ちです。'],
                    'D' => ['id' => 'Minat kerja mendukung bidang tujuan.', 'jp' => '職業興味は希望分野を支持しています。'],
                ],
                'generalDass' => [
                    'category' => 'Normal',
                    'narrativeId' => 'Kondisi dalam batas normal pada skrining ini.',
                    'narrativeJp' => '今回のスクリーニングでは通常範囲内です。',
                ],
            ],
            'internal' => [
                'instrumentSummaries' => [
                    ['instrument' => 'IST', 'summary' => 'IQ sintetis 104'],
                    ['instrument' => 'PAPI', 'summary' => '20 dimensi sintetis tersedia'],
                    ['instrument' => 'Kraepelin', 'summary' => '4 faktor sintetis tersedia'],
                    ['instrument' => 'RMIB', 'summary' => '12 kategori sintetis tersedia'],
                ],
                'integrationSlots' => [
                    'authorityReady' => false,
                    'S1' => null,
                    'S2' => null,
                    'S3' => null,
                    'S4' => null,
                    'S5' => null,
                    'S6' => null,
                    'S7' => null,
                ],
                'dass' => [
                    'subscales' => [
                        ['label' => 'Depresi (x2)', 'score' => 4, 'category' => 'Normal'],
                        ['label' => 'Kecemasan (x2)', 'score' => 2, 'category' => 'Normal'],
                        ['label' => 'Stres (x2)', 'score' => 6, 'category' => 'Normal'],
                    ],
                    'generalCategory' => 'Normal',
                    'followUp' => 'Tidak ada tindak lanjut otomatis.',
                    'flags' => [],
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function aspects(): array
    {
        return array_values(array_map(function (string $aspect): array {
            $isG7 = $aspect === 'C4';

            return [
                'code' => $aspect,
                'label' => self::ASPECT_LABELS[$aspect],
                'critical' => in_array($aspect, ['A1', 'B2', 'C4', 'C5'], true),
                'systemLevel' => 3,
                'finalLevel' => 3,
                'standard' => 3,
                'zone' => 'OK',
                'sources' => $isG7
                    ? [
                        ['sourceCode' => 'PAPI_E', 'sourceVersion' => 'synthetic-papi-v1', 'level' => 2],
                        ['sourceCode' => 'KRAEPELIN_HANKER', 'sourceVersion' => 'synthetic-kraepelin-v1', 'level' => 4],
                    ]
                    : [['sourceCode' => 'SYNTHETIC_'.$aspect, 'sourceVersion' => 'synthetic-v1', 'level' => 3]],
                'g7' => [
                    'required' => $isG7,
                    'state' => $isG7 ? 'UNRESOLVED' : 'NOT_REQUIRED',
                    'spread' => $isG7 ? 2 : 0,
                    'reasonCode' => $isG7 ? 'SOURCE_LEVEL_SPREAD' : null,
                ],
            ];
        }, self::ASPECTS));
    }

    /** @return array<mixed> */
    private function g7Discrepancy(): array
    {
        return (new AspectSourceDiscrepancyPolicy)->evaluate([
            'aspect' => 'C4',
            'sources' => [
                ['source' => 'PAPI_E', 'level' => 2],
                ['source' => 'KRAEPELIN_HANKER', 'level' => 4],
            ],
        ]);
    }

    private function authorizeReviewer(): void
    {
        abort_unless(self::canAccess(), 404);
    }

    private static function currentReviewer(): ?Admin
    {
        $user = Filament::auth()->user();
        if (! $user instanceof Admin) {
            return null;
        }

        return Admin::query()->whereKey($user->getKey())->first();
    }
}
