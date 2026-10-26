import 'dart:math';
import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';
import 'package:sqflite/sqflite.dart';
import '../../../core/api/api_client.dart';
import '../../../core/database/app_database.dart';
import '../../../core/sync/outbox_manager.dart';
import '../../../core/storage/secure_storage.dart';
import '../../../core/database/local_cache.dart';

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
  final String? stockId;
  final String? stockName;
  final String? sizeId;
  final String? sizeName;
  final bool canUpdate;
  final bool canDelete;
  final String? imageUrl;
  final int revision;
  final String? categoryId;
  final String? categoryName;
  final String? thumbnailUrl;

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
    this.stockId,
    this.stockName,
    this.sizeId,
    this.sizeName,
    this.canUpdate = false,
    this.canDelete = false,
    this.imageUrl,
    this.revision = 0,
    this.categoryId,
    this.categoryName,
    this.thumbnailUrl,
  });

  factory LocalProduct.fromMap(Map<String, dynamic> map,
      {int stockQuantity = 0}) {
    final catalog = map['catalog_json'] is String
        ? jsonDecode(map['catalog_json'] as String) as Map<String, dynamic>
        : map;
    final category = catalog['category'] as Map?;
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
      stockId: map['stock_id']?.toString(),
      stockName: map['stock_name']?.toString(),
      sizeId: map['size_id']?.toString(),
      sizeName: map['size_name']?.toString(),
      canUpdate: map['can_update'] == 1 || map['can_update'] == true,
      canDelete: map['can_delete'] == 1 || map['can_delete'] == true,
      imageUrl: map['image_url']?.toString() ?? map['image']?.toString(),
      revision: int.tryParse(map['revision']?.toString() ?? '') ?? 0,
      categoryId: map['category_id']?.toString() ?? category?['id']?.toString(),
      categoryName:
          map['category_name']?.toString() ?? category?['name']?.toString(),
      thumbnailUrl:
          map['thumbnail_url']?.toString() ?? catalog['thumbnail']?.toString(),
    );
  }

  Map<String, dynamic> toMap() => {
        'id': id,
        'store_id': storeId,
        'name': name,
        'barcode': barcode,
        'category_id': categoryId,
        'category_name': categoryName,
        'thumbnail_url': thumbnailUrl,
        'image_url': imageUrl,
        'type': type,
        'price': price,
        'stock_id': stockId,
        'stock_name': stockName,
        'size_id': sizeId,
        'size_name': sizeName,
        'updated_at': updatedAt,
        'stock_quantity': stockQuantity,
        'revision': revision
      };
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
  Future<List<LocalProduct>> searchProducts(String storeId,
      {String? query}) async {
    final db = await _getDb();
    String sql = '''
      SELECT p.*, c.payload_json AS catalog_json, MIN(s.stock_id) as stock_id, COALESCE(SUM(s.quantity), 0) as total_stock
      FROM products p
      LEFT JOIN product_stocks s ON p.id = s.product_id
      LEFT JOIN server_cache c ON c.entity = 'products' AND c.id = p.id
      WHERE p.store_id = ?
    ''';
    List<dynamic> args = [storeId];

    if (query != null && query.trim().isNotEmpty) {
      final words = query.trim().toLowerCase().split(RegExp(r'\s+'));
      for (final word in words) {
        sql += ' AND (p.search_name LIKE ? OR p.barcode LIKE ?)';
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
      SELECT p.*, MIN(s.stock_id) as stock_id, COALESCE(SUM(s.quantity), 0) as total_stock
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

  Future<LocalProduct?> getProduct(String storeId, String productId) async {
    final db = await _getDb();
    final rows = await db.rawQuery('''
      SELECT p.*, COALESCE(SUM(s.quantity), 0) AS total_stock
      FROM products p
      LEFT JOIN product_stocks s ON p.id = s.product_id
      WHERE p.store_id = ? AND p.id = ?
      GROUP BY p.id
    ''', [storeId, productId]);
    if (rows.isEmpty) return null;
    return LocalProduct.fromMap(rows.first,
        stockQuantity: (rows.first['total_stock'] as num).toInt());
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

  ApiClient get _api => apiClient ?? ApiClient(storage: SecureStorageService());

  Future<Map<String, dynamic>> formData(
          String storeId, String? productId) async =>
      Map<String, dynamic>.from((await _api.get(productId == null
              ? '/stores/$storeId/products/create-options'
              : '/stores/$storeId/products/$productId'))
          .data as Map);

  Future<LocalProduct> saveProduct(String storeId, Map<String, dynamic> payload,
      {String? productId, Uint8List? photo}) async {
    final data = FormData.fromMap({
      ...payload,
      if (payload.containsKey('stocks'))
        'stocks': jsonEncode(payload['stocks']),
      if (payload.containsKey('sizes')) 'sizes': jsonEncode(payload['sizes']),
      if (photo != null)
        'photo': MultipartFile.fromBytes(photo, filename: 'photo.jpg'),
    });
    final response = await _api.post(
        '/stores/$storeId/products${productId == null ? '' : '/$productId'}',
        data: data);
    final product = Map<String, dynamic>.from(response.data['data'] as Map);
    final db = await _getDb();
    await db.transaction((txn) => LocalCache.product(txn, storeId, product));
    return LocalProduct.fromMap({
      ...product,
      'id': product['id'].toString(),
      'store_id': storeId,
      'price': LocalCache.number(product['price']),
      'initial_price': product['initial_price'] == null
          ? null
          : LocalCache.number(product['initial_price'])
    }, stockQuantity: (product['quantity'] as num?)?.toInt() ?? 0);
  }

  Future<String> requestBarcode(String storeId) async =>
      (await _api.get('/stores/$storeId/products/barcode'))
          .data['barcode']
          .toString();

  Future<LocalProduct> receiveStock(LocalProduct product,
      {required String requestId,
      required String stockId,
      String? sizeId,
      required int quantity}) async {
    final response = await _api.post(
        '/stores/${product.storeId}/products/${product.id}/stock-in',
        data: {
          'request_id': requestId,
          'stock_id': stockId,
          if (sizeId != null) 'product_size_id': sizeId,
          'quantity': quantity,
        });
    final data = Map<String, dynamic>.from(response.data['data'] as Map);
    final db = await _getDb();
    await db
        .transaction((txn) => LocalCache.product(txn, product.storeId, data));
    return (await getProduct(product.storeId, product.id))!;
  }

  Future<List<LocalProduct>> variants(LocalProduct product) async {
    final db = await _getDb();
    final state = await db
        .query('sync_state', where: 'key = ?', whereArgs: ['store_stocks']);
    final stockNames = <String, String>{
      if (state.isNotEmpty)
        for (final stock in jsonDecode(state.first['value'] as String) as List)
          stock['id'].toString(): stock['name'].toString(),
    };
    final stocks = await db.query('product_stocks',
        where: 'product_id = ?', whereArgs: [product.id]);
    return stocks
        .map((stock) => LocalProduct.fromMap({
              ...product.toMap(),
              'initial_price': product.initialPrice,
              'can_update': product.canUpdate,
              'can_delete': product.canDelete,
              ...stock,
              'id': product.id,
              'stock_name': stockNames[stock['stock_id'].toString()]
            }, stockQuantity: (stock['quantity'] as num).toInt()))
        .toList();
  }

  Future<LocalProduct> createProduct(
      {required String storeId,
      required String name,
      String? barcode,
      required double price,
      required double initialPrice,
      int initialStock = 0}) async {
    final api = _api;
    final finalBarcode = barcode ?? await requestBarcode(storeId);
    final options = await api.get('/stores/$storeId/products/create-options');
    final stocks = options.data['stocks'] as List;
    if (initialStock > 0 && stocks.isEmpty) {
      throw StateError('Do‘kon uchun ombor mavjud emas.');
    }
    final response = await api.post('/stores/$storeId/products', data: {
      'name': name,
      'barcode': finalBarcode,
      'type': 'package',
      'price': price.toInt(),
      'initial_price': initialPrice.toInt(),
      'stocks': stocks.isEmpty
          ? []
          : [
              {'stock_id': stocks.first['id'], 'quantity': initialStock}
            ],
    });
    final product = Map<String, dynamic>.from(response.data['data'] as Map);
    final db = await _getDb();
    await db.transaction((txn) => LocalCache.product(txn, storeId, product));
    return LocalProduct.fromMap({
      ...product,
      'id': product['id'].toString(),
      'store_id': storeId,
      'price': LocalCache.number(product['price']),
      'initial_price': product['initial_price'] == null
          ? null
          : LocalCache.number(product['initial_price'])
    }, stockQuantity: (product['quantity'] as num?)?.toInt() ?? 0);
  }

  Future<void> deleteProduct(String productId) async {
    final db = await _getDb();
    final rows =
        await db.query('products', where: 'id = ?', whereArgs: [productId]);
    if (rows.isEmpty) throw StateError('Mahsulot topilmadi.');
    await _api.delete('/stores/${rows.first['store_id']}/products/$productId');
    await db.transaction((txn) async {
      await txn.delete('product_stocks',
          where: 'product_id = ?', whereArgs: [productId]);
      await txn.delete('products', where: 'id = ?', whereArgs: [productId]);
    });
  }
}
