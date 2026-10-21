import 'dart:convert';
import 'package:sqflite/sqflite.dart';

class LocalCache {
  static Future<void> removeCanonical(
      DatabaseExecutor db, String entity, String serverId) async {
    final rows = await db.query(entity,
        where: 'server_id = ? OR id = ?', whereArgs: [serverId, serverId]);
    final operations =
        await db.query('sync_outbox', where: "status != 'applied'");
    for (final row in rows) {
      final id = row['id'];
      var pending = operations.any((operation) {
        final envelope =
            jsonDecode(operation['envelope_json'] as String) as Map;
        return (envelope['entity_type'] ==
                    (entity == 'clients' ? 'client' : 'debtor') &&
                [id, serverId]
                    .contains(envelope['entity_id'] ?? envelope['local_id'])) ||
            envelope['payload']?['local_debtor_id'] == id;
      });
      if (entity == 'debtors') {
        pending = pending ||
            (await db.query('debtor_transactions',
                    where: "debtor_id = ? AND sync_status != 'synced'",
                    whereArgs: [id]))
                .isNotEmpty;
        if (pending) {
          await db.update(entity, {'can_update': 0},
              where: 'id = ?', whereArgs: [id]);
        } else {
          await db.delete('debtor_transactions',
              where: 'debtor_id = ?', whereArgs: [id]);
          await db
              .delete('debtor_media', where: 'debtor_id = ?', whereArgs: [id]);
        }
      } else {
        pending = pending ||
            (await db.query('offline_sales',
                    where: "client_id = ? AND sync_status != 'synced'",
                    whereArgs: [id]))
                .isNotEmpty;
      }
      if (!pending) {
        await db.delete(entity, where: 'id = ?', whereArgs: [id]);
      }
    }
  }

  static Future<void> client(
      DatabaseExecutor db, String storeId, Map<String, dynamic> item) async {
    final serverId = item['id'].toString();
    final rows = await db.query('clients',
        where: 'server_id = ? OR id = ?', whereArgs: [serverId, serverId]);
    await db.insert(
        'clients',
        {
          'id': rows.isEmpty ? serverId : rows.first['id'],
          'server_id': serverId,
          'store_id': storeId,
          'full_name': item['full_name'],
          'phone': item['phone']
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  static double number(dynamic value) =>
      double.tryParse(value?.toString() ?? '') ?? 0;

  static Future<void> product(
      DatabaseExecutor db, String storeId, Map<String, dynamic> item) async {
    final id = item['id'].toString();
    await db.insert(
        'products',
        {
          'id': id,
          'store_id': storeId,
          'name': item['name'] ?? '',
          'search_name': (item['name']?.toString() ?? '').toLowerCase(),
          'revision': number(item['revision']).toInt(),
          'image_url': item['image']?.toString(),
          'barcode': item['barcode']?.toString(),
          'type': item['type'] ?? 'package',
          'price': number(item['price']),
          'initial_price': item.containsKey('initial_price')
              ? number(item['initial_price'])
              : null,
          'updated_at': item['updated_at'],
          'can_update': item['can_update'] == true ? 1 : 0,
          'can_delete': item['can_delete'] == true ? 1 : 0,
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
    await db.delete('product_stocks', where: 'product_id = ?', whereArgs: [id]);
    final stocks = <Map<String, dynamic>>[];
    for (final stock in item['stocks'] as List? ?? []) {
      stocks.add(Map<String, dynamic>.from(stock as Map));
    }
    for (final rawSize in item['sizes'] as List? ?? []) {
      final size = Map<String, dynamic>.from(rawSize as Map);
      for (final stock in size['stocks'] as List? ?? []) {
        stocks.add({
          ...Map<String, dynamic>.from(stock as Map),
          'size_id': size['id'].toString(),
          'size_name': size['size']
        });
      }
    }
    if (stocks.isEmpty && item['type'] != 'size') {
      final state = await db
          .query('sync_state', where: 'key = ?', whereArgs: ['store_stocks']);
      if (state.isNotEmpty) {
        for (final stock
            in jsonDecode(state.first['value'] as String) as List) {
          stocks.add({'stock_id': stock['id'].toString(), 'quantity': 0});
        }
      }
    }
    final pendingSales =
        await db.query('offline_sales', where: "sync_status != 'synced'");
    for (final stock in stocks) {
      var quantity = number(stock['quantity']).toInt();
      for (final sale in pendingSales) {
        for (final line in jsonDecode(sale['items_json'] as String) as List) {
          if (line['product_id'].toString() == id &&
              line['stock_id'].toString() == stock['stock_id'].toString() &&
              line['product_size_id']?.toString() ==
                  stock['size_id']?.toString()) {
            quantity -= number(line['quantity']).toInt();
          }
        }
      }
      await db.insert('product_stocks', {
        'product_id': id,
        'stock_id': stock['stock_id'].toString(),
        'size_id': stock['size_id'],
        'size_name': stock['size_name'],
        'quantity': quantity < 0 ? 0 : quantity
      });
    }
  }

  static Future<void> debtor(
      DatabaseExecutor db, String storeId, Map<String, dynamic> item) async {
    final serverId = item['id'].toString();
    final existing = await db.query('debtors',
        where: 'server_id = ? OR id = ?', whereArgs: [serverId, serverId]);
    final id = existing.isEmpty ? serverId : existing.first['id'] as String;
    final pending = await db.query('debtor_transactions',
        where: "debtor_id = ? AND sync_status != 'synced'", whereArgs: [id]);
    var amount = number(item['amount']);
    for (final transaction in pending) {
      amount += (transaction['type'] == 'debt' ? 1 : -1) *
          number(transaction['amount']);
    }
    final edits = await db.query('sync_outbox',
        where: "operation_type = 'debtor.update' AND status != 'applied'",
        orderBy: 'created_at ASC, rowid ASC');
    final overlay = <String, dynamic>{};
    for (final edit in edits) {
      final envelope = jsonDecode(edit['envelope_json'] as String) as Map;
      if (envelope['entity_id'] == id || envelope['entity_id'] == serverId) {
        final payload = Map<String, dynamic>.from(envelope['payload'] as Map);
        for (final key in ['full_name', 'phone', 'date', 'note']) {
          if (payload.containsKey(key)) overlay[key] = payload[key];
        }
      }
    }
    await db.insert(
        'debtors',
        {
          'id': id,
          'server_id': serverId,
          'store_id': storeId,
          'client_id': item['client_id']?.toString(),
          'full_name': item['full_name'] ?? '',
          'phone': item['phone'],
          'amount': amount,
          'currency': item['currency'] ?? 'UZS',
          'date': item['date'],
          'note': item['note'],
          'photo_path': item['photo_url'],
          'updated_at': item['updated_at'],
          'revision': number(item['revision']).toInt(),
          'can_update': item['can_update'] == true ? 1 : 0,
          ...overlay,
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
    await db.delete('debtor_transactions',
        where: "debtor_id = ? AND sync_status = 'synced'", whereArgs: [id]);
    for (final raw in item['transactions'] as List? ?? []) {
      final transaction = Map<String, dynamic>.from(raw as Map);
      await db.insert(
          'debtor_transactions',
          {
            'id': 'server_${transaction['id']}',
            'debtor_id': id,
            'type': transaction['type'],
            'amount': number(transaction['amount']),
            'date': transaction['date'],
            'note': transaction['note'],
            'sync_status': 'synced'
          },
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
  }
}
