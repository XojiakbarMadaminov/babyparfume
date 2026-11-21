import 'dart:async';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:babyparfume_mobile/core/api/api_client.dart';
import 'package:babyparfume_mobile/core/storage/secure_storage.dart';
import 'package:babyparfume_mobile/core/theme/app_theme.dart';
import 'package:babyparfume_mobile/features/debtors/data/sms_balance_controller.dart';
import 'package:babyparfume_mobile/features/debtors/presentation/sms_balance_card.dart';
import 'package:babyparfume_mobile/core/sync/outbox_manager.dart';
import 'package:babyparfume_mobile/features/debtors/data/debtors_repository.dart';
import 'package:babyparfume_mobile/features/debtors/presentation/debtors_screen.dart';

class BalanceLayoutDebtors extends DebtorsRepository {
  String? query;
  String? filter;
  BalanceLayoutDebtors() : super(outboxManager: OutboxManager());

  @override
  Future<List<LocalDebtor>> getDebtors(String storeId,
      {String? query, String filter = 'all'}) async {
    this.query = query;
    this.filter = filter;
    return [
      LocalDebtor(
          id: '1', storeId: storeId, fullName: 'Ali Valiyev', amount: 520000)
    ];
  }

  @override
  Future<({Map<String, double> totals, Map<String, int> counts})> summary(
          String storeId) async =>
      (
        totals: {'UZS': 50187326.0, 'USD': 100.0},
        counts: {'active': 259, 'closed': 561, 'overdue': 14}
      );
}

class BalanceApi extends ApiClient {
  final List<String> requests = [];
  Map<String, dynamic> data = {};
  DioException? error;
  Completer<Response>? pending;
  BalanceApi(SecureStorageService storage) : super(storage: storage);

  Future<Response> respond(String method, String path) async {
    requests.add('$method $path');
    if (pending != null) return pending!.future;
    if (error != null) throw error!;
    return Response(
        requestOptions: RequestOptions(path: path), data: {'data': data});
  }

  @override
  Future<Response> get(String path,
          {Map<String, dynamic>? queryParameters,
          Options? options,
          CancelToken? cancelToken}) =>
      respond('GET', path);

  @override
  Future<Response> post(String path,
          {dynamic data,
          Map<String, dynamic>? queryParameters,
          Options? options}) =>
      respond('POST', path);
}

Map<String, dynamic> balanceSession(String user, {bool allowed = true}) => {
      'user': {'id': user},
      'permissions': {'sms_balance': allowed}
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  late SecureStorageService storage;
  late BalanceApi api;
  late SmsBalanceController controller;
  late DateTime now;
  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    now = DateTime.utc(2026, 11, 21, 12);
    storage = SecureStorageService();
    api = BalanceApi(storage)
      ..data = {
        'balance': 125000,
        'updated_at': now.toIso8601String(),
        'status': 'normal',
        'stale': false
      };
    controller =
        SmsBalanceController(apiClient: api, storage: storage, now: () => now);
  });
  tearDown(() => controller.dispose());

  test('loads account balance and throttles automatic refresh to five minutes',
      () async {
    await controller.updateSession(balanceSession('1'));
    expect(controller.balance!.amount, 125000);
    await controller.updateSession(balanceSession('1'));
    expect(api.requests, ['GET /sms-balance']);
    now = now.add(const Duration(minutes: 5));
    await controller.updateSession(balanceSession('1'));
    expect(api.requests.length, 2);
  });

  test('manual refresh uses the refresh endpoint', () async {
    await controller.updateSession(balanceSession('1'));
    now = now.add(const Duration(seconds: 61));
    await controller.refresh(force: true);
    expect(api.requests.last, 'POST /sms-balance/refresh');
  });

  test('keeps the amount and timestamp after a failed refresh', () async {
    await controller.updateSession(balanceSession('1'));
    final timestamp = controller.balance!.updatedAt;
    now = now.add(const Duration(minutes: 6));
    api.error =
        DioException(requestOptions: RequestOptions(path: '/sms-balance'));
    await controller.refresh();
    expect(controller.balance!.amount, 125000);
    expect(controller.balance!.updatedAt, timestamp);
    expect(controller.balance!.stale, isTrue);
  });

  test('restores cached balance offline after restart', () async {
    await storage.setSmsBalance('1', api.data);
    api.error =
        DioException(requestOptions: RequestOptions(path: '/sms-balance'));
    await controller.updateSession(balanceSession('1'));
    expect(controller.balance!.amount, 125000);
    expect(controller.balance!.stale, isTrue);
  });

  test('unavailable server data does not erase a local last known balance',
      () async {
    await storage.setSmsBalance('1', api.data);
    api.data = {
      'balance': null,
      'updated_at': null,
      'status': 'unavailable',
      'stale': true
    };
    await controller.updateSession(balanceSession('1'));
    expect(controller.balance!.amount, 125000);
    expect(controller.balance!.stale, isTrue);
  });

  test('unknown balance remains unknown rather than zero', () async {
    api.error =
        DioException(requestOptions: RequestOptions(path: '/sms-balance'));
    await controller.updateSession(balanceSession('1'));
    expect(controller.balance?.amount, isNull);
  });

  test('permission removal hides and clears the cached balance', () async {
    await controller.updateSession(balanceSession('1'));
    await controller.updateSession(balanceSession('1', allowed: false));
    expect(controller.enabled, isFalse);
    expect(controller.balance, isNull);
    expect(await storage.getSmsBalance('1'), isNull);
    expect(api.requests.length, 1);
  });

  test('server permission denial clears the previously visible balance',
      () async {
    await controller.updateSession(balanceSession('1'));
    now = now.add(const Duration(minutes: 6));
    api.error = DioException(
        requestOptions: RequestOptions(path: '/sms-balance'),
        response: Response(
            requestOptions: RequestOptions(path: '/sms-balance'),
            statusCode: 403));
    await controller.refresh();
    expect(controller.enabled, isFalse);
    expect(controller.balance, isNull);
    expect(await storage.getSmsBalance('1'), isNull);
  });

  test('does not expose another users cached balance', () async {
    await storage.setSmsBalance('1', api.data);
    api.error =
        DioException(requestOptions: RequestOptions(path: '/sms-balance'));
    await controller.updateSession(balanceSession('2'));
    expect(controller.balance?.amount, isNull);
  });

  test('does not expose another servers cached balance', () async {
    await storage.setSmsBalance('1', api.data);
    await storage.setServerUrl('https://other.example/api/flutter/v1');
    expect(await storage.getSmsBalance('1'), isNull);
  });

  test('ignores in-flight responses when permission is removed', () async {
    api.pending = Completer<Response>();
    final loading = controller.updateSession(balanceSession('1'));
    while (api.requests.isEmpty) {
      await Future<void>.delayed(Duration.zero);
    }
    await controller.updateSession(balanceSession('1', allowed: false));
    api.pending!.complete(Response(
        requestOptions: RequestOptions(path: '/sms-balance'),
        data: {'data': api.data}));
    await loading;
    expect(controller.balance, isNull);
    expect(await storage.getSmsBalance('1'), isNull);
  });

  test('rejects invalid balance values and missing timestamps', () {
    for (final data in [
      {'balance': 'invalid'},
      {'balance': 'NaN'},
      {'balance': 5000}
    ]) {
      expect(() => SmsBalance.fromJson(data), throwsFormatException);
    }
    expect(SmsBalance.fromJson({...api.data, 'balance': -2000}).amount, -2000);
  });

  for (final brightness in Brightness.values) {
    testWidgets('balance card fits a narrow screen in ${brightness.name} mode',
        (tester) async {
      tester.view.physicalSize = const Size(320, 680);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      var refreshed = false;
      await tester.pumpWidget(MaterialApp(
          theme: brightness == Brightness.dark
              ? AppTheme.darkTheme()
              : AppTheme.lightTheme(),
          home: Scaffold(
              body: SmsBalanceCard(
                  balance: SmsBalance.fromJson(api.data).asStale(),
                  loading: false,
                  onRefresh: () => refreshed = true))));
      expect(find.text('Umumiy SMS balansi'), findsOneWidget);
      expect(find.text('125 000 so‘m'), findsOneWidget);
      expect(find.textContaining('eskirgan ma’lumot'), findsOneWidget);
      await tester.tap(find.byTooltip('SMS balansini yangilash'));
      expect(refreshed, isTrue);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('unknown amount displays a dash', (tester) async {
    await tester.pumpWidget(MaterialApp(
        home:
            Scaffold(body: SmsBalanceCard(loading: false, onRefresh: () {}))));
    expect(find.text('—'), findsOneWidget);
    expect(find.text('0 so‘m'), findsNothing);
  });

  for (final width in [320.0, 393.0, 600.0]) {
    for (final brightness in Brightness.values) {
      testWidgets(
          'header search and 2 to 1 summary fit $width in ${brightness.name}',
          (tester) async {
        tester.view.physicalSize = Size(width, 800);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final repository = BalanceLayoutDebtors();
        await tester.pumpWidget(MaterialApp(
            theme: brightness == Brightness.dark
                ? AppTheme.darkTheme()
                : AppTheme.lightTheme(),
            home: DebtorsScreen(
                storeId: '1',
                repository: repository,
                smsBalanceCard: SmsBalanceCard(
                    compact: true,
                    balance: SmsBalance.fromJson(api.data),
                    loading: false,
                    onRefresh: () {}))));
        await tester.pumpAndSettle();
        final search = find.byKey(const ValueKey('debtors-search'));
        expect(find.ancestor(of: search, matching: find.byType(AppBar)),
            findsOneWidget);
        final debt = find.byKey(const ValueKey('debtors-summary'));
        final sms = find.byKey(const ValueKey('sms-balance-summary'));
        final debtRect = tester.getRect(debt);
        final smsRect = tester.getRect(sms);
        expect(debtRect.width, closeTo(smsRect.width * 2, 1));
        expect(debtRect.top, smsRect.top);
        expect(debtRect.height, smsRect.height);
        for (final text in [
          'Jami qarz miqdori',
          '1 ta qarzdor',
          "50 187 326 so'm",
          '100 USD'
        ]) {
          expect(find.descendant(of: debt, matching: find.text(text)),
              findsOneWidget);
        }
        expect(tester.takeException(), isNull);
        await tester.enterText(search, 'ali');
        await tester.pumpAndSettle();
        expect(repository.query, 'ali');
        expect(repository.filter, 'active');
        await tester.ensureVisible(find.text('Yopilgan (561)'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Yopilgan (561)'));
        await tester.pumpAndSettle();
        expect(repository.query, 'ali');
        expect(repository.filter, 'closed');
        await tester.tap(find.byTooltip('Qidiruvni tozalash'));
        await tester.pumpAndSettle();
        expect(repository.query, '');
        expect(tester.takeException(), isNull);
      });
    }
  }
}
