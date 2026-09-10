<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Http\Requests\ProvisionAssessmentParticipantRequest;
use App\Http\Requests\StoreParticipantRegistrationRequest;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;
use Tests\Support\AssessmentBillingFixture;

final class CheckoutPartialProfileSchemaTest extends OrganizationPaymentTestCase
{
    private const FIELDS = ['full_name', 'gender', 'birth_date', 'education_level', 'intended_field', 'phone'];

    protected function setUp(): void
    {
        parent::setUp();
        // Fresh memory connection per application, without a surrounding test transaction:
        // SQLite rebuilds need FK PRAGMAs outside transactions, as Laravel's migrator does.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_31_000600_allow_checkout_partial_profiles.php');
    }

    public function test_populated_roundtrip_preserves_values_types_indexes_and_foreign_keys(): void
    {
        $f = Fixture::create();
        $participant = DB::table('participants')->find($f['participant']);
        $attempt = DB::table('assessment_participants')->find($f['attempt']);
        $structures = $this->structure();
        $this->migration()->down();
        $this->assertNullable(false);
        $this->assertEquals($participant, DB::table('participants')->find($f['participant']));
        $this->assertEquals($attempt, DB::table('assessment_participants')->find($f['attempt']));
        $this->migration()->up();
        $this->assertNullable(true);
        $this->assertEquals($structures, $this->structure());
        $this->assertEquals($participant, DB::table('participants')->find($f['participant']));
        $this->assertEquals($attempt, DB::table('assessment_participants')->find($f['attempt']));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
    }

    #[DataProvider('profileFields')]
    public function test_partial_profile_is_stored_but_gate_remains_closed_and_down_refuses_without_mutation(string $field): void
    {
        $f = Fixture::create();
        DB::table('participants')->where('id', $f['participant'])->update([$field => null]);
        try {
            app(RlsContextRunner::class)->runAsService(fn () => app(AssessmentEntitlementGate::class)
                ->assertReady(new AssessmentPrincipal($f['participant'], $f['organization'], $f['attempt']), 'ist'));
            $this->fail('Incomplete profile must not authorize access.');
        } catch (EntitlementLocked) {
            $this->assertDatabaseHas('assessment_entitlements', ['id' => $f['entitlement'], 'status' => 'ready', 'started_at' => null]);
        }
        $before = DB::table('participants')->find($f['participant']);
        $schema = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY name');
        try {
            $this->migration()->down();
            $this->fail('Rollback must not invent a missing value.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Checkout partial profiles prevent rollback; complete or resolve nullable data explicitly.', $exception->getMessage());
        }
        $this->assertEquals($before, DB::table('participants')->find($f['participant']));
        $this->assertEquals($schema, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY name'));
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public static function profileFields(): iterable
    {
        foreach (self::FIELDS as $field) {
            yield $field => [$field];
        }
    }

    public function test_unselected_funding_can_be_provisioned_or_cancelled_but_blocks_down_without_mutation(): void
    {
        $f = Fixture::create();
        foreach (['PROVISIONED', 'REVOKED', 'VOID'] as $status) {
            DB::table('assessment_participants')->where('id', $f['attempt'])->update(['funding_mode' => null, 'assessment_status' => $status]);
            $this->assertDatabaseHas('assessment_participants', ['id' => $f['attempt'], 'funding_mode' => null, 'assessment_status' => $status]);
        }
        $before = DB::table('assessment_participants')->find($f['attempt']);
        $schema = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY name');
        try {
            $this->migration()->down();
            $this->fail('Rollback must not select a payer.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('prevent rollback', $exception->getMessage());
        }
        $this->assertEquals($before, DB::table('assessment_participants')->find($f['attempt']));
        $this->assertEquals($schema, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY name'));
        $this->assertNullable(true);
    }

    #[DataProvider('invalidFunding')]
    public function test_null_funding_rejects_missing_marker_and_access_status(?string $metadata, string $status): void
    {
        $f = Fixture::create();
        $this->expectException(QueryException::class);
        DB::table('assessment_participants')->where('id', $f['attempt'])->update([
            'funding_mode' => null, 'metadata' => $metadata, 'assessment_status' => $status,
        ]);
    }

    public static function invalidFunding(): iterable
    {
        foreach ([null, '{}', 'null', '{"checkout_contract_version":null}', '{"checkout_contract_version":"v1"}',
            '{"checkout_contract_version":true}', '[]', '{"checkout_contract_version":["checkout-v2"]}'] as $i => $metadata) {
            yield 'invalid marker '.$i => [$metadata, 'PROVISIONED'];
        }
        foreach (['READY', 'IN_PROGRESS', 'COMPLETED', 'UNDER_REVIEW', 'FINALIZED'] as $status) {
            yield $status => ['{"checkout_contract_version":"checkout-v2"}', $status];
        }
    }

    public function test_null_funding_insert_and_later_marker_removal_are_rejected(): void
    {
        $f = Fixture::create();
        $row = (array) DB::table('assessment_participants')->find($f['attempt']);
        unset($row['id']);
        $row['assessment_attempt_id'] = (string) Str::ulid();
        $row['assessment_case_id'] = AssessmentBillingFixture::createExactIntegratedCase(
            (int) $row['participant_id'],
            (int) $row['organization_id'],
            (int) $row['package_id'],
            $row['assessment_attempt_id'],
        );
        $row['idempotency_key'] = 'invalid-new';
        $row['logical_assessment_key'] = hash('sha256', 'invalid-new');
        try {
            DB::table('assessment_participants')->insert([...$row, 'funding_mode' => null, 'metadata' => null]);
            $this->fail('Insert bypassed the funding constraint.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('assessment_participants_checkout_funding_check', $exception->getMessage());
        }
        DB::table('assessment_participants')->where('id', $f['attempt'])->update(['funding_mode' => null, 'assessment_status' => 'PROVISIONED']);
        $this->expectException(QueryException::class);
        DB::table('assessment_participants')->where('id', $f['attempt'])->update(['metadata' => '{}']);
    }

    public function test_legacy_and_public_registration_validation_still_require_profile_values(): void
    {
        $v1 = new ProvisionAssessmentParticipantRequest;
        $validator = Validator::make(['profile' => []], $v1->rules());
        foreach (['fullName', 'birthDate', 'gender', 'educationLevel', 'phone'] as $field) {
            $this->assertTrue($validator->errors()->has('profile.'.$field));
        }
        $this->assertTrue($validator->errors()->has('fundingMode'));
        $public = StoreParticipantRegistrationRequest::create('/register', 'POST');
        $public->setLaravelSession(app('session')->driver());
        $validator = Validator::make([], $public->rules());
        foreach (self::FIELDS as $field) {
            $this->assertTrue($validator->errors()->has($field));
        }
        foreach (['participants', 'assessment_participants', 'entitlements', 'outbox_messages'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function assertNullable(bool $nullable): void
    {
        foreach (Schema::getColumns('participants') as $column) {
            if (in_array($column['name'], self::FIELDS, true)) {
                $this->assertSame($nullable, $column['nullable']);
            }
        }
        $column = collect(Schema::getColumns('assessment_participants'))->firstWhere('name', 'funding_mode');
        $this->assertSame($nullable, $column['nullable']);
    }

    private function structure(): array
    {
        $result = [];
        foreach (['participants', 'assessment_participants'] as $table) {
            $result[$table] = [Schema::getColumns($table), Schema::getIndexes($table), Schema::getForeignKeys($table)];
        }

        return $result;
    }
}
