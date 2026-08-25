<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::connection()->getPdo()->exec(
            File::get(database_path('schema/rls_policies.sql')),
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::connection()->getPdo()->exec(
            File::get(database_path('schema/rls_rollback.sql')),
        );
    }
};
