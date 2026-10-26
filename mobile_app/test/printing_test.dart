import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:babyparfume_mobile/core/api/api_client.dart';
import 'package:babyparfume_mobile/core/database/app_database.dart';
import 'package:babyparfume_mobile/core/database/local_cache.dart';
import 'package:babyparfume_mobile/core/storage/secure_storage.dart';
import 'package:babyparfume_mobile/core/sync/offline_sale_service.dart';
import 'package:babyparfume_mobile/core/sync/outbox_manager.dart';
import 'package:babyparfume_mobile/features/printing/data/printing_repository.dart';
import 'package:babyparfume_mobile/features/printing/presentation/print_dialog.dart';
import 'package:babyparfume_mobile/features/products/data/products_repository.dart';
import 'offline_integrity_test.dart'
    show product, debtor, saleItems, FailingOutbox;

Map<String, dynamic> printOptions() => {
      'enabled': true,
      'auto_print_receipt': true,
      'agent_online': true,
      'profiles': [
        {
          'id': 'profile-label',
          'version': 1,
          'name': 'Barcode 30×20',
          'document_type': 'barcode',
          'is_default': true,
          'selectable': true,
          'default_copies': 1,
          'max_copies': 100,
          'printer_name': 'XP365'
        },
        {
          'id': 'profile-receipt',
          'version': 1,
          'name': 'Chek 80 mm',
          'document_type': 'receipt',
          'is_default': true,
          'selectable': true,
          'default_copies': 1,
          'max_copies': 5,
          'printer_name': 'Chek printeri'
        },
      ]
    };

class PrintTransport implements HttpClientAdapter {
  final Future<(int, Object)> Function(RequestOptions) callback;
  PrintTransport(this.callback);
  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream,
      Future<void>? cancel) async {
    final response = await callback(options);
    return ResponseBody.fromString(jsonEncode(response.$2), response.$1,
        headers: {
          Headers.contentTypeHeader: ['application/json']
        });
  }

  @override
  void close({bool force = false}) {}
}

class ScreenPrinting extends PrintingRepository {
  Map<String, dynamic>? previewBody;
  Map<String, dynamic>? submitted;
  Completer<void>? gate;
  int downloads = 0;
  int submissions = 0;
  @override
  Future<Map<String, dynamic>?> receiptIntent(String receiptId) async => null;
  @override
  Future<Map<String, dynamic>> receiptSource(String receiptId) async =>
      {'kind': 'server_sale', 'sale_id': 1};
  @override
  Future<Map<String, dynamic>> options(String id) async => printOptions();
  @override
  Future<Map<String, dynamic>> preview(
      String id, Map<String, dynamic> payload) async {
    previewBody = payload;
    return {
      'document_token': 'token',
      'document_path': '/preview',
      'total_labels': payload['document_type'] == 'barcode'
          ? payload['items'][0]['copies']
          : 0,
      'copies': payload['copies'] ?? 1,
    };
  }

  @override
  Future<Uint8List> pdf(String path) async {
    downloads++;
    return Uint8List.fromList([1, 2, 3]);
  }

  @override
  Future<Map<String, dynamic>> submit(
      String storeId, Map<String, dynamic> payload,
      {String? receiptId, String? reprintOf}) async {
    submitted = payload;
    submissions++;
    await gate?.future;
    return {
      'id': 'job',
      'status': 'spooled',
      'document_type': previewBody?['document_type'],
      'profile_name': 'Barcode 30×20',
      'printer_name': 'XP365',
      'total_labels': 3
    };
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  sqfliteFfiInit();
  late Database db;
  late ApiClient api;
  late PrintingRepository repo;
  late OutboxManager outbox;
  final now = DateTime.utc(2026, 10, 25, 1);
  setUp(() async {
    FlutterSecureStorage.setMockInitialValues({});
    final storage = SecureStorageService();
    await storage.setServerUrl('https://one.test/api/flutter/v1');
    api = ApiClient(storage: storage);
    db = await databaseFactoryFfi.openDatabase(inMemoryDatabasePath,
        options: OpenDatabaseOptions(
            version: 1, onCreate: AppDatabase.createSchema));
    repo = PrintingRepository(
        apiClient: api, getDb: () async => db, now: () => now);
    outbox =
        OutboxManager(getDb: () async => db, operationTime: () async => now);
    await LocalCache.product(db, '1', product());
    await LocalCache.debtor(db, '1', debtor());
  });
  tearDown(() async {
    api.dio.close();
    await db.close();
  });

  test('automatic intent commits with the sale and preserves discounts and IDs',
      () async {
    api.dio.httpClientAdapter =
        PrintTransport((_) async => (200, {'data': printOptions()}));
    await repo.options('1');
    final service = OfflineSaleService(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    final sale = await service.processOfflineSale(
        storeId: '1',
        items: [
          {...saleItems().first, 'quantity': 1, 'discount': '1000.00'}
        ],
        totalAmount: 12000,
        paidAmount: 5000,
        remainingAmount: 7000,
        paymentType: 'partial',
        clientId: '12',
        receiptDiscount: 2000,
        cashAmount: 5000,
        cardAmount: 0);
    final intent = await repo.intent(sale.localSaleId);
    expect(intent, isNotNull);
    final body = intent!['payload'] as Map;
    expect(body['client_job_id'], sale.localSaleId);
    expect(body['source']['operation_id'], sale.operationId);
    expect(body['source']['snapshot']['receipt_discount'], 2000);
    expect(body['source']['snapshot']['items'][0]['discount'], '1000.00');
    expect((await db.query('sync_outbox')), hasLength(1));
  });

  test('failed sale transaction rolls back its print intent too', () async {
    api.dio.httpClientAdapter =
        PrintTransport((_) async => (200, {'data': printOptions()}));
    await repo.options('1');
    final service = OfflineSaleService(
        outboxManager: FailingOutbox(db),
        getDb: () async => db,
        ensureWrite: () async {});
    await expectLater(
        service.processOfflineSale(
            storeId: '1',
            items: [
              {...saleItems().first, 'quantity': 1}
            ],
            totalAmount: 15000,
            paidAmount: 15000,
            remainingAmount: 0,
            paymentType: 'cash'),
        throwsStateError);
    expect(await db.query('offline_sales'), isEmpty);
    expect(await db.query('sync_state', where: "key LIKE 'print_intent_%'"),
        isEmpty);
  });

  test(
      'a completed sale prints automatically from fresh admin settings even without cached options',
      () async {
    final service = OfflineSaleService(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    final sale = await service.processOfflineSale(
        storeId: '1',
        items: [
          {...saleItems().first, 'quantity': 1}
        ],
        totalAmount: 15000,
        paidAmount: 15000,
        remainingAmount: 0,
        paymentType: 'cash');
    expect(await repo.receiptIntent(sale.localSaleId), isNull);
    var posts = 0;
    Map? payload;
    api.dio.httpClientAdapter = PrintTransport((request) async {
      if (request.path.endsWith('/printing/options')) {
        return (200, {'data': printOptions()});
      }
      if (request.method == 'POST') {
        posts++;
        payload = request.data as Map;
        return (
          201,
          {
            'data': {'id': 'auto-job', 'status': 'queued'}
          }
        );
      }
      return (
        200,
        {
          'data': {'id': 'auto-job', 'status': 'queued'}
        }
      );
    });
    final result = await repo.automatic('1', sale.localSaleId);
    expect(result?['id'], 'auto-job');
    expect(payload?['trigger'], 'automatic');
    expect(payload?['paper_profile_id'], 'profile-receipt');
    expect(payload?['client_job_id'], sale.localSaleId);
    await repo.automatic('1', sale.localSaleId);
    expect(posts, 1);
    expect(await db.query('offline_sales'), hasLength(1));
  });

  test(
      'admin disabling auto print skips an unsubmitted cached intent and allows manual printing',
      () async {
    api.dio.httpClientAdapter =
        PrintTransport((_) async => (200, {'data': printOptions()}));
    await repo.options('1');
    final sale = await OfflineSaleService(
            outboxManager: outbox,
            getDb: () async => db,
            ensureWrite: () async {})
        .processOfflineSale(
            storeId: '1',
            items: [
              {...saleItems().first, 'quantity': 1}
            ],
            totalAmount: 15000,
            paidAmount: 15000,
            remainingAmount: 0,
            paymentType: 'cash');
    var posts = 0;
    api.dio.httpClientAdapter = PrintTransport((request) async {
      if (request.method == 'POST') posts++;
      return (
        200,
        {
          'data': {...printOptions(), 'auto_print_receipt': false}
        }
      );
    });
    expect(await repo.automatic('1', sale.localSaleId), isNull);
    expect(posts, 0);
    expect(await repo.receiptIntent(sale.localSaleId), isNull);
    expect((await repo.intent(sale.localSaleId))?['state'], 'skipped');
    expect(await db.query('offline_sales'), hasLength(1));
  });

  test(
      'a manual request created during auto settings refresh prevents a second print',
      () async {
    final sale = await OfflineSaleService(
            outboxManager: outbox,
            getDb: () async => db,
            ensureWrite: () async {})
        .processOfflineSale(
            storeId: '1',
            items: [
              {...saleItems().first, 'quantity': 1}
            ],
            totalAmount: 15000,
            paidAmount: 15000,
            remainingAmount: 0,
            paymentType: 'cash');
    final started = Completer<void>();
    final gate = Completer<void>();
    var posts = 0;
    api.dio.httpClientAdapter = PrintTransport((request) async {
      if (request.path.endsWith('/printing/options')) {
        started.complete();
        await gate.future;
        return (200, {'data': printOptions()});
      }
      if (request.method == 'POST') posts++;
      return (
        201,
        {
          'data': {'id': 'manual-job', 'status': 'queued'}
        }
      );
    });
    final automatic = repo.automatic('1', sale.localSaleId);
    await started.future;
    await repo.submit(
        '1', {'client_job_id': 'manual-first', 'document_token': 'token'},
        receiptId: sale.localSaleId);
    gate.complete();
    expect(await automatic, isNull);
    expect(posts, 1);
    expect(
        (await repo.receiptIntent(sale.localSaleId))?['payload']
            ['client_job_id'],
        'manual-first');
  });

  test(
      'manual print reuses the automatic intent if it was created while the preview was open',
      () async {
    api.dio.httpClientAdapter =
        PrintTransport((_) async => (200, {'data': printOptions()}));
    await repo.options('1');
    final sale = await OfflineSaleService(
            outboxManager: outbox,
            getDb: () async => db,
            ensureWrite: () async {})
        .processOfflineSale(
            storeId: '1',
            items: [
              {...saleItems().first, 'quantity': 1}
            ],
            totalAmount: 15000,
            paidAmount: 15000,
            remainingAmount: 0,
            paymentType: 'cash');
    Map? sent;
    api.dio.httpClientAdapter = PrintTransport((request) async {
      sent = request.data as Map;
      return (
        201,
        {
          'data': {'id': 'auto-job', 'status': 'queued'}
        }
      );
    });
    final result = await repo.submit(
        '1', {'client_job_id': 'manual-second', 'document_token': 'token'},
        receiptId: sale.localSaleId);
    expect(result['id'], 'auto-job');
    expect(sent?['trigger'], 'automatic');
    expect(sent?['client_job_id'], sale.localSaleId);
    expect(await repo.intent('manual-second'), isNull);
  });

  test('malformed printer cache does not prevent saving a sale', () async {
    await db.insert(
        'sync_state', {'key': 'printing_options', 'value': 'broken json'});
    final service = OfflineSaleService(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    await service.processOfflineSale(
        storeId: '1',
        items: [
          {...saleItems().first, 'quantity': 1}
        ],
        totalAmount: 15000,
        paidAmount: 15000,
        remainingAmount: 0,
        paymentType: 'cash');
    expect(await db.query('offline_sales'), hasLength(1));
  });

  test(
      'lost create response checks the same intent and never submits a second job',
      () async {
    var posts = 0;
    api.dio.httpClientAdapter = PrintTransport((request) async {
      if (request.method == 'POST') {
        posts++;
        throw DioException.connectionTimeout(
            requestOptions: request, timeout: const Duration(seconds: 1));
      }
      expect(request.path, contains('/by-client/'));
      return (
        200,
        {
          'data': {'id': 'job-1', 'status': 'queued'}
        }
      );
    });
    final body = {'client_job_id': 'id-1', 'document_token': 'token'};
    await expectLater(repo.submit('1', body), throwsA(isA<DioException>()));
    final saved = await repo.intent('id-1');
    expect(saved?['state'], 'attempting');
    final result = await repo.retry('id-1');
    expect(result['id'], 'job-1');
    expect(posts, 1);
    expect((await repo.intent('id-1'))?['result']['id'], 'job-1');
  });

  test('a rejected stale profile unlocks a fresh explicit confirmation',
      () async {
    api.dio.httpClientAdapter =
        PrintTransport((request) async => (409, {'message': 'profile_stale'}));
    Object? failure;
    try {
      await repo.submit('1',
          {'client_job_id': 'stale-profile', 'document_token': 'old-token'});
    } catch (error) {
      failure = error;
    }
    expect(failure, isA<DioException>());
    expect(PrintingRepository.isRejected(failure!), isTrue);
    expect((await repo.intent('stale-profile'))?['state'], 'rejected');
    expect(PrintingRepository.error(failure), contains('qayta tasdiqlang'));
  });

  test('a stale unsent intent requires a new confirmation', () async {
    await db.insert('sync_state', {
      'key': 'print_intent_old',
      'value': jsonEncode({
        'payload': {'client_job_id': 'old', 'document_token': 'token'},
        'created_at':
            now.subtract(const Duration(minutes: 11)).toIso8601String(),
        'state': 'attempting',
        'store_id': '1'
      })
    });
    var posts = 0;
    api.dio.httpClientAdapter = PrintTransport((request) async {
      if (request.method == 'POST') posts++;
      return (404, {'message': 'not found'});
    });
    await expectLater(repo.retry('old'), throwsStateError);
    expect(posts, 0);
    expect((await repo.intent('old'))?['state'], 'expired');
  });

  for (final dark in [false, true]) {
    for (final receipt in [false, true]) {
      for (final previewFirst in [false, true]) {
        testWidgets(
            '${receipt ? 'receipt' : 'barcode'} ${previewFirst ? 'preview then print' : 'direct print'} fits ${dark ? 'dark' : 'light'} narrow screens',
            (tester) async {
          tester.view.physicalSize = const Size(320, 700);
          tester.view.devicePixelRatio = 1;
          addTearDown(tester.view.resetPhysicalSize);
          addTearDown(tester.view.resetDevicePixelRatio);
          addTearDown(tester.view.resetViewInsets);
          final fake = ScreenPrinting()..gate = Completer<void>();
          await tester.pumpWidget(MaterialApp(
              theme: ThemeData(
                  brightness: dark ? Brightness.dark : Brightness.light),
              home: PrintDialog(
                  storeId: '1',
                  repository: fake,
                  receiptId: receipt ? 'receipt-1' : null,
                  products: receipt
                      ? []
                      : [
                          LocalProduct(
                              id: '11',
                              storeId: '1',
                              name: 'Dior Sauvage',
                              barcode: '0012345678905',
                              type: 'package',
                              price: 15000,
                              stockQuantity: 3)
                        ],
                  previewBuilder: (_) =>
                      const Center(child: Text('PDF preview')))));
          await tester.pumpAndSettle();
          expect(find.text(receipt ? 'Chek 80 mm' : 'Barcode 30×20'),
              findsOneWidget);
          expect(find.text('Oldindan ko‘rish'), findsOneWidget);
          expect(find.text('Print'), findsOneWidget);
          expect(tester.getCenter(find.text('Oldindan ko‘rish')).dx,
              lessThan(tester.getCenter(find.text('Print')).dx));
          final input = find.byType(TextFormField);
          await tester.enterText(input, '0');
          await tester.tap(find.text('Print'));
          await tester.pumpAndSettle();
          expect(fake.previewBody, isNull);
          await tester.enterText(input, '3');
          if (previewFirst) {
            await tester.tap(find.text('Oldindan ko‘rish'));
            await tester.pumpAndSettle();
            expect(fake.downloads, 1);
          }
          expect(find.text('Print'), findsOneWidget);
          expect(tester.takeException(), isNull);
          tester.view.viewInsets = const FakeViewPadding(bottom: 250);
          await tester.pumpAndSettle();
          expect(tester.getRect(find.text('Print')).bottom,
              lessThanOrEqualTo(450));
          await tester.tap(find.text('Print'));
          await tester.pump();
          expect(
              receipt
                  ? (fake.previewBody?['copies'])
                  : (fake.previewBody?['items'][0]['copies']),
              3);
          expect(fake.submitted?['document_token'], 'token');
          expect(fake.submissions, 1);
          expect(fake.downloads, previewFirst ? 1 : 0);
          fake.gate!.complete();
          await tester.pumpAndSettle();
          expect(find.text('Printer navbatiga yuborildi'), findsOneWidget);
          expect(tester.takeException(), isNull);
          await tester.pumpWidget(const SizedBox.shrink());
        });
      }
    }
  }
}
