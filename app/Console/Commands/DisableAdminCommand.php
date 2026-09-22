<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admins\DisableAdmin;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

final class DisableAdminCommand extends Command
{
    protected $signature = 'admins:disable {id} {--operator=}';

    protected $description = 'Disable an admin account (sets disabled_at; never deletes the row)';

    public function handle(DisableAdmin $disable): int
    {
        $operator = $this->stringOption('operator') ?? $this->ask('Nama/identitas operator yang menjalankan perintah ini');
        if (blank($operator)) {
            $this->components->error('Operator wajib diisi.');

            return self::FAILURE;
        }

        try {
            $admin = $disable->handle((int) $this->argument('id'), (string) $operator);
        } catch (LogicException|ModelNotFoundException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Admin #{$admin->id} ({$admin->email}) dinonaktifkan.");

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
