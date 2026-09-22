<?php

declare(strict_types=1);

namespace App\Actions\Admins;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). General admin
 * creation, every role the AdminRole enum currently has (bootstrap's first
 * super_admin aside -- that's BootstrapSuperAdmin, a separate one-time
 * command). Role-parameterized rather than one action per role, so a
 * future role (central_admin, once that plan lands) needs no change here.
 *
 * The created row gets an unusable random password hash, never a real one
 * -- this command never collects or sets a password directly. The caller
 * (CreateAdminCommand) issues a one-time set-password link via
 * IssueAdminPasswordSetupLink immediately after, as a separate step: if
 * that second step fails, the admin exists but simply has no way to set a
 * password yet, recoverable later via admins:reset-password -- better than
 * a single action that can half-succeed with a real password nobody saw.
 */
final readonly class CreateAdmin
{
    public function __construct(
        private RlsContextRunner $runner,
        private RetentionPolicy $retention,
    ) {}

    public function handle(
        AdminRole $role,
        string $name,
        string $email,
        ?int $branchId,
        ?string $silpNumber,
        ?string $strNumber,
        bool $canVerifyPayments,
        string $operator,
    ): Admin {
        $this->assertBranchRequirement($role, $branchId);
        $this->assertPsychologistFields($role, $silpNumber, $strNumber);

        return $this->runner->runAsService(
            fn (): Admin => DB::transaction(function () use ($role, $name, $email, $branchId, $silpNumber, $strNumber, $canVerifyPayments, $operator): Admin {
                $admin = Admin::query()->create([
                    'branch_id' => $branchId,
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(40)),
                    'role' => $role,
                    'can_verify_payments' => $canVerifyPayments,
                    'silp_number' => $silpNumber,
                    'str_number' => $strNumber,
                ]);

                $occurredAt = now()->utc()->toImmutable();
                DB::table('audit_logs')->insert([
                    'branch_id' => $branchId,
                    'actor_type' => 'operator',
                    'actor_id' => $operator,
                    'action' => 'admin.created',
                    'subject_type' => Admin::class,
                    'subject_id' => (string) $admin->id,
                    'context' => json_encode(['role' => $role->value], JSON_THROW_ON_ERROR),
                    'occurred_at' => $occurredAt,
                    'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $occurredAt),
                ]);

                return $admin;
            }),
        );
    }

    private function assertBranchRequirement(AdminRole $role, ?int $branchId): void
    {
        $requiresBranch = in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true);

        if ($requiresBranch && $branchId === null) {
            throw new InvalidArgumentException("Role {$role->value} requires a branch_id.");
        }
        if (! $requiresBranch && $branchId !== null) {
            throw new InvalidArgumentException("Role {$role->value} must not have a branch_id.");
        }
    }

    private function assertPsychologistFields(AdminRole $role, ?string $silpNumber, ?string $strNumber): void
    {
        $isPsychologist = $role === AdminRole::Psychologist;

        if ($isPsychologist && (blank($silpNumber) || blank($strNumber))) {
            throw new InvalidArgumentException('Psychologist accounts require both silp_number and str_number.');
        }
        if (! $isPsychologist && (! blank($silpNumber) || ! blank($strNumber))) {
            throw new InvalidArgumentException("Role {$role->value} must not have silp_number/str_number.");
        }
    }
}
