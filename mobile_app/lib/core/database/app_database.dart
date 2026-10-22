import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:cryptography/cryptography.dart';
import 'package:path/path.dart';
import 'package:path_provider/path_provider.dart';
import 'package:sqflite/sqflite.dart';
import 'package:sqflite_sqlcipher/sqflite.dart' as cipher;
import '../storage/secure_storage.dart';
import '../lease/lease_manager.dart';

class AppDatabase {
  static final _mediaChanges = StreamController<String>.broadcast();
  static Stream<String> get mediaChanges => _mediaChanges.stream;
  static void notifyMediaChanged(String debtorId) =>
      _mediaChanges.add(debtorId);

  static Future<bool> hasLegacyDatabase() async => File(join(
          (await getApplicationDocumentsDirectory()).path,
          'babyparfume_offline.db'))
      .exists();

  static Database? _db;
  static Future<Database>? _opening;
  static String? _namespace;
  static SecureStorageService? _storage;

  static Future<void> configure(
      SecureStorageService storage, String storeId) async {
    final userId = await storage.getUserId();
    if (userId == null) throw StateError('Akkaunt aniqlanmagan.');
    final hash = await Sha256().hash(
        utf8.encode('${await storage.getServerUrl()}|1|$userId|$storeId'));
    final namespace = base64Url.encode(hash.bytes).replaceAll('=', '');
    if (_namespace != namespace) {
      await close();
      _namespace = namespace;
    }
    _storage = storage;
    await storage.setSelectedStoreId(storeId);
  }

  static Future<void> requireWriteAccess() async {
    await LeaseManager(storage: SecureStorageService()).requireActive();
    final db = await getInstance();
    final ready = await db
        .query('sync_state', where: 'key = ?', whereArgs: ['offline_ready']);
    if (ready.isEmpty || ready.first['value'] != '1') {
      throw StateError('Avval ma’lumotlarni to‘liq yuklang.');
    }
  }

  static Future<Database> getInstance() async {
    if (_db != null) return _db!;
    if (_namespace == null) {
      throw StateError('Avval akkaunt va do‘konni tanlang.');
    }
    _db = await (_opening ??= _initDb());
    return _db!;
  }

  static Future<Database> _initDb() async {
    final docsDir = await getApplicationDocumentsDirectory();
    final dbPath = join(docsDir.path, 'babyparfume_${_namespace}_encrypted.db');

    return cipher.openDatabase(
      dbPath,
      version: 1,
      password: await _storage!.databasePassword(_namespace!),
      onCreate: createSchema,
    );
  }

  static Future<void> createSchema(Database db, int version) async {
    await db.execute(
        'CREATE TABLE clients (id TEXT PRIMARY KEY, server_id TEXT, store_id TEXT NOT NULL, full_name TEXT NOT NULL, phone TEXT);');
    await db.execute(
        'CREATE TABLE debtor_media (debtor_id TEXT PRIMARY KEY, bytes BLOB, pending INTEGER NOT NULL DEFAULT 0);');
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
    await db
        .execute('CREATE INDEX idx_products_barcode ON products (barcode);');

    // 2. Product stocks table
    await db.execute('''
          CREATE TABLE product_stocks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id TEXT NOT NULL,
            stock_id TEXT NOT NULL,
            size_id TEXT,
            size_name TEXT,
            quantity INTEGER NOT NULL DEFAULT 0,
            UNIQUE(product_id, stock_id, size_id)
          );
        ''');
    await db.execute(
        'CREATE INDEX idx_stocks_product ON product_stocks (product_id);');

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
    await db.execute('ALTER TABLE debtors ADD COLUMN server_id TEXT;');
    await db.execute(
        'ALTER TABLE debtors ADD COLUMN revision INTEGER NOT NULL DEFAULT 0;');
    await db.execute(
        'ALTER TABLE debtors ADD COLUMN can_update INTEGER NOT NULL DEFAULT 0;');
    await db.execute('ALTER TABLE products ADD COLUMN search_name TEXT;');
    await db.execute(
        'ALTER TABLE products ADD COLUMN revision INTEGER NOT NULL DEFAULT 0;');
    await db.execute('ALTER TABLE products ADD COLUMN image_url TEXT;');
    await db.execute(
        'ALTER TABLE products ADD COLUMN can_update INTEGER NOT NULL DEFAULT 0;');
    await db.execute(
        'ALTER TABLE products ADD COLUMN can_delete INTEGER NOT NULL DEFAULT 0;');

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
            payment_json TEXT,
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
    await db.execute('ALTER TABLE sync_outbox ADD COLUMN envelope_json TEXT;');
    await db.execute('ALTER TABLE sync_outbox ADD COLUMN store_id TEXT;');
    await db.execute(
        'ALTER TABLE debtor_transactions ADD COLUMN operation_id TEXT;');
    await db.execute('ALTER TABLE offline_sales ADD COLUMN operation_id TEXT;');
    await db
        .execute('ALTER TABLE offline_sales ADD COLUMN server_sale_id TEXT;');
    await db.execute(
        'CREATE TABLE cart_drafts (id TEXT PRIMARY KEY, payload_json TEXT NOT NULL);');
    await db.execute(
        'CREATE TABLE server_cache (entity TEXT NOT NULL, id TEXT NOT NULL, payload_json TEXT NOT NULL, PRIMARY KEY(entity, id));');

    // 7. App sync state (watermark, cursors)
    await db.execute('''
          CREATE TABLE sync_state (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
          );
        ''');
  }

  static Future<void> close() async {
    final database = _db ?? await _opening;
    await database?.close();
    _db = null;
    _opening = null;
    _namespace = null;
  }
}
