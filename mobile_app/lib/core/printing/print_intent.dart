import 'dart:convert';
import 'package:sqflite/sqflite.dart';

class PrintIntent {
  static Map<String, dynamic> receiptSource(Map<String, Object?> sale,
      {String? clientName}) {
    if (sale['server_sale_id'] != null && sale['sync_status'] == 'synced') {
      return {
        'kind': 'server_sale',
        'sale_id': sale['server_sale_id'],
        'local_sale_id': sale['local_sale_id']
      };
    }
    final payment = jsonDecode(sale['payment_json'] as String? ?? '{}') as Map;
    final items = jsonDecode(sale['items_json'] as String) as List;
    return {
      'kind': 'local_sale',
      'local_sale_id': sale['local_sale_id'],
      'operation_id': sale['operation_id'],
      'snapshot': {
        'date': sale['occurred_at'],
        'client_name': clientName,
        'payment_type': sale['payment_type'],
        'total_amount': sale['total_amount'],
        'paid_amount': sale['paid_amount'],
        'remaining_amount': sale['remaining_amount'],
        'receipt_discount': payment['receipt_discount'] ?? 0,
        'cash': payment['cash'],
        'card': payment['card'],
        'received_cash': payment['received_cash'],
        'change': payment['change'],
        'items': items
            .map((line) => {
                  'name': line['name'] ?? line['product_id'].toString(),
                  if (line['size_name'] != null) 'size': line['size_name'],
                  'qty': line['quantity'],
                  'price': line['unit_price'],
                  'discount': line['discount'] ?? 0
                })
            .toList(),
      }
    };
  }

  static Future<void> automatic(
      DatabaseExecutor txn, String storeId, Map<String, Object?> sale) async {
    final rows = await txn
        .query('sync_state', where: 'key = ?', whereArgs: ['printing_options']);
    if (rows.isEmpty) return;
    dynamic options;
    try {
      options = jsonDecode(rows.first['value'] as String);
    } on FormatException {
      return;
    }
    if (options is! Map) return;
    if (options['enabled'] != true ||
        options['auto_print_receipt'] != true ||
        options['store_id'] != storeId) return;
    final cachedProfiles = options['profiles'];
    if (cachedProfiles is! List) return;
    final profiles = cachedProfiles.whereType<Map>().where(
        (p) => p['document_type'] == 'receipt' && p['is_default'] == true);
    if (profiles.isEmpty) return;
    final profile = profiles.first;
    if (profile['id'] is! String ||
        profile['version'] is! int ||
        profile['default_copies'] is! int ||
        profile['default_copies'] < 1 ||
        profile['default_copies'] > 100) return;
    String? clientName;
    if (sale['client_id'] != null) {
      final clients = await txn
          .query('clients', where: 'id = ?', whereArgs: [sale['client_id']]);
      if (clients.isNotEmpty) {
        clientName = clients.first['full_name']?.toString();
      }
    }
    final id = sale['local_sale_id'].toString();
    final body = {
      'requested_at': sale['occurred_at'],
      'client_job_id': id,
      'document_type': 'receipt',
      'source': receiptSource(sale, clientName: clientName),
      'paper_profile_id': profile['id'],
      'paper_profile_version': profile['version'],
      'copies': profile['default_copies'],
      'trigger': 'automatic'
    };
    await txn.insert(
        'sync_state',
        {
          'key': 'print_intent_$id',
          'value': jsonEncode({
            'payload': body,
            'created_at': sale['occurred_at'],
            'state': 'new',
            'receipt_id': id,
            'store_id': storeId,
          })
        },
        conflictAlgorithm: ConflictAlgorithm.ignore);
    await txn.insert('sync_state', {'key': 'print_receipt_$id', 'value': id},
        conflictAlgorithm: ConflictAlgorithm.replace);
  }
}
