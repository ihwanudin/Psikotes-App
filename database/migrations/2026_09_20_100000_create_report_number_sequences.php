<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly counter behind ReportNumberIssuer, mirroring
 * test_number_sequences (2026_08_25_000700). One report number is
 * issued per case, shared by its hpp and internal documents (Template
 * HPP v2.3 Bagian I.A + report-documents-schema-proposal.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_number_sequences', function (Blueprint $table): void {
            $table->char('period', 6)->primary();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_number_sequences');
    }
};
