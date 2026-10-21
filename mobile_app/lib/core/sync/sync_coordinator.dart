import 'dart:async';
import 'package:sqflite/sqflite.dart';
import '../api/api_client.dart';
import '../database/app_database.dart';
import '../lease/lease_manager.dart';
import 'outbox_manager.dart';
import 'sync_models.dart';

class SyncCoordinator {
  final ApiClient apiClient;
  final OutboxManager outboxManager;
  final LeaseManager leaseManager;
  final Future<Database> Function() _getDb;

  final StreamController<SyncProgress> _progressController =
      StreamController<SyncProgress>.broadcast();

  SyncProgress _currentProgress = const SyncProgress();
  SyncProgress get currentProgress => _currentProgress;
  Stream<SyncProgress> get progressStream => _progressController.stream;

  SyncCoordinator({
    required this.apiClient,
    required this.outboxManager,
    required this.leaseManager,
    Future<Database> Function()? getDb,
  }) : _getDb = getDb ?? AppDatabase.getInstance;

  void _updateProgress(SyncProgress progress) {
    _currentProgress = progress;
    _progressController.add(progress);
  }

  Future<int?> getSavedWatermark() async {
    final db = await _getDb();
    final rows = await db.query(
      'sync_state',
      where: 'key = ?',
      whereArgs: ['watermark'],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    return int.tryParse(rows.first['value'] as String);
  }

  Future<void> saveWatermark(int watermark) async {
    final db = await _getDb();
    await db.insert(
      'sync_state',
      {'key': 'watermark', 'value': watermark.toString()},
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  /// 1. Bootstrap: full data download for initial setup or cache reset
  Future<bool> runBootstrap(String storeId) async {
    _updateProgress(_currentProgress.copyWith(
      status: SyncStatus.syncing,
      message: "Serverdan dastlabki ma'lumotlar yuklanmoqda...",
    ));

    try {
      final response = await apiClient.post('/stores/$storeId/bootstrap');
      if (response.statusCode != 200 && response.statusCode != 201) {
        throw Exception("Bootstrap init xatosi: ${response.statusCode}");
      }

      final data = response.data['data'] as Map<String, dynamic>;
      final snapshotId = data['snapshot_id'].toString();
      final watermark = int.tryParse(data['watermark']?.toString() ?? '0') ?? 0;

      // Download paginated products
      await _downloadEntityPages(storeId, snapshotId, 'products');

      // Download paginated debtors
      await _downloadEntityPages(storeId, snapshotId, 'debtors');

      // Save watermark
      await saveWatermark(watermark);

      _updateProgress(_currentProgress.copyWith(
        status: SyncStatus.offlineReady,
        message: "Oflayn ma'lumotlar muvaffaqiyatli saqlandi",
        currentWatermark: watermark,
        lastSyncTime: DateTime.now(),
      ));

      return true;
    } catch (e) {
      _updateProgress(_currentProgress.copyWith(
        status: SyncStatus.error,
        message: "Bootstrap xatosi yuz berdi",
        lastError: e.toString(),
      ));
      return false;
    }
  }

  Future<void> _downloadEntityPages(String storeId, String snapshotId, String entity) async {
    int cursor = 0;
    bool hasMore = true;
    final db = await _getDb();

    while (hasMore) {
      final res = await apiClient.get(
        '/stores/$storeId/bootstrap/$snapshotId',
        queryParameters: {'entity': entity, 'cursor': cursor, 'limit': 100},
      );

      if (res.statusCode != 200) break;
      final payload = res.data['data'] is Map<String, dynamic>
          ? res.data['data'] as Map<String, dynamic>
          : <String, dynamic>{};

      final items = (payload['items'] as List? ?? res.data['data'] as List? ?? []);
      if (items.isEmpty) break;

      final batch = db.batch();
      for (final raw in items) {
        final item = raw as Map<String, dynamic>;
        if (entity == 'products') {
          final prodId = item['id'].toString();
          final stockQty = (item['quantity'] as num?)?.toInt() ?? 0;
          batch.insert(
            'products',
            {
              'id': prodId,
              'store_id': storeId,
              'name': item['name'] ?? '',
              'barcode': item['barcode']?.toString(),
              'type': item['type'] ?? 'package',
              'price': double.tryParse(item['price']?.toString() ?? '0') ?? 0.0,
              'initial_price': double.tryParse(item['initial_price']?.toString() ?? '0'),
              'updated_at': DateTime.now().toIso8601String(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
          batch.insert(
            'product_stocks',
            {
              'product_id': prodId,
              'stock_id': 'default',
              'quantity': stockQty,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        } else if (entity == 'debtors') {
          batch.insert(
            'debtors',
            {
              'id': item['id'].toString(),
              'store_id': storeId,
              'client_id': item['client_id']?.toString(),
              'full_name': item['full_name'] ?? item['name'] ?? '',
              'phone': item['phone']?.toString(),
              'amount': double.tryParse(item['amount']?.toString() ?? '0') ?? 0.0,
              'currency': item['currency'] ?? 'UZS',
              'date': item['date']?.toString(),
              'note': item['note']?.toString(),
              'photo_path': (item['photo_url'] ?? item['photo_path'])?.toString(),
              'updated_at': DateTime.now().toIso8601String(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      }
      await batch.commit(noResult: true);

      final nextCursorStr = payload['next_cursor']?.toString();
      hasMore = payload['has_more'] == true && nextCursorStr != null;
      if (nextCursorStr != null) {
        cursor = int.tryParse(nextCursorStr) ?? cursor;
      }
    }
  }

  /// 2. Push Outbox: send pending operations to server
  Future<int> pushOutbox(String storeId) async {
    final pending = await outboxManager.getPendingOperations();
    if (pending.isEmpty) return 0;

    int appliedCount = 0;
    for (final op in pending) {
      try {
        final response = await apiClient.post(
          '/stores/$storeId/operations',
          data: {
            'operations': [
              {
                'operation_id': op.operationId,
                'entity_type': op.entityType,
                'operation_type': op.operationType,
                'payload': op.payload,
              }
            ],
          },
        );

        if (response.statusCode == 200 || response.statusCode == 201) {
          final results = response.data['data']?['results'] as List? ?? [];
          final first = results.isNotEmpty ? results.first as Map<String, dynamic> : null;
          final status = first?['status'] as String?;
          if (status == 'applied' || status == 'conflict') {
            await outboxManager.markOperationApplied(op.operationId);
            appliedCount++;
          } else {
            await outboxManager.markOperationFailed(
              op.operationId,
              first?['error']?.toString() ?? "Status: $status",
            );
          }
        } else {
          await outboxManager.markOperationFailed(
            op.operationId,
            "Server kodi: ${response.statusCode}",
          );
        }
      } catch (e) {
        await outboxManager.markOperationFailed(op.operationId, e.toString());
        break; // Stop remaining batch on network disconnection
      }
    }

    final remaining = await outboxManager.getPendingCount();
    _updateProgress(_currentProgress.copyWith(
      pendingOperationsCount: remaining,
    ));

    return appliedCount;
  }

  /// 3. Delta pull: get changes since last watermark
  Future<int> pullDelta(String storeId) async {
    final watermark = await getSavedWatermark() ?? 0;
    try {
      final response = await apiClient.get(
        '/stores/$storeId/delta',
        queryParameters: {'cursor': watermark},
      );

      if (response.statusCode != 200) return 0;
      final revisions = response.data['data']?['changes'] as List?
          ?? response.data['data'] as List?
          ?? [];
      final newWatermarkRaw = response.data['data']?['next_cursor'] ?? response.data['watermark'];
      final newWatermark = int.tryParse(newWatermarkRaw?.toString() ?? '') ?? watermark;

      if (revisions.isNotEmpty) {
        final db = await _getDb();
        final batch = db.batch();

        for (final raw in revisions) {
          final rev = raw as Map<String, dynamic>;
          final entityType = rev['entity_type'] as String?;
          final action = rev['action'] as String?;
          final payload = rev['payload'] as Map<String, dynamic>? ?? {};
          final entityId = rev['entity_id']?.toString() ?? payload['id']?.toString();

          if (entityId == null) continue;

          if (action == 'delete') {
            if (entityType == 'product') {
              batch.delete('products', where: 'id = ?', whereArgs: [entityId]);
            } else if (entityType == 'debtor') {
              batch.delete('debtors', where: 'id = ?', whereArgs: [entityId]);
            }
          } else {
            // upsert
            if (entityType == 'product') {
              batch.insert(
                'products',
                {
                  'id': entityId,
                  'store_id': storeId,
                  'name': payload['name'] ?? '',
                  'barcode': payload['barcode']?.toString(),
                  'type': payload['type'] ?? 'package',
                  'price': double.tryParse(payload['price']?.toString() ?? '0') ?? 0.0,
                  'initial_price': double.tryParse(payload['initial_price']?.toString() ?? '0'),
                  'updated_at': payload['updated_at']?.toString(),
                },
                conflictAlgorithm: ConflictAlgorithm.replace,
              );
            } else if (entityType == 'debtor') {
              batch.insert(
                'debtors',
                {
                  'id': entityId,
                  'store_id': storeId,
                  'client_id': payload['client_id']?.toString(),
                  'full_name': payload['full_name'] ?? payload['name'] ?? '',
                  'phone': payload['phone']?.toString(),
                  'amount': double.tryParse(payload['amount']?.toString() ?? '0') ?? 0.0,
                  'currency': payload['currency'] ?? 'UZS',
                  'date': payload['date']?.toString(),
                  'note': payload['note']?.toString(),
                  'photo_path': payload['photo_path']?.toString(),
                  'updated_at': payload['updated_at']?.toString(),
                },
                conflictAlgorithm: ConflictAlgorithm.replace,
              );
            }
          }
        }
        await batch.commit(noResult: true);
      }

      await saveWatermark(newWatermark);
      _updateProgress(_currentProgress.copyWith(
        currentWatermark: newWatermark,
        lastSyncTime: DateTime.now(),
      ));

      return revisions.length;
    } catch (_) {
      return 0;
    }
  }

  /// 4. Full sync workflow: push outbox -> pull delta (or bootstrap) -> refresh lease
  Future<void> syncAll(String storeId) async {
    _updateProgress(_currentProgress.copyWith(
      status: SyncStatus.syncing,
      message: "Sinxronizatsiya boshlanmoqda...",
    ));

    // 1. Push local operations
    await pushOutbox(storeId);

    // 2. Pull remote delta changes
    final watermark = await getSavedWatermark();
    if (watermark == null) {
      await runBootstrap(storeId);
    } else {
      await pullDelta(storeId);
    }

    // 3. Renew offline lease from server
    try {
      final leaseRes = await apiClient.post('/stores/$storeId/lease');
      if (leaseRes.statusCode == 200 || leaseRes.statusCode == 201) {
        final expiresAt = leaseRes.data['data']?['expires_at'] as String?;
        if (expiresAt != null) {
          await leaseManager.updateLease(expiresAt);
        }
      }
    } catch (_) {}

    _updateProgress(_currentProgress.copyWith(
      status: SyncStatus.offlineReady,
      message: "Sinxronizatsiya muvaffaqiyatli yakunlandi",
      lastSyncTime: DateTime.now(),
    ));
  }
}
