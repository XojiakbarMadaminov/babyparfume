<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 15, 2)->nullable();
        });
        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->decimal('unit_cost', 15, 2)->nullable();
        });

        DB::table('sale_items')->update([
            'unit_cost' => DB::raw('(SELECT products.initial_price FROM products WHERE products.id = sale_items.product_id)'),
        ]);
        DB::table('inventory_adjustments')->update([
            'unit_cost' => DB::raw('COALESCE((SELECT products.initial_price FROM products WHERE products.id = inventory_adjustments.product_id), 0)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn('unit_cost');
        });
        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->dropColumn('unit_cost');
        });
    }
};
