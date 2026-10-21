<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flutter_sync_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 20)->default('upsert'); // upsert, delete
            $table->unsignedBigInteger('revision')->default(1);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_id', 'id'], 'idx_sync_rev_store_cursor');
            $table->index(['store_id', 'entity_type', 'entity_id'], 'idx_sync_rev_store_entity');
        });

        Schema::create('flutter_sync_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('device_id')->constrained('flutter_devices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 50);
            $table->string('entity_id', 50)->nullable();
            $table->uuid('local_id')->nullable();
            $table->string('operation_type', 50);
            $table->string('base_revision', 50)->nullable();
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->string('status', 20)->default('pending'); // applied, conflict, blocked, rejected
            $table->string('resolution', 50)->nullable();
            $table->json('response_data')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status'], 'idx_sync_ops_store_status');
            $table->index(['user_id', 'store_id'], 'idx_sync_ops_user_store');
            $table->index(['entity_type', 'entity_id'], 'idx_sync_ops_entity');
        });

        Schema::create('flutter_sync_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('operation_id')->on('flutter_sync_operations')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 50);
            $table->string('entity_id', 50)->nullable();
            $table->text('reason');
            $table->json('server_state')->nullable();
            $table->json('client_state')->nullable();
            $table->string('status', 20)->default('pending'); // pending, resolved
            $table->string('resolution', 50)->nullable(); // applied, rejected, merged
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status'], 'idx_sync_conflicts_store_status');
        });

        Schema::create('flutter_sync_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('flutter_devices')->cascadeOnDelete();
            $table->unsignedBigInteger('watermark');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('status', 20)->default('ready'); // ready, expired
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['store_id', 'user_id', 'device_id'], 'idx_sync_snapshots_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flutter_sync_snapshots');
        Schema::dropIfExists('flutter_sync_conflicts');
        Schema::dropIfExists('flutter_sync_operations');
        Schema::dropIfExists('flutter_sync_revisions');
    }
};
