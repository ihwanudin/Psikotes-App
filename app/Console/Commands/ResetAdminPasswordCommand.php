<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admins\IssueAdminPasswordSetupLink;
use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). "Reset" is just
 * reissuing a set-password link -- IssueAdminPasswordSetupLink already
 * revokes the prior active one before creating the new one, so there is
 * no separate reset action, only a distinct audit action string.
 */
final class ResetAdminPasswordCommand extends Command
{
    protected $signature = 'admins:reset-password {id} {--operator=}';

    protected $description = 'Issue a new one-time set-password link for an existing admin, revoking any prior unused link';

    public function handle(IssueAdminPasswordSetupLink $issueLink): int
    {
        $operator = $this->stringOption('operator') ?? $this->ask('Nama/identitas operator yang menjalankan perintah ini');
        if (blank($operator)) {
            $this->components->error('Operator wajib diisi.');

            return self::FAILURE;
        }

        try {
            $admin = Admin::query()->findOrFail((int) $this->argument('id'));
        } catch (ModelNotFoundException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $link = $issueLink->handle($admin->id, (string) $operator, 'admin.password_reset_link_issued');

        $this->components->info("Tautan atur-ulang kata sandi untuk admin #{$admin->id} ({$admin->email}) diterbitkan.");
        $this->newLine();
        $this->components->warn('Tautan berikut RAHASIA -- berlaku sekali pakai, kedaluwarsa '.$link->expiresAt->toDayDateTimeString().'. Jangan simpan di log atau catatan bersama.');
        $this->line($link->url);

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
