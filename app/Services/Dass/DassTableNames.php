<?php

declare(strict_types=1);

namespace App\Services\Dass;

use Illuminate\Support\Facades\DB;

/**
 * DASS's isolated schema is `dass.*` on PostgreSQL but `dass_*` on SQLite
 * (SQLite has no schema concept) -- the exact same driver split
 * `2026_08_25_000300_create_isolated_dass_schema.php` already uses. Every
 * caller that names one of these three tables outside a migration needs
 * this same split; a literal `'dass.assessments'` silently breaks on
 * SQLite ("no such table: dass.assessments" -- caught by this task's own
 * Feature tests, not guessed).
 */
final class DassTableNames
{
    public static function assessments(): string
    {
        return self::table('assessments');
    }

    public static function responses(): string
    {
        return self::table('responses');
    }

    public static function results(): string
    {
        return self::table('results');
    }

    private static function table(string $name): string
    {
        return DB::getDriverName() === 'pgsql' ? "dass.{$name}" : "dass_{$name}";
    }
}
