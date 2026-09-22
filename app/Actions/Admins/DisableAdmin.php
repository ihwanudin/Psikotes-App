<?php

declare(strict_types=1);

namespace App\Actions\Admins;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). Sets disabled_at,
 * never deleted_at -- report_signing_snapshots.signed_by_admin_id is
 * restrictOnDelete() and this project's design never deletes an admin row
 * regardless. Login is rejected on the next request by
 * App\Http\Middleware\RejectDisabledAdmin and Admin::canAccessPanel(), not
 * by hunting down and deleting session rows (see that middleware's own
 * doc comment for why: the sessions table's user_id column reflects only
 * the default `web` guard's user, not `admin`, so it can't reliably
 * identify which rows belong to which admin without risking an unrelated
 * User's session).
 */
final readonly class DisableAdmin
{
    public function __construct(
        private RlsContextRunner $runner,
        private RetentionPolicy $retention,
    ) {}

    public function handle(int $adminId, string $operator): Admin
    {
        return $this->runner->runAsService(
            fn (): Admin => DB::transaction(function () use ($adminId, $operator): Admin {
                $admin = Admin::query()->lockForUpdate()->findOrFail($adminId);
                if ($admin->disabled_at !== null) {
                    throw new LogicException("Admin {$adminId} is already disabled.");
                }

                $admin->forceFill(['disabled_at' => now()])->save();

                $occurredAt = now()->utc()->toImmutable();
                DB::table('audit_logs')->insert([
                    'branch_id' => $admin->branch_id,
                    'actor_type' => 'operator',
                    'actor_id' => $operator,
                    'action' => 'admin.disabled',
                    'subject_type' => Admin::class,
                    'subject_id' => (string) $admin->id,
                    'context' => null,
                    'occurred_at' => $occurredAt,
                    'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $occurredAt),
                ]);

                return $admin;
            }),
        );
    }
}
