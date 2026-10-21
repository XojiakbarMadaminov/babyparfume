import 'dart:convert';
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';
import '../database/app_database.dart';
import 'outbox_manager.dart';

class OfflineSaleResult {
  final String localSaleId;
  final String operationId;
  final List<Map<String, dynamic>> deficits;
  final double totalAmount;

  OfflineSaleResult({
    required this.localSaleId,
    required this.operationId,
    required this.deficits,
    required this.totalAmount,
  });
}

class OfflineSaleService {
  final OutboxManager outboxManager;
  final Future<Database> Function() _getDb;

  OfflineSaleService({
    required this.outboxManager,
    Future<Database> Function()? getDb,
  }) : _getDb = getDb ?? AppDatabase.getInstance;

  Future<OfflineSaleResult> processOfflineSale({
    required String storeId,
    String? clientId,
    required List<Map<String, dynamic>> items,
    required double totalAmount,
    required double paidAmount,
    required double remainingAmount,
    required String paymentType,
  }) async {
    final db = await _getDb();
    final localSaleId = const Uuid().v4();
    final operationId = const Uuid().v4();
    final occurredAt = DateTime.now().toIso8601String();

    final List<Map<String, dynamic>> deficits = [];

    // Atomic transaction for local ledger & inventory saturation
    await db.transaction((txn) async {
      for (final item in items) {
        final productId = item['product_id']?.toString() ?? '';
        final requestedQty = (item['quantity'] as num?)?.toInt() ?? 1;

        final stockRows = await txn.query(
          'product_stocks',
          where: 'product_id = ?',
          whereArgs: [productId],
        );

        int currentStock = 0;
        if (stockRows.isNotEmpty) {
          currentStock = (stockRows.first['quantity'] as num?)?.toInt() ?? 0;
        }

        if (requestedQty > currentStock) {
          deficits.add({
            'product_id': productId,
            'requested_quantity': requestedQty,
            'available_quantity': currentStock,
          });
        }

        // Saturation logic: GREATEST(currentStock - requestedQty, 0)
        final newStock = (currentStock - requestedQty) > 0 ? (currentStock - requestedQty) : 0;
        if (stockRows.isNotEmpty) {
          await txn.update(
            'product_stocks',
            {'quantity': newStock},
            where: 'product_id = ?',
            whereArgs: [productId],
          );
        }
      }

      // Record offline sale
      await txn.insert(
        'offline_sales',
        {
          'local_sale_id': localSaleId,
          'store_id': storeId,
          'client_id': clientId,
          'total_amount': totalAmount,
          'paid_amount': paidAmount,
          'remaining_amount': remainingAmount,
          'payment_type': paymentType,
          'items_json': jsonEncode(items),
          'deficits_json': jsonEncode(deficits),
          'occurred_at': occurredAt,
          'sync_status': 'pending',
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    });

    // Enqueue operation in Outbox for server sync
    await outboxManager.enqueueOperation(
      customOperationId: operationId,
      entityType: 'sale',
      operationType: 'sale.create',
      payload: {
        'local_sale_id': localSaleId,
        'client_id': clientId,
        'total_amount': totalAmount.toStringAsFixed(2),
        'paid_amount': paidAmount.toStringAsFixed(2),
        'remaining_amount': remainingAmount.toStringAsFixed(2),
        'payment_type': paymentType,
        'items': items,
        'deficits': deficits,
        'occurred_at': occurredAt,
      },
    );

    return OfflineSaleResult(
      localSaleId: localSaleId,
      operationId: operationId,
      deficits: deficits,
      totalAmount: totalAmount,
    );
  }
}
