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

class _BootstrapIntegrityException implements Exception {
  final String message;
  const _BootstrapIntegrityException(this.message);
}

class SyncCoordinator {
  static const _entityLabels = {
    'products': 'Mahsulotlar',
    'debtors': 'Qarzdorlar',
    'clients': 'Mijozlar',
  };
  final ApiClient apiClient;
  final OutboxManager outboxManager;
  final LeaseManager leaseManager;
  final Future<Database> Function() _getDb;
  final _progressController = StreamController<SyncProgress>.broadcast();
  SyncProgress _currentProgress = const SyncProgress();
  Future<bool>? _running;
  String? _activeStore;
  String _phase = '';
  Future<void>? _mediaTask;
  CancelToken? _mediaCancelToken;
  bool _disposed = false;
  Future<void> get mediaDownloadsComplete => _mediaTask ?? Future<void>.value();
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
    final resuming = await _state('bootstrap') != null;
    try {
      return await _runBootstrap(storeId);
    } on _BootstrapIntegrityException {
      return _runBootstrap(storeId);
    } on DioException catch (error) {
      if (!resuming || ![404, 409, 410].contains(error.response?.statusCode)) {
        rethrow;
      }
      return _runBootstrap(storeId);
    }
  }

  Future<void> _discardBootstrap() async {
    final db = await _getDb();
    await db.transaction((txn) async {
      await txn
          .delete('sync_state', where: 'key = ?', whereArgs: ['bootstrap']);
      await txn.delete('server_cache', where: "entity LIKE 'stage_%'");
    });
  }

  Map<String, int> _manifestCounts(Map<String, dynamic> snapshot) {
    final manifest = snapshot['manifest'];
    final counts = <String, int>{};
    for (final entity in _entityLabels.keys) {
      final count = manifest is Map ? manifest[entity] : null;
      if (count is! int || count < 0) {
        throw _BootstrapIntegrityException(
            'Server mobil API manifesti yaroqsiz: $entity soni berilmagan yoki noto‘g‘ri. Server mobil API’sini yangilang va qayta urinib ko‘ring.');
      }
      counts[entity] = count;
    }
    return counts;
  }

  void _setPhase(String message) {
    _phase = message;
    _update(_currentProgress.copyWith(message: message));
  }

  Future<bool> _runBootstrap(String storeId) async {
    final db = await _getDb();
    Map<String, dynamic> snapshot;
    final saved = await _state('bootstrap');
    if (saved != null) {
      snapshot = jsonDecode(saved) as Map<String, dynamic>;
    } else {
      _setPhase('Serverda ma’lumotlar nusxasi tayyorlanmoqda...');
      snapshot = Map<String, dynamic>.from((await apiClient.post(
              '/stores/$storeId/bootstrap',
              options: Options(receiveTimeout: const Duration(minutes: 2))))
          .data['data'] as Map);
      snapshot['completed'] = <String>[];
      snapshot['cursors'] = <String, dynamic>{};
      await db.transaction((txn) async {
        await txn.delete('server_cache', where: "entity LIKE 'stage_%'");
        await _save('bootstrap', jsonEncode(snapshot), txn);
      });
    }
    try {
      final manifest = _manifestCounts(snapshot);
      for (final entity in _entityLabels.keys) {
        final completed = List<String>.from(snapshot['completed'] as List);
        if (completed.contains(entity)) continue;
        if (manifest[entity] == 0) {
          (snapshot['completed'] as List).add(entity);
          continue;
        }
        var cursor = (snapshot['cursors'] as Map)[entity]?.toString() ?? '0';
        final label = _entityLabels[entity]!;
        var loaded = (await db.query('server_cache',
                columns: ['id'],
                where: 'entity = ?',
                whereArgs: ['stage_$entity']))
            .length;
        _setPhase('$label yuklanmoqda: $loaded / ${manifest[entity]}');
        while (true) {
          final response = await apiClient.get(
              '/stores/$storeId/bootstrap/${snapshot['snapshot_id']}',
              queryParameters: {
                'entity': entity,
                'cursor': cursor,
                'limit': 500
              },
              options: Options(receiveTimeout: const Duration(minutes: 1)));
          final page = Map<String, dynamic>.from(response.data['data'] as Map);
          final items = List<Map<String, dynamic>>.from((page['items'] as List)
              .map((v) => Map<String, dynamic>.from(v as Map)));
          final next = page['next_cursor']?.toString() ?? cursor;
          if (page['has_more'] == true && (next == cursor || items.isEmpty)) {
            throw _BootstrapIntegrityException(
                '$label yuklash kursori siljimadi. Server mobil API’sini tekshiring.');
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
          loaded += items.length;
          _setPhase('$label yuklanmoqda: $loaded / ${manifest[entity]}');
          cursor = next;
          if (page['has_more'] != true) break;
        }
      }
      _setPhase('Ma’lumotlar qurilmaga saqlanmoqda...');
      await db.transaction((txn) async {
        await _save('store_stocks', jsonEncode(snapshot['stocks'] ?? []), txn);
        for (final entity in _entityLabels.keys) {
          final rows = await txn.query('server_cache',
              where: 'entity = ?', whereArgs: ['stage_$entity']);
          final expected = manifest[entity]!;
          if (rows.length != expected) {
            throw _BootstrapIntegrityException(
                '${_entityLabels[entity]}: ${rows.length} / $expected. To‘liq ma’lumot yuklanmagan. Server mobil API’sini tekshirib, qayta yangilang.');
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
    } on _BootstrapIntegrityException {
      await _discardBootstrap();
      rethrow;
    } on DioException catch (error) {
      if ([404, 409, 410].contains(error.response?.statusCode)) {
        await _discardBootstrap();
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
    _update(SyncProgress(
        status: SyncStatus.syncing,
        pendingOperationsCount: _currentProgress.pendingOperationsCount,
        currentWatermark: _currentProgress.currentWatermark,
        lastSyncTime: _currentProgress.lastSyncTime));
    _setPhase('Akkaunt va do‘kon ruxsatlari tekshirilmoqda...');
    try {
      await cancelMediaDownloads();
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
      _setPhase('Saqlangan amallar serverga yuborilmoqda...');
      await pushOutbox(storeId);
      _setPhase('Serverdagi o‘zgarishlar yuklanmoqda...');
      await pullDelta(storeId);
      _setPhase('Oflayn ruxsat yangilanmoqda...');
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
      _startMediaDownload();
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
          lastError: '$_phase\n${_describeError(error)}'));
      return false;
    }
  }

  String _describeError(Object error) {
    if (error is DioException) {
      final status = error.response?.statusCode;
      final endpoint = error.requestOptions.path;
      final reason = switch (status) {
        401 => 'Sessiya tugagan. Akkauntga qayta kiring.',
        403 => 'Bu amal uchun ruxsat yo‘q. Administratorga murojaat qiling.',
        404 =>
          'Server API topilmadi. Server versiyasi va migratsiyalarni tekshiring.',
        409 || 410 => 'Serverdagi yuklash nusxasi eskirgan. Qayta yangilang.',
        429 => 'So‘rovlar limiti tugadi. Birozdan keyin qayta yangilang.',
        int code when code >= 500 =>
          'Serverda xatolik. Server loglari va migratsiyalarni tekshiring.',
        _ => switch (error.type) {
            DioExceptionType.connectionTimeout ||
            DioExceptionType.sendTimeout ||
            DioExceptionType.receiveTimeout =>
              'Server javobini kutish vaqti tugadi. Internetni tekshirib, qayta yangilang.',
            DioExceptionType.badCertificate =>
              'Serverning HTTPS sertifikati tekshirilmadi.',
            DioExceptionType.connectionError =>
              'Serverga ulanib bo‘lmadi. Internet va server manzilini tekshiring.',
            _ => 'Serverdan kutilgan javob olinmadi.',
          },
      };
      return '$reason\n${status == null ? '' : 'HTTP $status: '}$endpoint';
    }
    if (error is DatabaseException) {
      return 'Qurilma bazasiga ma’lumotni saqlab bo‘lmadi. Bo‘sh xotirani tekshiring; ilova ma’lumotlarini o‘chirmang.';
    }
    if (error is _BootstrapIntegrityException) return error.message;
    if (error is StateError) return error.message.toString();
    return 'Qurilmada ma’lumotlarni qayta ishlash xatosi (${error.runtimeType}). Ilovani yangilab, qayta urinib ko‘ring.';
  }

  void _startMediaDownload() {
    if (_disposed || _mediaTask != null) return;
    final cancelToken = CancelToken();
    _mediaCancelToken = cancelToken;
    _mediaTask = _downloadMedia(cancelToken).catchError((Object error) async {
      if (!_disposed &&
          error is DioException &&
          [401, 403].contains(error.response?.statusCode)) {
        await leaseManager.storage.clearLease();
        await leaseManager.checkLease();
        _update(_currentProgress.copyWith(
            status: SyncStatus.error,
            message: 'Server ruxsatini qayta tekshiring.',
            lastError: _describeError(error)));
      }
    }).whenComplete(() {
      _mediaTask = null;
      _mediaCancelToken = null;
    });
  }

  Future<void> cancelMediaDownloads() async {
    _mediaCancelToken?.cancel();
    await _mediaTask;
  }

  Future<void> _downloadMedia(CancelToken cancelToken) async {
    final db = await _getDb();
    if (cancelToken.isCancelled || _disposed) return;
    final debtors = await db.rawQuery('''
      SELECT d.id, d.photo_path, m.pending, m.bytes IS NOT NULL AS has_bytes,
        s.value AS cached_source
      FROM debtors d
      LEFT JOIN debtor_media m ON m.debtor_id = d.id
      LEFT JOIN sync_state s ON s.key = 'debtor_photo_' || d.id
      WHERE COALESCE(m.pending, 0) = 0 AND (
        (d.photo_path IS NULL AND (m.debtor_id IS NOT NULL OR s.key IS NOT NULL))
        OR (d.photo_path LIKE '/api/flutter/v1/stores/%' AND
          (m.bytes IS NULL OR s.value IS NULL OR s.value != d.photo_path))
      )
    ''');
    var next = 0;
    Future<void> worker() async {
      while (!cancelToken.isCancelled && !_disposed && next < debtors.length) {
        final debtor = debtors[next++];
        if (debtor['pending'] == 1) continue;
        final id = debtor['id'] as String;
        final path = debtor['photo_path'] as String?;
        if (path == null) {
          await db.transaction((txn) async {
            final current = await txn.query('debtors',
                where: 'id = ? AND photo_path IS NULL', whereArgs: [id]);
            if (current.isEmpty) return;
            await txn.delete('debtor_media',
                where: 'debtor_id = ? AND pending = 0', whereArgs: [id]);
            await txn.delete('sync_state',
                where: 'key = ?', whereArgs: ['debtor_photo_$id']);
          });
          AppDatabase.notifyMediaChanged(id);
          continue;
        }
        if (!path.startsWith('/api/flutter/v1/stores/') ||
            (debtor['has_bytes'] == 1 && debtor['cached_source'] == path)) {
          continue;
        }
        try {
          final response = await apiClient.get(
              path.substring('/api/flutter/v1'.length),
              options: Options(
                  responseType: ResponseType.bytes,
                  receiveTimeout: const Duration(seconds: 10)),
              cancelToken: cancelToken);
          if (cancelToken.isCancelled || _disposed) return;
          final bytes = response.data as List<int>;
          if (bytes.isEmpty || bytes.length > 10 * 1024 * 1024) continue;
          await db.transaction((txn) async {
            final current = await txn.query('debtors',
                where: 'id = ? AND photo_path = ?', whereArgs: [id, path]);
            final pending = await txn.query('debtor_media',
                where: 'debtor_id = ? AND pending = 1', whereArgs: [id]);
            if (current.isEmpty || pending.isNotEmpty) return;
            await txn.insert(
                'debtor_media',
                {
                  'debtor_id': id,
                  'bytes': Uint8List.fromList(bytes),
                  'pending': 0
                },
                conflictAlgorithm: ConflictAlgorithm.replace);
            await _save('debtor_photo_$id', path, txn);
          });
          AppDatabase.notifyMediaChanged(id);
        } on DioException catch (error) {
          if ([401, 403].contains(error.response?.statusCode)) {
            cancelToken.cancel();
            rethrow;
          }
        }
      }
    }

    await Future.wait(List.generate(3, (_) => worker()));
  }

  void dispose() {
    _disposed = true;
    _mediaCancelToken?.cancel();
    _progressController.close();
  }
}
