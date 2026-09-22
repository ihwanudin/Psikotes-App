<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admins\CreateAdmin;
use App\Actions\Admins\IssueAdminPasswordSetupLink;
use App\Enums\AdminRole;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). General creation for
 * every existing role (super_admin, branch_admin, staff, psychologist --
 * reads AdminRole::cases(), so a future central_admin needs no change
 * here). Never collects a password directly: issues a one-time
 * set-password link (Mode 2) immediately after creation.
 */
final class CreateAdminCommand extends Command
{
    protected $signature = 'admins:create
        {--operator=}
        {--role=}
        {--name=}
        {--email=}
        {--branch_id=}
        {--silp_number=}
        {--str_number=}
        {--can_verify_payments : Grant VerifyPayments-eligible flag (branch_admin/staff only)}';

    protected $description = 'Create an admin account of any existing role and issue a one-time set-password link';

    public function handle(CreateAdmin $create, IssueAdminPasswordSetupLink $issueLink): int
    {
        $operator = $this->stringOption('operator') ?? $this->ask('Nama/identitas operator yang menjalankan perintah ini');
        if (blank($operator)) {
            $this->components->error('Operator wajib diisi.');

            return self::FAILURE;
        }

        $roleValue = $this->stringOption('role') ?? $this->choice(
            'Peran',
            array_map(fn (AdminRole $case): string => $case->value, AdminRole::cases()),
        );
        $roleValue = is_array($roleValue) ? ($roleValue[0] ?? null) : $roleValue;
        $role = is_string($roleValue) ? AdminRole::tryFrom($roleValue) : null;
        if ($role === null) {
            $this->components->error('Peran tidak dikenal: '.(is_string($roleValue) ? $roleValue : ''));

            return self::FAILURE;
        }

        $name = $this->stringOption('name') ?? $this->ask('Nama lengkap');
        $email = $this->stringOption('email') ?? $this->ask('Email');
        if (blank($name) || blank($email)) {
            $this->components->error('Nama dan email wajib diisi.');

            return self::FAILURE;
        }

        $branchId = $this->branchIdOption($role);
        [$silpNumber, $strNumber] = $this->psychologistFields($role);

        try {
            $admin = $create->handle(
                $role,
                (string) $name,
                (string) $email,
                $branchId,
                $silpNumber,
                $strNumber,
                (bool) $this->option('can_verify_payments'),
                (string) $operator,
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $link = $issueLink->handle($admin->id, (string) $operator, 'admin.password_setup_link_issued');

        $this->components->info("Admin #{$admin->id} ({$admin->email}, {$role->value}) berhasil dibuat.");
        $this->newLine();
        $this->components->warn('Tautan berikut RAHASIA -- berlaku sekali pakai, kedaluwarsa '.$link->expiresAt->toDayDateTimeString().'. Jangan simpan di log atau catatan bersama.');
        $this->line($link->url);

        return self::SUCCESS;
    }

    /**
     * A supplied --branch_id is passed through even for a role that
     * doesn't take one, rather than silently discarded -- CreateAdmin's
     * own validation is what must reject it (single source of truth for
     * this rule, not this command guessing what to keep).
     */
    private function branchIdOption(AdminRole $role): ?int
    {
        $requiresBranch = in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true);
        $raw = $this->stringOption('branch_id');

        if ($raw === null && $requiresBranch) {
            $raw = $this->ask('branch_id (wajib untuk branch_admin/staff)');
        }

        return $raw !== null && ctype_digit($raw) ? (int) $raw : null;
    }

    /**
     * Same reasoning as branchIdOption(): supplied silp_number/str_number
     * are passed through even for a non-psychologist role instead of
     * being discarded, so CreateAdmin's validation can reject them.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function psychologistFields(AdminRole $role): array
    {
        $silp = $this->stringOption('silp_number');
        $str = $this->stringOption('str_number');

        if ($role === AdminRole::Psychologist) {
            $silp ??= $this->ask('Nomor SILP (wajib untuk psikolog)');
            $str ??= $this->ask('Nomor STR (wajib untuk psikolog)');
        }

        return [$silp, $str];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
