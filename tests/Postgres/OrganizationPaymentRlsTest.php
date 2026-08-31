<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OrganizationPaymentRlsTest extends TestCase
{
    /** @var list<int> */
    private static array $branches = [];

    /** @var list<int> */
    private static array $participants = [];

    public static function setUpBeforeClass(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $method = DB::table('payment_methods')->insertGetId([
                'code' => 'synthetic_manual', 'display_name' => 'Synthetic manual', 'is_active' => false,
            ]);
            foreach (['A', 'B'] as $code) {
                $branch = DB::table('branches')->insertGetId([
                    'code' => 'TEST-'.$code, 'ref_code' => 'TEST-'.$code, 'name' => 'Synthetic '.$code,
                    'organization_code' => 'TEST-'.$code, 'display_name' => 'Synthetic '.$code,
                ]);
                $participant = DB::table('participants')->insertGetId([
                    'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
                    'full_name' => 'Synthetic '.$code, 'gender' => 'male', 'birth_date' => '2000-01-01',
                    'education_level' => 'SMA_SMK', 'intended_field' => 'KAIGO', 'phone' => '620000000000',
                ]);
                DB::table('orders')->insert([
                    'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
                    'payment_method_id' => $method, 'amount' => 1, 'currency' => 'IDR', 'status' => 'pending',
                ]);
                DB::table('dass.assessments')->insert([
                    'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
                    'expires_at' => now()->addDay(),
                ]);
                self::$branches[] = $branch;
                self::$participants[] = $participant;
            }
        });
    }

    public function test_runtime_is_not_owner_superuser_or_bypassrls(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertSame('psikotes_organization_test', DB::selectOne('SELECT current_database() AS name')->name);
    }

    public function test_tenant_tables_have_forced_row_level_security(): void
    {
        foreach (['public.branches', 'public.participants', 'public.orders', 'public.assessment_participants', 'dass.assessments'] as $table) {
            $row = DB::selectOne(
                'SELECT relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) AS owner FROM pg_class WHERE oid = to_regclass(?)',
                [$table],
            );
            $this->assertNotNull($row, $table);
            $this->assertTrue($row->relrowsecurity, $table);
            $this->assertTrue($row->relforcerowsecurity, $table);
            $this->assertNotSame('psikotes_runtime', $row->owner, $table);
        }
    }

    public function test_absent_context_cannot_read_seeded_tenant_rows(): void
    {
        foreach (['branches', 'participants', 'orders', 'dass.assessments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
            $this->assertSame(2, app(RlsContextRunner::class)->runAsService(fn () => DB::table($table)->count()));
        }
    }

    public function test_branch_cannot_read_or_change_other_branch_participants(): void
    {
        $runner = app(RlsContextRunner::class);
        foreach (self::$branches as $index => $branch) {
            $runner->run(new RlsContext('branch_admin', $branch), function () use ($index): void {
                $this->assertSame([self::$participants[$index]], DB::table('participants')->pluck('id')->all());
                $this->assertSame([self::$participants[$index]], DB::table('orders')->pluck('participant_id')->all());
                $this->assertSame(0, DB::table('participants')->where('id', self::$participants[1 - $index])
                    ->update(['full_name' => 'Cross-tenant tampering']));
            });
            $this->assertSame(0, DB::table('participants')->count());
        }
    }

    public function test_participant_only_sees_their_own_order_and_dass_assessment(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('participant', self::$branches[0], self::$participants[0]), function (): void {
            foreach (['orders', 'dass.assessments'] as $table) {
                $this->assertSame([self::$participants[0]], DB::table($table)->pluck('participant_id')->all());
            }
        });
    }

    public function test_dass_is_hidden_from_branch_and_central_administrators(): void
    {
        $runner = app(RlsContextRunner::class);
        foreach (['branch_admin', 'staff', 'super_admin'] as $role) {
            $this->assertSame(0, $runner->run(new RlsContext($role, self::$branches[0]), fn () => DB::table('dass.assessments')->count()));
        }
        $this->assertSame(2, $runner->run(new RlsContext('psychologist'), fn () => DB::table('dass.assessments')->count()));
    }

    public function test_database_context_is_cleared_after_exception(): void
    {
        $runner = app(RlsContextRunner::class);
        try {
            $runner->run(new RlsContext('branch_admin', self::$branches[0]), static function (): never {
                throw new RuntimeException('Synthetic failure');
            });
            $this->fail('Expected synthetic failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic failure', $exception->getMessage());
        }
        $this->assertNull($runner->current());
        $this->assertNull(DB::selectOne('SELECT app_private.app_role() AS role')->role);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_unstubbed_http_is_blocked(): void
    {
        $this->expectException(StrayRequestException::class);
        Http::get('https://payments.example.invalid/forbidden');
    }
}
