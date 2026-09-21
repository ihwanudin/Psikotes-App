<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admins\BootstrapSuperAdmin;
use App\Security\AdminPasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). Creates the very
 * first super_admin; refuses to run again once one exists (active or
 * disabled). Mode 1 password handling (interactive hidden prompt, never a
 * CLI argument): the operator running this already has server access by
 * necessity (there is no admin yet to email a set-password link to), so
 * this is the one command in the suite that collects the password
 * directly rather than issuing a link.
 */
final class BootstrapSuperAdminCommand extends Command
{
    protected $signature = 'admins:bootstrap-super-admin {--operator=} {--name=} {--email=}';

    protected $description = 'Create the first super_admin account (refuses to run once any super_admin exists)';

    public function handle(BootstrapSuperAdmin $bootstrap): int
    {
        $operator = $this->stringOption('operator') ?? $this->ask('Nama/identitas operator yang menjalankan perintah ini');
        $name = $this->stringOption('name') ?? $this->ask('Nama lengkap super_admin');
        $email = $this->stringOption('email') ?? $this->ask('Email super_admin');

        if (blank($operator) || blank($name) || blank($email)) {
            $this->components->error('Operator, nama, dan email wajib diisi.');

            return self::FAILURE;
        }

        $password = $this->secret('Kata sandi (minimal 12 karakter, huruf besar/kecil, angka, simbol)');
        $confirmation = $this->secret('Ulangi kata sandi');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'confirmed', AdminPasswordPolicy::rule()]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        try {
            $admin = $bootstrap->handle($name, $email, Hash::make((string) $password), $operator);
        } catch (LogicException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Super admin #{$admin->id} ({$admin->email}) berhasil dibuat.");

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
