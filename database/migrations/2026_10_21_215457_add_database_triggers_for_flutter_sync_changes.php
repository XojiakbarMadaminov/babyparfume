<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const TABLES = ['products', 'product_stocks', 'product_size_stocks', 'product_sizes', 'debtors', 'debtor_transactions', 'clients', 'clients_index', 'store_stock', 'media'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Flutter change capture requires PostgreSQL or SQLite.');
        }

        foreach (self::TABLES as $table) {
            $actualTable = $table === 'clients_index' ? 'clients' : $table;
            $entity      = $table === 'clients_index' ? 'client' : (in_array($table, ['debtors', 'debtor_transactions', 'clients'], true) ? 'debtor' : 'product');
            if ($driver === 'pgsql') {
                $targets = $this->targets($table, 'NEW') . ' UNION ' . $this->targets($table, 'OLD');
                $action  = in_array($table, ['products', 'debtors'], true) ? "CASE WHEN TG_OP = 'DELETE' THEN 'delete' ELSE 'upsert' END" : "'upsert'";
                $payload = $entity === 'product' ? "(SELECT json_build_object('unit_cost', initial_price, 'catalog_price', price) FROM products WHERE id = target.entity_id)" : 'NULL';
                DB::unprepared("CREATE OR REPLACE FUNCTION flutter_capture_{$table}() RETURNS trigger AS \$\$
                    DECLARE target RECORD; revision_id BIGINT;
                    BEGIN
                        FOR target IN SELECT DISTINCT capture.store_id, capture.entity_id FROM ({$targets}) capture
                            WHERE capture.store_id IS NOT NULL AND capture.entity_id IS NOT NULL
                            AND EXISTS (SELECT 1 FROM stores WHERE stores.id = capture.store_id)
                            ORDER BY capture.store_id, capture.entity_id
                        LOOP
                            PERFORM id FROM stores WHERE id = target.store_id FOR UPDATE;
                            INSERT INTO flutter_sync_revisions (store_id, entity_type, entity_id, action, revision, payload, created_at)
                            VALUES (target.store_id, '{$entity}', target.entity_id, {$action}, 0, {$payload}, CURRENT_TIMESTAMP)
                            RETURNING id INTO revision_id;
                            UPDATE flutter_sync_revisions SET revision = revision_id WHERE id = revision_id;
                        END LOOP;
                        IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
                    END; \$\$ LANGUAGE plpgsql");
            }

            foreach (['insert', 'update', 'delete'] as $event) {
                $name   = "flutter_capture_{$table}_{$event}";
                $timing = $event === 'delete' ? 'BEFORE' : 'AFTER';
                if ($driver === 'pgsql') {
                    DB::unprepared("CREATE TRIGGER {$name} {$timing} " . strtoupper($event) . " ON {$actualTable}
                        FOR EACH ROW EXECUTE FUNCTION flutter_capture_{$table}()");
                } else {
                    $targets = $event === 'insert' ? $this->targets($table, 'NEW') : ($event === 'delete' ? $this->targets($table, 'OLD')
                        : $this->targets($table, 'NEW') . ' UNION ' . $this->targets($table, 'OLD'));
                    $action  = $event === 'delete' && in_array($table, ['products', 'debtors'], true) ? 'delete' : 'upsert';
                    $payload = $entity === 'product' ? "(SELECT json_object('unit_cost', initial_price, 'catalog_price', price) FROM products WHERE id = capture.entity_id)" : 'NULL';
                    DB::unprepared("CREATE TRIGGER {$name} {$timing} " . strtoupper($event) . " ON {$actualTable} BEGIN
                        INSERT INTO flutter_sync_revisions (store_id, entity_type, entity_id, action, revision, payload, created_at)
                        SELECT capture.store_id, '{$entity}', capture.entity_id, '{$action}',
                            (SELECT COALESCE(MAX(revision), 0) + 1 FROM flutter_sync_revisions r
                                WHERE r.store_id = capture.store_id AND r.entity_type = '{$entity}' AND r.entity_id = capture.entity_id),
                            {$payload}, CURRENT_TIMESTAMP
                        FROM ({$targets}) capture WHERE capture.store_id IS NOT NULL AND capture.entity_id IS NOT NULL
                            AND EXISTS (SELECT 1 FROM stores WHERE stores.id = capture.store_id);
                    END");
                }
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        foreach (self::TABLES as $table) {
            $actualTable = $table === 'clients_index' ? 'clients' : $table;
            foreach (['insert', 'update', 'delete'] as $event) {
                $name = "flutter_capture_{$table}_{$event}";
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}" . ($driver === 'pgsql' ? " ON {$actualTable}" : ''));
            }
            if ($driver === 'pgsql') {
                DB::unprepared("DROP FUNCTION IF EXISTS flutter_capture_{$table}()");
            }
        }
    }

    private function targets(string $table, string $row): string
    {
        if ($table === 'clients_index') {
            return "SELECT id AS store_id, {$row}.id AS entity_id FROM stores";
        }
        if ($table === 'products') {
            return "SELECT {$row}.store_id AS store_id, {$row}.id AS entity_id UNION " . $this->productTargets("{$row}.id");
        }
        if ($table === 'debtors') {
            return "SELECT {$row}.store_id AS store_id, {$row}.id AS entity_id";
        }
        if ($table === 'clients') {
            return "SELECT store_id, id AS entity_id FROM debtors WHERE client_id = {$row}.id";
        }
        if ($table === 'debtor_transactions') {
            return "SELECT store_id, id AS entity_id FROM debtors WHERE id = {$row}.debtor_id";
        }
        if ($table === 'product_sizes') {
            return $this->productTargets("{$row}.product_id");
        }
        if ($table === 'media') {
            return 'SELECT capture.* FROM (' . $this->productTargets("{$row}.model_id") . ") capture WHERE {$row}.model_type = 'App\\Models\\Product'";
        }
        if ($table === 'store_stock') {
            return "SELECT {$row}.store_id AS store_id, ids.entity_id FROM (" . $this->productsInStock("{$row}.stock_id") . ') ids';
        }
        $productId = $table === 'product_stocks'
            ? "COALESCE({$row}.product_id, (SELECT product_id FROM product_sizes WHERE id = {$row}.product_size_id))"
            : "(SELECT product_id FROM product_sizes WHERE id = {$row}.product_size_id)";

        return $this->productTargets($productId)
            . " UNION SELECT store_id, {$productId} AS entity_id FROM store_stock WHERE stock_id = {$row}.stock_id";
    }

    private function productTargets(string $productId): string
    {
        return "SELECT store_id, id AS entity_id FROM products WHERE id = {$productId}
            UNION SELECT ss.store_id, {$productId} AS entity_id FROM store_stock ss
                WHERE ss.stock_id IN (
                    SELECT stock_id FROM product_stocks WHERE product_id = {$productId}
                        OR product_size_id IN (SELECT id FROM product_sizes WHERE product_id = {$productId})
                    UNION SELECT stock_id FROM product_size_stocks WHERE product_size_id IN (SELECT id FROM product_sizes WHERE product_id = {$productId})
                )";
    }

    private function productsInStock(string $stockId): string
    {
        return "SELECT COALESCE(product_id, (SELECT product_id FROM product_sizes WHERE id = product_size_id)) AS entity_id
                FROM product_stocks WHERE stock_id = {$stockId}
            UNION SELECT s.product_id AS entity_id FROM product_sizes s JOIN product_size_stocks ps ON ps.product_size_id = s.id WHERE ps.stock_id = {$stockId}";
    }
};
