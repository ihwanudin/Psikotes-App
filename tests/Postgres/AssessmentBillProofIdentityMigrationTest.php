<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentBillingFixture as Fixture;

/** PostgreSQL-authoritative proof identity constraints, RLS preservation, and DDL lifecycle. */
final class AssessmentBillProofIdentityMigrationTest extends TestCase
{
    private const array COLUMNS = [
        'proof_checksum_sha256', 'proof_mime_type', 'proof_size_bytes', 'proof_uploaded_at',
    ];

    private const array CONSTRAINTS = [
        'assessment_bill_proof_identity_pair_check',
        'assessment_bill_proof_checksum_check',
        'assessment_bill_proof_mime_check',
        'assessment_bill_proof_size_check',
        'assessment_bill_proof_key_check',
    ];

    public function test_runtime_types_named_checks_valid_identity_and_rls_matrix_are_authoritative(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);

        $columns = DB::table('information_schema.columns')->where('table_schema', 'public')
            ->where('table_name', 'assessment_bills')->whereIn('column_name', self::COLUMNS)
            ->get()->keyBy('column_name');
        $this->assertSame('character', $columns['proof_checksum_sha256']->data_type);
        $this->assertSame(64, $columns['proof_checksum_sha256']->character_maximum_length);
        $this->assertSame('character varying', $columns['proof_mime_type']->data_type);
        $this->assertSame(32, $columns['proof_mime_type']->character_maximum_length);
        $this->assertSame('bigint', $columns['proof_size_bytes']->data_type);
        $this->assertSame('timestamp with time zone', $columns['proof_uploaded_at']->data_type);
        foreach ($columns as $column) {
            $this->assertSame('YES', $column->is_nullable);
            $this->assertNull($column->column_default);
        }

        $constraints = DB::table('pg_constraint')->where('conrelid', DB::raw("'assessment_bills'::regclass"))
            ->whereIn('conname', self::CONSTRAINTS)->pluck('conname')->all();
        sort($constraints);
        $expected = self::CONSTRAINTS;
        sort($expected);
        $this->assertSame($expected, $constraints);

        $security = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) AS owner
            FROM pg_class WHERE oid = 'assessment_bills'::regclass");
        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
        $this->assertNotSame('psikotes_runtime', $security->owner);

        DB::beginTransaction();
        try {
            [$own, $foreign] = app(RlsContextRunner::class)->runAsService(function (): array {
                $own = Fixture::create();
                $foreign = Fixture::create();
                DB::table('assessment_bills')->where('id', $own['bill'])->update($this->validIdentity());

                return [$own, $foreign];
            });
            app(RlsContextRunner::class)->run(new RlsContext('branch_admin', $own['organization']), function () use ($own, $foreign): void {
                $this->assertSame(1, DB::table('assessment_bills')->where('id', $own['bill'])->count());
                $this->assertSame(0, DB::table('assessment_bills')->where('id', $foreign['bill'])->count());
                $this->assertSame(0, DB::table('assessment_bills')->where('id', $own['bill'])
                    ->update(['proof_size_bytes' => 2]));
            });
            app(RlsContextRunner::class)->run(new RlsContext('super_admin'), function () use ($own, $foreign): void {
                $this->assertSame(2, DB::table('assessment_bills')->whereIn('id', [$own['bill'], $foreign['bill']])->count());
            });
            app(RlsContextRunner::class)->runAsService(function () use ($own): void {
                $row = DB::table('assessment_bills')->find($own['bill']);
                $this->assertSame($this->key('jpg'), $row->proof_object_key);
                $this->assertSame(str_repeat('a', 64), $row->proof_checksum_sha256);
                $this->assertSame('image/jpeg', $row->proof_mime_type);
                $this->assertSame(1, $row->proof_size_bytes);
                $this->assertNotNull($row->proof_uploaded_at);
            });
        } finally {
            DB::rollBack();
        }
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('invalidIdentities')]
    public function test_runtime_direct_sql_rejects_invalid_identity(array $values, string $sqlState = '23514'): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode($sqlState);
        app(RlsContextRunner::class)->runAsService(function () use ($values): void {
            $fixture = Fixture::create();
            DB::table('assessment_bills')->where('id', $fixture['bill'])->update($values);
        });
    }

    /** @return iterable<string, array{array<string, mixed>, 1?: string}> */
    public static function invalidIdentities(): iterable
    {
        $valid = self::validIdentityStatic();
        foreach (['proof_object_key', 'proof_checksum_sha256', 'proof_mime_type', 'proof_size_bytes', 'proof_uploaded_at'] as $column) {
            yield 'missing '.$column => [[...$valid, $column => null]];
        }
        yield 'uppercase checksum' => [[...$valid, 'proof_checksum_sha256' => str_repeat('A', 64)]];
        yield 'short checksum' => [[...$valid, 'proof_checksum_sha256' => str_repeat('a', 63)]];
        yield 'long checksum exceeds fixed type' => [
            [...$valid, 'proof_checksum_sha256' => str_repeat('a', 65)],
            '22001',
        ];
        yield 'unknown mime' => [[...$valid, 'proof_mime_type' => 'image/gif']];
        yield 'uppercase mime' => [[...$valid, 'proof_mime_type' => 'IMAGE/JPEG']];
        yield 'zero size' => [[...$valid, 'proof_size_bytes' => 0]];
        yield 'negative size' => [[...$valid, 'proof_size_bytes' => -1]];
        yield 'oversize' => [[...$valid, 'proof_size_bytes' => 5_120_001]];
        yield 'wrong prefix' => [[...$valid, 'proof_object_key' => 'manual/ab/'.str_repeat('c', 62).'.jpg']];
        yield 'absolute path' => [[...$valid, 'proof_object_key' => '/assessment-bills/ab/'.str_repeat('c', 62).'.jpg']];
        yield 'traversal' => [[...$valid, 'proof_object_key' => 'assessment-bills/ab/../'.str_repeat('c', 59).'.jpg']];
        yield 'backslash' => [[...$valid, 'proof_object_key' => 'assessment-bills\\ab\\'.str_repeat('c', 62).'.jpg']];
        yield 'control byte' => [[...$valid, 'proof_object_key' => 'assessment-bills/ab/'.str_repeat('c', 61)."\n.jpg"]];
        yield 'uppercase random name' => [[...$valid, 'proof_object_key' => 'assessment-bills/ab/'.str_repeat('C', 62).'.jpg']];
        yield 'short random name' => [[...$valid, 'proof_object_key' => 'assessment-bills/ab/'.str_repeat('c', 61).'.jpg']];
        yield 'unsupported extension' => [[...$valid, 'proof_object_key' => 'assessment-bills/ab/'.str_repeat('c', 62).'.gif']];
    }

    public function test_owner_roundtrip_and_both_preflights_are_atomic(): void
    {
        $this->assertFileExists('/.dockerenv');
        $runId = getenv('ORG_TEST_RUN_ID');
        $this->assertIsString($runId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $runId);
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        $this->assertSame('org-test-db', $config['host']);
        $this->assertSame('psikotes_organization_test', $config['database']);
        config()->set('database.connections.proof_identity_ddl_test', [...$config, 'username' => 'org_test_owner']);
        $owner = DB::connection('proof_identity_ddl_test');
        try {
            $owner->beginTransaction();
            DB::setDefaultConnection('proof_identity_ddl_test');
            Schema::clearResolvedInstance('db.schema');
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $fixture = Fixture::create();
            $baseColumns = array_values(array_diff(Schema::getColumnListing('assessment_bills'), self::COLUMNS));
            $row = DB::table('assessment_bills')->where('id', $fixture['bill'])->first($baseColumns);
            $structure = $this->structure();

            $this->migration()->down();
            foreach (self::COLUMNS as $column) {
                $this->assertFalse(Schema::hasColumn('assessment_bills', $column));
            }
            $this->assertEquals($row, DB::table('assessment_bills')->where('id', $fixture['bill'])->first($baseColumns));
            $this->migration()->up();
            $this->assertEquals($structure, $this->structure());
            $this->assertEquals($row, DB::table('assessment_bills')->where('id', $fixture['bill'])->first($baseColumns));

            DB::table('assessment_bills')->where('id', $fixture['bill'])->update($this->validIdentity());
            $before = DB::table('assessment_bills')->find($fixture['bill']);
            try {
                $this->migration()->down();
                $this->fail('Rollback discarded proof identity metadata.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Assessment bill proof identity prevents migration rollback.', $exception->getMessage());
            }
            $this->assertEquals($structure, $this->structure());
            $this->assertEquals($before, DB::table('assessment_bills')->find($fixture['bill']));

            DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
                'proof_object_key' => null,
                'proof_checksum_sha256' => null,
                'proof_mime_type' => null,
                'proof_size_bytes' => null,
                'proof_uploaded_at' => null,
            ]);
            $this->migration()->down();
            DB::table('assessment_bills')->where('id', $fixture['bill'])
                ->update(['proof_object_key' => 'legacy/private-proof.jpg']);
            $beforeStructure = $this->structure();
            try {
                $this->migration()->up();
                $this->fail('Existing object key was guessed or backfilled.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Assessment bill proof identity cannot be migrated safely.', $exception->getMessage());
            }
            $this->assertEquals($beforeStructure, $this->structure());
            $this->assertSame('legacy/private-proof.jpg', DB::table('assessment_bills')
                ->where('id', $fixture['bill'])->value('proof_object_key'));
            foreach (self::COLUMNS as $column) {
                $this->assertFalse(Schema::hasColumn('assessment_bills', $column));
            }
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            DB::setDefaultConnection($runtime);
            DB::purge('proof_identity_ddl_test');
            config()->set('database.connections.proof_identity_ddl_test', null);
            Schema::clearResolvedInstance('db.schema');
        }
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
    }

    /** @return array<string, mixed> */
    private function validIdentity(): array
    {
        return self::validIdentityStatic();
    }

    /** @return array<string, mixed> */
    private static function validIdentityStatic(): array
    {
        return [
            'proof_object_key' => 'assessment-bills/ab/'.str_repeat('c', 62).'.jpg',
            'proof_checksum_sha256' => str_repeat('a', 64),
            'proof_mime_type' => 'image/jpeg',
            'proof_size_bytes' => 1,
            'proof_uploaded_at' => '2026-09-01T12:00:00+00:00',
        ];
    }

    private function key(string $extension): string
    {
        return 'assessment-bills/ab/'.str_repeat('c', 62).'.'.$extension;
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_01_000200_add_manual_proof_identity_to_assessment_bills.php');
    }

    /** @return array<string, mixed> */
    private function structure(): array
    {
        return [
            'columns' => DB::select("SELECT attname, format_type(atttypid, atttypmod) AS type, attnotnull,
                pg_get_expr(adbin, adrelid) AS default_value FROM pg_attribute
                LEFT JOIN pg_attrdef ON adrelid = attrelid AND adnum = attnum
                WHERE attrelid = 'assessment_bills'::regclass AND attnum > 0 AND NOT attisdropped ORDER BY attnum"),
            'constraints' => DB::select("SELECT conname, pg_get_constraintdef(oid) AS definition
                FROM pg_constraint WHERE conrelid = 'assessment_bills'::regclass ORDER BY conname"),
            'indexes' => DB::select("SELECT indexname, indexdef FROM pg_indexes
                WHERE schemaname = 'public' AND tablename = 'assessment_bills' ORDER BY indexname"),
            'policies' => DB::select("SELECT * FROM pg_policies
                WHERE schemaname = 'public' AND tablename = 'assessment_bills' ORDER BY policyname"),
            'security' => DB::select("SELECT relrowsecurity, relforcerowsecurity, relowner
                FROM pg_class WHERE oid = 'assessment_bills'::regclass"),
        ];
    }
}
