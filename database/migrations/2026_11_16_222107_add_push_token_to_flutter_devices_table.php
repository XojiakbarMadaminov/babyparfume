<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flutter_devices', function (Blueprint $table): void {
            $table->text('push_token')->nullable();
            $table->char('push_token_hash', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('flutter_devices', function (Blueprint $table): void {
            $table->dropColumn(['push_token', 'push_token_hash']);
        });
    }
};
