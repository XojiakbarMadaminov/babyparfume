<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debtor_transactions', function (Blueprint $table): void {
            $table->uuid('mobile_request_id')->nullable()->unique();
            $table->string('mobile_request_hash', 64)->nullable();
            $table->foreignId('mobile_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debtor_transactions', function (Blueprint $table): void {
            $table->dropForeign(['mobile_user_id']);
            $table->dropUnique(['mobile_request_id']);
            $table->dropColumn(['mobile_request_id', 'mobile_request_hash', 'mobile_user_id']);
        });
    }
};
