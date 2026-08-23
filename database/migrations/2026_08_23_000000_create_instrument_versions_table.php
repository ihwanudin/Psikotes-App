<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrument_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32);
            $table->string('version', 64);
            $table->string('source_file', 128);
            $table->char('checksum', 64);
            $table->jsonb('payload');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['code', 'version']);
            $table->index(['code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_versions');
    }
};
