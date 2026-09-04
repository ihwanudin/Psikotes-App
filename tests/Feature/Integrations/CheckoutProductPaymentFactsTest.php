<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\PackageItem;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutPaymentFactsReader;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class CheckoutProductPaymentFactsTest extends OrganizationPaymentTestCase
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
        DB::table('assessment_participants')->update(['funding_mode' => 'INVOICED_TO_ORGANIZATION', 'metadata' => json_encode([
            'checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => null,
        ], JSON_THROW_ON_ERROR)]);
    }

    public function test_snapshot_wins_with_one_charge_lookup_without_catalog_queries_or_writes(): void
    {
        $snapshot = AssessmentCharge::findOrFail($this->fixture['charge'])->price_snapshot;
        DB::table('packages')->update(['name' => 'CHANGED_PRIVATE_CATALOG', 'amount' => 999]);
        DB::table('package_items')->update(['test_type' => 'papi']);
        DB::table('assessment_bills')->update(['invoice_url' => 'https://synthetic.invalid/PRIVATE', 'gateway_ref' => 'PRIVATE']);
        app(RlsContextRunner::class)->runAsService(function () use ($snapshot): void {
            $attempt = AssessmentParticipant::findOrFail($this->fixture['attempt']);
            $context = app(RlsContextRunner::class)->current();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $facts = app(CheckoutPaymentFactsReader::class)->projectAt($attempt, $this->asOf);
                $this->assertSame(['product' => ['packageLabel' => $snapshot['packageName'], 'source' => 'charge_snapshot', 'testTypes' => ['ist']],
                    'payment' => ['payer' => 'organization', 'state' => 'paid', 'amountIdr' => 100,
                        'amountSource' => 'charge_snapshot', 'consultationRequested' => false, 'actionAvailable' => false]], $facts->toArray());
                $this->assertSame($facts->toArray(), json_decode(json_encode($facts, JSON_THROW_ON_ERROR), true));
                $queries = array_column(DB::getQueryLog(), 'query');
                $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'from "assessment_charges"')));
                foreach ($queries as $sql) {
                    $this->assertStringStartsWith('select', $sql);
                    $this->assertStringNotContainsString('for update', $sql);
                    $this->assertStringNotContainsString('from "packages"', $sql);
                    $this->assertStringNotContainsString('from "package_items"', $sql);
                }
                $this->assertSame($context, app(RlsContextRunner::class)->current());
            } finally {
                DB::disableQueryLog();
            }
        });
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    public function test_no_charge_uses_only_preloaded_own_catalog_without_inventing_prices(): void
    {
        $this->removeCharge();
        DB::table('packages')->update(['name' => 'Own catalogue', 'amount' => null, 'consultation_amount' => null]);
        DB::table('package_items')->insert(['package_id' => $this->fixture['package'], 'test_type' => 'dass21', 'sort_order' => 9]);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $attempt = AssessmentParticipant::with('package.items')->findOrFail($this->fixture['attempt']);
            $reader = app(CheckoutPaymentFactsReader::class);
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $facts = $reader->projectAt($attempt, $this->asOf);
                $queries = array_column(DB::getQueryLog(), 'query');
                $this->assertCount(1, $queries);
                $this->assertStringContainsString('from "assessment_charges"', $queries[0]);
            } finally {
                DB::disableQueryLog();
            }
            $this->assertSame(['packageLabel' => 'Own catalogue', 'source' => 'catalog', 'testTypes' => ['dass21', 'ist']], $facts->toArray()['product']);
            $this->assertNull($facts->payment->amountIdr);
            $this->assertNull($facts->payment->consultationRequested);
            $this->assertSame($reader->project($attempt)->toArray(), $facts->payment->toArray());
            $attempt->unsetRelation('package');
            $this->assertNull($reader->project($attempt)->amountIdr);
        });
    }

    public function test_all_known_catalog_types_are_sorted_without_selecting_payer_or_price(): void
    {
        $this->removeCharge();
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED', 'funding_mode' => null]);
        DB::table('package_items')->delete();
        foreach (['rmib', 'papi', 'ist', 'dass21', 'kraepelin'] as $position => $type) {
            DB::table('package_items')->insert(['package_id' => $this->fixture['package'], 'test_type' => $type, 'sort_order' => $position]);
        }
        app(RlsContextRunner::class)->runAsService(function (): void {
            $attempt = AssessmentParticipant::with('package.items')->findOrFail($this->fixture['attempt']);
            $facts = app(CheckoutPaymentFactsReader::class)->projectAt($attempt, $this->asOf);
            $this->assertSame(['dass21', 'ist', 'kraepelin', 'papi', 'rmib'], $facts->testTypes);
            $this->assertNull($facts->payment->payer);
            $this->assertSame('unselected', $facts->payment->state);
            $this->assertNull($facts->payment->amountIdr);
            $this->assertNull($facts->payment->consultationRequested);
        });
    }

    public function test_explicit_free_projection_reuses_instant_after_marker_check_and_clock_drift(): void
    {
        DB::table('assessment_bill_items')->delete();
        $charge = AssessmentCharge::findOrFail($this->fixture['charge']);
        $snapshot = $charge->price_snapshot;
        $snapshot['amount'] = $snapshot['baseAmount'] = 0;
        $charge->update(['amount' => 0, 'base_amount' => 0, 'price_snapshot' => $snapshot, 'free_settled_at' => $this->asOf]);
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = clone $original;
        $connection->setEventDispatcher($events);
        $hit = false;
        $events->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$hit): void {
            if (! $hit && str_starts_with($event->sql, 'select') && str_contains($event->sql, 'from "assessment_bill_items"')) {
                $hit = true;
                $this->travelTo($this->asOf->subDay());
                config()->set('consent.documents', null);
            }
        });
        try {
            $this->assertSame('free', $this->read()->payment->state);
            $this->assertTrue($hit);
            $this->assertTrue(now()->equalTo($this->asOf->subDay()));
            $this->assertNull(config('consent.documents'));
        } finally {
            $connection->setEventDispatcher($original);
            $this->travelTo($this->asOf);
        }
    }

    #[DataProvider('invalidCatalogs')]
    public function test_invalid_catalog_graph_fails_closed(string $mutation): void
    {
        $this->removeCharge();
        app(RlsContextRunner::class)->runAsService(function () use ($mutation): void {
            $attempt = AssessmentParticipant::with('package.items')->findOrFail($this->fixture['attempt']);
            $package = $attempt->getRelation('package');
            match ($mutation) {
                'unloaded package' => $attempt->unsetRelation('package'),
                'missing package' => $attempt->setRelation('package', null),
                'wrong package type' => $attempt->setRelation('package', new PackageItem),
                'foreign package' => $attempt->setRelation('package', TestPackage::create(['code' => 'foreign', 'name' => 'Foreign'])),
                'unsaved package' => $package->exists = false,
                'dirty attempt scope' => $attempt->organization_id = 9999,
                'unloaded items' => $package->unsetRelation('items'),
                'empty items' => $package->setRelation('items', collect()),
                'wrong item type' => $package->setRelation('items', collect([$package])),
                'foreign item' => $package->items->first()->package_id = 9999,
                'unsaved item' => $package->items->first()->exists = false,
                'missing item key' => $package->items->first()->id = null,
                'blank label' => $package->name = ' ',
                'invalid label type' => $package->setRawAttributes([...$package->getAttributes(), 'name' => 123]),
                'unknown type' => $package->items->first()->test_type = 'unknown',
                'empty type' => $package->items->first()->test_type = '',
                'duplicate types' => $package->setRelation('items', collect([$package->items->first(), $package->items->first()])),
            };
            if (in_array($mutation, ['blank label', 'invalid label type', 'unknown type', 'empty type'], true)) {
                // Model a malformed loaded record as well as rejecting unsaved graph edits.
                $package->syncOriginal();
                $package->items->first()->syncOriginal();
            }
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('CHECKOUT_PAYMENT_UNAVAILABLE');
            app(CheckoutPaymentFactsReader::class)->projectAt($attempt, $this->asOf);
        });
    }

    public static function invalidCatalogs(): iterable
    {
        foreach (['unloaded package', 'missing package', 'wrong package type', 'foreign package', 'unsaved package',
            'dirty attempt scope', 'unloaded items', 'empty items', 'wrong item type', 'foreign item', 'unsaved item', 'missing item key',
            'blank label', 'invalid label type', 'unknown type', 'empty type', 'duplicate types'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidSnapshots')]
    public function test_canonical_snapshot_rejection_is_not_replaced_with_catalog(array $change): void
    {
        $charge = AssessmentCharge::findOrFail($this->fixture['charge']);
        $charge->update(['price_snapshot' => array_replace($charge->price_snapshot, $change)]);
        $this->expectExceptionMessage('CHECKOUT_PAYMENT_UNAVAILABLE');
        $this->read();
    }

    public static function invalidSnapshots(): iterable
    {
        yield [['testTypes' => []]];
        yield [['testTypes' => ['ist', 'ist']]];
        yield [['testTypes' => ['unknown']]];
        yield [['testTypes' => ['papi', 'ist']]];
        yield [['testTypes' => ['ist', 1]]];
        yield [['packageName' => ' ']];
        yield [['packageId' => 9999]];
        yield [['extra' => 'PRIVATE']];
    }

    #[DataProvider('timeEvidence')]
    public function test_shared_instant_bounds_free_and_paid_evidence_despite_global_drift(string $table, string $column, int $offset): void
    {
        if ($column === 'free_settled_at') {
            DB::table('assessment_bill_items')->delete();
            $charge = AssessmentCharge::findOrFail($this->fixture['charge']);
            $snapshot = $charge->price_snapshot;
            $snapshot['amount'] = $snapshot['baseAmount'] = 0;
            $charge->update(['amount' => 0, 'base_amount' => 0, 'price_snapshot' => $snapshot]);
        }
        DB::table($table)->update([$column => $this->asOf->addSeconds($offset)]);
        $this->travelTo($offset > 0 ? $this->asOf->addDay() : $this->asOf->subDay());
        if ($offset > 0) {
            $this->expectExceptionMessage('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        $this->assertSame($column === 'free_settled_at' ? 'free' : 'paid', $this->read()->payment->state);
    }

    public static function timeEvidence(): iterable
    {
        foreach (['assessment_bills' => 'paid_at', 'assessment_bill_items' => 'settled_at', 'assessment_charges' => 'free_settled_at'] as $table => $column) {
            foreach ([0, 1] as $offset) {
                yield $column.$offset => [$table, $column, $offset];
            }
        }
    }

    public function test_context_denies_before_malformed_graph_without_elevation(): void
    {
        foreach ([null, 'participant', 'super_admin'] as $role) {
            $call = function (): void {
                $context = app(RlsContextRunner::class)->current();
                try {
                    app(CheckoutPaymentFactsReader::class)->projectAt(new AssessmentParticipant, $this->asOf);
                    $this->fail('Context bypassed.');
                } catch (LogicException $exception) {
                    $this->assertSame('Checkout payment projection requires its validated service transaction.', $exception->getMessage());
                    $this->assertSame($context, app(RlsContextRunner::class)->current());
                }
            };
            $role === null ? $call() : app(RlsContextRunner::class)->run(new RlsContext($role, $this->fixture['organization'], $this->fixture['participant']), $call);
        }
    }

    private function read(): mixed
    {
        return app(RlsContextRunner::class)->runAsService(fn () => app(CheckoutPaymentFactsReader::class)
            ->projectAt(AssessmentParticipant::findOrFail($this->fixture['attempt']), $this->asOf));
    }

    private function removeCharge(): void
    {
        DB::table('assessment_entitlements')->delete();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->delete();
    }
}
