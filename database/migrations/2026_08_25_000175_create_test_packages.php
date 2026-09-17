<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount')->nullable();
            $table->char('currency', 3)->default('IDR');
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();

            $table->index(['is_active', 'name']);
        });

        Schema::create('package_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->string('test_type', 24);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['package_id', 'test_type']);
            $table->index(['test_type', 'package_id']);
        });

        Schema::table('participants', function (Blueprint $table): void {
            $table->foreignId('package_id')
                ->nullable()
                ->after('registration_token')
                ->constrained('packages')
                ->restrictOnDelete();
            $table->char('registration_payload_hash', 64)
                ->nullable()
                ->after('registration_token');
        });

        $this->addPostgresConstraints();
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->dropForeign(['package_id']);
            $table->dropColumn(['package_id', 'registration_payload_hash']);
        });

        Schema::dropIfExists('package_items');
        Schema::dropIfExists('packages');
    }

    private function addPostgresConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE packages ADD CONSTRAINT packages_currency_check CHECK (currency = 'IDR')");
        DB::statement('ALTER TABLE packages ADD CONSTRAINT packages_amount_check CHECK (amount IS NULL OR amount > 0)');
        DB::statement('ALTER TABLE packages ADD CONSTRAINT packages_activation_check CHECK (is_active = false OR (amount IS NOT NULL AND amount > 0))');
        DB::statement("ALTER TABLE package_items ADD CONSTRAINT package_items_test_type_check CHECK (test_type IN ('ist', 'papi', 'rmib', 'kraepelin', 'dass21'))");
    }
};
