<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Proctoring\ProctoringValidityPolicy;
use App\Enums\AdminRole;
use App\Models\Admin;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class F7OperationalOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Ringkasan operasional';

    protected ?string $description = 'Dashboard read-only untuk admin pusat dan cabang dari data yang sudah tersedia.';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Filament::auth()->user() instanceof Admin;
    }

    /**
     * @return array{
     *     participants:int,
     *     pendingBills:int,
     *     paidBills:int,
     *     readyEntitlements:int,
     *     proctoringRisk:int,
     *     scope:string
     * }
     */
    public static function metricsFor(Admin $admin): array
    {
        $branchId = self::scopedBranchId($admin);
        $emptyDecision = (new ProctoringValidityPolicy)->decide([]);

        return [
            'participants' => self::scopeByBranch(DB::table('participants'), 'branch_id', $branchId)
                ->whereNull('deleted_at')
                ->count(),
            'pendingBills' => self::scopeByBranch(DB::table('assessment_bills'), 'organization_id', $branchId)
                ->where('status', 'pending')
                ->count(),
            'paidBills' => self::scopeByBranch(DB::table('assessment_bills'), 'organization_id', $branchId)
                ->where('status', 'paid')
                ->count(),
            'readyEntitlements' => self::scopeByBranch(DB::table('assessment_entitlements'), 'organization_id', $branchId)
                ->where('status', 'ready')
                ->count(),
            // Proctoring persistence is not implemented yet. Keep this visible as
            // a policy-backed zero rather than inventing a source from unrelated data.
            'proctoringRisk' => $emptyDecision->pendingAdjudication ? count($emptyDecision->pendingEvidenceIds) : 0,
            'scope' => $branchId === null ? 'Semua cabang' : 'Cabang sendiri',
        ];
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $admin = Filament::auth()->user();
        if (! $admin instanceof Admin) {
            return [];
        }

        $metrics = self::metricsFor($admin);

        return [
            Stat::make('Peserta', self::formatInteger($metrics['participants']))
                ->description($metrics['scope'])
                ->color('primary'),
            Stat::make('Tagihan menunggu bayar', self::formatInteger($metrics['pendingBills']))
                ->description('Status pending')
                ->color($metrics['pendingBills'] > 0 ? 'warning' : 'success'),
            Stat::make('Pembayaran lunas', self::formatInteger($metrics['paidBills']))
                ->description('Status paid')
                ->color('success'),
            Stat::make('Entitlement siap tes', self::formatInteger($metrics['readyEntitlements']))
                ->description('Status ready')
                ->color('info'),
            Stat::make('Risiko proctoring terbuka', self::formatInteger($metrics['proctoringRisk']))
                ->description('Belum ada evidence proctoring persisten')
                ->color($metrics['proctoringRisk'] > 0 ? 'danger' : 'gray'),
        ];
    }

    private static function scopedBranchId(Admin $admin): ?int
    {
        return in_array($admin->role, [AdminRole::BranchAdmin, AdminRole::Staff], true)
            ? (int) $admin->branch_id
            : null;
    }

    private static function scopeByBranch(Builder $query, string $column, ?int $branchId): Builder
    {
        return $branchId === null ? $query : $query->where($column, $branchId);
    }

    private static function formatInteger(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
