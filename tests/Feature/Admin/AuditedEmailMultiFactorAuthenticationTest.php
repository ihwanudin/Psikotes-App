<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Security\AuditedEmailMultiFactorAuthentication;
use Filament\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Lead's explicit ask on the MFA plan (2026-09-22): one audit_logs row when
 * a code is issued, one when one is successfully consumed -- never the code
 * itself. Delivery stays on Filament's own mail path (Lead's explicit
 * decision, not the app's Notifier/outbox pattern), so this only tests the
 * audit trail, not mail transport.
 */
final class AuditedEmailMultiFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_a_code_writes_exactly_one_audit_row_without_the_code(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $provider = app(AuditedEmailMultiFactorAuthentication::class);

        $sent = $provider->sendCode($admin);

        $this->assertTrue($sent);
        $rows = DB::table('audit_logs')->where('action', 'admin.mfa_code_issued')->get();
        $this->assertCount(1, $rows);
        $row = (array) $rows->sole();
        $this->assertSame('admin', $row['actor_type']);
        $this->assertSame((string) $admin->id, $row['actor_id']);
        $this->assertSame(Admin::class, $row['subject_type']);
        $this->assertSame((string) $admin->id, $row['subject_id']);
        $this->assertNull($row['context']);

        Notification::assertSentTo($admin, VerifyEmailAuthentication::class, function (VerifyEmailAuthentication $notification) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $notification->code);

            return true;
        });
    }

    public function test_verifying_the_correct_code_writes_exactly_one_consumed_row(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $provider = app(AuditedEmailMultiFactorAuthentication::class);
        $provider->sendCode($admin);

        $code = null;
        Notification::assertSentTo($admin, VerifyEmailAuthentication::class, function (VerifyEmailAuthentication $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });
        $this->assertIsString($code);

        $verified = $provider->verifyCode($code, $admin);

        $this->assertTrue($verified);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'admin.mfa_code_consumed')->count());
    }

    public function test_verifying_a_wrong_code_writes_no_consumed_row(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $provider = app(AuditedEmailMultiFactorAuthentication::class);
        $provider->sendCode($admin);

        $verified = $provider->verifyCode('000000', $admin);

        $this->assertFalse($verified);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'admin.mfa_code_consumed')->count());
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'branch_id' => null,
            'name' => 'Super Admin',
            'email' => 'mfa-'.uniqid('', true).'@example.test',
            'password' => 'not-a-real-password',
            'role' => AdminRole::SuperAdmin,
        ]);
    }
}
