import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';
import 'package:sqflite/sqflite.dart';
import '../../../core/api/api_client.dart';
import '../../../core/database/app_database.dart';
import '../../../core/storage/secure_storage.dart';
import '../../../core/printing/print_intent.dart';

class PrintingRepository {
  final ApiClient api;
  final Future<Database> Function() _getDb;
  final DateTime Function() _now;
  PrintingRepository(
      {ApiClient? apiClient,
      Future<Database> Function()? getDb,
      DateTime Function()? now})
      : api = apiClient ?? ApiClient(storage: SecureStorageService()),
        _getDb = getDb ?? AppDatabase.getInstance,
        _now = now ?? DateTime.now;

  Future<Map<String, dynamic>> options(String storeId) async {
    try {
      final data = Map<String, dynamic>.from(
          (await api.get('/stores/$storeId/printing/options')).data['data']
              as Map);
      final db = await _getDb();
      await db.insert(
          'sync_state',
          {
            'key': 'printing_options',
            'value': jsonEncode({...data, 'store_id': storeId})
          },
          conflictAlgorithm: ConflictAlgorithm.replace);
      return data;
    } on DioException catch (e) {
      if ([403, 404].contains(e.response?.statusCode)) {
        final db = await _getDb();
        await db.delete('sync_state',
            where: 'key = ?', whereArgs: ['printing_options']);
      }
      rethrow;
    }
  }

  Future<Map<String, dynamic>> preview(
          String storeId, Map<String, dynamic> payload) async =>
      Map<String, dynamic>.from(
          (await api.post('/stores/$storeId/printing/preview', data: payload))
              .data['data'] as Map);

  Future<Uint8List> pdf(String path) async => Uint8List.fromList(
      (await api.get(path, options: Options(responseType: ResponseType.bytes)))
          .data as List<int>);

  Future<Map<String, dynamic>?> intent(String id) async {
    final db = await _getDb();
    final rows = await db
        .query('sync_state', where: 'key = ?', whereArgs: ['print_intent_$id']);
    return rows.isEmpty
        ? null
        : Map<String, dynamic>.from(
            jsonDecode(rows.first['value'] as String) as Map);
  }

  Future<void> _save(String id, Map<String, dynamic> value) async {
    final db = await _getDb();
    await db.insert(
        'sync_state', {'key': 'print_intent_$id', 'value': jsonEncode(value)},
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<Map<String, dynamic>> submit(
      String storeId, Map<String, dynamic> payload,
      {String? receiptId, String? reprintOf}) async {
    final id = payload['client_job_id'].toString();
    var saved = await intent(id);
    payload = {
      ...payload,
      'requested_at': (saved?['payload'] as Map?)?['requested_at'] ??
          _now().toUtc().toIso8601String()
    };
    if (saved == null) {
      saved = {
        'payload': payload,
        'created_at': _now().toUtc().toIso8601String(),
        'state': 'new',
        'store_id': storeId,
        'receipt_id': receiptId,
        'reprint_of': reprintOf
      };
      final db = await _getDb();
      await db.transaction((txn) async {
        await txn.insert('sync_state',
            {'key': 'print_intent_$id', 'value': jsonEncode(saved)},
            conflictAlgorithm: ConflictAlgorithm.abort);
        if (receiptId != null) {
          await txn.insert(
              'sync_state', {'key': 'print_receipt_$receiptId', 'value': id},
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      });
    } else if (saved['store_id'] != storeId ||
        jsonEncode(saved['payload']) != jsonEncode(payload)) {
      throw StateError('Chop etish so‘rovi ma’lumoti o‘zgargan.');
    }
    return retry(id);
  }

  Future<Map<String, dynamic>> retry(String id) async {
    final saved = await intent(id);
    if (saved == null) throw StateError('Chop etish so‘rovi topilmadi.');
    final storeId = saved['store_id'].toString();
    if (saved['result'] is Map) {
      return status(storeId, (saved['result'] as Map)['id'].toString());
    }
    if (saved['state'] != 'new') {
      try {
        final found = Map<String, dynamic>.from(
            (await api.get('/stores/$storeId/print-jobs/by-client/$id'))
                .data['data'] as Map);
        saved['result'] = found;
        saved['state'] = 'accepted';
        await _save(id, saved);
        return found;
      } on DioException catch (error) {
        if (error.response?.statusCode != 404) rethrow;
      }
    }
    final age = _now()
        .toUtc()
        .difference(DateTime.parse(saved['created_at'].toString()).toUtc());
    if (age >= const Duration(minutes: 10) ||
        age < const Duration(seconds: -5)) {
      saved['state'] = 'expired';
      await _save(id, saved);
      throw StateError('Chop etish muddati tugagan. Yangi tasdiq kerak.');
    }
    saved['state'] = 'attempting';
    await _save(id, saved);
    final parent = saved['reprint_of'];
    final endpoint = parent == null
        ? '/stores/$storeId/print-jobs'
        : '/stores/$storeId/print-jobs/$parent/reprint';
    late final Response response;
    try {
      response = await api.post(endpoint, data: saved['payload']);
    } on DioException catch (error) {
      if (isRejected(error)) {
        saved['state'] = 'rejected';
        await _save(id, saved);
      }
      rethrow;
    }
    final result = Map<String, dynamic>.from(response.data['data'] as Map);
    saved['result'] = result;
    saved['state'] = 'accepted';
    await _save(id, saved);
    return result;
  }

  Future<Map<String, dynamic>?> receiptIntent(String receiptId) async {
    final db = await _getDb();
    final rows = await db.query('sync_state',
        where: 'key = ?', whereArgs: ['print_receipt_$receiptId']);
    return rows.isEmpty ? null : intent(rows.first['value'].toString());
  }

  Future<Map<String, dynamic>> receiptSource(String receiptId) async {
    final db = await _getDb();
    final rows = await db.query('offline_sales',
        where: 'local_sale_id = ?', whereArgs: [receiptId]);
    if (rows.isEmpty) throw StateError('Saqlangan chek topilmadi.');
    String? name;
    final client = rows.first['client_id'];
    if (client != null) {
      final clients =
          await db.query('clients', where: 'id = ?', whereArgs: [client]);
      if (clients.isNotEmpty) name = clients.first['full_name']?.toString();
    }
    return PrintIntent.receiptSource(rows.first, clientName: name);
  }

  Future<Map<String, dynamic>> status(String storeId, String jobId) async =>
      Map<String, dynamic>.from(
          (await api.get('/stores/$storeId/print-jobs/$jobId')).data['data']
              as Map);
  Future<List<Map<String, dynamic>>> history(String storeId) async =>
      ((await api.get('/stores/$storeId/print-jobs')).data['data'] as List)
          .map((e) => Map<String, dynamic>.from(e as Map))
          .toList();
  Future<void> cancel(String storeId, String id) async =>
      api.post('/stores/$storeId/print-jobs/$id/cancel');

  static bool isRejected(Object error) =>
      error is DioException &&
      ([400, 403, 404, 422].contains(error.response?.statusCode) ||
          (error.response?.statusCode == 409 &&
              error.response?.data is Map &&
              error.response?.data['message'] == 'profile_stale'));

  static String error(Object e) {
    if (e is DioException) {
      final data = e.response?.data;
      if (data is Map && data['errors'] is Map) {
        return (data['errors'] as Map)
            .values
            .expand((v) => v is List ? v : [v])
            .join('\n');
      }
      if (data is Map &&
          data['message'] is String &&
          (e.response?.statusCode ?? 500) < 500) {
        return data['message'] == 'profile_stale'
            ? 'Admin qog‘oz profilini o‘zgartirgan. Sozlamalarni yangilab, qayta tasdiqlang.'
            : data['message'] as String;
      }
      return 'Server bilan aloqa yakunlanmadi. So‘rov saqlangan; holatini qayta tekshiring.';
    }
    return e.toString().replaceFirst('Bad state: ', '');
  }

  static String statusLabel(String state) =>
      const {
        'queued': 'Kompyuter yoki printer kutilmoqda',
        'claimed': 'Kompyuter qabul qildi',
        'rendering': 'Hujjat tayyorlanmoqda',
        'submitting': 'Printerga uzatilmoqda',
        'spooled': 'Printer navbatiga yuborildi',
        'completed_reported': 'Driver yakunlangan deb bildirdi',
        'unknown': 'Chiqish holati noma’lum — printerni tekshiring',
        'failed_before_submission': 'Printerga yuborilmadi',
        'expired': '10 daqiqa tugadi — yangi tasdiq kerak',
        'cancelled': 'Bekor qilingan',
      }[state] ??
      state;
}
