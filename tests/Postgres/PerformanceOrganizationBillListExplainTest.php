<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Enums\AdminRole;
use App\Filament\Resources\OrganizationBills\OrganizationBillResource;
use App\Models\Admin;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentBillingFixture as Fixture;

/** Disposable PostgreSQL plan evidence only; no production latency or index claim. */
final class PerformanceOrganizationBillListExplainTest extends TestCase
{
    private array $own;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->own = Fixture::create('organization');
            Fixture::create('organization');
            $this->admin = Admin::create([
                'name' => 'Synthetic plan admin',
                'email' => 'pg-plan@example.test',
                'password' => 'synthetic-test-password',
                'role' => AdminRole::BranchAdmin,
                'branch_id' => $this->own['organization'],
                'can_verify_payments' => false,
            ]);
        });

        Filament::auth()->setUser($this->admin);
        $this->clearDatabaseContext();
    }

    protected function tearDown(): void
    {
        Filament::auth()->forgetUser();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    #[DataProvider('cardinalities')]
    public function test_tenant_bill_list_plan_is_bounded_select_evidence(
        int $rowCount,
        int $pageSize,
    ): void {
        app(RlsContextRunner::class)->runAsService(
            fn () => $this->seedOrganizationBills($rowCount),
        );

        $this->asBranch(function () use ($rowCount, $pageSize): void {
            $role = DB::selectOne(
                'SELECT current_user AS name, rolsuper, rolbypassrls '
                .'FROM pg_roles WHERE rolname = current_user',
            );
            $this->assertSame('psikotes_runtime', $role->name);
            $this->assertFalse($role->rolsuper);
            $this->assertFalse($role->rolbypassrls);

            $security = DB::selectOne(
                'SELECT relrowsecurity, relforcerowsecurity, '
                .'pg_get_userbyid(relowner) AS owner '
                ."FROM pg_class WHERE oid = 'assessment_bills'::regclass",
            );
            $this->assertTrue($security->relrowsecurity);
            $this->assertTrue($security->relforcerowsecurity);
            $this->assertNotSame($role->name, $security->owner);

            $query = OrganizationBillResource::getEloquentQuery()
                ->orderByDesc('id')
                ->limit($pageSize);
            $sql = $query->toSql();
            $this->assertStringStartsWith('select ', strtolower($sql));
            $this->assertStringContainsString('"payer_type"', $sql);
            $this->assertStringContainsString('"organization_id"', $sql);

            $before = app(RlsContextRunner::class)->runAsService(
                fn (): int => DB::table('assessment_bills')->count(),
            );
            $explain = DB::selectOne(
                'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql,
                $query->getBindings(),
            );
            $document = json_decode(
                (string) $explain->{'QUERY PLAN'},
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            $after = app(RlsContextRunner::class)->runAsService(
                fn (): int => DB::table('assessment_bills')->count(),
            );
            $this->assertSame($before, $after, 'EXPLAIN ANALYZE must remain SELECT-only.');

            $root = $document[0] ?? null;
            $this->assertIsArray($root);
            $this->assertArrayHasKey('Plan', $root);
            $plan = $root['Plan'];
            $this->assertSame($pageSize, (int) $plan['Actual Rows']);
            $this->assertSame('Limit', $plan['Node Type']);

            $nodes = $this->flattenPlan($plan);
            $nodeTypes = array_column($nodes, 'Node Type');
            $this->assertNotEmpty($nodeTypes);
            $this->assertSame([], array_values(array_intersect(
                ['ModifyTable', 'Insert', 'Update', 'Delete'],
                $nodeTypes,
            )));

            $rows = $query->get();
            $this->assertCount($pageSize, $rows);
            $this->assertTrue($rows->every(
                fn ($bill): bool => $bill->organization_id === $this->own['organization']
                    && $bill->payer_type === 'organization',
            ));

            $metrics = [
                'syntheticRows' => $rowCount,
                'pageSize' => $pageSize,
                'actualRows' => (int) $plan['Actual Rows'],
                'planningMs' => (float) ($root['Planning Time'] ?? 0.0),
                'executionMs' => (float) ($root['Execution Time'] ?? 0.0),
                'buffers' => $this->bufferTotals($nodes),
                'nodes' => $nodeTypes,
            ];
            fwrite(STDERR, "\nPortal PG EXPLAIN ".json_encode(
                $metrics,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            )."\n");
        });
    }

    public static function cardinalities(): iterable
    {
        yield 'ten rows, ten visible' => [10, 10];
        yield 'fifty rows, twenty-five visible' => [50, 25];
        yield 'five hundred rows, fifty visible' => [500, 50];
    }

    private function asBranch(callable $callback): mixed
    {
        return app(RlsContextRunner::class)->run(
            new RlsContext('branch_admin', $this->own['organization']),
            $callback,
        );
    }

    private function seedOrganizationBills(int $count): void
    {
        $template = (array) DB::table('assessment_bills')
            ->where('id', $this->own['bill'])
            ->sole();
        unset($template['id']);
        $rows = [];
        for ($index = 1; $index < $count; $index++) {
            $nonce = hash('sha256', "performance-{$count}-{$index}");
            $rows[] = [
                ...$template,
                'public_reference' => 'AB_0'.substr(strtoupper($nonce), 0, 25),
                'selection_hash' => $nonce,
                'idempotency_key' => 'perf-'.$nonce,
                'request_hash' => hash('sha256', 'request-'.$nonce),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('assessment_bills')->insert($chunk);
        }
    }

    /** @return list<array<string, mixed>> */
    private function flattenPlan(array $plan): array
    {
        $nodes = [$plan];
        foreach ($plan['Plans'] ?? [] as $child) {
            $nodes = [...$nodes, ...$this->flattenPlan($child)];
        }

        return $nodes;
    }

    /** @return array<string, int> */
    private function bufferTotals(array $nodes): array
    {
        $keys = [
            'Shared Hit Blocks', 'Shared Read Blocks', 'Shared Dirtied Blocks',
            'Shared Written Blocks', 'Local Hit Blocks', 'Local Read Blocks',
            'Temp Read Blocks', 'Temp Written Blocks',
        ];
        $totals = array_fill_keys($keys, 0);
        foreach ($nodes as $node) {
            foreach ($keys as $key) {
                $totals[$key] += (int) ($node[$key] ?? 0);
            }
        }

        return $totals;
    }

    private function clearDatabaseContext(): void
    {
        DB::select(
            "SELECT set_config('app.role', '', true), "
            ."set_config('app.branch_id', '', true), "
            ."set_config('app.participant_id', '', true)",
        );
    }
}
