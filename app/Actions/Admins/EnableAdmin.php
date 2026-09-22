<?php

declare(strict_types=1);

namespace App\Actions\Admins;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class EnableAdmin
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
                if ($admin->disabled_at === null) {
                    throw new LogicException("Admin {$adminId} is not disabled.");
                }

                $admin->forceFill(['disabled_at' => null])->save();

                $occurredAt = now()->utc()->toImmutable();
                DB::table('audit_logs')->insert([
                    'branch_id' => $admin->branch_id,
                    'actor_type' => 'operator',
                    'actor_id' => $operator,
                    'action' => 'admin.enabled',
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
