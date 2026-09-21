<?php

declare(strict_types=1);

namespace App\Actions\Admins;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\Admin;
use App\Security\AdminPasswordPolicy;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). The consuming half
 * of IssueAdminPasswordSetupLink -- runs with no authenticated admin
 * session (the whole point of the link), so it establishes its own
 * `service` RLS context, same as ConsumeAssessmentInvitation.
 *
 * Re-validates against AdminPasswordPolicy here, not just at the HTTP
 * layer: this is the one place every set-password-via-link path actually
 * converges, so enforcing it here (not only in a FormRequest a caller
 * might skip) is what makes AdminPasswordPolicy a real single source of
 * truth rather than a convention callers have to remember to apply.
 */
final readonly class ConsumeAdminPasswordSetupLink
{
    public function __construct(private RlsContextRunner $runner, private RetentionPolicy $retention) {}

    public function handle(string $publicId, string $token, string $newPassword): void
    {
        $validator = Validator::make(
            ['password' => $newPassword],
            ['password' => ['required', 'string', AdminPasswordPolicy::rule()]],
        );
        if ($validator->fails()) {
            throw new AdminPasswordSetupRejected('Kata sandi tidak memenuhi syarat keamanan.', 422);
        }

        $this->runner->run(new RlsContext('service'), function () use ($publicId, $token, $newPassword): void {
            DB::transaction(function () use ($publicId, $token, $newPassword): void {
                $setup = DB::table('admin_password_setup_tokens')
                    ->where('public_id', $publicId)
                    ->where('token_hash', $this->hashToken($token))
                    ->lockForUpdate()
                    ->first();

                // Not a strict `!== true`: raw DB::table() results carry the
                // driver's native boolean representation (SQLite's PDO
                // driver returns 0/1 integers here, not PHP bool -- only
                // Eloquent's attribute casting would convert that, and this
                // is a plain query builder read), so a strict comparison
                // against PHP's `true` silently fails on SQLite even for a
                // genuinely active row.
                if ($setup === null || $setup->status !== 'PENDING' || ! $setup->active_marker) {
                    throw new AdminPasswordSetupRejected('Tautan sudah digunakan atau tidak lagi berlaku.', 409);
                }

                $expiresAt = now()->parse($setup->expires_at);
                if (! $expiresAt->isFuture()) {
                    DB::table('admin_password_setup_tokens')->where('id', $setup->id)
                        ->update(['status' => 'EXPIRED', 'active_marker' => null, 'updated_at' => now()]);

                    throw new AdminPasswordSetupRejected('Tautan telah kedaluwarsa.', 410);
                }

                $admin = Admin::query()->lockForUpdate()->find((int) $setup->admin_id);
                if ($admin === null || $admin->disabled_at !== null) {
                    DB::table('admin_password_setup_tokens')->where('id', $setup->id)
                        ->update(['status' => 'REVOKED', 'active_marker' => null, 'updated_at' => now()]);

                    throw new AdminPasswordSetupRejected('Akun tidak lagi dapat menggunakan tautan ini.', 409);
                }

                $admin->forceFill(['password' => Hash::make($newPassword)])->save();

                DB::table('admin_password_setup_tokens')->where('id', $setup->id)->update([
                    'status' => 'CONSUMED',
                    'active_marker' => null,
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);

                $occurredAt = now()->utc()->toImmutable();
                DB::table('audit_logs')->insert([
                    'branch_id' => $admin->branch_id,
                    'actor_type' => 'admin',
                    'actor_id' => (string) $admin->id,
                    'action' => 'admin.password_set',
                    'subject_type' => Admin::class,
                    'subject_id' => (string) $admin->id,
                    'context' => null,
                    'occurred_at' => $occurredAt,
                    'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $occurredAt),
                ]);
            });
        });
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
