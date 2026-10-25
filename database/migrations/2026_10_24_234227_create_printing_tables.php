<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->json('flutter_receipt_meta')->nullable();
        });
        Schema::create('print_agents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_store_id')->nullable()->unique()->constrained('stores')->nullOnDelete();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->string('version', 50)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('print_pairing_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('print_printers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained('print_agents')->cascadeOnDelete();
            $table->string('local_key', 64);
            $table->string('name');
            $table->string('driver')->nullable();
            $table->json('capabilities')->nullable();
            $table->boolean('available')->default(true);
            $table->string('loaded_media', 30)->default('unknown');
            $table->timestamps();
            $table->unique(['agent_id', 'local_key']);
        });
        Schema::create('print_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('printer_id')->nullable()->constrained('print_printers')->nullOnDelete();
            $table->string('name', 100);
            $table->string('document_type', 20);
            $table->decimal('width_mm', 8, 2);
            $table->decimal('height_mm', 8, 2)->nullable();
            $table->json('layout');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->boolean('selectable')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('default_copies')->default(1);
            $table->unsignedInteger('max_copies')->default(100);
            $table->timestamps();
        });
        Schema::create('print_store_settings', function (Blueprint $table): void {
            $table->foreignId('store_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('auto_print_receipt')->default(false);
            $table->timestamps();
        });
        Schema::create('print_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('flutter_devices')->nullOnDelete();
            $table->foreignUuid('profile_id')->constrained('print_profiles')->restrictOnDelete();
            $table->json('profile_snapshot');
            $table->json('payload');
            $table->string('document_type', 20);
            $table->string('source_key', 100)->nullable();
            $table->unsignedInteger('copies')->default(1);
            $table->unsignedInteger('total_labels')->default(0);
            $table->string('path');
            $table->string('sha256', 64);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('print_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_job_id')->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('flutter_devices')->nullOnDelete();
            $table->foreignUuid('document_id')->constrained('print_documents')->restrictOnDelete();
            $table->foreignUuid('agent_id')->constrained('print_agents')->restrictOnDelete();
            $table->foreignUuid('printer_id')->constrained('print_printers')->restrictOnDelete();
            $table->string('payload_hash', 64);
            $table->string('automatic_key', 160)->nullable()->unique();
            $table->string('status', 40)->default('queued');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('auto_submit_until');
            $table->unsignedBigInteger('windows_job_id')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('message', 500)->nullable();
            $table->uuid('reprint_of')->nullable();
            $table->string('reprint_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'status', 'created_at']);
        });
        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->foreign('reprint_of')->references('id')->on('print_jobs')->restrictOnDelete();
        });
        Schema::create('print_job_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('job_id')->constrained('print_jobs')->cascadeOnDelete();
            $table->uuid('event_id')->unique();
            $table->string('status', 40);
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn('flutter_receipt_meta');
        });
        foreach (['print_job_events', 'print_jobs', 'print_documents', 'print_store_settings', 'print_profiles', 'print_printers', 'print_pairing_codes', 'print_agents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
