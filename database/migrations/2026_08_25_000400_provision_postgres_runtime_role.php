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
            File::get(database_path('schema/postgres_roles.sql')),
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public, dass FROM psikotes_runtime;
            REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public, dass FROM psikotes_runtime;
            REVOKE USAGE ON SCHEMA public, dass FROM psikotes_runtime;
            SQL);
    }
};
