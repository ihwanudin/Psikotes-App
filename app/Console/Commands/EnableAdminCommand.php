<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admins\EnableAdmin;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

final class EnableAdminCommand extends Command
{
    protected $signature = 'admins:enable {id} {--operator=}';

    protected $description = 'Re-enable a disabled admin account';

    public function handle(EnableAdmin $enable): int
    {
        $operator = $this->stringOption('operator') ?? $this->ask('Nama/identitas operator yang menjalankan perintah ini');
        if (blank($operator)) {
            $this->components->error('Operator wajib diisi.');

            return self::FAILURE;
        }

        try {
            $admin = $enable->handle((int) $this->argument('id'), (string) $operator);
        } catch (LogicException|ModelNotFoundException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Admin #{$admin->id} ({$admin->email}) diaktifkan kembali.");

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
