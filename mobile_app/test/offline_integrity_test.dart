import 'package:babyparfume_mobile/features/pos/data/clients_repository.dart';
import 'package:babyparfume_mobile/features/products/data/barcode_guard.dart';
import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:babyparfume_mobile/core/api/api_client.dart';
import 'package:babyparfume_mobile/core/database/app_database.dart';
import 'package:babyparfume_mobile/core/database/local_cache.dart';
import 'package:babyparfume_mobile/core/lease/lease_manager.dart';
import 'package:babyparfume_mobile/core/storage/secure_storage.dart';
import 'package:babyparfume_mobile/core/sync/offline_sale_service.dart';
import 'package:babyparfume_mobile/core/sync/outbox_manager.dart';
import 'package:babyparfume_mobile/core/sync/sync_coordinator.dart';
import 'package:babyparfume_mobile/core/sync/sync_models.dart';
import 'package:babyparfume_mobile/features/debtors/data/debtors_repository.dart';

class TestAdapter implements HttpClientAdapter {
  final Future<Map<String, dynamic>> Function(RequestOptions) handle;
  TestAdapter(this.handle);
  @override
  Future<ResponseBody> fetch(RequestOptions options,
          Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async =>
      ResponseBody.fromString(jsonEncode(await handle(options)), 200, headers: {
        Headers.contentTypeHeader: ['application/json']
      });
  @override
  void close({bool force = false}) {}
}

class FailingOutbox extends OutboxManager {
  FailingOutbox(Database db)
      : super(
            getDb: () async => db,
            operationTime: () async => DateTime.now().toUtc());
  @override
  Future<String> enqueueOperation(
          {required String storeId,
          required String entityType,
          required String operationType,
          required Map<String, dynamic> payload,
          String? customOperationId,
          String? entityId,
          String? localId,
          int? baseRevision,
          String? baseOperationId,
          List<String> dependsOn = const [],
          DatabaseExecutor? executor}) async =>
      throw StateError('Disk full');
}

Map<String, dynamic> product({String id = '11', int quantity = 3}) => {
      'id': id,
      'name': 'ЎЗБЕК Parfyum',
      'barcode': '0012345678905',
      'type': 'package',
      'price': '15000',
      'stocks': [
        {'stock_id': '71', 'quantity': quantity}
      ],
      'sizes': [],
      'can_update': false,
      'can_delete': false,
    };
Map<String, dynamic> debtor({String amount = '50000'}) => {
      'id': '12',
      'client_id': '42',
      'full_name': 'Ali Audit',
      'phone': '+998901234567',
      'amount': amount,
      'currency': 'UZS',
      'date': '2026-10-21',
      'revision': '1',
      'can_update': true,
      'transactions': [
        {
          'id': '1',
          'type': 'debt',
          'amount': '50000',
          'date': '2026-10-21',
          'note': null
        }
      ],
    };
List<Map<String, dynamic>> saleItems() => [
      {
        'product_id': '11',
        'stock_id': '71',
        'quantity': 2,
        'unit_price': '15000'
      }
    ];

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  sqfliteFfiInit();
  late Database db;
  late OutboxManager outbox;
  late SecureStorageService storage;
  late ApiClient api;
  late LeaseManager lease;
  var uptime = 1000;
  var boot = 'boot-A';
  late String device;
  Map<String, dynamic> serverLease() => {
        'user_id': '1',
        'store_id': '1',
        'device_uuid': device,
        'server_time': '2026-10-21T00:00:00Z',
        'offline_until': '2026-10-21T10:00:00Z',
      };

  setUp(() async {
    FlutterSecureStorage.setMockInitialValues({});
    storage = SecureStorageService();
    await storage.setServerUrl('https://one.test/api/flutter/v1');
    await storage.setSession({
      'user': {'id': '1'},
      'stores': [
        {'id': '1', 'name': 'A'}
      ]
    });
    await storage.setSelectedStoreId('1');
    device = await storage.getOrCreateDeviceUuid();
    uptime = 1000;
    boot = 'boot-A';
    db = await databaseFactoryFfi.openDatabase(inMemoryDatabasePath,
        options: OpenDatabaseOptions(
            version: 1, onCreate: AppDatabase.createSchema));
    outbox = OutboxManager(
        getDb: () async => db,
        operationTime: () async => DateTime.now().toUtc());
    api = ApiClient(storage: storage);
    lease = LeaseManager(
        storage: storage,
        readClock: () async => {'uptime_ms': uptime, 'boot_id': boot});
    await LocalCache.product(db, '1', product());
    await LocalCache.debtor(db, '1', debtor());
  });
  tearDown(() async {
    lease.dispose();
    api.dio.close();
    await db.close();
  });

  test(
      'sale, debt, warehouse deduction, receipt and immutable outbox commit together',
      () async {
    await db.insert('product_stocks',
        {'product_id': '11', 'stock_id': '72', 'quantity': 9});
    final service = OfflineSaleService(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    final result = await service.processOfflineSale(
        storeId: '1',
        clientId: '12',
        items: saleItems(),
        totalAmount: 30000,
        paidAmount: 0,
        remainingAmount: 30000,
        paymentType: 'debt');
    expect((await db.query('debtors')).single['amount'], 80000);
    expect(
        (await db.query('product_stocks',
                where: 'stock_id = ?', whereArgs: ['71']))
            .single['quantity'],
        1);
    expect(
        (await db.query('product_stocks',
                where: 'stock_id = ?', whereArgs: ['72']))
            .single['quantity'],
        9);
    expect(await db.query('offline_sales'), hasLength(1));
    final operations = await outbox.getPendingOperations();
    expect(operations, hasLength(1));
    expect(operations.single.operationType, 'sale.create');
    expect(operations.single.payload['client']['server_id'], '42');
    expect(operations.single.operationId, result.operationId);
  });

  test('outbox failure rolls back both checkout and debtor creation', () async {
    final failing = FailingOutbox(db);
    final sales = OfflineSaleService(
        outboxManager: failing,
        getDb: () async => db,
        ensureWrite: () async {});
    await expectLater(
        sales.processOfflineSale(
            storeId: '1',
            clientId: '12',
            items: saleItems(),
            totalAmount: 30000,
            paidAmount: 0,
            remainingAmount: 30000,
            paymentType: 'debt'),
        throwsStateError);
    expect(await db.query('offline_sales'), isEmpty);
    expect((await db.query('product_stocks')).single['quantity'], 3);
    expect((await db.query('debtors')).single['amount'], 50000);
    final debtors = DebtorsRepository(
        outboxManager: failing,
        getDb: () async => db,
        ensureWrite: () async {});
    await expectLater(
        debtors.createDebtor(
            storeId: '1',
            fullName: 'Vali',
            phone: '+998901234568',
            initialAmount: 10000),
        throwsStateError);
    expect(await db.query('debtors'), hasLength(1));
  });

  test('payment over the debt is rejected instead of silently clamped',
      () async {
    final debtors = DebtorsRepository(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    await expectLater(
        debtors.addTransaction(
            storeId: '1', debtorId: '12', type: 'payment', amount: 60000),
        throwsArgumentError);
    expect((await db.query('debtors')).single['amount'], 50000);
    expect(await outbox.getPendingOperations(), isEmpty);
  });

  test('delta overlays pending payment and retains history', () async {
    final debtors = DebtorsRepository(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    await debtors.addTransaction(
        storeId: '1', debtorId: '12', type: 'payment', amount: 10000);
    await LocalCache.debtor(db, '1', debtor(amount: '45000'));
    expect((await db.query('debtors')).single['amount'], 35000);
    expect(await debtors.getTransactions('12'), hasLength(2));
  });

  test(
      'network retry reuses the original envelope and conflicts remain visible',
      () async {
    final id = await outbox.enqueueOperation(
        storeId: '1',
        entityType: 'debtor',
        operationType: 'debtor.transact',
        entityId: '12',
        payload: {'type': 'payment', 'amount': '1000'});
    final seen = <String>[];
    var fail = true;
    api.dio.httpClientAdapter = TestAdapter((request) async {
      seen.add(jsonEncode((request.data as Map)['operations']));
      if (fail) {
        fail = false;
        throw DioException(
            requestOptions: request, type: DioExceptionType.connectionError);
      }
      return {
        'data': {
          'results': [
            {
              'operation_id': id,
              'status': 'conflict',
              'error': 'stale_revision'
            }
          ]
        }
      };
    });
    final sync = SyncCoordinator(
        apiClient: api,
        outboxManager: outbox,
        leaseManager: lease,
        getDb: () async => db);
    await expectLater(sync.pushOutbox('1'), throwsA(isA<DioException>()));
    expect((await outbox.getPendingOperations()).single.status, 'failed');
    await sync.pushOutbox('1');
    expect(seen[0], seen[1]);
    expect(await outbox.getPendingCount(), 1);
    expect((await outbox.getPendingOperations(includeAll: true)).single.status,
        'conflict');
    sync.dispose();
  });

  test(
      'bootstrap resumes after a failed page and never reports incomplete cache as ready',
      () async {
    await db.delete('products');
    await db.delete('product_stocks');
    await db.delete('debtors');
    var initializes = 0;
    var fail = true;
    api.dio.httpClientAdapter = TestAdapter((request) async {
      if (request.path.endsWith('/session')) {
        return {
          'data': {
            'user': {'id': '1'}
          }
        };
      }
      if (request.path.endsWith('/bootstrap')) {
        expect(request.receiveTimeout, const Duration(minutes: 2));
        initializes++;
        return {
          'data': {
            'snapshot_id': 'snapshot',
            'watermark': 0,
            'manifest': {'products': 2, 'debtors': 1, 'clients': 0},
            'stocks': []
          }
        };
      }
      if (request.path.endsWith('/bootstrap/snapshot')) {
        expect(request.receiveTimeout, const Duration(minutes: 1));
        final cursor = request.queryParameters['cursor'];
        if (request.queryParameters['entity'] == 'debtors') {
          return {
            'data': {
              'items': [debtor()],
              'next_cursor': '12',
              'has_more': false
            }
          };
        }
        if (cursor == '0') {
          return {
            'data': {
              'items': [product()],
              'next_cursor': '11',
              'has_more': true
            }
          };
        }
        if (fail) {
          fail = false;
          throw DioException(
              requestOptions: request, type: DioExceptionType.connectionError);
        }
        return {
          'data': {
            'items': [product(id: '13')],
            'next_cursor': '13',
            'has_more': false
          }
        };
      }
      if (request.path.endsWith('/delta')) {
        return {
          'data': {'changes': [], 'next_cursor': '0', 'has_more': false}
        };
      }
      if (request.path.endsWith('/lease')) return {'data': serverLease()};
      throw StateError(request.path);
    });
    final sync = SyncCoordinator(
        apiClient: api,
        outboxManager: outbox,
        leaseManager: lease,
        getDb: () async => db);
    expect(await sync.syncAll('1'), isFalse);
    expect(await sync.getSavedWatermark(), isNull);
    expect(await db.query('products'), isEmpty);
    expect(await sync.syncAll('1'), isTrue);
    expect(initializes, 1);
    expect(await db.query('products'), hasLength(2));
    expect(await db.query('debtor_transactions'), hasLength(1));
    expect(sync.currentProgress.status, SyncStatus.offlineReady);
    expect(sync.currentProgress.lastError, isNull);
    sync.dispose();
  });

  for (final incompleteManifest in [false, true]) {
    test(
        'bootstrap replaces a saved ${incompleteManifest ? 'legacy manifest' : 'incomplete completed snapshot'} without losing pending operations',
        () async {
      await db.insert('sync_state', {
        'key': 'bootstrap',
        'value': jsonEncode({
          'snapshot_id': 'broken',
          'watermark': 5,
          'manifest': {
            'products': 2,
            'debtors': 0,
            if (!incompleteManifest) 'clients': 0,
          },
          'completed': ['products', 'debtors', 'clients'],
          'cursors': {'products': '13'}
        })
      });
      await db.insert('server_cache', {
        'entity': 'stage_products',
        'id': '11',
        'payload_json': jsonEncode(product())
      });
      await outbox.enqueueOperation(
          storeId: '1',
          entityType: 'client',
          operationType: 'client.create',
          payload: {'full_name': 'Pending'});
      final pending = await db.query('sync_outbox');
      var initializes = 0;
      api.dio.httpClientAdapter = TestAdapter((request) async {
        if (request.path.endsWith('/bootstrap')) {
          initializes++;
          expect(await db.query('products'), hasLength(1));
          expect(await db.query('sync_outbox'), pending);
          expect(await db.query('server_cache'), isEmpty);
          return {
            'data': {
              'snapshot_id': 'fresh',
              'watermark': 10,
              'manifest': {'products': 1, 'debtors': 0, 'clients': 0},
            }
          };
        }
        expect(request.path, '/stores/1/bootstrap/fresh');
        expect(request.queryParameters['entity'], 'products');
        expect(request.queryParameters['cursor'], '0');
        return {
          'data': {
            'items': [product(id: '13')],
            'next_cursor': '13',
            'has_more': false,
          }
        };
      });
      final sync = SyncCoordinator(
          apiClient: api,
          outboxManager: outbox,
          leaseManager: lease,
          getDb: () async => db);
      expect(await sync.runBootstrap('1'), isTrue);
      expect(initializes, 1);
      expect(await sync.getSavedWatermark(), 10);
      expect((await db.query('products')).single['id'], '13');
      expect(await db.query('sync_outbox'), pending);
      expect(await db.query('sync_state', where: "key = 'bootstrap'"), isEmpty);
      expect(await db.query('server_cache', where: "entity LIKE 'stage_%'"),
          isEmpty);
      sync.dispose();
    });
  }

  for (final failure in ['products', 'debtors', 'clients', 'legacy_manifest']) {
    test('bootstrap rejects $failure with a specific error and bounded retries',
        () async {
      final missingClients = failure == 'legacy_manifest';
      await outbox.enqueueOperation(
          storeId: '1',
          entityType: 'client',
          operationType: 'client.create',
          payload: {'full_name': 'Pending'});
      final pending = await db.query('sync_outbox');
      final products = await db.query('products');
      final stocks = await db.query('product_stocks');
      final debtors = await db.query('debtors');
      final transactions = await db.query('debtor_transactions');
      final clients = await db.query('clients');
      await db.insert('sync_state', {
        'key': 'store_stocks',
        'value': jsonEncode([
          {'id': '71', 'name': 'Existing stock'}
        ])
      });
      var initializes = 0;
      api.dio.httpClientAdapter = TestAdapter((request) async {
        if (request.path.endsWith('/session')) {
          return {
            'data': {
              'user': {'id': '1'}
            }
          };
        }
        if (request.path.endsWith('/bootstrap')) {
          initializes++;
          return {
            'data': {
              'snapshot_id': 'broken_$initializes',
              'watermark': 10,
              'manifest': {
                'products': failure == 'products' ? 2 : 1,
                'debtors': failure == 'debtors' ? 2 : 1,
                if (!missingClients) 'clients': failure == 'clients' ? 2 : 1,
              },
              'stocks': [
                {'id': '72', 'name': 'Replacement stock'}
              ]
            }
          };
        }
        expect(missingClients, isFalse);
        final entity = request.queryParameters['entity'];
        final item = switch (entity) {
          'products' => product(id: '13'),
          'debtors' => debtor(),
          _ => {'id': '42', 'full_name': 'Server client'},
        };
        final firstPage = request.queryParameters['cursor'] == '0';
        return {
          'data': {
            'items': [item],
            'next_cursor': item['id'],
            'has_more': entity == failure && firstPage,
          }
        };
      });
      final sync = SyncCoordinator(
          apiClient: api,
          outboxManager: outbox,
          leaseManager: lease,
          getDb: () async => db);
      expect(await sync.syncAll('1'), isFalse);
      expect(initializes, 2);
      expect(sync.currentProgress.status, SyncStatus.error);
      final label = {
        'products': 'Mahsulotlar',
        'debtors': 'Qarzdorlar',
        'clients': 'Mijozlar',
      }[failure];
      expect(sync.currentProgress.lastError,
          contains(missingClients ? 'clients' : '$label: 1 / 2'));
      if (missingClients) {
        expect(sync.currentProgress.lastError, contains('Server mobil API'));
      }
      expect(await db.query('products'), products);
      expect(await db.query('product_stocks'), stocks);
      expect(await db.query('debtors'), debtors);
      expect(await db.query('debtor_transactions'), transactions);
      expect(await db.query('clients'), clients);
      expect(
          jsonDecode(
              (await db.query('sync_state', where: "key = 'store_stocks'"))
                  .single['value'] as String),
          [
            {'id': '71', 'name': 'Existing stock'}
          ]);
      expect(await db.query('sync_outbox'), pending);
      expect(await sync.getSavedWatermark(), isNull);
      expect(await db.query('sync_state', where: "key = 'offline_ready'"),
          isEmpty);
      expect(await db.query('sync_state', where: "key = 'bootstrap'"), isEmpty);
      expect(await db.query('server_cache', where: "entity LIKE 'stage_%'"),
          isEmpty);
      expect((await lease.checkLease()).isLocked, isTrue);
      sync.dispose();
    });
  }

  for (final status in [404, 409, 410]) {
    test('expired bootstrap HTTP $status restarts within the same sync',
        () async {
      await db.insert('sync_state', {
        'key': 'bootstrap',
        'value': jsonEncode({
          'snapshot_id': 'expired',
          'watermark': 0,
          'manifest': {'products': 2, 'debtors': 0, 'clients': 0},
          'completed': [],
          'cursors': {'products': '11'}
        })
      });
      await db.insert('server_cache', {
        'entity': 'stage_products',
        'id': '11',
        'payload_json': jsonEncode(product())
      });
      await outbox.enqueueOperation(
          storeId: '1',
          entityType: 'client',
          operationType: 'client.create',
          payload: {'full_name': 'Pending'});
      var initializes = 0;
      api.dio.httpClientAdapter = TestAdapter((request) async {
        if (request.path.endsWith('/bootstrap/expired')) {
          throw DioException(
              requestOptions: request,
              type: DioExceptionType.badResponse,
              response: Response(requestOptions: request, statusCode: status));
        }
        if (request.path.endsWith('/bootstrap')) {
          initializes++;
          expect(await db.query('products'), hasLength(1));
          expect(await outbox.getPendingCount(), 1);
          expect(await db.query('server_cache'), isEmpty);
          return {
            'data': {
              'snapshot_id': 'fresh',
              'watermark': 10,
              'manifest': {'products': 1, 'debtors': 0, 'clients': 0},
            }
          };
        }
        if (request.path.endsWith('/bootstrap/fresh')) {
          expect(request.queryParameters['cursor'], '0');
          return {
            'data': {
              'items': [product(id: '13')],
              'next_cursor': '13',
              'has_more': false,
            }
          };
        }
        throw StateError(request.path);
      });
      final sync = SyncCoordinator(
          apiClient: api,
          outboxManager: outbox,
          leaseManager: lease,
          getDb: () async => db);
      expect(await sync.runBootstrap('1'), isTrue);
      expect(initializes, 1);
      expect(await sync.getSavedWatermark(), 10);
      expect((await db.query('products')).single['id'], '13');
      expect(await outbox.getPendingCount(), 1);
      expect(await db.query('server_cache', where: "entity LIKE 'stage_%'"),
          isEmpty);
      sync.dispose();
    });
  }

  for (final status in [404, 500, null]) {
    test('bootstrap failure $status exposes a safe actionable error', () async {
      await outbox.enqueueOperation(
          storeId: '1',
          entityType: 'client',
          operationType: 'client.create',
          payload: {'full_name': 'Pending'});
      api.dio.httpClientAdapter = TestAdapter((request) async {
        if (request.path.endsWith('/session')) {
          return {
            'data': {
              'user': {'id': '1'}
            }
          };
        }
        throw DioException(
            requestOptions: request,
            type: status == null
                ? DioExceptionType.receiveTimeout
                : DioExceptionType.badResponse,
            response: status == null
                ? null
                : Response(
                    requestOptions: request,
                    statusCode: status,
                    data: {'message': 'SQLSTATE secret server details'}));
      });
      final sync = SyncCoordinator(
          apiClient: api,
          outboxManager: outbox,
          leaseManager: lease,
          getDb: () async => db);
      expect(await sync.syncAll('1'), isFalse);
      expect(sync.currentProgress.lastError,
          contains('Serverda ma’lumotlar nusxasi tayyorlanmoqda'));
      expect(sync.currentProgress.lastError, contains('/stores/1/bootstrap'));
      expect(sync.currentProgress.lastError, isNot(contains('SQLSTATE')));
      expect(sync.currentProgress.lastError,
          contains(status == null ? 'kutish vaqti tugadi' : 'HTTP $status'));
      expect(await outbox.getPendingCount(), 1);
      expect(await sync.getSavedWatermark(), isNull);
      expect((await lease.checkLease()).isLocked, isTrue);
      sync.dispose();
    });
  }

  test('lease expires at exactly ten hours and rejects reboot or wrong store',
      () async {
    await lease.updateLease(serverLease());
    uptime += const Duration(hours: 10).inMilliseconds - 1;
    expect((await lease.checkLease()).isLocked, isFalse);
    uptime++;
    expect((await lease.checkLease()).isLocked, isTrue);
    uptime = 1000;
    await lease.updateLease(serverLease());
    boot = 'boot-B';
    expect((await lease.checkLease()).isLocked, isTrue);
    boot = 'boot-A';
    await storage.setSelectedStoreId('2');
    expect((await lease.checkLease()).isLocked, isTrue);
  });

  test('tokens and database keys remain partitioned by origin and account',
      () async {
    await storage.setToken('origin-one');
    final key = await storage.databasePassword('account-store-A');
    expect(await storage.databasePassword('account-store-A'), key);
    expect(await storage.databasePassword('account-store-B'), isNot(key));
    await storage.setServerUrl('https://two.test/api/flutter/v1');
    expect(await storage.getToken(), isNull);
    expect(await storage.getSession(), isNull);
    await storage.setServerUrl('https://one.test/api/flutter/v1');
    expect(await storage.getToken(), 'origin-one');
    expect(
        () => SecureStorageService.normalizeServerUrl(
            'http://unsafe.test/api/flutter/v1'),
        throwsArgumentError);
  });

  test(
      'debtor edit and photo survive a pending delta and use a dependency chain',
      () async {
    final repo = DebtorsRepository(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    final original = (await repo.getDebtor('12'))!;
    await repo.updateDebtor(original,
        fullName: 'Ali Tahrir',
        phone: '+998901234567',
        amount: 60000,
        date: '2026-10-20',
        note: 'Tuzatish');
    await repo.setPhoto(original, Uint8List.fromList([1, 2, 3, 4]));
    await LocalCache.debtor(db, '1', debtor(amount: '45000'));
    expect((await repo.getDebtor('12'))!.fullName, 'Ali Tahrir');
    expect((await repo.getDebtor('12'))!.amount, 55000);
    expect(await repo.getPhoto('12'), [1, 2, 3, 4]);
    final operations = await outbox.getPendingOperations();
    expect(operations, hasLength(2));
    expect(operations.last.envelope!['depends_on'],
        [operations.first.operationId]);
    expect(operations.last.operationType, 'debtor.photo');
  });
  test(
      'new offline client credit sale and payment keep their dependency mapping',
      () async {
    final clients = ClientsRepository(
        outbox: outbox, getDb: () async => db, ensureWrite: () async {});
    final customer = await clients.create('1', 'Yangi Xaridor', '901234569');
    final sales = OfflineSaleService(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    final result = await sales.processOfflineSale(
        storeId: '1',
        customerId: customer.id,
        items: saleItems(),
        totalAmount: 30000,
        paidAmount: 0,
        remainingAmount: 30000,
        paymentType: 'debt');
    final localDebtor = (await db
            .query('debtors', where: 'client_id = ?', whereArgs: [customer.id]))
        .single;
    final debtors = DebtorsRepository(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    await debtors.addTransaction(
        storeId: '1',
        debtorId: localDebtor['id'] as String,
        type: 'payment',
        amount: 10000);
    final operations = await outbox.getPendingOperations();
    expect(operations, hasLength(3));
    expect(operations[1].envelope!['depends_on'], [operations[0].operationId]);
    expect(operations[2].envelope!['depends_on'], [result.operationId]);
    await outbox.acknowledge(
        operations[0], {'status': 'applied', 'canonical_id': '88'});
    await outbox.acknowledge(operations[1], {
      'status': 'applied',
      'canonical_id': '99',
      'response_data': {'client_id': '88', 'debtor_id': '90'}
    });
    final mapped = (await db
            .query('debtors', where: 'id = ?', whereArgs: [localDebtor['id']]))
        .single;
    expect(mapped['server_id'], '90');
    expect(mapped['client_id'], '88');
    expect(mapped['amount'], 20000);
  });
  test('barcode keeps leading zero and accepts a scan only once', () {
    final guard = BarcodeGuard();
    expect(BarcodeGuard.validChecksum('0012345678905'), isTrue);
    expect(guard.accept('0012345678904', verifyChecksum: true), isFalse);
    expect(guard.accept('0012345678905', verifyChecksum: true), isTrue);
    expect(guard.accept('0012345678905', verifyChecksum: true), isFalse);
    expect(BarcodeGuard().accept('CODE128-01'), isTrue);
  });

  test(
      'server deletion retains pending money and removes stale canonical records',
      () async {
    await LocalCache.client(
        db, '1', {'id': '42', 'full_name': 'Ali', 'phone': '+998901234567'});
    await db.insert('debtor_media', {
      'debtor_id': '12',
      'bytes': Uint8List.fromList([1])
    });
    final repo = DebtorsRepository(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    await repo.addTransaction(
        storeId: '1', debtorId: '12', type: 'payment', amount: 10000);
    await LocalCache.removeCanonical(db, 'debtors', '12');
    expect((await db.query('debtors')).single['amount'], 40000);
    expect((await db.query('debtors')).single['can_update'], 0);
    expect(await db.query('debtor_media'), hasLength(1));
    await LocalCache.removeCanonical(db, 'clients', '42');
    expect(await db.query('clients'), isEmpty);
    await db.update('sync_outbox', {'status': 'applied'});
    await db.update('debtor_transactions', {'sync_status': 'synced'});
    await LocalCache.removeCanonical(db, 'debtors', '12');
    expect(await db.query('debtors'), isEmpty);
    expect(await db.query('debtor_media'), isEmpty);
  });
  test('credit checkout depends on the previous pending payment', () async {
    final repo = DebtorsRepository(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    await repo.addTransaction(
        storeId: '1', debtorId: '12', type: 'payment', amount: 10000);
    final previous = (await outbox.getPendingOperations()).single;
    final service = OfflineSaleService(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    await service.processOfflineSale(
        storeId: '1',
        clientId: '12',
        items: saleItems(),
        totalAmount: 30000,
        paidAmount: 0,
        remainingAmount: 30000,
        paymentType: 'debt',
        paymentNote: 'Audit qarz');
    final sale = (await outbox.getPendingOperations()).last;
    expect(sale.envelope!['depends_on'], contains(previous.operationId));
    expect(sale.payload['debtor_context']['base_operation_id'],
        previous.operationId);
    expect(
        jsonDecode((await db.query('offline_sales')).single['payment_json']
            as String)['note'],
        'Audit qarz');
  });
  test(
      'cash receipt retains tendered cash and change without increasing revenue',
      () async {
    final service = OfflineSaleService(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    await service.processOfflineSale(
        storeId: '1',
        items: saleItems(),
        totalAmount: 30000,
        paidAmount: 30000,
        remainingAmount: 0,
        paymentType: 'cash',
        receivedCash: 50000);
    final receipt = (await db.query('offline_sales')).single;
    final payment = jsonDecode(receipt['payment_json'] as String) as Map;
    expect(receipt['paid_amount'], 30000);
    expect(payment['received_cash'], 50000);
    expect(payment['change'], 20000);
    expect(
        (await outbox.getPendingOperations()).single.payload['payment']['cash'],
        '30000.00');
  });

  test('network delay consumes the server issued ten hour lease', () async {
    final started = {'uptime_ms': uptime, 'boot_id': boot};
    uptime += 60000;
    await lease.updateLease(serverLease(), requestClock: started);
    expect((await lease.checkLease()).remainingTime,
        const Duration(hours: 10) - const Duration(minutes: 1));
    uptime += const Duration(hours: 10).inMilliseconds - 60000;
    expect((await lease.checkLease()).isLocked, isTrue);
  });
  test('debt summaries keep currencies separate and include filter counts',
      () async {
    await LocalCache.debtor(
        db, '1', {...debtor(amount: '100'), 'id': '13', 'currency': 'USD'});
    final repo = DebtorsRepository(
        outboxManager: outbox, getDb: () async => db, ensureWrite: () async {});
    final summary = await repo.summary('1');
    expect(summary.totals, {'UZS': 50000, 'USD': 100});
    expect(summary.counts['active'], 2);
    expect(await repo.getTotalDebt('1'), 50000);
    expect(await repo.getTotalDebt('1', currency: 'USD'), 100);
  });
  test('known server revocation locks offline writes and preserves the outbox',
      () async {
    await lease.updateLease(serverLease());
    await outbox.enqueueOperation(
        storeId: '1',
        entityType: 'client',
        operationType: 'client.create',
        payload: {'full_name': 'Pending'});
    api.dio.httpClientAdapter = TestAdapter((options) async {
      throw DioException(
          requestOptions: options,
          type: DioExceptionType.badResponse,
          response: Response(requestOptions: options, statusCode: 403));
    });
    final sync = SyncCoordinator(
        apiClient: api,
        outboxManager: outbox,
        leaseManager: lease,
        getDb: () async => db);
    expect(await sync.syncAll('1'), isFalse);
    expect((await lease.checkLease()).isLocked, isTrue);
    expect(await outbox.getPendingCount(), 1);
    sync.dispose();
  });
}
