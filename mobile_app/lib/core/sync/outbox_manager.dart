import 'dart:convert';
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';
import '../database/app_database.dart';
import '../lease/lease_manager.dart';
import '../storage/secure_storage.dart';
import 'sync_models.dart';

class OutboxManager {
  final Future<Database> Function() _getDb;
  final Future<DateTime> Function() operationTime;
  OutboxManager(
      {Future<Database> Function()? getDb,
      Future<DateTime> Function()? operationTime})
      : _getDb = getDb ?? AppDatabase.getInstance,
        operationTime = operationTime ??
            (() => LeaseManager(storage: SecureStorageService()).serverNow());

  Future<String> enqueueOperation({
    required String storeId,
    required String entityType,
    required String operationType,
    required Map<String, dynamic> payload,
    String? customOperationId,
    String? entityId,
    String? localId,
    int? baseRevision,
    String? baseOperationId,
    List<String> dependsOn = const [],
    DatabaseExecutor? executor,
  }) async {
    final db = executor ?? await _getDb();
    final id = customOperationId ?? const Uuid().v4();
    final now = (await operationTime()).toUtc().toIso8601String();
    final envelope = {
      'operation_id': id,
      'entity_type': entityType,
      'operation_type': operationType,
      'payload': payload,
      'occurred_at': now,
      if (entityId != null) 'entity_id': entityId,
      if (localId != null) 'local_id': localId,
      if (baseRevision != null) 'base_revision': baseRevision,
      if (baseOperationId != null) 'base_operation_id': baseOperationId,
      if (dependsOn.isNotEmpty) 'depends_on': dependsOn,
    };
    await db.insert('sync_outbox', {
      'operation_id': id,
      'store_id': storeId,
      'entity_type': entityType,
      'operation_type': operationType,
      'payload_json': jsonEncode(payload),
      'envelope_json': jsonEncode(envelope),
      'status': 'pending',
      'created_at': now,
    });
    return id;
  }

  Future<List<OutboxItem>> getPendingOperations(
      {String? storeId, bool includeAll = false}) async {
    final db = await _getDb();
    final rows = await db.query('sync_outbox',
        where:
            "${storeId == null ? '' : 'store_id = ? AND '}${includeAll ? "status != 'applied'" : "status IN ('pending','failed','blocked')"}",
        whereArgs: storeId == null ? [] : [storeId],
        orderBy: 'created_at ASC, rowid ASC');
    return rows
        .map((r) => OutboxItem(
              operationId: r['operation_id'] as String,
              entityType: r['entity_type'] as String,
              operationType: r['operation_type'] as String,
              payload: jsonDecode(r['payload_json'] as String)
                  as Map<String, dynamic>,
              envelope: r['envelope_json'] == null
                  ? null
                  : jsonDecode(r['envelope_json'] as String)
                      as Map<String, dynamic>,
              status: r['status'] as String,
              createdAt: DateTime.parse(r['created_at'] as String),
              lastError: r['last_error'] as String?,
            ))
        .toList();
  }

  Future<int> getPendingCount() async =>
      (await getPendingOperations(includeAll: true)).length;

  Future<void> acknowledge(OutboxItem item, Map<String, dynamic> result) async {
    final db = await _getDb();
    final status = result['status']?.toString();
    if (!['applied', 'conflict', 'blocked', 'rejected'].contains(status)) {
      throw StateError("Server amal holatini qaytarmadi.");
    }
    await db.transaction((txn) async {
      await txn.update(
          'sync_outbox',
          {
            'status': status,
            'last_error': (result['error'] ?? result['response_data']?['error'])
                ?.toString()
          },
          where: 'operation_id = ?',
          whereArgs: [item.operationId]);
      await txn.update('debtor_transactions',
          {'sync_status': status == 'applied' ? 'synced' : status},
          where: 'operation_id = ?', whereArgs: [item.operationId]);
      await txn.update(
          'offline_sales',
          {
            'sync_status': status == 'applied' ? 'synced' : status,
            if (status == 'applied')
              'server_sale_id': result['canonical_id']?.toString(),
          },
          where: 'operation_id = ?',
          whereArgs: [item.operationId]);
      if (status == 'applied' && item.operationType == 'debtor.create') {
        final response =
            Map<String, dynamic>.from(result['response_data'] as Map? ?? {});
        final localId = item.envelope?['local_id'] ?? item.payload['local_id'];
        await txn.update(
            'debtors',
            {
              'server_id': result['canonical_id']?.toString(),
              'client_id': response['client_id']?.toString(),
              'revision':
                  int.tryParse(response['revision']?.toString() ?? '') ?? 0,
            },
            where: 'id = ?',
            whereArgs: [localId]);
      }
      if (status == 'applied' && item.operationType == 'client.create') {
        final localId = item.envelope?['local_id'];
        await txn.update(
            'clients', {'server_id': result['canonical_id']?.toString()},
            where: 'id = ?', whereArgs: [localId]);
        await txn.update(
            'debtors', {'client_id': result['canonical_id']?.toString()},
            where: 'client_id = ?', whereArgs: [localId]);
      }
      if (status == 'applied' && item.operationType == 'sale.create') {
        final response =
            Map<String, dynamic>.from(result['response_data'] as Map? ?? {});
        if (response['client_id'] != null && response['debtor_id'] != null) {
          await txn.update(
              'debtors', {'server_id': response['debtor_id'].toString()},
              where: 'client_id = ? AND server_id IS NULL',
              whereArgs: [response['client_id'].toString()]);
        }
      }
      if (status == 'applied' && item.operationType == 'debtor.photo') {
        final id = item.envelope?['entity_id'];
        final rows = await txn.query('debtors',
            where: 'id = ? OR server_id = ?', whereArgs: [id, id]);
        for (final row in rows) {
          await txn.update('debtor_media', {'pending': 0},
              where: 'debtor_id = ?', whereArgs: [row['id']]);
        }
      }
    });
  }

  Future<void> markOperationFailed(String id, String error) async {
    final db = await _getDb();
    await db.update('sync_outbox', {'status': 'failed', 'last_error': error},
        where: 'operation_id = ?', whereArgs: [id]);
  }
}
