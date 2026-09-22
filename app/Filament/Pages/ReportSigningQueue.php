<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

final class ReportSigningQueue extends Page
{
    protected static ?string $slug = 'report-signing';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Pengujian';

    protected static ?string $navigationLabel = 'Tanda Tangan Laporan';

    protected static ?string $title = 'Tanda Tangan Laporan';

    /** @var view-string */
    protected string $view = 'filament.pages.report-signing-queue';

    /** @var array<int, array<string, mixed>> */
    public array $cases = [];

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

    public function mount(RlsContextRunner $runner): void
    {
        abort_unless(self::canAccess(), 404);

        // runAsService: RLS policies on assessment_cases, eligibility_decision_versions,
        // bilingual_narrative_versions, and report_signing_snapshots only allow the
        // service role. Access is still gated by canAccess() / ReviewReports ability.
        $this->cases = $runner->runAsService(function (): array {
            return DB::table('assessment_cases', 'ac')
                ->join('participants as p', 'p.id', '=', 'ac.participant_id')
                ->leftJoin('branches as b', 'b.id', '=', 'ac.organization_id')
                ->whereExists(function ($q): void {
                    $q->select(DB::raw(1))
                        ->from('eligibility_decision_versions', 'edv')
                        ->whereColumn('edv.assessment_case_id', 'ac.id');
                })
                ->whereExists(function ($q): void {
                    $q->select(DB::raw(1))
                        ->from('bilingual_narrative_versions', 'bnv')
                        ->whereColumn('bnv.assessment_case_id', 'ac.id');
                })
                ->leftJoin(
                    DB::raw('('.
                        'SELECT assessment_case_id, state, version FROM ('.
                        'SELECT assessment_case_id, state, version, '.
                        'ROW_NUMBER() OVER (PARTITION BY assessment_case_id ORDER BY version DESC) as rn '.
                        'FROM report_signing_snapshots'.
                        ') ranked WHERE rn = 1'.
                    ') as latest_signing'),
                    'latest_signing.assessment_case_id',
                    '=',
                    'ac.id'
                )
                ->select([
                    'ac.public_id',
                    'ac.intended_field_snapshot',
                    'p.full_name',
                    'p.test_number',
                    'b.name as branch_name',
                    'latest_signing.state as signing_state',
                    'latest_signing.version as signing_version',
                ])
                ->orderBy('ac.created_at', 'desc')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->values()
                ->toArray();
        });
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
