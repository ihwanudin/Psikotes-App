<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\Admin;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Filament's own EmailAuthentication, with one addition: an audit_logs row
 * when a code is issued and when one is successfully consumed (2026-09-22,
 * Lead's explicit ask on the MFA plan). Never the code itself -- audit
 * trail, not a secrets store.
 *
 * Deliberately still uses Filament's own mail delivery (Notifiable/
 * Notification), not this app's Notifier/outbox pattern (Lead's explicit
 * decision): an MFA code is a short-lived secret, and the outbox is built
 * for durable, retryable participant notifications -- routing a secret
 * through it would widen where the code can be stored/seen for no matching
 * benefit (a participant who misses one just asks Filament's own
 * already-rate-limited resend).
 */
final class AuditedEmailMultiFactorAuthentication extends EmailAuthentication
{
    public function sendCode(HasEmailAuthentication $user): bool
    {
        $sent = parent::sendCode($user);

        if ($sent && $user instanceof Admin) {
            $this->audit($user, 'admin.mfa_code_issued');
        }

        return $sent;
    }

    public function verifyCode(#[SensitiveParameter] string $code, ?HasEmailAuthentication $user = null): bool
    {
        // Mirrors the parent's own resolution (user ??= Filament::auth()->user())
        // so the audit fires for every call site, not just the login
        // challenge form which happens to pass $user explicitly.
        $subject = $user ?? Filament::auth()->user();
        $verified = parent::verifyCode($code, $user);

        if ($verified && $subject instanceof Admin) {
            $this->audit($subject, 'admin.mfa_code_consumed');
        }

        return $verified;
    }

    private function audit(Admin $admin, string $action): void
    {
        $occurredAt = now()->utc()->toImmutable();

        app(RlsContextRunner::class)->runAsService(function () use ($admin, $action, $occurredAt): void {
            DB::table('audit_logs')->insert([
                'branch_id' => $admin->branch_id,
                'actor_type' => 'admin',
                'actor_id' => (string) $admin->id,
                'action' => $action,
                'subject_type' => Admin::class,
                'subject_id' => (string) $admin->id,
                'context' => null,
                'occurred_at' => $occurredAt,
                'expires_at' => app(RetentionPolicy::class)->expiresAt(RetentionDataClass::Audit, $occurredAt),
            ]);
        });
    }
}
