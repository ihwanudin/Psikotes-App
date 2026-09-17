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
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('display_name', 120);
            $table->boolean('is_active')->default(false);
            $table->json('settings')->nullable();
            $table->timestampsTz();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('participant_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('IDR');
            $table->string('gateway_ref', 160)->nullable()->unique();
            $table->text('invoice_url')->nullable();
            $table->string('proof_object_key', 512)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['participant_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('test_type', 24);
            $table->string('status', 24)->default('locked');
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['participant_id', 'test_type']);
            $table->index(['status', 'test_type']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type', 32);
            $table->string('actor_id', 64)->nullable();
            $table->string('action', 120);
            $table->string('subject_type', 120);
            $table->string('subject_id', 64)->nullable();
            $table->json('context')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('expires_at');

            $table->index(['branch_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('expires_at');
        });

        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('message_id')->unique();
            $table->string('topic', 120);
            $table->string('aggregate_type', 120);
            $table->string('aggregate_id', 64);
            $table->json('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index('expires_at');
        });

        $this->addPostgresConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('entitlements');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('payment_methods');
    }

    private function addPostgresConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'paid', 'rejected', 'expired', 'cancelled'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_currency_check CHECK (currency = 'IDR')");
        DB::statement("ALTER TABLE entitlements ADD CONSTRAINT entitlements_test_type_check CHECK (test_type IN ('ist', 'papi', 'rmib', 'kraepelin', 'dass21'))");
        DB::statement("ALTER TABLE entitlements ADD CONSTRAINT entitlements_status_check CHECK (status IN ('locked', 'ready', 'in_progress', 'done'))");
        DB::statement("ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_status_check CHECK (status IN ('pending', 'processing', 'processed', 'failed'))");
    }
};
