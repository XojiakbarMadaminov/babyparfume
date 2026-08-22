<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add type column to products
        Schema::table('products', function (Blueprint $table) {
            $table->string('type')->default('size')->index();
        });

        // Ensure check constraint for type values (PostgreSQL)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type IN ('size','package'))");
        }

        // Create unified product_stocks table
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_size_id')->nullable()->constrained('product_sizes')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('stocks')->cascadeOnDelete();
            $table->bigInteger('quantity')->default(0);
            $table->timestamps();

            // Composite unique for all 3 to avoid duplicates in general
            $table->unique(['product_id', 'product_size_id', 'stock_id'], 'product_stocks_unique_all');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Add check constraint: one of product_id or product_size_id must be non-null, but not both
            DB::statement("ALTER TABLE product_stocks ADD CONSTRAINT product_stocks_product_or_size_chk CHECK ((product_id IS NOT NULL AND product_size_id IS NULL) OR (product_id IS NULL AND product_size_id IS NOT NULL))");

            // Partial unique indexes for package- and size-based rows
            DB::statement('CREATE UNIQUE INDEX product_stocks_unique_package ON product_stocks (product_id, stock_id) WHERE product_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX product_stocks_unique_size ON product_stocks (product_size_id, stock_id) WHERE product_size_id IS NOT NULL');

            // Data migration: copy existing size stocks into product_stocks
            DB::statement("INSERT INTO product_stocks (product_id, product_size_id, stock_id, quantity, created_at, updated_at)
                SELECT NULL, pss.product_size_id, pss.stock_id, pss.quantity, NOW(), NOW()
                FROM product_size_stocks pss
                ON CONFLICT DO NOTHING");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Drop partial unique indexes and table
            DB::statement('DROP INDEX IF EXISTS product_stocks_unique_package');
            DB::statement('DROP INDEX IF EXISTS product_stocks_unique_size');
        }

        Schema::dropIfExists('product_stocks');

        if (DB::getDriverName() === 'pgsql') {
            // Drop type column and constraint
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_type_check');
        }
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};

