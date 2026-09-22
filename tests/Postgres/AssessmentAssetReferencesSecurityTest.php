<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\AssessmentAssets\SyncIstAssets;
use App\Actions\AssessmentSessions\GetAssessmentSessionAssetUrl;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Http\Controllers\GetAssessmentSessionAssetUrlController;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * F2 IST reader Stage 1 correction (2026-09-21, Lead review of PR #78).
 * The original migration created `assessment_asset_references` with no
 * RLS/GRANT at all -- an S4-class gap SQLite can never surface, since
 * SQLite has no roles/RLS to enforce. This is the PostgreSQL proof Lead
 * required before merge: the runtime role's exact privilege/policy shape
 * (mirrors AssessmentSessionDefinitionCatalogSecurityTest's own proof for
 * a single-migration RLS table), a participant context's direct read
 * returning nothing (RLS-masked) and direct write being refused
 * (SQLSTATE 42501), the real asset-URL controller succeeding end-to-end
 * under Postgres, a cross-instrument asset 404ing under Postgres, and
 * SyncIstAssets's own write path succeeding under the same runtime role
 * `assets:sync-ist` runs as at deploy time (psikotes_runtime -- it is a
 * plain `php artisan` command against the app's normal `pgsql` connection,
 * NOT the owner-only `pgsql_migration` connection `migrate` uses; see
 * DEPLOYMENT.md).
 */
final class AssessmentAssetReferencesSecurityTest extends TestCase
{
    private const TABLE = 'assessment_asset_references';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_runtime_has_forced_service_only_rls_and_least_privilege(): void
    {
        $identity = DB::selectOne(<<<'SQL'
            SELECT current_user AS name, role.rolsuper, role.rolbypassrls,
                pg_get_userbyid(class.relowner) AS table_owner,
                class.relrowsecurity, class.relforcerowsecurity
            FROM pg_roles role
            CROSS JOIN pg_class class
            WHERE role.rolname = current_user
                AND class.oid = 'assessment_asset_references'::regclass
            SQL);

        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $this->assertNotSame('psikotes_runtime', $identity->table_owner);
        $this->assertTrue($identity->relrowsecurity);
        $this->assertTrue($identity->relforcerowsecurity);

        foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
            $this->assertTrue((bool) DB::scalar(
                "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                [self::TABLE, $privilege],
            ), $privilege);
        }
        foreach (['DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
            $this->assertFalse((bool) DB::scalar(
                "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                [self::TABLE, $privilege],
            ), $privilege);
        }

        $policies = DB::table('pg_policies')
            ->where('schemaname', 'public')
            ->where('tablename', self::TABLE)
            ->orderBy('policyname')
            ->get();
        $this->assertSame([
            'assessment_asset_references_service_insert',
            'assessment_asset_references_service_select',
            'assessment_asset_references_service_update',
        ], $policies->pluck('policyname')->all());
        foreach ($policies as $policy) {
            $this->assertSame('{psikotes_runtime}', $policy->roles);
            $this->assertStringContainsString(
                "app_private.app_role() = 'service'",
                ($policy->qual ?? '').($policy->with_check ?? ''),
            );
        }
    }

    public function test_participant_context_cannot_read_or_write_the_table_directly(): void
    {
        $this->insertAssetRow('ist', 'fa/legend-1-a.png');

        app(RlsContextRunner::class)->run(new RlsContext('participant', 1, 1), function (): void {
            // RLS masks rather than errors on SELECT -- same shape as every
            // other service-only catalog table in this codebase.
            $this->assertSame(0, DB::table(self::TABLE)->count());
            $this->assertSqlState('42501', fn () => DB::table(self::TABLE)->insert([
                'asset_id' => (string) Str::ulid(), 'instrument' => 'ist', 'disk' => 'ist-assets',
                'object_key' => 'fa/legend-1-b.png', 'checksum_sha256' => hash('sha256', 'x'),
                'created_at' => now(), 'updated_at' => now(),
            ]));
        });
    }

    public function test_asset_url_is_issued_through_the_real_controller_for_an_in_progress_ist_session(): void
    {
        Storage::fake('ist-assets');
        $fixture = $this->sessionFixture('ist');
        $assetId = $this->insertAssetRow('ist', 'fa/legend-1-a.png');

        $response = (new GetAssessmentSessionAssetUrlController)(
            $this->principalRequest($fixture['participant'], $fixture['branch']),
            $fixture['public_id'],
            $assetId,
            $this->action('2026-09-08T03:30:00+00:00'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame(['url', 'expires_at'], array_keys($body));
        $this->assertIsString($body['url']);
        $this->assertNotSame('', $body['url']);
    }

    public function test_an_asset_registered_under_a_different_instrument_is_404_under_postgres(): void
    {
        Storage::fake('ist-assets');
        $fixture = $this->sessionFixture('ist');
        $assetId = $this->insertAssetRow('papi', 'papi/whatever.png');

        $response = (new GetAssessmentSessionAssetUrlController)(
            $this->principalRequest($fixture['participant'], $fixture['branch']),
            $fixture['public_id'],
            $assetId,
            $this->action('2026-09-08T03:30:00+00:00'),
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('ASSET_NOT_FOUND', $response->getData(true)['error']['code']);
    }

    public function test_sync_ist_assets_write_path_succeeds_under_the_same_runtime_role_used_at_deploy(): void
    {
        $identity = DB::selectOne('SELECT current_user AS name FROM pg_roles WHERE rolname = current_user');
        $this->assertSame(
            'psikotes_runtime',
            $identity->name,
            'assets:sync-ist runs as a plain artisan command against the app\'s normal '
            .'pgsql connection (psikotes_runtime), not the owner-only migrate connection -- '
            .'this asserts the disposable test harness\'s default connection really is that role.',
        );

        Storage::fake('ist-assets');
        $source = sys_get_temp_dir().'/ist-assets-pg-sync-test-'.uniqid('', true);
        File::ensureDirectoryExists($source);
        File::put($source.'/legend-1-a.png', 'synthetic-pg-bytes');

        try {
            $result = app(SyncIstAssets::class)->handle($source, 'ist-assets');

            $this->assertSame(1, $result['synced']);
            $this->assertSame([], $result['failed']);
            $row = app(RlsContextRunner::class)->runAsService(
                fn () => DB::table(self::TABLE)->where('object_key', 'legend-1-a.png')->first(),
            );
            $this->assertNotNull($row);
            $this->assertSame(hash('sha256', 'synthetic-pg-bytes'), $row->checksum_sha256);
        } finally {
            File::deleteDirectory($source);
        }
    }

    public function test_migration_up_down_up_cycle_round_trips_cleanly(): void
    {
        $this->asOwner(function (): void {
            $migration = require database_path('migrations/2026_09_21_000400_create_assessment_asset_references_table.php');

            DB::beginTransaction();
            try {
                $migration->down();
                $this->assertNull(DB::selectOne("SELECT to_regclass('assessment_asset_references') AS name")->name);
                $migration->up();
                $this->assertTrue(DB::selectOne(
                    "SELECT relforcerowsecurity FROM pg_class WHERE oid = 'assessment_asset_references'::regclass",
                )->relforcerowsecurity);
                $policies = DB::table('pg_policies')
                    ->where('schemaname', 'public')->where('tablename', self::TABLE)->count();
                $this->assertSame(3, $policies);
            } finally {
                DB::rollBack();
            }
        });
    }

    private function insertAssetRow(string $instrument, string $objectKey): string
    {
        $assetId = (string) Str::ulid();
        app(RlsContextRunner::class)->runAsService(fn () => DB::table(self::TABLE)->insert([
            'asset_id' => $assetId,
            'instrument' => $instrument,
            'disk' => 'ist-assets',
            'object_key' => $objectKey,
            'checksum_sha256' => hash('sha256', $objectKey),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $assetId;
    }

    private function action(string $iso): GetAssessmentSessionAssetUrl
    {
        return new GetAssessmentSessionAssetUrl(
            app(RlsContextRunner::class),
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        );
    }

    private function principalRequest(int $participant, int $branch): Request
    {
        $request = Request::create('/api/sessions/x/assets/y/url', 'GET');
        $request->attributes->set('participant_principal', new ParticipantPrincipal($participant, $branch));

        return $request;
    }

    /** @return array{branch:int,participant:int,public_id:string} */
    private function sessionFixture(string $instrument): array
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($instrument): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'Asset URL Synthetic',
                'organization_code' => $key, 'display_name' => 'Asset URL Synthetic',
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch,
                'referral_source' => 'default', 'full_name' => 'Asset URL Synthetic',
                'phone' => '620000000000',
            ]);

            $definitionSource = [
                'instrument' => $instrument, 'version' => 'synthetic-definition-v1',
                'provenance' => 'asset-url-pg-test-only', 'total_duration_seconds' => 3600,
                'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 1]],
                'randomization' => 'fixed', 'seed' => null, 'generator' => null,
            ];
            $definition = SessionDefinition::fromArray([
                ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
            ]);
            $publicId = (string) Str::ulid();
            DB::table('test_sessions')->insert([
                'public_id' => $publicId, 'participant_id' => $participant,
                'test_type' => $instrument, 'attempt_no' => 1,
                'authorization_id' => (string) Str::ulid(),
                'allocation_intent_id' => (string) Str::ulid(),
                'duration_seconds' => 3600, 'status' => 'in_progress', 'answers_revision' => 0,
                'started_at' => '2026-09-08 03:00:00.000000+00',
                'ends_at' => '2026-09-08 04:00:00.000000+00',
                'session_definition_version' => $definition->version,
                'session_definition_provenance' => $definition->provenance,
                'session_definition_checksum' => $definition->checksum,
                'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
            ]);

            return ['branch' => $branch, 'participant' => $participant, 'public_id' => $publicId];
        });
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0] ?? null, $exception->getMessage());
        }
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.asset_reference_owner', [
            ...$config,
            'username' => 'org_test_owner',
        ]);
        DB::setDefaultConnection('asset_reference_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('asset_reference_owner');
            config()->set('database.connections.asset_reference_owner', null);
        }
    }
}
