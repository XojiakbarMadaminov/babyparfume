<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_id');
            $table->json('store_ids');
            $table->json('permissions');
            $table->foreignId('default_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'client_id']);
        });

        Schema::create('mcp_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('connection_id')->constrained('mcp_connections')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('type', 24);
            $table->json('payload');
            $table->char('fingerprint', 64)->nullable();
            $table->string('status', 24)->default('pending');
            $table->json('result')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'request_id']);
        });

        Schema::create('mcp_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('operation_id')->constrained('mcp_operations')->cascadeOnDelete();
            $table->string('channel', 24);
            $table->unsignedBigInteger('device_id')->default(0);
            $table->text('payload');
            $table->string('status', 24)->default('queued');
            $table->string('message_id')->nullable();
            $table->string('error_type')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['operation_id', 'channel', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_deliveries');
        Schema::dropIfExists('mcp_operations');
        Schema::dropIfExists('mcp_connections');
    }
};
