import 'dart:math';
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';
import '../../../core/api/api_client.dart';
import '../../../core/database/app_database.dart';
import '../../../core/sync/outbox_manager.dart';

class LocalProduct {
  final String id;
  final String storeId;
  final String name;
  final String? barcode;
  final String type;
  final double price;
  final double? initialPrice;
  final int stockQuantity;
  final String? updatedAt;

  LocalProduct({
    required this.id,
    required this.storeId,
    required this.name,
    this.barcode,
    required this.type,
    required this.price,
    this.initialPrice,
    required this.stockQuantity,
    this.updatedAt,
  });

  factory LocalProduct.fromMap(Map<String, dynamic> map, {int stockQuantity = 0}) {
    return LocalProduct(
      id: map['id'] as String,
      storeId: map['store_id'] as String,
      name: map['name'] as String,
      barcode: map['barcode'] as String?,
      type: map['type'] as String? ?? 'package',
      price: (map['price'] as num?)?.toDouble() ?? 0.0,
      initialPrice: (map['initial_price'] as num?)?.toDouble(),
      stockQuantity: stockQuantity,
      updatedAt: map['updated_at'] as String?,
    );
  }
}

class ProductsRepository {
  final ApiClient? apiClient;
  final OutboxManager outboxManager;
  final Future<Database> Function() _getDb;

  ProductsRepository({
    this.apiClient,
    required this.outboxManager,
    Future<Database> Function()? getDb,
  }) : _getDb = getDb ?? AppDatabase.getInstance;

  /// Fast offline multiword and barcode search (PH07-01)
  Future<List<LocalProduct>> searchProducts(String storeId, {String? query}) async {
    final db = await _getDb();
    String sql = '''
      SELECT p.*, COALESCE(SUM(s.quantity), 0) as total_stock
      FROM products p
      LEFT JOIN product_stocks s ON p.id = s.product_id
      WHERE p.store_id = ?
    ''';
    List<dynamic> args = [storeId];

    if (query != null && query.trim().isNotEmpty) {
      final words = query.trim().toLowerCase().split(RegExp(r'\s+'));
      for (final word in words) {
        sql += ' AND (LOWER(p.name) LIKE ? OR p.barcode LIKE ?)';
        final match = '%$word%';
        args.addAll([match, match]);
      }
    }

    sql += ' GROUP BY p.id ORDER BY p.name ASC';

    final rows = await db.rawQuery(sql, args);
    return rows.map((r) {
      final stock = (r['total_stock'] as num?)?.toInt() ?? 0;
      return LocalProduct.fromMap(r, stockQuantity: stock);
    }).toList();
  }

  Future<LocalProduct?> findByBarcode(String storeId, String barcode) async {
    final db = await _getDb();
    final rows = await db.rawQuery('''
      SELECT p.*, COALESCE(SUM(s.quantity), 0) as total_stock
      FROM products p
      LEFT JOIN product_stocks s ON p.id = s.product_id
      WHERE p.store_id = ? AND p.barcode = ?
      GROUP BY p.id
      LIMIT 1
    ''', [storeId, barcode]);

    if (rows.isEmpty) return null;
    final r = rows.first;
    final stock = (r['total_stock'] as num?)?.toInt() ?? 0;
    return LocalProduct.fromMap(r, stockQuantity: stock);
  }

  /// Generates a valid unique 13-digit EAN barcode
  String generateBarcode() {
    final random = Random();
    final buffer = StringBuffer('478'); // Uzbekistan prefix
    for (int i = 0; i < 9; i++) {
      buffer.write(random.nextInt(10));
    }
    final raw = buffer.toString();
    int checksum = 0;
    for (int i = 0; i < raw.length; i++) {
      final digit = int.parse(raw[i]);
      checksum += (i % 2 == 0) ? digit : digit * 3;
    }
    final checkDigit = (10 - (checksum % 10)) % 10;
    return '$raw$checkDigit';
  }

  Future<LocalProduct> createProduct({
    required String storeId,
    required String name,
    String? barcode,
    required double price,
    int initialStock = 0,
  }) async {
    final db = await _getDb();
    final productId = const Uuid().v4();
    final now = DateTime.now().toIso8601String();
    final finalBarcode = barcode ?? generateBarcode();

    await db.transaction((txn) async {
      await txn.insert('products', {
        'id': productId,
        'store_id': storeId,
        'name': name,
        'barcode': finalBarcode,
        'type': 'package',
        'price': price,
        'initial_price': price,
        'updated_at': now,
      });

      if (initialStock > 0) {
        await txn.insert('product_stocks', {
          'product_id': productId,
          'stock_id': 'default',
          'quantity': initialStock,
        });
      }
    });

    // Enqueue operation in outbox
    await outboxManager.enqueueOperation(
      entityType: 'product',
      operationType: 'product.create',
      payload: {
        'local_id': productId,
        'name': name,
        'barcode': finalBarcode,
        'price': price.toStringAsFixed(2),
        'stock_quantity': initialStock,
        'occurred_at': now,
      },
    );

    return LocalProduct(
      id: productId,
      storeId: storeId,
      name: name,
      barcode: finalBarcode,
      type: 'package',
      price: price,
      stockQuantity: initialStock,
      updatedAt: now,
    );
  }

  Future<void> deleteProduct(String productId) async {
    final db = await _getDb();
    await db.transaction((txn) async {
      await txn.delete('products', where: 'id = ?', whereArgs: [productId]);
      await txn.delete('product_stocks', where: 'product_id = ?', whereArgs: [productId]);
    });

    await outboxManager.enqueueOperation(
      entityType: 'product',
      operationType: 'product.delete',
      payload: {'id': productId},
    );
  }
}
