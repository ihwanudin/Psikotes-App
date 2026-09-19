<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Domain\Review\ReportSigningPrerequisitePolicy;
use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use App\Services\Review\ReportSigningService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use UnitEnum;

final class ReportSigning extends Page
{
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

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

    protected static ?string $slug = 'report-signing';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Pengujian';

    protected static ?string $navigationLabel = 'Tanda Tangan Laporan';

    protected static ?string $title = 'Tanda Tangan Laporan';

    /** @var view-string */
    protected string $view = 'filament.pages.report-signing';

    // ─── Locked (immutable) properties ───

    #[Locked]
    public string $casePublicId;

    #[Locked]
    public string $participantLabel = '';

    #[Locked]
    public string $caseLabel = '';

    #[Locked]
    public ?string $intendedField = null;

    #[Locked]
    public ?string $eligibilityVersionId = null;

    #[Locked]
    public ?string $narrativeVersionId = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $baseline = [];

    #[Locked]
    public string $systemLabel = '';

    #[Locked]
    public string $validity = '';

    #[Locked]
    public int $iq = 0;

    #[Locked]
    public bool $isReadOnly = false;

    #[Locked]
    public bool $isRevision = false;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $existingSnapshot = null;

    #[Locked]
    public string $revisionReasonForSigning = '';

    // ─── Mutable form state ───

    /** @var array<string, array{system_level: int, final_level: int, reason: string}> */
    public array $levelOverrides = [];

    public ?string $labelOverrideFinal = null;

    public string $labelOverrideReason = '';

    public string $revisionReason = '';

    /** @var array<string, array{sources: list<array{source: string, level: int}>, final_level: int|null, reason: string}> */
    public array $g7Resolutions = [];

    /** @var array{A: string, B: string, C: string, D: string} */
    public array $narrativeClusters = [
        'A' => '',
        'B' => '',
        'C' => '',
        'D' => '',
    ];

    public string $procedureNote = '';

    public string $accompanimentConditions = '';

    /** @var list<string> */
    public array $blockingCodes = [];

    // ─── Page lifecycle ───

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public static function canAccess(): bool
    {
        $admin = self::currentReviewer();

        return $admin instanceof Admin
            && $admin->canPerform(AdminAbility::ReviewReports);
    }

    public function mount(string $case, ReportSigningService $signingService, RlsContextRunner $runner): void
    {
        abort_unless(self::canAccess(), 404);

        $this->casePublicId = $case;

        // Check for existing SIGNED snapshot
        $snapshot = $signingService->latest($case);
        if ($snapshot !== null && $snapshot->state === 'SIGNED') {
            $this->isReadOnly = true;
            $this->existingSnapshot = json_decode($snapshot->snapshot_json, true, 512, JSON_THROW_ON_ERROR);
        }

        // Load case + eligibility data via RLS
        $data = $runner->runAsService(function () use ($case): array {
            $caseRecord = DB::table('assessment_cases')
                ->where('public_id', $case)
                ->first();
            abort_if($caseRecord === null, 404);

            $participant = DB::table('participants')
                ->where('id', (int) $caseRecord->participant_id)
                ->first();

            $eligibility = DB::table('eligibility_decision_versions')
                ->where('assessment_case_id', (int) $caseRecord->id)
                ->orderByDesc('version')
                ->first();

            $narrative = DB::table('bilingual_narrative_versions')
                ->where('assessment_case_id', (int) $caseRecord->id)
                ->orderByDesc('version')
                ->first();

            return compact('caseRecord', 'participant', 'eligibility', 'narrative');
        });

        $this->participantLabel = $data['participant']->full_name ?? 'Unknown';
        $this->caseLabel = $data['caseRecord']->public_id;
        $this->intendedField = $data['caseRecord']->intended_field_snapshot;

        if ($data['eligibility'] !== null) {
            $this->eligibilityVersionId = $data['eligibility']->id;
            $canonicalInput = json_decode($data['eligibility']->canonical_input_json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($canonicalInput)) {
                abort(500, 'Eligibility baseline data is corrupt.');
            }

            try {
                $baseline = EligibilityDecisionSnapshot::create($canonicalInput);
            } catch (\InvalidArgumentException $e) {
                abort(500, 'Eligibility baseline reconstruction failed: '.$e->getMessage());
            }

            $this->baseline = $baseline->toArray();
            $this->systemLabel = $this->baseline['recommendation']['label'] ?? '';
            $this->validity = $data['eligibility']->validity;
            $this->iq = (int) $data['eligibility']->iq;

            // Initialize form state from baseline
            $this->loadFormState();
        }

        if ($data['narrative'] !== null) {
            $this->narrativeVersionId = $data['narrative']->id;
        }
    }

    public function hydrateCanAuthorizeAccess(): void
    {
        abort_unless(self::canAccess(), 404);
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 404);
    }

    // ─── Actions ───

    public function updatedLevelOverrides(): void
    {
        $this->refreshBlockingCodes();
    }

    public function updatedG7Resolutions(): void
    {
        $this->refreshBlockingCodes();
    }

    public function updatedNarrativeClusters(): void
    {
        $this->refreshBlockingCodes();
    }

    public function updatedProcedureNote(): void
    {
        $this->refreshBlockingCodes();
    }

    public function updatedAccompanimentConditions(): void
    {
        $this->refreshBlockingCodes();
    }

    public function updatedLabelOverrideFinal(): void
    {
        $this->refreshBlockingCodes();
    }

    public function validateDraft(): void
    {
        $this->resetValidation();
        $this->refreshBlockingCodes();
        $this->addBlockerFieldErrors();

        Notification::make()
            ->title('Validasi draf diperbarui.')
            ->body('Periksa daftar kondisi yang masih perlu dipenuhi sebelum tanda tangan.')
            ->info()
            ->send();
    }

    public function submit(ReportSigningService $signingService): void
    {
        if ($this->isReadOnly) {
            Notification::make()
                ->title('Laporan sudah ditandatangani.')
                ->body('Tidak dapat menandatangani ulang.')
                ->warning()
                ->send();

            return;
        }

        $admin = self::currentReviewer();
        if (! $admin instanceof Admin) {
            return;
        }

        $input = $this->buildInput();

        $result = $signingService->sign($this->casePublicId, $admin, $input);

        if (! $result['success']) {
            Notification::make()
                ->title('Gagal menandatangani')
                ->body($result['message'])
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Laporan berhasil ditandatangani.')
            ->body('Snapshot versi '.$result['data']['version'].' tersimpan.')
            ->success()
            ->send();

        // Redirect to read-only view
        $this->isReadOnly = true;
        $this->existingSnapshot = $result['data']['snapshot'];
    }

    public function requestRevision(): void
    {
        if (! $this->isReadOnly) {
            return;
        }

        $reason = trim($this->revisionReason);
        if (mb_strlen($reason) < 20) {
            $this->addError('revisionReason', 'Alasan revisi minimal 20 karakter.');

            return;
        }

        $this->isReadOnly = false;
        $this->isRevision = true;
        $this->existingSnapshot = null;
        $this->revisionReasonForSigning = $reason;
        $this->revisionReason = '';
        $this->loadFormState();
    }

    private function loadFormState(): void
    {
        $this->levelOverrides = [];
        $this->g7Resolutions = [];
        $this->narrativeClusters = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
        $this->procedureNote = '';
        $this->accompanimentConditions = '';
        $this->labelOverrideFinal = null;
        $this->labelOverrideReason = '';
        $this->revisionReason = '';
        $this->blockingCodes = [];

        if (empty($this->baseline)) {
            return;
        }

        foreach (self::ASPECTS as $aspect) {
            $systemLevel = (int) $this->baseline['zone']['aspects'][$aspect]['level'];
            $this->levelOverrides[$aspect] = [
                'system_level' => $systemLevel,
                'final_level' => $systemLevel,
                'reason' => '',
            ];
            $this->g7Resolutions[$aspect] = [
                'sources' => [['source' => 'CANONICAL', 'level' => $systemLevel]],
                'final_level' => null,
                'reason' => '',
            ];
        }
    }

    public function focusBlocker(string $code): void
    {
        $target = match ($code) {
            'V2_PROCEDURE_NOTE_REQUIRED' => 'procedure-note',
            'ACCOMPANIMENT_CONDITIONS_REQUIRED' => 'accompaniment-conditions',
            'G7_ASPECTS_UNRESOLVED' => 'g7-section',
            'OVERRIDE_REASON_MIN_LENGTH' => 'level-overrides-section',
            'TARGET_FIELD_REQUIRED' => 'case-header',
            'NARRATIVE_CLUSTER_A_REQUIRED' => 'cluster-A',
            'NARRATIVE_CLUSTER_B_REQUIRED' => 'cluster-B',
            'NARRATIVE_CLUSTER_C_REQUIRED' => 'cluster-C',
            'NARRATIVE_CLUSTER_D_REQUIRED' => 'cluster-D',
            default => 'readiness-heading',
        };

        $this->dispatch('review-focus', target: $target);
    }

    // ─── View data ───

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'aspects' => $this->aspectsData(),
            'aspLabels' => self::ASPECT_LABELS,
        ];
    }

    // ─── Helpers ───

    /** @return list<array<string, mixed>> */
    private function aspectsData(): array
    {
        return array_map(function (string $aspect): array {
            $systemLevel = $this->levelOverrides[$aspect]['system_level'] ?? 3;
            $finalLevel = $this->levelOverrides[$aspect]['final_level'] ?? $systemLevel;
            $overrideReason = $this->levelOverrides[$aspect]['reason'] ?? '';
            $changed = $finalLevel !== $systemLevel && $overrideReason !== '';

            $g7 = $this->g7Resolutions[$aspect] ?? null;
            $g7Sources = $g7['sources'] ?? [];
            $g7FinalLevel = $g7['final_level'] ?? null;
            $g7Reason = $g7['reason'] ?? '';

            // Determine G7 review state
            $discrepancyPolicy = new AspectSourceDiscrepancyPolicy;
            try {
                $discrepancy = $discrepancyPolicy->evaluate([
                    'aspect' => $aspect,
                    'sources' => $g7Sources,
                ]);
                $g7Required = $discrepancy['review_required'];
            } catch (\InvalidArgumentException) {
                $g7Required = false;
            }

            return [
                'code' => $aspect,
                'label' => self::ASPECT_LABELS[$aspect],
                'systemLevel' => $systemLevel,
                'finalLevel' => $finalLevel,
                'changed' => $changed,
                'overrideReason' => $overrideReason,
                'g7Required' => $g7Required,
                'g7Sources' => $g7Sources,
                'g7FinalLevel' => $g7FinalLevel,
                'g7Reason' => $g7Reason,
            ];
        }, self::ASPECTS);
    }

    /** @return array<string, mixed> */
    private function buildInput(): array
    {
        $levelOverrides = [];
        foreach (self::ASPECTS as $aspect) {
            $override = $this->levelOverrides[$aspect] ?? null;
            if ($override === null) {
                continue;
            }
            if ($override['final_level'] !== $override['system_level']) {
                $levelOverrides[] = [
                    'aspect' => $aspect,
                    'system_level' => $override['system_level'],
                    'final_level' => $override['final_level'],
                    'reason' => $override['reason'],
                ];
            }
        }

        $g7Resolutions = [];
        foreach (self::ASPECTS as $aspect) {
            $g7 = $this->g7Resolutions[$aspect] ?? null;
            if ($g7 === null) {
                continue;
            }
            $g7Resolutions[] = [
                'aspect' => $aspect,
                'sources' => $g7['sources'],
                'final_level' => $g7['final_level'],
                'reason' => $g7['reason'] ?: null,
            ];
        }

        $input = [
            'eligibility_version_id' => $this->eligibilityVersionId,
            'narrative_version_id' => $this->narrativeVersionId,
            'level_overrides' => $levelOverrides,
            'label_override' => null,
            'g7_resolutions' => $g7Resolutions,
            'procedure_note' => $this->procedureNote ?: null,
            'accompaniment_conditions' => $this->accompanimentConditions ?: null,
            'narrative_clusters' => $this->narrativeClusters,
        ];

        if ($this->labelOverrideFinal !== null && $this->labelOverrideFinal !== $this->systemLabel) {
            $input['label_override'] = [
                'system_label' => $this->systemLabel,
                'final_label' => $this->labelOverrideFinal,
                'reason' => $this->labelOverrideReason,
            ];
        }

        if ($this->isRevision) {
            $input['revision_reason'] = $this->revisionReasonForSigning;
        }

        return $input;
    }

    private function refreshBlockingCodes(): void
    {
        if ($this->isReadOnly || $this->eligibilityVersionId === null) {
            $this->blockingCodes = [];

            return;
        }

        // V3 (publication_blocked) is a hard blocker — cannot be resolved
        if ($this->baseline['publication_blocked'] === true) {
            $this->blockingCodes = ['VALIDITY_V3'];

            return;
        }

        $overrides = [];
        foreach (self::ASPECTS as $aspect) {
            $override = $this->levelOverrides[$aspect] ?? null;
            if ($override !== null && $override['final_level'] !== $override['system_level']) {
                $overrides[] = ['type' => 'level', 'aspect' => $aspect, 'reason' => $override['reason']];
            }
        }
        if ($this->labelOverrideFinal !== null && $this->labelOverrideFinal !== $this->systemLabel) {
            $overrides[] = ['type' => 'label', 'aspect' => null, 'reason' => $this->labelOverrideReason];
        }

        $unresolvedG7 = [];
        foreach (self::ASPECTS as $aspect) {
            $g7 = $this->g7Resolutions[$aspect] ?? null;
            if ($g7 === null) {
                continue;
            }
            $discrepancyPolicy = new AspectSourceDiscrepancyPolicy;
            try {
                $discrepancy = $discrepancyPolicy->evaluate([
                    'aspect' => $aspect,
                    'sources' => $g7['sources'],
                ]);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($discrepancy['review_required'] && $g7['final_level'] === null) {
                $unresolvedG7[] = $aspect;
            }
        }

        try {
            $result = (new ReportSigningPrerequisitePolicy)->evaluate([
                'validity' => $this->validity,
                'procedure_note' => $this->procedureNote,
                'label' => $this->labelOverrideFinal ?? $this->systemLabel,
                'accompaniment_conditions' => $this->accompanimentConditions,
                'unresolved_g7_aspects' => $unresolvedG7,
                'overrides' => $overrides,
                'target_field' => $this->intendedField,
                'narrative_clusters' => $this->narrativeClusters,
            ]);

            $this->blockingCodes = $result['blocking_reason_codes'];
        } catch (\InvalidArgumentException) {
            $this->blockingCodes = [];
        }
    }

    private function addBlockerFieldErrors(): void
    {
        $fields = [
            'V2_PROCEDURE_NOTE_REQUIRED' => ['procedureNote', 'Catatan prosedur wajib untuk validitas V2.'],
            'ACCOMPANIMENT_CONDITIONS_REQUIRED' => ['accompanimentConditions', 'Syarat pendampingan wajib untuk label DIPERTIMBANGKAN.'],
            'TARGET_FIELD_REQUIRED' => ['intendedField', 'Bidang tujuan wajib ditetapkan.'],
            'NARRATIVE_CLUSTER_A_REQUIRED' => ['narrativeClusters.A', 'Narasi klaster A wajib diisi.'],
            'NARRATIVE_CLUSTER_B_REQUIRED' => ['narrativeClusters.B', 'Narasi klaster B wajib diisi.'],
            'NARRATIVE_CLUSTER_C_REQUIRED' => ['narrativeClusters.C', 'Narasi klaster C wajib diisi.'],
            'NARRATIVE_CLUSTER_D_REQUIRED' => ['narrativeClusters.D', 'Narasi klaster D wajib diisi.'],
        ];

        foreach ($fields as $code => [$field, $message]) {
            if (in_array($code, $this->blockingCodes, true)) {
                $this->addError($field, $message);
            }
        }
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
