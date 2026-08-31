<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\AssessmentBillItem;
use App\Models\AssessmentEntitlement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentBillingFixture as Fixture;

final class AssessmentBillItemsSchemaTest extends OrganizationPaymentTestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('assessment_bill_items'));
        $this->assertTrue(Schema::hasTable('assessment_entitlements'));
        $this->fixture = Fixture::create();
    }

    public function test_models_preserve_relations_and_locked_defaults_without_side_effects(): void
    {
        $item = AssessmentBillItem::create(Fixture::item($this->fixture))->refresh();
        $access = AssessmentEntitlement::create(Fixture::entitlement($this->fixture))->refresh();
        $this->assertSame($this->fixture['bill'], $item->bill->id);
        $this->assertSame($this->fixture['charge'], $item->charge->id);
        $this->assertSame(100, $item->amount);
        $this->assertNull($item->settled_at);
        $this->assertSame($this->fixture['attempt'], $access->assessmentParticipant->id);
        $this->assertSame($this->fixture['charge'], $access->charge->id);
        $this->assertSame('locked', $access->status);
        $this->assertNull($access->ready_at);
        $this->assertNull($access->started_at);
        $this->assertNull($access->completed_at);
        foreach (['orders', 'entitlements', 'outbox_messages'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_charge_cannot_be_claimed_twice_even_after_expiry(): void
    {
        DB::table('assessment_bill_items')->insert(Fixture::item($this->fixture));
        DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update(['status' => 'expired']);
        $another = Fixture::create('organization', $this->fixture);
        $this->expectException(QueryException::class);
        DB::table('assessment_bill_items')->insert([...Fixture::item($this->fixture), 'bill_id' => $another['bill']]);
    }

    public function test_entitlement_is_unique_per_attempt_not_per_participant(): void
    {
        DB::table('assessment_entitlements')->insert(Fixture::entitlement($this->fixture));
        $another = Fixture::create('organization', $this->fixture);
        DB::table('assessment_entitlements')->insert(Fixture::entitlement($another));
        $this->assertDatabaseCount('assessment_entitlements', 2);
        $this->expectException(QueryException::class);
        DB::table('assessment_entitlements')->insert(Fixture::entitlement($this->fixture));
    }

    #[DataProvider('crossScopeFields')]
    public function test_existing_foreign_scope_is_rejected(string $table, string $field, string $fixtureKey): void
    {
        $foreign = Fixture::create();
        $data = $table === 'assessment_bill_items' ? Fixture::item($this->fixture) : Fixture::entitlement($this->fixture);
        $this->expectException(QueryException::class);
        DB::table($table)->insert([...$data, $field => $foreign[$fixtureKey]]);
    }

    public static function crossScopeFields(): iterable
    {
        foreach (['bill_id' => 'bill', 'charge_id' => 'charge', 'organization_id' => 'organization', 'participant_id' => 'participant'] as $field => $key) {
            yield ['assessment_bill_items', $field, $key];
        }
        foreach (['charge_id' => 'charge', 'assessment_participant_id' => 'attempt', 'organization_id' => 'organization', 'participant_id' => 'participant'] as $field => $key) {
            yield ['assessment_entitlements', $field, $key];
        }
    }

    public function test_populated_reverse_rollback_preserves_bill_charge_and_attempt(): void
    {
        DB::table('assessment_bill_items')->insert(Fixture::item($this->fixture));
        DB::table('assessment_entitlements')->insert(Fixture::entitlement($this->fixture));
        $before = DB::table('assessment_charges')->where('id', $this->fixture['charge'])->first();
        $entitlements = require database_path('migrations/2026_08_31_000400_create_assessment_entitlements.php');
        $items = require database_path('migrations/2026_08_31_000300_create_assessment_bill_items.php');
        $entitlements->down();
        $items->down();
        $this->assertFalse(Schema::hasTable('assessment_bill_items'));
        $this->assertFalse(Schema::hasTable('assessment_entitlements'));
        $this->assertEquals($before, DB::table('assessment_charges')->where('id', $this->fixture['charge'])->first());
        $this->assertDatabaseHas('assessment_bills', ['id' => $this->fixture['bill'], 'status' => 'reserved']);
        $items->up();
        $entitlements->up();
        DB::table('assessment_bill_items')->insert(Fixture::item($this->fixture));
        DB::table('assessment_entitlements')->insert(Fixture::entitlement($this->fixture));
        $this->assertDatabaseCount('assessment_entitlements', 1);
    }
}
