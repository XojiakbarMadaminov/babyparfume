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
        Schema::table('general_settings', function (Blueprint $table): void {
            $table->boolean('subscription_warning_active')->default(false);
            $table->string('subscription_warning_text')->default('Oylik obuna to‘lovini to‘lashingiz talab qilinadi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('general_settings', function (Blueprint $table): void {
            $table->dropColumn(['subscription_warning_active', 'subscription_warning_text']);
        });
    }
};
