<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Psychologist licence identifiers, printed on HPP/internal reports
 * (Template HPP v2.3 Bagian I.C). No expiry column and no expiry check:
 * a deliberate owner decision 2026-09-20 (tasks/handoffs/f6/
 * report-documents-schema-proposal.md §6) — do not add one without
 * asking the owner again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->string('silp_number', 64)->nullable()->after('can_verify_payments');
            $table->string('str_number', 64)->nullable()->after('silp_number');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->dropColumn(['silp_number', 'str_number']);
        });
    }
};
