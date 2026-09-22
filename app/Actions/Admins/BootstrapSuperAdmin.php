<?php

declare(strict_types=1);

namespace App\Actions\Admins;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). Creates the very
 * first super_admin in an otherwise-empty admins table -- the one command
 * in this suite that must run under a `service` RLS context by
 * construction, since admins_write's policy only ever permits
 * app_role() IN ('service', 'super_admin') and there is, by definition, no
 * super_admin principal yet for the first row.
 *
 * Refuses to run once ANY row with role=super_admin exists, active or
 * disabled -- "already bootstrapped" is a permanent fact about this
 * database, not a state that un-happens if that account is later disabled.
 * Checked with withTrashed() even though this design never soft-deletes an
 * admin going forward: a defensive check against the SoftDeletes trait
 * Admin already had before this feature existed.
 */
final readonly class BootstrapSuperAdmin
{
    private const int BOOTSTRAP_LOCK_KEY = 8823_0922;

    public function __construct(
        private RlsContextRunner $runner,
        private RetentionPolicy $retention,
    ) {}

    public function handle(string $name, string $email, string $hashedPassword, string $operator): Admin
    {
        return $this->runner->runAsService(
            fn (): Admin => DB::transaction(function () use ($name, $email, $hashedPassword, $operator): Admin {
                // Row-locking can't serialize concurrent bootstrap attempts
                // against an EMPTY table (there is no row yet to lock) --
                // an advisory lock, keyed on a fixed constant rather than
                // any row, is what actually closes that race. Bootstrap is
                // a rare, manual, single-operator operation, but the fix is
                // cheap enough not to skip.
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SELECT pg_advisory_xact_lock(?)', [self::BOOTSTRAP_LOCK_KEY]);
                }

                $alreadyBootstrapped = Admin::withTrashed()
                    ->where('role', AdminRole::SuperAdmin)
                    ->exists();
                if ($alreadyBootstrapped) {
                    throw new LogicException('A super_admin already exists; bootstrap can only run once.');
                }

                $admin = Admin::query()->create([
                    'branch_id' => null,
                    'name' => $name,
                    'email' => $email,
                    'password' => $hashedPassword,
                    'role' => AdminRole::SuperAdmin,
                    'can_verify_payments' => false,
                ]);

                $occurredAt = now()->utc()->toImmutable();
                DB::table('audit_logs')->insert([
                    'branch_id' => null,
                    'actor_type' => 'operator',
                    'actor_id' => $operator,
                    'action' => 'admin.bootstrapped',
                    'subject_type' => Admin::class,
                    'subject_id' => (string) $admin->id,
                    'context' => json_encode(['role' => AdminRole::SuperAdmin->value], JSON_THROW_ON_ERROR),
                    'occurred_at' => $occurredAt,
                    'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $occurredAt),
                ]);

                return $admin;
            }),
        );
    }
}
