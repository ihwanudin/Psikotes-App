<?php

declare(strict_types=1);

namespace App\Actions\Admins;

use App\Data\Admins\IssuedAdminPasswordSetupLink;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). Issues a one-time
 * set-password link -- used both for a newly-created admin (who has no
 * usable password yet) and for a password reset (reissuing revokes the
 * prior active link, same as assessment invitations' reissue behavior).
 *
 * Same shape as IssueAssessmentInvitation: opaque random token, HMAC-hashed
 * at rest (never stored raw), delivered via the URL fragment so it never
 * reaches server logs. Short-lived by design (config('admin_accounts.
 * password_setup_token_ttl_minutes'), default 60 minutes) -- this link sets
 * an admin's password, a materially more sensitive action than a
 * participant's assessment invitation.
 *
 * $issuedByOperator is a free-text identifier, not an admins.id -- there is
 * no authenticated admin acting when this runs from a console command.
 */
final readonly class IssueAdminPasswordSetupLink
{
    public function __construct(
        private RlsContextRunner $runner,
        private RetentionPolicy $retention,
    ) {}

    public function handle(int $adminId, string $issuedByOperator, string $auditAction): IssuedAdminPasswordSetupLink
    {
        $token = Str::random(64);
        $auditAt = now()->utc()->toImmutable();
        $ttlMinutes = max(15, min(1440, (int) config('admin_accounts.password_setup_token_ttl_minutes', 60)));
        $expiresAt = $auditAt->addMinutes($ttlMinutes);

        $publicId = $this->runner->runAsService(
            fn (): string => DB::transaction(function () use ($adminId, $issuedByOperator, $auditAction, $token, $auditAt, $expiresAt): string {
                $admin = Admin::query()->lockForUpdate()->findOrFail($adminId);

                DB::table('admin_password_setup_tokens')
                    ->where('admin_id', $admin->id)
                    ->where('active_marker', true)
                    ->update(['active_marker' => null, 'status' => 'REVOKED', 'updated_at' => now()]);

                $publicId = (string) Str::ulid();
                DB::table('admin_password_setup_tokens')->insert([
                    'public_id' => $publicId,
                    'admin_id' => $admin->id,
                    'issued_by_operator' => $issuedByOperator,
                    'token_hash' => $this->hashToken($token),
                    'active_marker' => true,
                    'status' => 'PENDING',
                    'expires_at' => $expiresAt,
                    'created_at' => $auditAt,
                    'updated_at' => $auditAt,
                ]);

                DB::table('audit_logs')->insert([
                    'branch_id' => $admin->branch_id,
                    'actor_type' => 'operator',
                    'actor_id' => $issuedByOperator,
                    'action' => $auditAction,
                    'subject_type' => Admin::class,
                    'subject_id' => (string) $admin->id,
                    'context' => json_encode([
                        'expires_at' => $expiresAt->toISOString(),
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => $auditAt,
                    'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $auditAt),
                ]);

                return $publicId;
            }),
        );

        return new IssuedAdminPasswordSetupLink(
            route('admin.password-setup.show', ['publicId' => $publicId]).'#token='.rawurlencode($token),
            $expiresAt,
        );
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
