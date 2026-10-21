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
        Schema::table('flutter_sync_snapshots', function (Blueprint $table): void {
            $table->json('data')->nullable();
            $table->string('permission_hash', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flutter_sync_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['data', 'permission_hash']);
        });
    }
};
