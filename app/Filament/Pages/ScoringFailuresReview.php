<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;
use UnitEnum;

/**
 * Read-only review page for `assessment_scoring_attempts` rows that are
 * NOT `outcome = 'scored'` -- the audit trail ADR-0032 §3 added so a
 * rejected/unscorable attempt is durably recorded, not lost. Without this
 * page, those rows were recorded but invisible to anyone who didn't query
 * the database directly (Lead's finding, 2026-09-23).
 *
 * v1 scope (Lead's explicit decision, 2026-09-23): `failed_to_score` only
 * -- `not_scorable` (RMIB with 2+ defective rank groups, "ADR-0032 PR3")
 * has no code path writing it yet anywhere in this codebase (verified: zero
 * references outside the migration's own CHECK constraint), so building UI
 * against it now would be UI against data nothing produces. The query
 * below already includes 'not_scorable' in its outcome filter so this page
 * needs no further change once that lands -- it will just start showing
 * rows.
 *
 * Two-tier access (Lead's explicit decision, 2026-09-23 -- asked first,
 * not decided unilaterally, per CLAUDE.md's psychometric-access caution):
 * - ReviewReports (Psychologist): full row, reason_code included. This is
 *   psychometric content, same ability that already gates report
 *   signing/narrative editing/eligibility overrides (ReportSigning.php).
 * - ReviewScoringFailures (SuperAdmin/CentralAdmin, new ability): existence
 *   only (session/participant/instrument/attempted_at) for operational
 *   action (re-invite, re-administer) -- reason_code is NEVER included in
 *   $attempts for this tier, not just hidden in the view. A Livewire
 *   component's public properties serialize to the page's own HTML for
 *   client-side hydration regardless of what the Blade view chooses to
 *   render, so omitting the field from the query/array is the only way to
 *   keep it out of a SuperAdmin/CentralAdmin viewer's browser at all --
 *   `@if` in the view alone would not have been enough.
 * - BranchAdmin/Staff: no access, either tier (canAccess() false, 404).
 */
final class ScoringFailuresReview extends Page
{
    protected static ?string $slug = 'scoring-failures-review';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|UnitEnum|null $navigationGroup = 'Pengujian';

    protected static ?string $navigationLabel = 'Kegagalan Penilaian';

    protected static ?string $title = 'Kegagalan Penilaian';

    /** @var view-string */
    protected string $view = 'filament.pages.scoring-failures-review';

    private const OUTCOMES = ['failed_to_score', 'not_scorable'];

    /** @var list<array<string, mixed>> */
    public array $attempts = [];

    public bool $canSeeReason = false;

    public ?string $instrumentFilter = null;

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public ?string $reasonCodeFilter = null;

    /** @var list<string> */
    public array $reasonCodeOptions = [];

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public static function canAccess(): bool
    {
        $admin = self::currentReviewer();

        return $admin instanceof Admin
            && ($admin->canPerform(AdminAbility::ReviewReports)
                || $admin->canPerform(AdminAbility::ReviewScoringFailures));
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 404);

        $admin = self::currentReviewer();
        $this->canSeeReason = $admin instanceof Admin && $admin->canPerform(AdminAbility::ReviewReports);

        $this->reload();
    }

    public function updatedInstrumentFilter(): void
    {
        $this->reload();
    }

    public function updatedDateFrom(): void
    {
        $this->reload();
    }

    public function updatedDateTo(): void
    {
        $this->reload();
    }

    public function updatedReasonCodeFilter(): void
    {
        $this->reload();
    }

    public function mountCanAuthorizeAccess(): void
    {
        abort_unless(self::canAccess(), 404);
    }

    public function hydrateCanAuthorizeAccess(): void
    {
        abort_unless(self::canAccess(), 404);
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 404);

        // Re-derived from the current admin on every hydrate cycle, not
        // trusted from Livewire's own round-tripped property value --
        // belt-and-suspenders alongside the canAccess() re-check above,
        // since this flag decides whether reason_code ever reaches the
        // query in reload().
        $admin = self::currentReviewer();
        $this->canSeeReason = $admin instanceof Admin && $admin->canPerform(AdminAbility::ReviewReports);
    }

    private function reload(): void
    {
        // assessment_scoring_attempts is service-role-select-only (no
        // admin-role RLS policy at all -- see its own migration docblock),
        // same reason ReportSigningQueue wraps its own service-only reads.
        // Access to THIS page is still gated by canAccess()/mount() above.
        [$this->attempts, $this->reasonCodeOptions] = app(RlsContextRunner::class)->runAsService(
            fn (): array => [array_values($this->query()->get()->map($this->row(...))->all()), $this->reasonCodeOptions()],
        );
    }

    /** @return Builder */
    private function query()
    {
        $query = DB::table('assessment_scoring_attempts', 'attempt')
            ->join('test_sessions as session', 'session.id', '=', 'attempt.session_id')
            ->join('participants as participant', 'participant.id', '=', 'session.participant_id')
            ->whereIn('attempt.outcome', self::OUTCOMES);

        if ($this->instrumentFilter !== null && $this->instrumentFilter !== '') {
            $query->where('attempt.instrument_code', $this->instrumentFilter);
        }
        if ($this->dateFrom !== null && $this->dateFrom !== '') {
            $query->whereDate('attempt.attempted_at', '>=', $this->dateFrom);
        }
        if ($this->dateTo !== null && $this->dateTo !== '') {
            $query->whereDate('attempt.attempted_at', '<=', $this->dateTo);
        }
        // Reason-code filtering only makes sense for a viewer who can also
        // see the reason values themselves -- narrowing results by a code
        // a viewer can't see would leak information about that code's
        // existence through the result set even without a visible label.
        if ($this->canSeeReason && $this->reasonCodeFilter !== null && $this->reasonCodeFilter !== '') {
            $query->where('attempt.reason_code', $this->reasonCodeFilter);
        }

        return $query
            ->select([
                'attempt.public_id',
                'attempt.instrument_code',
                'attempt.attempted_at',
                'attempt.outcome',
                'attempt.reason_code',
                'session.public_id as session_public_id',
                'participant.full_name',
                'participant.test_number',
            ])
            ->orderBy('attempt.attempted_at', 'desc');
    }

    /** @return array<string, mixed> */
    private function row(stdClass $row): array
    {
        return [
            'public_id' => $row->public_id,
            'instrument_code' => $row->instrument_code,
            'attempted_at' => $row->attempted_at,
            'outcome' => $row->outcome,
            'reason_code' => $this->canSeeReason ? $row->reason_code : null,
            'session_public_id' => $row->session_public_id,
            'full_name' => $row->full_name,
            'test_number' => $row->test_number,
        ];
    }

    /** @return list<string> */
    private function reasonCodeOptions(): array
    {
        if (! $this->canSeeReason) {
            return [];
        }

        return array_values(DB::table('assessment_scoring_attempts')
            ->whereIn('outcome', self::OUTCOMES)
            ->whereNotNull('reason_code')
            ->distinct()
            ->orderBy('reason_code')
            ->pluck('reason_code')
            ->all());
    }

    /** @return list<string> */
    public function instrumentOptions(): array
    {
        return array_map(
            fn (GenericAssessmentInstrument $instrument): string => $instrument->value,
            GenericAssessmentInstrument::cases(),
        );
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
