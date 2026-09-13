<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Domain\Review\G7AspectResolution;
use App\Domain\Review\ProfessionalOverridePolicy;
use App\Domain\Review\ReportSigningPrerequisitePolicy;
use App\Domain\Review\ReviewedEligibilityDecision;
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

    public string $procedureNote = '';

    public string $accompanimentConditions = '';

    public string $labelFinal = 'DIPERTIMBANGKAN';

    public string $labelReason = '';

    /** @var array{A: string, B: string, C: string, D: string} */
    public array $clusterDrafts = [
        'A' => 'Kemampuan umum berada pada taraf cukup.',
        'B' => 'Cara kerja menunjukkan pola yang cukup terarah.',
        'C' => 'Aspek C4 menunggu tinjauan profesional.',
        'D' => 'Minat kerja mendukung bidang tujuan.',
    ];

    #[Locked]
    public ?string $targetField = 'KAIGO';

    public bool $previewInvalidated = false;

    public ?string $previewFinalLabel = 'DIPERTIMBANGKAN';

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
        abort_unless(is_string($requestedScenario) && in_array($requestedScenario, ['review', 'v2', 'v3', 'missing-field'], true), 404);

        $this->scenario = $requestedScenario;
        if ($requestedScenario === 'missing-field') {
            $this->targetField = null;
        }

        $this->refreshBlockingCodes();
    }

    public function mountCanAuthorizeAccess(): void
    {
        $this->authorizeReviewer();
    }

    public function hydrateCanAuthorizeAccess(): void
    {
        $this->authorizeReviewer();
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
        $this->dispatch('review-focus', target: 'projection-heading');
    }

    public function updatedG6FinalLevel(): void
    {
        $this->authorizeReviewer();
        if ($this->g6FinalLevel === 3) {
            $this->g6Reason = '';
            $this->previewFinalLabel = $this->labelFinal;
            $this->previewInvalidated = false;

            return;
        }

        $this->previewFinalLabel = null;
        $this->previewInvalidated = true;
    }

    public function updatedG7FinalLevel(): void
    {
        $this->authorizeReviewer();
        if ($this->g7FinalLevel === 3) {
            $this->g7Reason = '';
        }
    }

    public function updatedLabelFinal(): void
    {
        $this->authorizeReviewer();
        if ($this->labelFinal === 'DIPERTIMBANGKAN') {
            $this->labelReason = '';
        }
    }

    public function focusBlocker(string $code): void
    {
        $this->authorizeReviewer();
        $target = match ($code) {
            'V2_PROCEDURE_NOTE_REQUIRED' => 'procedure-note',
            'ACCOMPANIMENT_CONDITIONS_REQUIRED' => 'accompaniment-conditions',
            'G7_ASPECTS_UNRESOLVED' => 'g7-final-level',
            'OVERRIDE_REASON_MIN_LENGTH' => $this->labelFinal !== 'DIPERTIMBANGKAN' ? 'label-reason' : 'g6-reason',
            'TARGET_FIELD_REQUIRED' => 'target-field-status',
            'NARRATIVE_CLUSTER_A_REQUIRED' => 'cluster-a-id',
            'NARRATIVE_CLUSTER_B_REQUIRED' => 'cluster-b-id',
            'NARRATIVE_CLUSTER_C_REQUIRED' => 'cluster-c-id',
            'NARRATIVE_CLUSTER_D_REQUIRED' => 'cluster-d-id',
            default => 'readiness-heading',
        };

        $this->dispatch('review-focus', target: $target);
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

        $overrides = [];
        $levelOverride = null;

        try {
            $levelOverride = (new ProfessionalOverridePolicy)->levelOverride([
                'aspect' => 'A2',
                'system_level' => 3,
                'final_level' => $this->g6FinalLevel,
                'reason' => $this->g6FinalLevel === 3 ? null : $this->g6Reason,
            ]);
            if ($levelOverride['changed']) {
                $overrides[] = ['type' => 'level', 'aspect' => 'A2', 'reason' => $this->g6Reason];
            }
        } catch (InvalidArgumentException) {
            $this->addError('g6Reason', 'Perubahan level memerlukan alasan minimal 20 karakter.');
            $overrides[] = ['type' => 'level', 'aspect' => 'A2', 'reason' => $this->g6Reason];
        }

        $unresolvedG7 = [];
        if ($this->g7FinalLevel === null) {
            $unresolvedG7[] = 'C4';
            $this->addError('g7FinalLevel', 'Aspek C4 harus ditetapkan sebelum siap ditandatangani.');
        } else {
            try {
                G7AspectResolution::resolved(
                    $this->g7Discrepancy(),
                    3,
                    $this->g7FinalLevel,
                    $this->g7FinalLevel === 3 ? null : $this->g7Reason,
                );
                if ($this->g7FinalLevel !== 3) {
                    $overrides[] = ['type' => 'level', 'aspect' => 'C4', 'reason' => $this->g7Reason];
                }
            } catch (InvalidArgumentException) {
                $this->addError('g7Reason', 'Perubahan hasil G7 memerlukan alasan minimal 20 karakter.');
                $overrides[] = ['type' => 'level', 'aspect' => 'C4', 'reason' => $this->g7Reason];
            }
        }

        $labelOverride = null;
        try {
            $labelOverride = (new ProfessionalOverridePolicy)->labelOverride([
                'system_label' => 'DIPERTIMBANGKAN',
                'final_label' => $this->labelFinal,
                'reason' => $this->labelFinal === 'DIPERTIMBANGKAN' ? null : $this->labelReason,
            ]);
            if ($labelOverride['changed']) {
                $overrides[] = ['type' => 'label', 'aspect' => null, 'reason' => $this->labelReason];
            }
        } catch (InvalidArgumentException) {
            $this->addError('labelReason', 'Perubahan label memerlukan alasan minimal 20 karakter.');
            $overrides[] = ['type' => 'label', 'aspect' => null, 'reason' => $this->labelReason];
        }

        $this->refreshBlockingCodes($unresolvedG7, $overrides);
        $this->addBlockerFieldErrors();

        if (! $this->getErrorBag()->has('g6Reason') && $levelOverride !== null) {
            $this->recomputePreview($levelOverride, $labelOverride);
        }
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
        $v2 = $this->scenario === 'v2';

        return [
            'fixtureId' => 'F5-SYNTHETIC-REVIEW-001',
            'case' => [
                'publicId' => '01SYNTHETICCASE000000000001',
                'origin' => 'DIRECT_PUBLIC',
                'organizationLabel' => 'Cabang Sintetis Salatiga',
                'packageLabel' => 'Paket Psikotes Sintetis',
                'intendedField' => $this->scenario === 'missing-field' ? null : $this->targetField,
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
                'status' => $v3 ? 'V3' : ($v2 ? 'V2' : 'V1'),
                'procedureNote' => $this->procedureNote !== '' ? $this->procedureNote : null,
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
                'finalLabel' => $v3 || $this->previewInvalidated ? null : $this->previewFinalLabel,
                'guardrails' => $v3 ? ['G3 — VALIDITY_V3'] : ['G7 — SOURCE_LEVEL_SPREAD'],
            ],
            'hpp' => [
                'clusters' => [
                    'A' => ['id' => $this->clusterDrafts['A'], 'jp' => '一般能力は十分な水準です。'],
                    'B' => ['id' => $this->clusterDrafts['B'], 'jp' => '作業方法は概ね体系的です。'],
                    'C' => ['id' => $this->clusterDrafts['C'], 'jp' => 'C4項目は専門家の確認待ちです。'],
                    'D' => ['id' => $this->clusterDrafts['D'], 'jp' => '職業興味は希望分野を支持しています。'],
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
                'finalLevel' => $isG7 && $this->g7FinalLevel !== null ? $this->g7FinalLevel : 3,
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
                    'state' => $isG7 && $this->g7FinalLevel === null ? 'UNRESOLVED' : ($isG7 ? 'RESOLVED' : 'NOT_REQUIRED'),
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

    /**
     * @param  list<string>|null  $unresolvedG7
     * @param  list<array{type: string, aspect: string|null, reason: string|null}>  $overrides
     */
    private function refreshBlockingCodes(?array $unresolvedG7 = null, array $overrides = []): void
    {
        $v3 = $this->scenario === 'v3';
        $result = (new ReportSigningPrerequisitePolicy)->evaluate([
            'validity' => $v3 ? 'V3' : ($this->scenario === 'v2' ? 'V2' : 'V1'),
            'procedure_note' => $this->procedureNote,
            'label' => $v3 ? null : $this->labelFinal,
            'accompaniment_conditions' => $this->accompanimentConditions,
            'unresolved_g7_aspects' => $unresolvedG7 ?? ($v3 ? [] : ['C4']),
            'overrides' => $overrides,
            'target_field' => $this->scenario === 'missing-field' ? null : $this->targetField,
            'narrative_clusters' => $this->clusterDrafts,
        ]);

        $this->blockingCodes = [...$result['blocking_reason_codes'], 'PERSISTENCE_AUTHORITY_UNBOUND'];
    }

    private function addBlockerFieldErrors(): void
    {
        $fields = [
            'V2_PROCEDURE_NOTE_REQUIRED' => ['procedureNote', 'Catatan prosedur wajib untuk validitas V2.'],
            'ACCOMPANIMENT_CONDITIONS_REQUIRED' => ['accompanimentConditions', 'Syarat pendampingan wajib untuk label DIPERTIMBANGKAN.'],
            'TARGET_FIELD_REQUIRED' => ['targetField', 'Bidang tujuan wajib ditetapkan.'],
            'NARRATIVE_CLUSTER_A_REQUIRED' => ['clusterDrafts.A', 'Narasi klaster A wajib diisi.'],
            'NARRATIVE_CLUSTER_B_REQUIRED' => ['clusterDrafts.B', 'Narasi klaster B wajib diisi.'],
            'NARRATIVE_CLUSTER_C_REQUIRED' => ['clusterDrafts.C', 'Narasi klaster C wajib diisi.'],
            'NARRATIVE_CLUSTER_D_REQUIRED' => ['clusterDrafts.D', 'Narasi klaster D wajib diisi.'],
        ];

        foreach ($fields as $code => [$field, $message]) {
            if (in_array($code, $this->blockingCodes, true)) {
                $this->addError($field, $message);
            }
        }
    }

    /**
     * @param  array<mixed>  $levelOverride
     * @param  array<mixed>|null  $labelOverride
     */
    private function recomputePreview(array $levelOverride, ?array $labelOverride): void
    {
        $reviewed = ReviewedEligibilityDecision::create(
            $this->baselineEligibility(),
            $levelOverride['changed'] ? [$levelOverride] : [],
            $labelOverride !== null && $labelOverride['changed'] ? $labelOverride : null,
        )->toArray();

        $this->previewFinalLabel = $reviewed['final_decision']['label'];
        $this->previewInvalidated = false;
    }

    private function baselineEligibility(): EligibilityDecisionSnapshot
    {
        $contents = file_get_contents(database_path('seeders/data/reporting.json'));
        $configuration = is_string($contents) ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($configuration)) {
            throw new InvalidArgumentException('Synthetic reporting fixture is unavailable.');
        }

        return EligibilityDecisionSnapshot::create([
            'levels' => array_fill_keys(self::ASPECTS, 3),
            'field_code' => 'KAIGO',
            'iq' => 104,
            'validity' => 'V1',
            'standard_configuration' => [
                'standard_version' => $configuration['standard_version'],
                'base_standards' => $configuration['base_standards'],
                'fields' => $configuration['fields'],
            ],
            'eligibility_source_versions' => [
                'ist' => 'synthetic-ist-v1',
                'papi' => 'synthetic-papi-v1',
                'kraepelin' => 'synthetic-kraepelin-v1',
                'rmib' => 'synthetic-rmib-v1',
                'reporting' => $configuration['standard_version'],
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
