import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';
import 'package:sqflite/sqflite.dart';
import '../api/api_client.dart';
import '../database/app_database.dart';
import '../database/local_cache.dart';
import '../lease/lease_manager.dart';
import 'outbox_manager.dart';
import 'sync_models.dart';

class SyncCoordinator {
  final ApiClient apiClient;
  final OutboxManager outboxManager;
  final LeaseManager leaseManager;
  final Future<Database> Function() _getDb;
  final _progressController = StreamController<SyncProgress>.broadcast();
  SyncProgress _currentProgress = const SyncProgress();
  Future<bool>? _running;
  String? _activeStore;
  SyncProgress get currentProgress => _currentProgress;
  Stream<SyncProgress> get progressStream => _progressController.stream;
  SyncCoordinator(
      {required this.apiClient,
      required this.outboxManager,
      required this.leaseManager,
      Future<Database> Function()? getDb})
      : _getDb = getDb ?? AppDatabase.getInstance;

  void _update(SyncProgress progress) {
    _currentProgress = progress;
    if (!_progressController.isClosed) _progressController.add(progress);
  }

  Future<String?> _state(String key) async {
    final rows = await (await _getDb())
        .query('sync_state', where: 'key = ?', whereArgs: [key]);
    return rows.isEmpty ? null : rows.first['value'] as String;
  }

  Future<void> _save(String key, String value,
          [DatabaseExecutor? executor]) async =>
      (executor ?? await _getDb()).insert(
          'sync_state', {'key': key, 'value': value},
          conflictAlgorithm: ConflictAlgorithm.replace);
  Future<int?> getSavedWatermark() async =>
      int.tryParse(await _state('watermark') ?? '');
  Future<void> saveWatermark(int value) => _save('watermark', value.toString());

  Future<bool> runBootstrap(String storeId) async {
    final db = await _getDb();
    Map<String, dynamic> snapshot;
    final saved = await _state('bootstrap');
    if (saved != null) {
      snapshot = jsonDecode(saved) as Map<String, dynamic>;
    } else {
      snapshot = Map<String, dynamic>.from(
          (await apiClient.post('/stores/$storeId/bootstrap')).data['data']
              as Map);
      snapshot['completed'] = <String>[];
      snapshot['cursors'] = <String, dynamic>{};
      await db.transaction((txn) async {
        await txn.delete('server_cache', where: "entity LIKE 'stage_%'");
        await _save('bootstrap', jsonEncode(snapshot), txn);
        await _save('store_stocks', jsonEncode(snapshot['stocks'] ?? []), txn);
      });
    }
    try {
      for (final entity in ['products', 'debtors', 'clients']) {
        final completed = List<String>.from(snapshot['completed'] as List);
        if (completed.contains(entity)) continue;
        if ((snapshot['manifest'] as Map)[entity] == 0 ||
            (snapshot['manifest'] as Map)[entity] == null) {
          (snapshot['completed'] as List).add(entity);
          continue;
        }
        var cursor = (snapshot['cursors'] as Map)[entity]?.toString() ?? '0';
        while (true) {
          final response = await apiClient.get(
              '/stores/$storeId/bootstrap/${snapshot['snapshot_id']}',
              queryParameters: {
                'entity': entity,
                'cursor': cursor,
                'limit': 500
              });
          final page = Map<String, dynamic>.from(response.data['data'] as Map);
          final items = List<Map<String, dynamic>>.from((page['items'] as List)
              .map((v) => Map<String, dynamic>.from(v as Map)));
          final next = page['next_cursor']?.toString() ?? cursor;
          if (page['has_more'] == true && (next == cursor || items.isEmpty)) {
            throw StateError('Yuklash kursori siljimadi.');
          }
          await db.transaction((txn) async {
            for (final item in items) {
              await txn.insert(
                  'server_cache',
                  {
                    'entity': 'stage_$entity',
                    'id': item['id'].toString(),
                    'payload_json': jsonEncode(item)
                  },
                  conflictAlgorithm: ConflictAlgorithm.replace);
            }
            (snapshot['cursors'] as Map)[entity] = next;
            if (page['has_more'] != true) {
              (snapshot['completed'] as List).add(entity);
            }
            await _save('bootstrap', jsonEncode(snapshot), txn);
          });
          cursor = next;
          if (page['has_more'] != true) break;
        }
      }
      await db.transaction((txn) async {
        for (final entity in ['products', 'debtors', 'clients']) {
          final rows = await txn.query('server_cache',
              where: 'entity = ?', whereArgs: ['stage_$entity']);
          final expected = (snapshot['manifest'] as Map)[entity];
          if (expected is! num || rows.length != expected.toInt()) {
            throw StateError('To‘liq ma’lumot yuklanmagan.');
          }
          await txn
              .delete('server_cache', where: 'entity = ?', whereArgs: [entity]);
          for (final row in rows) {
            final item = jsonDecode(row['payload_json'] as String)
                as Map<String, dynamic>;
            await txn.insert('server_cache', {...row, 'entity': entity},
                conflictAlgorithm: ConflictAlgorithm.replace);
            if (entity == 'products') {
              await LocalCache.product(txn, storeId, item);
            } else if (entity == 'clients') {
              await LocalCache.client(txn, storeId, item);
            } else {
              await LocalCache.debtor(txn, storeId, item);
            }
          }
        }
        final staleProducts = await txn.rawQuery(
            "SELECT id FROM products WHERE id NOT IN (SELECT id FROM server_cache WHERE entity = 'products')");
        for (final product in staleProducts) {
          await txn.delete('product_stocks',
              where: 'product_id = ?', whereArgs: [product['id']]);
          await txn
              .delete('products', where: 'id = ?', whereArgs: [product['id']]);
        }
        for (final entity in ['debtors', 'clients']) {
          final stale = await txn.rawQuery(
              'SELECT server_id FROM $entity WHERE server_id IS NOT NULL AND server_id NOT IN (SELECT id FROM server_cache WHERE entity = ?)',
              [entity]);
          for (final row in stale) {
            await LocalCache.removeCanonical(
                txn, entity, row['server_id'] as String);
          }
        }
        await _save('watermark', snapshot['watermark'].toString(), txn);
        await _save('offline_ready', '1', txn);
        await txn
            .delete('sync_state', where: 'key = ?', whereArgs: ['bootstrap']);
        await txn.delete('server_cache', where: "entity LIKE 'stage_%'");
      });
      return true;
    } on DioException catch (error) {
      if ([404, 409, 410].contains(error.response?.statusCode)) {
        await db
            .delete('sync_state', where: 'key = ?', whereArgs: ['bootstrap']);
      }
      rethrow;
    }
  }

  Future<int> pushOutbox(String storeId) async {
    var applied = 0;
    final all = await outboxManager.getPendingOperations(
        storeId: storeId, includeAll: true);
    for (final item in all.where(
        (item) => item.status == 'conflict' || item.status == 'rejected')) {
      try {
        final response = await apiClient
            .get('/stores/$storeId/operations/${item.operationId}');
        await outboxManager.acknowledge(
            item, Map<String, dynamic>.from(response.data['data'] as Map));
      } on DioException catch (error) {
        if (error.response?.statusCode != 404) rethrow;
      }
    }
    for (final item
        in await outboxManager.getPendingOperations(storeId: storeId)) {
      if (item.envelope == null) {
        throw StateError('Eski amalni avtomatik yuborish xavfsiz emas.');
      }
      try {
        final response =
            await apiClient.post('/stores/$storeId/operations', data: {
          'schema_version': 1,
          'operations': [item.envelope]
        });
        final result = Map<String, dynamic>.from(
            (response.data['data']['results'] as List).single as Map);
        if (result['operation_id'] != item.operationId) {
          throw StateError('Server boshqa amalni tasdiqladi.');
        }
        await outboxManager.acknowledge(item, result);
        if (result['status'] == 'applied') applied++;
      } catch (_) {
        await outboxManager.markOperationFailed(item.operationId,
            'Server javobi olinmadi. Xuddi shu amal qayta tekshiriladi.');
        rethrow;
      }
    }
    return applied;
  }

  Future<int> pullDelta(String storeId) async {
    final db = await _getDb();
    var cursor = await getSavedWatermark() ?? 0;
    var count = 0;
    while (true) {
      final response = await apiClient.get('/stores/$storeId/delta',
          queryParameters: {'cursor': cursor, 'limit': 500});
      final data = Map<String, dynamic>.from(response.data['data'] as Map);
      final changes = List<Map<String, dynamic>>.from((data['changes'] as List)
          .map((v) => Map<String, dynamic>.from(v as Map)));
      final next = int.parse(data['next_cursor'].toString());
      if (next < cursor || (data['has_more'] == true && next == cursor)) {
        throw StateError('Server kursori yaroqsiz.');
      }
      await db.transaction((txn) async {
        for (final change in changes) {
          final entity = change['entity'];
          if (!['product', 'debtor', 'client'].contains(entity)) continue;
          final table = entity == 'product'
              ? 'products'
              : entity == 'client'
                  ? 'clients'
                  : 'debtors';
          final id = change['id'].toString();
          if (change['action'] == 'delete') {
            await txn.delete('server_cache',
                where: 'entity = ? AND id = ?', whereArgs: [table, id]);
            if (entity == 'product') {
              await txn.delete('products', where: 'id = ?', whereArgs: [id]);
              await txn.delete('product_stocks',
                  where: 'product_id = ?', whereArgs: [id]);
            } else if (entity == 'client') {
              await LocalCache.removeCanonical(txn, table, id);
            } else {
              await LocalCache.removeCanonical(txn, table, id);
            }
          } else {
            final value = Map<String, dynamic>.from(change['value'] as Map);
            await txn.insert('server_cache',
                {'entity': table, 'id': id, 'payload_json': jsonEncode(value)},
                conflictAlgorithm: ConflictAlgorithm.replace);
            if (entity == 'product') {
              await LocalCache.product(txn, storeId, value);
            } else if (entity == 'client') {
              await LocalCache.client(txn, storeId, value);
            } else {
              await LocalCache.debtor(txn, storeId, value);
            }
          }
        }
        await _save('watermark', next.toString(), txn);
      });
      count += changes.length;
      cursor = next;
      if (data['has_more'] != true) break;
    }
    return count;
  }

  Future<bool> syncAll(String storeId) {
    if (_running != null && _activeStore != storeId) {
      throw StateError('Avval joriy sinxronlash yakunlansin.');
    }
    _activeStore = storeId;
    return _running ??= _sync(storeId).whenComplete(() {
      _running = null;
      _activeStore = null;
    });
  }

  Future<bool> _sync(String storeId) async {
    _update(_currentProgress.copyWith(
        status: SyncStatus.syncing, message: 'Ma’lumotlar sinxronlanmoqda...'));
    try {
      final session = Map<String, dynamic>.from(
          (await apiClient.get('/stores/$storeId/session')).data['data']
              as Map);
      if (session['user']['id'].toString() !=
          await apiClient.storage.getUserId()) {
        throw StateError('Akkaunt mos emas.');
      }
      await apiClient.storage.setSession(session);
      final oldPermissions = await _state('permission_hash');
      final newPermissions = session['permission_hash']?.toString();
      if (oldPermissions != null &&
          newPermissions != null &&
          oldPermissions != newPermissions) {
        final db = await _getDb();
        await db.transaction((txn) async {
          await txn.update('products', {'initial_price': null});
          await txn.update('debtors', {'can_update': 0});
          await txn.delete('server_cache');
          await txn.delete('sync_state',
              where:
                  "key IN ('watermark', 'bootstrap', 'offline_ready') OR key LIKE 'statistics_%'");
        });
      }
      if (await getSavedWatermark() == null) await runBootstrap(storeId);
      if (newPermissions != null) {
        await _save('permission_hash', newPermissions);
      }
      await pushOutbox(storeId);
      await pullDelta(storeId);
      await _downloadMedia();
      final requestClock = await leaseManager.readClock();
      final lease = Map<String, dynamic>.from(
          (await apiClient.post('/stores/$storeId/lease')).data['data'] as Map);
      await leaseManager.updateLease(lease, requestClock: requestClock);
      final pending = await outboxManager.getPendingCount();
      _update(_currentProgress.copyWith(
          status: pending == 0
              ? SyncStatus.offlineReady
              : SyncStatus.hasPendingOperations,
          pendingOperationsCount: pending,
          message: pending == 0
              ? 'Ma’lumotlar yangilandi.'
              : 'Tasdiqlanmagan amallar mavjud.',
          currentWatermark: await getSavedWatermark(),
          lastSyncTime: DateTime.now()));
      return true;
    } catch (error) {
      if (error is DioException &&
          [401, 403].contains(error.response?.statusCode)) {
        await leaseManager.storage.clearLease();
        await leaseManager.checkLease();
      }
      _update(_currentProgress.copyWith(
          status: SyncStatus.error,
          message: 'Sinxronlash yakunlanmadi. Navbat saqlangan.',
          lastError: 'Internet va akkaunt ruxsatlarini tekshiring.'));
      return false;
    }
  }

  Future<void> _downloadMedia() async {
    final db = await _getDb();
    for (final debtor in await db.query('debtors')) {
      final media = await db.query('debtor_media',
          where: 'debtor_id = ?', whereArgs: [debtor['id']]);
      if (media.isNotEmpty && media.first['pending'] == 1) continue;
      final path = debtor['photo_path'] as String?;
      if (path == null) {
        await db.delete('debtor_media',
            where: 'debtor_id = ?', whereArgs: [debtor['id']]);
      } else if (path.startsWith('/api/flutter/v1/stores/')) {
        try {
          final response = await apiClient.get(
              path.substring('/api/flutter/v1'.length),
              options: Options(responseType: ResponseType.bytes));
          final bytes = response.data as List<int>;
          if (bytes.length <= 10 * 1024 * 1024) {
            await db.insert(
                'debtor_media',
                {
                  'debtor_id': debtor['id'],
                  'bytes': Uint8List.fromList(bytes),
                  'pending': 0
                },
                conflictAlgorithm: ConflictAlgorithm.replace);
          }
        } on DioException catch (error) {
          if ([401, 403].contains(error.response?.statusCode)) rethrow;
        }
      }
    }
  }

  void dispose() {
    _progressController.close();
  }
}
