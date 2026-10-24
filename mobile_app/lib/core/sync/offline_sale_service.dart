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
  OfflineSaleResult(
      {required this.localSaleId,
      required this.operationId,
      required this.deficits,
      required this.totalAmount});
}

class OfflineSaleService {
  final OutboxManager outboxManager;
  final Future<Database> Function() _getDb;
  final Future<void> Function() _ensureWrite;
  OfflineSaleService(
      {required this.outboxManager,
      Future<Database> Function()? getDb,
      Future<void> Function()? ensureWrite})
      : _getDb = getDb ?? AppDatabase.getInstance,
        _ensureWrite = ensureWrite ?? AppDatabase.requireWriteAccess;

  Future<OfflineSaleResult> processOfflineSale(
      {required String storeId,
      String? clientId,
      String? customerId,
      required List<Map<String, dynamic>> items,
      required double totalAmount,
      required double paidAmount,
      required double remainingAmount,
      required String paymentType,
      double receiptDiscount = 0,
      double? cashAmount,
      double? cardAmount,
      double? receivedCash,
      String? paymentNote,
      String? draftId}) async {
    await _ensureWrite();
    if (items.isEmpty ||
        !['cash', 'card', 'debt', 'transfer', 'mixed', 'partial', 'preorder']
            .contains(paymentType)) {
      throw ArgumentError('Savat yoki to‘lov turi noto‘g‘ri.');
    }
    double computedTotal = 0;
    for (final item in items) {
      final qty = item['quantity'] as num?;
      final price = num.tryParse(item['unit_price'].toString());
      if (qty == null ||
          qty <= 0 ||
          qty != qty.toInt() ||
          price == null ||
          !price.isFinite ||
          price < 0 ||
          int.tryParse(item['stock_id'].toString()) == null) {
        throw ArgumentError('Mahsulot, ombor, miqdor va narxni tekshiring.');
      }
      final discount = num.tryParse(item['discount']?.toString() ?? '0') ?? 0;
      if (!discount.isFinite || discount < 0 || discount > qty * price) {
        throw ArgumentError('Pozitsiya chegirmasini tekshiring.');
      }
      computedTotal += qty * price - discount;
    }
    computedTotal -= receiptDiscount;
    if (!totalAmount.isFinite ||
        (paymentType == 'cash' &&
            receivedCash != null &&
            (!receivedCash.isFinite || receivedCash < paidAmount)) ||
        !receiptDiscount.isFinite ||
        (cashAmount != null && (!cashAmount.isFinite || cashAmount < 0)) ||
        (cardAmount != null && (!cardAmount.isFinite || cardAmount < 0)) ||
        (paymentNote?.length ?? 0) > 500 ||
        !paidAmount.isFinite ||
        !remainingAmount.isFinite ||
        receiptDiscount < 0 ||
        computedTotal < 0 ||
        (computedTotal - totalAmount).abs() > 0.001 ||
        paidAmount < 0 ||
        remainingAmount < 0 ||
        (paidAmount + remainingAmount - totalAmount).abs() > 0.001 ||
        (paymentType == 'debt' && paidAmount != 0) ||
        (['cash', 'card', 'transfer', 'mixed'].contains(paymentType) &&
            remainingAmount != 0) ||
        (paymentType == 'preorder' && paidAmount != 0) ||
        (['mixed', 'partial'].contains(paymentType) &&
            ((cashAmount ?? 0) + (cardAmount ?? 0) - paidAmount).abs() >
                0.001) ||
        (paymentType == 'partial' &&
            (paidAmount <= 0 || paidAmount >= totalAmount))) {
      throw ArgumentError('To‘lov va chek summasi mos emas.');
    }
    final db = await _getDb();
    final localSaleId = const Uuid().v4();
    final operationId = const Uuid().v4();
    final occurredAt =
        (await outboxManager.operationTime()).toUtc().toIso8601String();
    final deficits = <Map<String, dynamic>>[];
    Map<String, dynamic>? debtorContext;
    await db.transaction((txn) async {
      final client = <String, dynamic>{};
      final dependencies = <String>[];
      String? localDebtorId = clientId;
      if (customerId != null) {
        final customers = await txn.query('clients',
            where: 'id = ? AND store_id = ?', whereArgs: [customerId, storeId]);
        if (customers.isEmpty) throw StateError('Mijoz topilmadi.');
        final customer = customers.single;
        final serverId = customer['server_id'] as String?;
        if (serverId != null) {
          client['server_id'] = serverId;
        } else {
          client['local_id'] = customerId;
          final parents = await txn.query('sync_outbox',
              where: "operation_type = 'client.create'");
          for (final parent in parents) {
            if ((jsonDecode(parent['envelope_json'] as String)
                    as Map)['local_id'] ==
                customerId) dependencies.add(parent['operation_id'] as String);
          }
          if (dependencies.isEmpty) {
            throw StateError('Mijozni sinxronlash amali topilmadi.');
          }
        }
        if (remainingAmount > 0 && paymentType != 'preorder') {
          final existing = await txn.query('debtors',
              where:
                  'client_id IN (?, ?) AND store_id = ? AND UPPER(currency) = ?',
              whereArgs: [serverId ?? customerId, customerId, storeId, 'UZS'],
              orderBy: 'CASE WHEN amount > 0 THEN 0 WHEN EXISTS '
                  '(SELECT 1 FROM debtor_transactions WHERE debtor_id = debtors.id '
                  "AND sync_status != 'synced') THEN 1 ELSE 2 END, "
                  'CAST(COALESCE(server_id, id) AS INTEGER), id',
              limit: 1);
          if (existing.isNotEmpty) {
            localDebtorId = existing.first['id'] as String;
          } else {
            localDebtorId = const Uuid().v4();
            await txn.insert('debtors', {
              'id': localDebtorId,
              'store_id': storeId,
              'client_id': serverId ?? customerId,
              'full_name': customer['full_name'],
              'phone': customer['phone'],
              'amount': 0,
              'currency': 'UZS',
              'date': occurredAt.substring(0, 10),
              'can_update': 1
            });
          }
        }
      }
      if (localDebtorId != null) {
        final debtors = await txn.query('debtors',
            where: 'id = ? AND store_id = ?',
            whereArgs: [localDebtorId, storeId]);
        if (debtors.isEmpty) throw StateError('Mijoz topilmadi.');
        final debtor = debtors.first;
        if (customerId == null && debtor['client_id'] != null) {
          client['server_id'] = debtor['client_id'];
        } else if (customerId == null) {
          client['local_debtor_id'] = localDebtorId;
          final parents = await txn.query('sync_outbox',
              where:
                  "operation_type = 'debtor.create' AND status != 'applied'");
          for (final parent in parents) {
            if ((jsonDecode(parent['envelope_json'] as String)
                    as Map)['local_id'] ==
                localDebtorId) {
              dependencies.add(parent['operation_id'] as String);
            }
          }
          if (dependencies.isEmpty) {
            throw StateError('Mijozni sinxronlash amali topilmadi.');
          }
        }
        if (remainingAmount > 0 && paymentType != 'preorder') {
          if (remainingAmount != remainingAmount.roundToDouble()) {
            throw ArgumentError('Qarz summasi butun so‘m bo‘lishi kerak.');
          }
          final previous = await txn.query('sync_outbox',
              where: "entity_type IN ('debtor','sale') AND status != 'applied'",
              orderBy: 'created_at DESC, rowid DESC');
          String? parentId;
          for (final candidate in previous) {
            final envelope =
                jsonDecode(candidate['envelope_json'] as String) as Map;
            if (envelope['local_id'] == localDebtorId ||
                envelope['payload']?['local_debtor_id'] == localDebtorId ||
                [localDebtorId, debtor['server_id']]
                    .whereType<String>()
                    .contains(envelope['entity_id'])) {
              parentId = candidate['operation_id'] as String;
              break;
            }
          }
          if (parentId != null) dependencies.add(parentId);
          if (debtor['server_id'] != null || parentId != null) {
            debtorContext = {
              'id': debtor['server_id'] ?? localDebtorId,
              'base_revision': debtor['revision'] ?? 0,
              if (parentId != null) 'base_operation_id': parentId,
            };
          }
          await txn.update(
              'debtors',
              {
                'amount': (debtor['amount'] as num).toDouble() + remainingAmount
              },
              where: 'id = ?',
              whereArgs: [localDebtorId]);
          await txn.insert('debtor_transactions', {
            'id': const Uuid().v4(),
            'debtor_id': localDebtorId,
            'type': 'debt',
            'amount': remainingAmount,
            'date': occurredAt,
            'note': 'Savdo cheki #$localSaleId',
            'sync_status': 'pending',
            'operation_id': operationId
          });
        }
      } else if (customerId == null &&
          !['cash', 'card'].contains(paymentType)) {
        throw ArgumentError('Bu to‘lov turi uchun mijoz tanlang.');
      }
      for (final item in items) {
        final id = item['product_id'].toString();
        final qty = (item['quantity'] as num).toInt();
        final products = await txn.query('products',
            where: 'id = ? AND store_id = ?', whereArgs: [id, storeId]);
        if (products.isEmpty) {
          throw StateError('Mahsulot ushbu do‘konda topilmadi.');
        }
        final rows = await txn.query('product_stocks',
            where:
                'product_id = ? AND stock_id = ? AND COALESCE(size_id, \'\') = ?',
            whereArgs: [
              id,
              item['stock_id'].toString(),
              item['product_size_id']?.toString() ?? ''
            ]);
        final available =
            rows.isEmpty ? 0 : (rows.first['quantity'] as num).toInt();
        if (qty > available) {
          deficits.add({
            'product_id': id,
            'stock_id': item['stock_id'],
            'requested': qty,
            'available': available
          });
        }
        if (rows.isNotEmpty) {
          await txn.update('product_stocks',
              {'quantity': available > qty ? available - qty : 0},
              where: 'id = ?', whereArgs: [rows.first['id']]);
        }
      }
      await txn.insert('offline_sales', {
        'local_sale_id': localSaleId,
        'store_id': storeId,
        'client_id': customerId ?? clientId,
        'total_amount': totalAmount,
        'paid_amount': paidAmount,
        'remaining_amount': remainingAmount,
        'payment_type': paymentType,
        'payment_json': jsonEncode({
          'cash': cashAmount ?? (paymentType == 'cash' ? paidAmount : 0),
          'card': cardAmount ?? (paymentType == 'card' ? paidAmount : 0),
          'receipt_discount': receiptDiscount,
          'received_cash': receivedCash,
          'change': paymentType == 'cash'
              ? (receivedCash ?? paidAmount) - paidAmount
              : 0,
          'note': paymentNote,
        }),
        'items_json': jsonEncode(items),
        'deficits_json': jsonEncode(deficits),
        'occurred_at': occurredAt,
        'sync_status': 'pending',
        'operation_id': operationId
      });
      await outboxManager.enqueueOperation(
          executor: txn,
          customOperationId: operationId,
          storeId: storeId,
          entityType: 'sale',
          operationType: 'sale.create',
          dependsOn: dependencies,
          payload: {
            'local_sale_id': localSaleId,
            'client': client,
            if (debtorContext != null) 'debtor_context': debtorContext,
            if (localDebtorId != null) 'local_debtor_id': localDebtorId,
            'items': items,
            'payment': {
              'type': paymentType,
              'note': paymentNote,
              'cash': (cashAmount ??
                      (paymentType == 'cash' || paymentType == 'partial'
                          ? paidAmount
                          : 0))
                  .toStringAsFixed(2),
              'card': (cardAmount ?? (paymentType == 'card' ? paidAmount : 0))
                  .toStringAsFixed(2)
            },
            'receipt_discount': receiptDiscount.toStringAsFixed(2),
            'currency': 'UZS',
            'scale': 2
          });
      if (draftId != null) {
        await txn.delete('cart_drafts', where: 'id = ?', whereArgs: [draftId]);
      }
    });
    return OfflineSaleResult(
        localSaleId: localSaleId,
        operationId: operationId,
        deficits: deficits,
        totalAmount: totalAmount);
  }
}
