<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off): the only existing
 * state-toggle on `admins` was SoftDeletes' `deleted_at`, which is
 * semantically a deletion marker -- report_signing_snapshots.signed_by_admin_id
 * is restrictOnDelete(), and this project's design deliberately never
 * deletes an admin row at all (disable/re-enable only). `disabled_at` is a
 * distinct, additive flag: null means active, a timestamp means disabled
 * and unable to authenticate (see App\Http\Middleware\RejectDisabledAdmin
 * and Admin::canAccessPanel()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->timestampTz('disabled_at')->nullable()->after('str_number');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->dropColumn('disabled_at');
        });
    }
};
