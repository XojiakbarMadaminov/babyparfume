import 'dart:convert';
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';
import '../database/app_database.dart';
import 'sync_models.dart';

class OutboxManager {
  final Future<Database> Function() _getDb;

  OutboxManager({Future<Database> Function()? getDb})
      : _getDb = getDb ?? AppDatabase.getInstance;

  Future<String> enqueueOperation({
    required String entityType,
    required String operationType,
    required Map<String, dynamic> payload,
    String? customOperationId,
  }) async {
    final db = await _getDb();
    final operationId = customOperationId ?? const Uuid().v4();
    final now = DateTime.now().toIso8601String();

    await db.insert(
      'sync_outbox',
      {
        'operation_id': operationId,
        'entity_type': entityType,
        'operation_type': operationType,
        'payload_json': jsonEncode(payload),
        'status': 'pending',
        'created_at': now,
        'last_error': null,
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );

    return operationId;
  }

  Future<List<OutboxItem>> getPendingOperations() async {
    final db = await _getDb();
    final rows = await db.query(
      'sync_outbox',
      where: 'status = ?',
      whereArgs: ['pending'],
      orderBy: 'created_at ASC',
    );

    return rows.map((row) {
      return OutboxItem(
        operationId: row['operation_id'] as String,
        entityType: row['entity_type'] as String,
        operationType: row['operation_type'] as String,
        payload: jsonDecode(row['payload_json'] as String) as Map<String, dynamic>,
        status: row['status'] as String,
        createdAt: DateTime.parse(row['created_at'] as String),
        lastError: row['last_error'] as String?,
      );
    }).toList();
  }

  Future<int> getPendingCount() async {
    final db = await _getDb();
    final result = await db.rawQuery(
      'SELECT COUNT(*) as count FROM sync_outbox WHERE status = ?',
      ['pending'],
    );
    return Sqflite.firstIntValue(result) ?? 0;
  }

  Future<void> markOperationApplied(String operationId) async {
    final db = await _getDb();
    await db.delete(
      'sync_outbox',
      where: 'operation_id = ?',
      whereArgs: [operationId],
    );
  }

  Future<void> markOperationFailed(String operationId, String error) async {
    final db = await _getDb();
    await db.update(
      'sync_outbox',
      {
        'status': 'failed',
        'last_error': error,
      },
      where: 'operation_id = ?',
      whereArgs: [operationId],
    );
  }
}
