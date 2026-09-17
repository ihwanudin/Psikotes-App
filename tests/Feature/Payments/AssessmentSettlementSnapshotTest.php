<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\AssessmentSettlementReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class AssessmentSettlementSnapshotTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $fixture;

    private CarbonImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:00:00 UTC'));
        $this->asOf = CarbonImmutable::instance(now());
        $this->fixture = Fixture::create();
    }

    private function settled(bool $explicit = true): bool
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($explicit): bool {
            $charge = AssessmentCharge::findOrFail($this->fixture['charge']);
            $reader = app(AssessmentSettlementReader::class);

            return $explicit ? $reader->isSettledAt($charge, $this->asOf) : $reader->isSettled($charge);
        });
    }

    private function collectiveMember(): array
    {
        $other = Fixture::create(identity: ['organization' => $this->fixture['organization']]);
        DB::table('assessment_bill_items')->where('id', $other['item'])->update(['bill_id' => $this->fixture['bill']]);
        DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update(['amount' => 200, 'item_count' => 2]);

        return $other;
    }

    #[DataProvider('timeBoundaries')]
    public function test_every_timestamp_uses_the_supplied_boundary(string $level, int $offset): void
    {
        $at = $this->asOf->addSeconds($offset);
        if ($level === 'free') {
            DB::table('assessment_bill_items')->where('id', $this->fixture['item'])->delete();
            DB::table('packages')->where('id', $this->fixture['package'])->update(['amount' => 0]);
            $snapshot = app(AssessmentPriceSnapshot::class)->capture(TestPackage::with('items')->findOrFail($this->fixture['package']), false);
            AssessmentCharge::findOrFail($this->fixture['charge'])->update([
                'amount' => 0, 'base_amount' => 0, 'price_snapshot' => $snapshot, 'free_settled_at' => $at,
            ]);
        } elseif ($level === 'bill') {
            DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update(['paid_at' => $at]);
        } else {
            $item = $level === 'member' ? $this->collectiveMember()['item'] : $this->fixture['item'];
            DB::table('assessment_bill_items')->where('id', $item)->update(['settled_at' => $at]);
        }
        $this->travelTo($this->asOf->addDay());
        $before = $this->businessRows();
        $this->assertSame($offset <= 0, $this->settled());
        $this->assertTrue(now()->equalTo($this->asOf->addDay()));
        $this->assertSame($before, $this->businessRows());
    }

    public static function timeBoundaries(): iterable
    {
        foreach (['free', 'item', 'bill', 'member'] as $level) {
            foreach ([-1, 0, 1] as $offset) {
                yield "$level $offset" => [$level, $offset];
            }
        }
    }

    #[DataProvider('midQueryClocks')]
    public function test_both_entrypoints_keep_one_instant_when_clock_changes_after_join(bool $explicit, int $shift): void
    {
        $other = $this->collectiveMember();
        DB::table('assessment_bill_items')->where('id', $other['item'])
            ->update(['settled_at' => $shift > 0 ? $this->asOf->addSecond() : $this->asOf]);
        $before = $this->businessRows();
        $connection = DB::connection();
        $previous = $connection->getEventDispatcher();
        $events = clone $previous;
        $connection->setEventDispatcher($events);
        $seen = false;
        $events->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$seen, $shift): void {
            if (! $seen && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"assessment_bill_items" as "item"')) {
                $seen = true;
                $this->travelTo($this->asOf->addSeconds($shift));
            }
        });
        try {
            $this->assertSame($shift < 0, $this->settled($explicit));
            $this->assertTrue($seen, 'The real joined evidence query must cross the clock barrier.');
            $this->assertTrue(now()->equalTo($this->asOf->addSeconds($shift)));
            $this->assertSame($before, $this->businessRows());
            $this->assertNull(app(RlsContextRunner::class)->current());
        } finally {
            $connection->setEventDispatcher($previous);
            $this->travelTo($this->asOf);
        }
    }

    public static function midQueryClocks(): iterable
    {
        foreach ([true, false] as $explicit) {
            foreach ([-2, 2] as $shift) {
                yield ($explicit ? 'explicit' : 'legacy')." $shift" => [$explicit, $shift];
            }
        }
    }

    public function test_legacy_captures_current_time_fresh_on_each_call(): void
    {
        DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update(['paid_at' => $this->asOf->addSecond()]);
        $this->assertFalse($this->settled(false));
        $this->travelTo($this->asOf->addSecond());
        $this->assertTrue($this->settled(false));
        $this->assertFalse($this->settled());
    }

    #[DataProvider('deniedContexts')]
    public function test_service_guard_precedes_charge_evaluation_and_performs_no_query(?string $role, bool $explicit): void
    {
        // Unpersisted charge: denial must precede evaluating its amount/marker or querying items.
        $charge = new AssessmentCharge;
        $read = function () use ($charge, $explicit): void {
            $context = app(RlsContextRunner::class)->current();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $reader = app(AssessmentSettlementReader::class);
                try {
                    $explicit ? $reader->isSettledAt($charge, $this->asOf) : $reader->isSettled($charge);
                    $this->fail('Non-service context must not evaluate settlement.');
                } catch (LogicException $exception) {
                    $this->assertSame('Settlement reader requires service RLS context.', $exception->getMessage());
                }
                $this->assertSame([], DB::getQueryLog());
                $this->assertSame($context, app(RlsContextRunner::class)->current());
                $this->assertTrue(now()->equalTo($this->asOf));
            } finally {
                DB::disableQueryLog();
            }
        };
        if ($role === null) {
            $read();
        } else {
            app(RlsContextRunner::class)->run(new RlsContext($role, $this->fixture['organization'], $this->fixture['participant']), $read);
        }
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    public static function deniedContexts(): iterable
    {
        foreach ([null, 'super_admin', 'branch_admin', 'staff', 'psychologist', 'participant'] as $role) {
            foreach ([true, false] as $explicit) {
                yield ($role ?? 'absent').($explicit ? ' explicit' : ' legacy') => [$role, $explicit];
            }
        }
    }

    public function test_as_of_does_not_reconstruct_an_earlier_paid_status(): void
    {
        DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update(['status' => 'pending']);
        $this->assertFalse($this->settled());
    }

    private function businessRows(): array
    {
        $rows = [];
        foreach (['assessment_bills', 'assessment_bill_items', 'assessment_charges', 'assessment_entitlements',
            'assessment_participants', 'audit_logs', 'outbox_messages'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
