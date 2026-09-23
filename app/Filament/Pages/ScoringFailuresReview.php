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
 * had no code path writing it yet at the time, so building UI against it
 * would have been UI against data nothing produces. The query below
 * already includes 'not_scorable' in its outcome filter so it needs no
 * further change now that PR3 exists -- it will just start showing rows
 * once a session actually lands there.
 *
 * ADR-0032 PR3 follow-up (2026-09-23): RMIB's OTHER new outcome --
 * `reviewRequired` (exactly one rank group excluded, still scored, per
 * psychologist P3) -- is a SEPARATE section below ($reviewRequiredResults),
 * not a row in $attempts. Unlike `failed_to_score`/`not_scorable`, a
 * reviewRequired result is NOT an assessment_scoring_attempts row at all --
 * it's a normal, successful `generic_instrument_results` row (scoring did
 * not fail), just one whose payload the psychologist should read
 * qualitatively rather than take at face value (SCORING_ALGORITHM.md /
 * P3's own wording). `reviewRequired`/`excludedGroups` live only inside
 * that row's `result_payload` JSON (SealedRmibResult -- no dedicated
 * columns), so this page decodes and filters in PHP rather than a
 * driver-specific JSON-path WHERE, since generic_instrument_results has no
 * volume concerns that would make that unaffordable on an admin-only page.
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

    /** @var list<array<string, mixed>> */
    public array $reviewRequiredResults = [];

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
        // assessment_scoring_attempts and generic_instrument_results are
        // both service-role-select-only (no admin-role RLS policy at all --
        // see their own migration docblocks), same reason ReportSigningQueue
        // wraps its own service-only reads. Access to THIS page is still
        // gated by canAccess()/mount() above.
        [$this->attempts, $this->reasonCodeOptions, $this->reviewRequiredResults] = app(RlsContextRunner::class)->runAsService(
            fn (): array => [
                array_values($this->query()->get()->map($this->row(...))->all()),
                $this->reasonCodeOptions(),
                $this->reviewRequiredResults(),
            ],
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

    /** @return list<array<string, mixed>> */
    private function reviewRequiredResults(): array
    {
        // Only RMIB can ever produce this outcome (ADR-0032 PR3) -- an
        // instrument filter for anything else means the viewer explicitly
        // narrowed away from RMIB, so respect that rather than showing an
        // unrelated section underneath a filtered-out instrument's table.
        if ($this->instrumentFilter !== null && $this->instrumentFilter !== '' && $this->instrumentFilter !== 'rmib') {
            return [];
        }

        $query = DB::table('generic_instrument_results as result')
            ->join('participants as participant', 'participant.id', '=', 'result.participant_id')
            ->where('result.instrument_code', 'rmib');

        if ($this->dateFrom !== null && $this->dateFrom !== '') {
            $query->whereDate('result.submitted_at', '>=', $this->dateFrom);
        }
        if ($this->dateTo !== null && $this->dateTo !== '') {
            $query->whereDate('result.submitted_at', '<=', $this->dateTo);
        }

        $rows = $query
            ->select(['result.session_public_id', 'result.submitted_at', 'result.result_payload', 'participant.full_name', 'participant.test_number'])
            ->orderBy('result.submitted_at', 'desc')
            ->get();

        $results = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->result_payload, true, flags: JSON_THROW_ON_ERROR);
            if (($payload['reviewRequired'] ?? false) !== true) {
                continue;
            }

            $results[] = [
                'session_public_id' => $row->session_public_id,
                'submitted_at' => $row->submitted_at,
                'full_name' => $row->full_name,
                'test_number' => $row->test_number,
                // Same tier split as reason_code above: excludedGroups is
                // psychometric detail (which rank groups the score ignored
                // and why), not just an operational flag -- never included
                // in the array (not merely hidden in the view) for a
                // viewer who can't see reasons.
                'excluded_groups' => $this->canSeeReason ? $payload['excludedGroups'] : null,
            ];
        }

        return $results;
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
