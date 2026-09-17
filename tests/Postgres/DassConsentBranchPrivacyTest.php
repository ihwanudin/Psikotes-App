<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

/** PostgreSQL runtime evidence for the DASS consent privacy boundary. */
final class DassConsentBranchPrivacyTest extends TestCase
{
    /** @var array{organization:int,participant:int,firstAttempt:int,secondAttempt:int,foreignOrganization:int,foreignParticipant:int,dassAssessment:int} */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->fixture = app(RlsContextRunner::class)->runAsService(function (): array {
            $first = Fixture::create();
            $second = Fixture::create(['organization' => $first['organization'], 'participant' => $first['participant']]);
            $foreign = Fixture::create();
            DB::table('participants')->where('id', $first['participant'])->update([
                'full_name' => 'PRIVATE DASS CONSENT PROFILE',
            ]);
            $consentIds = [];
            foreach (['psychotest', 'dass'] as $type) {
                $document = ConsentDocument::for($type);
                $consentIds[$type] = DB::table('consent_records')->insertGetId([
                    'participant_id' => $first['participant'], 'consent_type' => $type,
                    'status' => 'accepted', 'document_version' => $document->version,
                    'document_hash' => $document->hash, 'consented_at' => now(),
                ]);
            }
            $assessment = DB::table('dass.assessments')->insertGetId([
                'public_id' => (string) Str::ulid(), 'participant_id' => $first['participant'],
                'consent_record_id' => $consentIds['dass'], 'status' => 'completed',
                'started_at' => now()->subMinute(), 'completed_at' => now(),
                'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('dass.responses')->insert([
                'assessment_id' => $assessment, 'item_number' => 1, 'response_value' => 3,
                'response_time_ms' => 321, 'answered_at' => now(), 'expires_at' => now()->addYear(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('dass.results')->insert([
                'assessment_id' => $assessment,
                'depression_raw' => 1, 'anxiety_raw' => 2, 'stress_raw' => 3,
                'depression_score' => 2, 'anxiety_score' => 4, 'stress_score' => 6,
                'depression_category' => 'PRIVATE-DEP', 'anxiety_category' => 'PRIVATE-ANX',
                'stress_category' => 'PRIVATE-STRESS', 'overall_category' => 'PRIVATE-OVERALL',
                'follow_up' => 'PRIVATE-FOLLOWUP',
                'validity_flags' => json_encode(['marker' => 'PRIVATE-DASS-RESULT'], JSON_THROW_ON_ERROR),
                'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return [
                'organization' => (int) $first['organization'],
                'participant' => (int) $first['participant'],
                'firstAttempt' => (int) $first['attempt'],
                'secondAttempt' => (int) $second['attempt'],
                'foreignOrganization' => (int) $foreign['organization'],
                'foreignParticipant' => (int) $foreign['participant'],
                'dassAssessment' => (int) $assessment,
            ];
        });
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_dass_consent_is_private_while_generic_consent_keeps_existing_readers(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertNotSame($this->fixture['firstAttempt'], $this->fixture['secondAttempt']);
        $this->assertConsentCounts(0, 0);

        $runner = app(RlsContextRunner::class);
        foreach ([new RlsContext('service'), new RlsContext('psychologist'),
            new RlsContext('participant', $this->fixture['organization'], $this->fixture['participant'])] as $context) {
            $runner->run($context, function (): void {
                $this->assertConsentCounts(1, 1);
                $this->assertDassTableCounts(1);
            });
        }

        $runner->run(new RlsContext('participant', $this->fixture['foreignOrganization'], $this->fixture['foreignParticipant']), function (): void {
            $this->assertConsentCounts(0, 0);
            $this->assertDassTableCounts(0);
        });

        foreach (['super_admin', 'branch_admin', 'staff'] as $roleName) {
            $branch = $roleName === 'super_admin' ? null : $this->fixture['organization'];
            $runner->run(new RlsContext($roleName, $branch), function (): void {
                $this->assertConsentCounts(0, 1);
                $this->assertDassTableCounts(0);
            });
        }

        foreach (['branch_admin', 'staff'] as $roleName) {
            $runner->run(new RlsContext($roleName, $this->fixture['foreignOrganization']), function (): void {
                $this->assertConsentCounts(0, 0);
                $this->assertDassTableCounts(0);
            });
        }
    }

    public function test_policy_migration_cycles_up_down_up_without_changing_write_policy(): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        $writeBefore = $this->policy('public', 'consent_records_write');
        $schema = 'p17a_policy_'.strtolower(Str::random(12));

        config()->set('database.connections.consent_policy_ddl_test', [...$config, 'username' => 'org_test_owner']);
        $owner = DB::connection('consent_policy_ddl_test');
        try {
            $this->assertSame('org_test_owner', $owner->selectOne('SELECT current_user AS name')->name);
            $owner->beginTransaction();
            $owner->statement('CREATE SCHEMA "'.$schema.'"');
            $owner->statement('CREATE TABLE "'.$schema.'".participants (id bigint PRIMARY KEY, branch_id bigint NOT NULL)');
            $owner->statement("CREATE TABLE \"{$schema}\".consent_records (participant_id bigint NOT NULL, consent_type text NOT NULL)");
            $owner->statement('SET LOCAL search_path TO "'.$schema.'", public');

            DB::setDefaultConnection('consent_policy_ddl_test');
            Schema::clearResolvedInstance('db.schema');
            $migration = require database_path('migrations/2026_09_05_000500_restrict_dass_consent_read_policy.php');

            $migration->up();
            $up = $this->policy($schema, 'consent_records_read');
            $guard = $this->policy($schema, 'consent_records_dass_privacy');
            $this->assertStringContainsString('consent_type', $up['qual']);
            $this->assertStringContainsString('dass', $up['qual']);
            $this->assertSame('SELECT', $guard['cmd']);
            $this->assertSame('RESTRICTIVE', $guard['permissive']);
            $this->assertStringContainsString('consent_type', $guard['qual']);

            $migration->down();
            $down = $this->policy($schema, 'consent_records_read');
            $this->assertStringNotContainsString('consent_type', $down['qual']);
            $this->assertStringContainsString('super_admin', $down['qual']);
            $this->assertNull($this->findPolicy($schema, 'consent_records_dass_privacy'));

            $migration->up();
            $this->assertSame($up['qual'], $this->policy($schema, 'consent_records_read')['qual']);
            $this->assertSame($guard['qual'], $this->policy($schema, 'consent_records_dass_privacy')['qual']);
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('consent_policy_ddl_test');
            config()->set('database.connections.consent_policy_ddl_test', null);
        }

        $this->assertEquals($writeBefore, $this->policy('public', 'consent_records_write'));
        $schemaSql = file_get_contents(database_path('schema/rls_policies.sql'));
        $this->assertIsString($schemaSql);
        $this->assertStringContainsString("consent_type <> 'dass'", $schemaSql);
    }

    private function assertConsentCounts(int $dass, int $psychotest): void
    {
        $query = DB::table('consent_records')->where('participant_id', $this->fixture['participant']);
        $this->assertSame($dass, (clone $query)->where('consent_type', 'dass')->count());
        $this->assertSame($psychotest, (clone $query)->where('consent_type', 'psychotest')->count());
    }

    private function assertDassTableCounts(int $expected): void
    {
        foreach (['dass.assessments', 'dass.responses', 'dass.results'] as $table) {
            $column = $table === 'dass.assessments' ? 'id' : 'assessment_id';
            $this->assertSame($expected, DB::table($table)->where($column, $this->fixture['dassAssessment'])->count(),
                $table.' visibility differs from DASS consent.');
        }
    }

    /** @return array{cmd:string,permissive:string,qual:string,with_check:?string} */
    private function policy(string $schema, string $name): array
    {
        $policy = $this->findPolicy($schema, $name);
        $this->assertNotNull($policy);

        return $policy;
    }

    /** @return array{cmd:string,permissive:string,qual:string,with_check:?string}|null */
    private function findPolicy(string $schema, string $name): ?array
    {
        $policy = DB::table('pg_policies')->where('schemaname', $schema)->where('tablename', 'consent_records')
            ->where('policyname', $name)->first(['cmd', 'permissive', 'qual', 'with_check']);
        if ($policy === null) {
            return null;
        }

        return [
            'cmd' => (string) $policy->cmd,
            'permissive' => (string) $policy->permissive,
            'qual' => (string) $policy->qual,
            'with_check' => $policy->with_check === null ? null : (string) $policy->with_check,
        ];
    }
}
