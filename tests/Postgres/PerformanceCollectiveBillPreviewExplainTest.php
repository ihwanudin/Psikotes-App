<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Enums\AdminRole;
use App\Filament\Actions\PreviewCollectiveBillSelection;
use App\Models\Admin;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

/**
 * Disposable functional EXPLAIN execution evidence only. Planner statistics are
 * intentionally not refreshed; this makes no index, latency, or production claim.
 */
final class PerformanceCollectiveBillPreviewExplainTest extends TestCase
{
    private array $own;

    private array $foreign;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->own = $this->fixture('OWN');
            $this->foreign = $this->fixture('FOREIGN');
            $this->admin = Admin::create([
                'name' => 'Synthetic collective plan admin',
                'email' => 'pg-collective-plan@example.test',
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

    #[DataProvider('functionalSelectionSizes')]
    public function test_collective_preview_functional_explain_shapes_are_select_only(
        ?int $requestedSize,
    ): void {
        $configuredMaximum = (int) config('assessment_billing.max_items');
        $this->assertSame(100, $configuredMaximum);
        $selectionSize = $requestedSize ?? $configuredMaximum;
        $this->assertTrue(in_array($selectionSize, [10, $configuredMaximum], true));

        $selection = app(RlsContextRunner::class)->runAsService(
            function () use ($selectionSize): array {
                $selection = [Fixture::selection($this->own)];
                for ($index = 1; $index < $selectionSize; $index++) {
                    $selection[] = Fixture::selection(
                        $this->fixture(
                            'PLAN-'.$index,
                            $this->own['organization'],
                        ),
                    );
                }

                return $selection;
            },
        );

        $role = DB::selectOne(
            'SELECT current_user AS name, rolsuper, rolbypassrls '
            .'FROM pg_roles WHERE rolname = current_user',
        );
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        foreach (
            [
                'participants',
                'assessment_participants',
                'assessment_charges',
                'assessment_bills',
                'assessment_bill_items',
                'assessment_entitlements',
            ] as $table
        ) {
            $security = DB::selectOne(
                'SELECT relrowsecurity, relforcerowsecurity, '
                .'pg_get_userbyid(relowner) AS owner '
                .'FROM pg_class WHERE oid = to_regclass(?)',
                [$table],
            );
            $this->assertTrue($security->relrowsecurity, $table);
            $this->assertTrue($security->relforcerowsecurity, $table);
            $this->assertNotSame($role->name, $security->owner, $table);
        }

        $before = $this->effectCounts();
        [$result, $plans] = $this->asBranch(function () use ($selection): array {
            $this->assertFalse(DB::table('assessment_participants')
                ->where('id', $this->foreign['attempt'])->exists());
            $this->assertSame(count($selection), DB::table('assessment_participants')
                ->whereIn('id', array_column($selection, 'assessmentParticipantId'))->count());

            return $this->captureFunctionalPlans($selection);
        });
        $this->assertSame($before, $this->effectCounts());
        $this->assertCount($selectionSize, $result['items']);
        $this->assertSame(
            array_column($selection, 'assessmentParticipantId'),
            array_column($result['items'], 'assessmentParticipantId'),
        );
        $this->assertNotEmpty($plans);
        $this->assertLessThanOrEqual(16, count($plans));

        $metrics = [
            'fixtureOwnTenantRows' => $selectionSize,
            'functionalSelectPlans' => count($plans),
            'plans' => $plans,
            'functionalPlanShapeOnly' => true,
            'plannerStatisticsRefreshed' => false,
            'indexClaim' => false,
            'latencyClaim' => false,
            'productionClaim' => false,
        ];
        $this->assertTrue($metrics['functionalPlanShapeOnly']);
        $this->assertFalse($metrics['plannerStatisticsRefreshed']);
        $this->assertFalse($metrics['indexClaim']);
        $this->assertFalse($metrics['latencyClaim']);
        $this->assertFalse($metrics['productionClaim']);
        fwrite(STDERR, "\nCollective preview PG EXPLAIN ".json_encode(
            $metrics,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        )."\n");
    }

    public static function functionalSelectionSizes(): iterable
    {
        yield 'ten selections' => [10];
        yield 'configured maximum selections' => [null];
    }

    private function fixture(string $label, ?int $organization = null): array
    {
        $fixture = Fixture::create(
            $organization === null ? null : ['organization' => $organization],
        );
        DB::table('package_items')->insert([
            'package_id' => $fixture['package'],
            'test_type' => 'dass21',
            'sort_order' => 2,
        ]);
        DB::table('participants')->where('id', $fixture['participant'])->update([
            'full_name' => 'Peserta '.$label,
        ]);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'external_candidate_id' => 'CANDIDATE-'.$fixture['attempt'],
            'assessment_round_id' => 'Synthetic period',
            'metadata' => '{"checkout_contract_version":"checkout-v2"}',
        ]);

        return $fixture;
    }

    private function asBranch(callable $callback): mixed
    {
        return app(RlsContextRunner::class)->run(
            new RlsContext('branch_admin', $this->own['organization']),
            $callback,
        );
    }

    /**
     * Execute the accepted production preview and EXPLAIN each emitted application
     * SELECT immediately under the same RLS context. Context-setting SELECTs are
     * verified as SELECT-only but deliberately not replayed.
     *
     * @param  list<array{assessmentParticipantId: int, consultationRequested: bool}>  $selection
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function captureFunctionalPlans(array $selection): array
    {
        $connection = DB::connection();
        $originalEvents = $connection->getEventDispatcher();
        $this->assertNotNull($originalEvents);
        $events = clone $originalEvents;
        $plans = [];
        $explaining = false;
        $contextStatements = 0;

        $connection->setEventDispatcher($events);
        try {
            $events->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$plans, &$explaining, &$contextStatements): void {
                if ($explaining) {
                    return;
                }

                $sql = $event->sql;
                $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql);
                $this->assertDoesNotMatchRegularExpression('/;\s*\S/', $sql);

                if (preg_match('/^\s*select\s+set_config\s*\(/i', $sql) === 1) {
                    $normalized = preg_replace('/\s+/', ' ', trim($sql));
                    $this->assertSame(
                        "SELECT set_config('app.role', ?, true), "
                        ."set_config('app.branch_id', ?, true), "
                        ."set_config('app.participant_id', ?, true)",
                        $normalized,
                    );
                    $this->assertCount(3, $event->bindings);
                    foreach ($event->bindings as $binding) {
                        $this->assertIsString($binding);
                    }
                    $contextStatements++;

                    return;
                }

                $explaining = true;
                try {
                    $explain = DB::selectOne(
                        'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql,
                        $event->bindings,
                    );
                } finally {
                    $explaining = false;
                }

                $document = json_decode(
                    (string) $explain->{'QUERY PLAN'},
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                );
                $root = $document[0]['Plan'] ?? null;
                $this->assertIsArray($root);
                $nodes = $this->flattenPlan($root);
                $nodeTypes = array_column($nodes, 'Node Type');
                $this->assertNotEmpty($nodeTypes);
                $this->assertSame([], array_values(array_intersect(
                    ['ModifyTable', 'Insert', 'Update', 'Delete'],
                    $nodeTypes,
                )));
                $buffers = $this->rootBufferTotals($root);
                foreach (array_keys($buffers) as $key) {
                    $this->assertSame((int) ($root[$key] ?? 0), $buffers[$key]);
                }

                $plans[] = [
                    'ordinal' => count($plans) + 1,
                    'rootNode' => $root['Node Type'],
                    'actualRows' => (int) $root['Actual Rows'],
                    'buffers' => $buffers,
                    'nodes' => $nodeTypes,
                ];
            });

            $result = app(PreviewCollectiveBillSelection::class)->execute($selection);
            $this->assertSame(2, $contextStatements);
        } finally {
            $connection->setEventDispatcher($originalEvents);
        }

        return [$result, $plans];
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
    private function rootBufferTotals(array $root): array
    {
        $keys = [
            'Shared Hit Blocks', 'Shared Read Blocks', 'Shared Dirtied Blocks',
            'Shared Written Blocks', 'Local Hit Blocks', 'Local Read Blocks',
            'Temp Read Blocks', 'Temp Written Blocks',
        ];
        $totals = [];
        foreach ($keys as $key) {
            // PostgreSQL root BUFFERS already include every child plan node.
            $totals[$key] = (int) ($root[$key] ?? 0);
        }

        return $totals;
    }

    /** @return array<string, int> */
    private function effectCounts(): array
    {
        return app(RlsContextRunner::class)->runAsService(fn (): array => [
            'assessment_charges' => DB::table('assessment_charges')->count(),
            'assessment_bills' => DB::table('assessment_bills')->count(),
            'assessment_bill_items' => DB::table('assessment_bill_items')->count(),
            'assessment_entitlements' => DB::table('assessment_entitlements')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'outbox_messages' => DB::table('outbox_messages')->count(),
            'orders' => DB::table('orders')->count(),
            'entitlements' => DB::table('entitlements')->count(),
        ]);
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
