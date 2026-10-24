import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:babyparfume_mobile/core/api/api_client.dart';
import 'package:babyparfume_mobile/core/storage/secure_storage.dart';
import 'package:babyparfume_mobile/features/statistics/data/statistics_repository.dart';

class StatisticsApi extends ApiClient {
  Map<String, dynamic> data = {};
  DioException? error;
  String? path;
  Map<String, dynamic>? parameters;
  StatisticsApi() : super(storage: SecureStorageService());

  @override
  Future<Response> get(String path,
      {Map<String, dynamic>? queryParameters,
      Options? options,
      CancelToken? cancelToken}) async {
    this.path = path;
    parameters = queryParameters;
    if (error != null) throw error!;
    return Response(
        requestOptions: RequestOptions(path: path), data: {'data': data});
  }
}

Map<String, dynamic> statisticsData(StatisticsFilter filter,
        {String store = '1'}) =>
    {
      'store_id': store,
      ...filter.parameters,
      'sales_amount': '9000',
      'sales_count': 2,
      'average_receipt': '5000',
      'profit': '2500',
      'change_percent': -10,
      'trend': [
        {'label': '12:00', 'amount': 10000}
      ],
      'payments': [
        {'key': 'cash', 'name': 'Naqd', 'amount': '7500'},
        {'key': 'card', 'name': 'Karta', 'amount': '2500'}
      ],
      'top_products': [
        {'id': '11', 'name': 'Bahor atiri', 'quantity': 2, 'amount': '10000'}
      ],
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  sqfliteFfiInit();
  final now = DateTime.utc(2026, 10, 23, 12);
  final filter = StatisticsFilter.forPeriod('today', now);
  late Database db;
  late StatisticsApi api;
  late StatisticsRepository repository;

  setUp(() async {
    db = await databaseFactoryFfi.openDatabase(inMemoryDatabasePath);
    await db
        .execute('CREATE TABLE sync_state (key TEXT PRIMARY KEY, value TEXT)');
    await db.execute(
        'CREATE TABLE offline_sales (id TEXT PRIMARY KEY, store_id TEXT, sync_status TEXT, payment_type TEXT, occurred_at TEXT, total_amount TEXT)');
    api = StatisticsApi()..data = statisticsData(filter);
    repository = StatisticsRepository(
        apiClient: api, getDb: () async => db, now: () => now);
  });
  tearDown(() async => db.close());

  test('periods use the Tashkent day, Monday week and first day of month', () {
    final sundayUtc = DateTime.utc(2026, 11, 1, 20);
    expect(StatisticsFilter.forPeriod('today', sundayUtc).parameters['start'],
        '2026-11-02');
    expect(StatisticsFilter.forPeriod('week', sundayUtc).parameters['start'],
        '2026-11-02');
    expect(StatisticsFilter.forPeriod('month', sundayUtc).parameters['start'],
        '2026-11-01');
    expect(StatisticsFilter.forPeriod('week', now).parameters['start'],
        '2026-10-19');
  });

  test('keeps server totals and gross payment percentages despite net returns',
      () async {
    final result = await repository.load('1', filter);
    expect(api.path, '/stores/1/statistics');
    expect(api.parameters, filter.parameters);
    expect(result.report!.salesAmount, 9000);
    expect(result.report!.payments.map((p) => p.percent), [75, 25]);
    expect(result.report!.products.single.id, '11');
    expect(result.cachedAt, isNull);
  });

  test('honors server payment percentages instead of recalculating a subset',
      () async {
    api.data['payments'] = [
      {'key': 'cash', 'name': 'Naqd', 'amount': '7500', 'percent': 60}
    ];
    expect((await repository.load('1', filter)).report!.payments.single.percent,
        60);
  });

  test('network failure reuses only the exact store and period cached report',
      () async {
    await repository.load('1', filter);
    api.error = DioException(
        requestOptions: RequestOptions(path: '/statistics'),
        type: DioExceptionType.connectionError);
    final cached = await repository.load('1', filter);
    expect(cached.cachedAt, now);
    expect(cached.report!.salesAmount, 9000);
    expect((await repository.load('2', filter)).report, isNull);
    expect(
        (await repository.load('1', StatisticsFilter.forPeriod('week', now)))
            .report,
        isNull);
  });

  test(
      'denied requests invalidate cached data instead of showing protected figures',
      () async {
    await repository.load('1', filter);
    final options = RequestOptions(path: '/statistics');
    api.error = DioException(
        requestOptions: options,
        response: Response(requestOptions: options, statusCode: 403),
        type: DioExceptionType.badResponse);
    await expectLater(
        repository.load('1', filter), throwsA(isA<DioException>()));
    expect(await db.query('sync_state'), isEmpty);
  });

  test('cancellation and mismatched server reports never populate cache',
      () async {
    final token = CancelToken()..cancel('changed period');
    await expectLater(repository.load('1', filter, cancelToken: token),
        throwsA(isA<DioException>()));
    expect(await db.query('sync_state'), isEmpty);
    api.data['store_id'] = '2';
    await expectLater(repository.load('1', filter), throwsFormatException);
    expect(await db.query('sync_state'), isEmpty);
  });

  test('pending local sales stay separate and respect store, date and status',
      () async {
    Future<void> sale(String id,
            {String store = '1',
            String status = 'pending',
            String type = 'cash',
            String date = '2026-10-22T20:00:00Z'}) =>
        db.insert('offline_sales', {
          'id': id,
          'store_id': store,
          'sync_status': status,
          'payment_type': type,
          'occurred_at': date,
          'total_amount': '3000'
        });
    await sale('included');
    await sale('other-store', store: '2');
    await sale('synced', status: 'synced');
    await sale('preorder', type: 'preorder');
    await sale('previous-day', date: '2026-10-22T18:00:00Z');
    final result = await repository.load('1', filter);
    expect(result.pendingCount, 1);
    expect(result.pendingAmount, 3000);
    expect(result.report!.salesAmount, 9000);
    expect(result.report!.salesCount, 2);
  });

  test('corrupt or undated cache cannot masquerade as a current zero report',
      () async {
    api.error = DioException(
        requestOptions: RequestOptions(path: '/statistics'),
        type: DioExceptionType.connectionError);
    for (final value in [
      'not json',
      jsonEncode(statisticsData(filter)),
      jsonEncode({
        ...statisticsData(filter),
        'sales_amount': 'NaN',
        'cached_at': now.toIso8601String()
      })
    ]) {
      await db.insert('sync_state', {'key': filter.cacheKey, 'value': value},
          conflictAlgorithm: ConflictAlgorithm.replace);
      expect((await repository.load('1', filter)).report, isNull);
    }
  });
}
