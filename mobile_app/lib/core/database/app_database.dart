import 'package:path/path.dart';
import 'package:path_provider/path_provider.dart';
import 'package:sqflite/sqflite.dart';

class AppDatabase {
  static Database? _db;

  static Future<Database> getInstance() async {
    if (_db != null) return _db!;
    _db = await _initDb();
    return _db!;
  }

  static Future<Database> _initDb() async {
    final docsDir = await getApplicationDocumentsDirectory();
    final dbPath = join(docsDir.path, 'babyparfume_offline.db');

    return openDatabase(
      dbPath,
      version: 1,
      onCreate: (db, version) async {
        // 1. Products table
        await db.execute('''
          CREATE TABLE products (
            id TEXT PRIMARY KEY,
            store_id TEXT NOT NULL,
            name TEXT NOT NULL,
            barcode TEXT,
            type TEXT NOT NULL,
            price REAL NOT NULL,
            initial_price REAL,
            updated_at TEXT
          );
        ''');
        await db.execute('CREATE INDEX idx_products_store ON products (store_id);');
        await db.execute('CREATE INDEX idx_products_barcode ON products (barcode);');

        // 2. Product stocks table
        await db.execute('''
          CREATE TABLE product_stocks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id TEXT NOT NULL,
            stock_id TEXT NOT NULL,
            size_id TEXT,
            quantity INTEGER NOT NULL DEFAULT 0
          );
        ''');
        await db.execute('CREATE INDEX idx_stocks_product ON product_stocks (product_id);');

        // 3. Debtors table
        await db.execute('''
          CREATE TABLE debtors (
            id TEXT PRIMARY KEY,
            store_id TEXT NOT NULL,
            client_id TEXT,
            full_name TEXT NOT NULL,
            phone TEXT,
            amount REAL NOT NULL DEFAULT 0,
            currency TEXT NOT NULL DEFAULT 'UZS',
            date TEXT,
            note TEXT,
            photo_path TEXT,
            updated_at TEXT
          );
        ''');
        await db.execute('CREATE INDEX idx_debtors_store ON debtors (store_id);');

        // 4. Debtor transactions table
        await db.execute('''
          CREATE TABLE debtor_transactions (
            id TEXT PRIMARY KEY,
            debtor_id TEXT NOT NULL,
            type TEXT NOT NULL,
            amount REAL NOT NULL,
            date TEXT NOT NULL,
            note TEXT,
            sync_status TEXT NOT NULL DEFAULT 'synced'
          );
        ''');

        // 5. Offline sales table
        await db.execute('''
          CREATE TABLE offline_sales (
            local_sale_id TEXT PRIMARY KEY,
            store_id TEXT NOT NULL,
            client_id TEXT,
            total_amount REAL NOT NULL,
            paid_amount REAL NOT NULL,
            remaining_amount REAL NOT NULL,
            payment_type TEXT NOT NULL,
            items_json TEXT NOT NULL,
            deficits_json TEXT,
            occurred_at TEXT NOT NULL,
            sync_status TEXT NOT NULL DEFAULT 'pending'
          );
        ''');

        // 6. Sync Outbox table
        await db.execute('''
          CREATE TABLE sync_outbox (
            operation_id TEXT PRIMARY KEY,
            entity_type TEXT NOT NULL,
            operation_type TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL,
            last_error TEXT
          );
        ''');

        // 7. App sync state (watermark, cursors)
        await db.execute('''
          CREATE TABLE sync_state (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
          );
        ''');
      },
    );
  }

  static Future<void> close() async {
    await _db?.close();
    _db = null;
  }
}
