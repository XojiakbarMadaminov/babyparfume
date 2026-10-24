import 'dart:async';
import 'dart:io';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart';
import 'package:babyparfume_mobile/core/sync/offline_sale_service.dart';
import 'package:babyparfume_mobile/core/sync/outbox_manager.dart';
import 'package:babyparfume_mobile/core/theme/app_theme.dart';
import 'package:babyparfume_mobile/features/pos/data/clients_repository.dart';
import 'package:babyparfume_mobile/features/pos/presentation/pos_screen.dart';
import 'package:babyparfume_mobile/features/pos/presentation/pos_widgets.dart';
import 'package:babyparfume_mobile/features/products/data/products_repository.dart';

class CartDatabase implements Database {
  final tables = <String, Map<String, Map<String, Object?>>>{};

  @override
  Future<List<Map<String, Object?>>> query(String table,
      {bool? distinct,
      List<String>? columns,
      String? where,
      List<Object?>? whereArgs,
      String? groupBy,
      String? having,
      String? orderBy,
      int? limit,
      int? offset}) async {
    final rows = tables[table]?.values.toList() ?? [];
    if (whereArgs == null) return rows;
    final column = table == 'sync_state' ? 'key' : 'id';
    return rows.where((row) => row[column] == whereArgs.first).toList();
  }

  @override
  Future<int> insert(String table, Map<String, Object?> values,
      {String? nullColumnHack, ConflictAlgorithm? conflictAlgorithm}) async {
    final key = (values['id'] ?? values['key']) as String;
    (tables[table] ??= {})[key] = Map.of(values);
    return 1;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class PosProducts extends ProductsRepository {
  final delayedSearches = <String, Completer<List<LocalProduct>>>{};
  bool failSearch = false;
  PosProducts() : super(outboxManager: OutboxManager());

  LocalProduct product(String id, String name,
          {String type = 'package',
          String? category = 'Atirlar',
          String stock = '71',
          String? size,
          String? sizeName,
          double price = 30000}) =>
      LocalProduct(
          id: id,
          storeId: '1',
          name: name,
          barcode: '0012345678905',
          type: type,
          price: price,
          initialPrice: 18000,
          stockQuantity: 8,
          stockId: stock,
          stockName: 'Asosiy ombor',
          sizeId: size,
          sizeName: sizeName,
          categoryId: category == null ? null : '1',
          categoryName: category);

  @override
  Future<List<LocalProduct>> searchProducts(String storeId,
      {String? query}) async {
    if (failSearch) throw StateError('Offline database unavailable');
    if (delayedSearches.containsKey(query)) {
      return delayedSearches[query]!.future;
    }
    return [
      product('11', 'Bahor atiri'),
      product('12', 'Sovun', category: null),
      product('13', 'Razmerli atir', type: 'size')
    ]
        .where((p) =>
            query == null || p.name.toLowerCase().contains(query.toLowerCase()))
        .toList();
  }

  @override
  Future<List<LocalProduct>> variants(LocalProduct product) async =>
      product.type == 'size'
          ? [
              this.product('13', product.name,
                  type: 'size', size: '21', sizeName: '50 ml'),
              this.product('13', product.name,
                  type: 'size', stock: '72', size: '22', sizeName: '100 ml')
            ]
          : [product];
}

class PosClients extends ClientsRepository {
  String? createdPhone;
  PosClients() : super(outbox: OutboxManager());
  @override
  Future<List<LocalClient>> all(String storeId) async =>
      [LocalClient(id: '42', name: 'Ali Valiyev', phone: '+998901234567')];

  @override
  Future<LocalClient> create(
      String storeId, String name, String rawPhone) async {
    createdPhone = rawPhone;
    return LocalClient(id: '43', name: name, phone: rawPhone);
  }
}

class PosSales extends OfflineSaleService {
  int submissions = 0;
  Map<String, dynamic>? submitted;
  Completer<void>? gate;
  PosSales() : super(outboxManager: OutboxManager());

  @override
  Future<OfflineSaleResult> processOfflineSale(
      {required String storeId,
      String? clientId,
      String? customerId,
      required List<Map<String, dynamic>> items,
      required double totalAmount,
      required double paidAmount,
      required double remainingAmount,
      required String paymentType,
      double receiptDiscount = 0,
      double? cashAmount,
      double? cardAmount,
      double? receivedCash,
      String? paymentNote,
      String? draftId}) async {
    submissions++;
    submitted = {
      'items': items,
      'total': totalAmount,
      'paid': paidAmount,
      'remaining': remainingAmount,
      'type': paymentType,
      'customer': customerId,
      'cash': cashAmount,
      'card': cardAmount,
      'received': receivedCash,
      'discount': receiptDiscount,
      'draft': draftId,
      'note': paymentNote
    };
    await gate?.future;
    return OfflineSaleResult(
        localSaleId: 'receipt-12345678',
        operationId: 'operation-1',
        deficits: [],
        totalAmount: totalAmount);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  late CartDatabase db;
  late PosProducts products;
  late PosSales sales;
  final previewKey = GlobalKey();

  setUpAll(() async {
    final root = Platform.environment['FLUTTER_ROOT'];
    if (root == null) return;
    for (final entry in {
      'Roboto': 'Roboto-Regular.ttf',
      'MaterialIcons': 'MaterialIcons-Regular.otf'
    }.entries) {
      final file =
          File('$root/bin/cache/artifacts/material_fonts/${entry.value}');
      if (await file.exists()) {
        final loader = FontLoader(entry.key);
        loader.addFont(
            Future.value(ByteData.sublistView(await file.readAsBytes())));
        await loader.load();
      }
    }
  });

  setUp(() {
    db = CartDatabase();
    products = PosProducts();
    sales = PosSales();
  });

  Future<void> pumpPage(WidgetTester tester,
      {bool cost = false,
      double width = 393,
      PosClients? clients,
      Brightness brightness = Brightness.dark}) async {
    tester.view.physicalSize = Size(width, 852);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    addTearDown(tester.view.resetViewInsets);
    await tester.pumpWidget(RepaintBoundary(
        key: previewKey,
        child: MaterialApp(
            debugShowCheckedModeBanner: false,
            theme: AppTheme.lightTheme(),
            home: Scaffold(
                body: PosScreen(
                    storeId: '1',
                    brightness: brightness,
                    storeName: 'Million Parfume',
                    canSeeCostPrice: cost,
                    productsRepository: products,
                    clientsRepository: clients ?? PosClients(),
                    saleService: sales,
                    getDb: () async => db,
                    onViewStatistics: () {})))));
    await tester.pumpAndSettle();
  }

  Future<void> capture(WidgetTester tester, String name) async {
    final directory = Platform.environment['POS_PREVIEW_DIR'];
    if (directory == null) return;
    await tester.runAsync(() async {
      final boundary = previewKey.currentContext!.findRenderObject()!
          as RenderRepaintBoundary;
      final image = await boundary.toImage(pixelRatio: 2);
      final bytes = (await image.toByteData(format: ui.ImageByteFormat.png))!;
      await Directory(directory).create(recursive: true);
      await File('$directory/$name.png')
          .writeAsBytes(bytes.buffer.asUint8List());
      image.dispose();
    });
  }

  Future<void> add(WidgetTester tester, {String name = 'Bahor atiri'}) async {
    await tester.tap(find.byTooltip('$name — savatga qo‘shish'));
    await tester.pumpAndSettle();
  }

  Future<void> cart(WidgetTester tester) async {
    await tester.tap(find.text('Savatni ochish'));
    await tester.pumpAndSettle();
  }

  Future<void> payment(WidgetTester tester) async {
    await cart(tester);
    await tester.tap(find.textContaining('To‘lash ·'));
    await tester.pumpAndSettle();
  }

  for (final phone in ['+998901234569', '+79031234567', '901234569']) {
    testWidgets('new customer starts with editable +998 and submits $phone',
        (tester) async {
      final clients = PosClients();
      await pumpPage(tester, clients: clients);
      await add(tester);
      await cart(tester);
      await tester.tap(find.text('Mijoz qo‘shish'));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Yangi mijoz'));
      await tester.tap(find.text('Yangi mijoz'));
      await tester.pumpAndSettle();
      final phoneField = find.byWidgetPredicate((widget) =>
          widget is TextField &&
          widget.decoration?.labelText == 'Telefon raqami');
      final nameField = find.byWidgetPredicate((widget) =>
          widget is TextField && widget.decoration?.labelText == 'Ism');
      expect(tester.widget<TextField>(phoneField).controller!.text, '+998');
      await tester.enterText(nameField, 'Yangi Mijoz');
      await tester.enterText(phoneField, phone);
      await tester.tap(find.text('Saqlash'));
      await tester.pumpAndSettle();
      expect(clients.createdPhone, phone);
      expect(tester.takeException(), isNull);
    });
  }

  for (final brightness in Brightness.values) {
    testWidgets('cart discount and checkout follow ${brightness.name} mode',
        (tester) async {
      await pumpPage(tester, brightness: brightness);
      await add(tester);
      await cart(tester);
      expect(
          Theme.of(tester.element(find.text('Savat'))).brightness, brightness);
      await tester.tap(find.byTooltip('Bahor atiri — chegirma'));
      await tester.pumpAndSettle();
      expect(Theme.of(tester.element(find.text('Qo‘llash'))).brightness,
          brightness);
      await tester.tap(find.text('Qo‘llash'));
      await tester.pumpAndSettle();
      await tester.tap(find.textContaining('To‘lash ·'));
      await tester.pumpAndSettle();
      expect(
          Theme.of(tester.element(find.byKey(const ValueKey('cash-received'))))
              .brightness,
          brightness);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets(
      'catalog matches mobile web hierarchy and category filtering without exposing cost',
      (tester) async {
    await pumpPage(tester);
    expect(find.text('Savdo'), findsOneWidget);
    expect(find.text('MILLION PARFUME'), findsOneWidget);
    expect(find.text('Sotuv statistikasi'), findsOneWidget);
    expect(find.text('Skanerlash'), findsOneWidget);
    expect(find.text('#1 · 0'), findsOneWidget);
    expect(find.text('Bahor atiri'), findsOneWidget);
    expect(find.text('Sovun'), findsOneWidget);
    expect(find.textContaining('Kelgan narxi'), findsNothing);
    expect(Theme.of(tester.element(find.byType(TextField).first)).brightness,
        Brightness.dark);
    await capture(tester, 'catalog');
    await tester.tap(find.text('Atirlar'));
    await tester.pumpAndSettle();
    expect(find.text('Sovun'), findsNothing);
    expect(find.text('Bahor atiri'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'switching catalog appearance preserves the cart and uses readable surfaces',
      (tester) async {
    await pumpPage(tester);
    await add(tester);
    await pumpPage(tester, brightness: Brightness.light);

    final theme = Theme.of(tester.element(find.byType(TextField).first));
    expect(theme.brightness, Brightness.light);
    expect(theme.cardColor, Colors.white);
    expect(find.text('1 mahsulot · 30 000 so‘m'), findsOneWidget);
    expect(find.text('Bahor atiri'), findsOneWidget);

    await pumpPage(tester);
    expect(Theme.of(tester.element(find.byType(TextField).first)).brightness,
        Brightness.dark);
    expect(find.text('1 mahsulot · 30 000 so‘m'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'adding products keeps catalog visible and carts retain separate quantities',
      (tester) async {
    await pumpPage(tester);
    await add(tester);
    expect(find.text('Bahor atiri'), findsOneWidget);
    expect(find.text('1 mahsulot · 30 000 so‘m'), findsOneWidget);
    await cart(tester);
    await tester.tap(find.byTooltip('Bahor atiri — miqdorni oshirish'));
    await tester.pumpAndSettle();
    expect(find.textContaining('To‘lash · 60 000 UZS'), findsOneWidget);
    await capture(tester, 'cart');
    await tester.tap(find.byTooltip('Savat oynasini yopish'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('+ Savat'));
    await tester.pumpAndSettle();
    expect(find.text('#2 · 0'), findsOneWidget);
    expect(find.text('Savatni ochish'), findsNothing);
    await tester.tap(find.text('#1 · 2'));
    await tester.pumpAndSettle();
    expect(find.text('2 mahsulot · 60 000 so‘m'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'item discount, cash keypad, change and duplicate submit preserve checkout amounts',
      (tester) async {
    await pumpPage(tester);
    await add(tester);
    await cart(tester);
    await tester.tap(find.byTooltip('Bahor atiri — chegirma'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('10%'));
    await tester.pumpAndSettle();
    await capture(tester, 'discount');
    await tester.tap(find.text('Qo‘llash'));
    await tester.pumpAndSettle();
    expect(find.textContaining('To‘lash · 27 000 UZS'), findsOneWidget);
    await tester.tap(find.textContaining('To‘lash ·'));
    await tester.pumpAndSettle();
    expect(
        find.widgetWithText(FilledButton, '0').hitTestable(), findsOneWidget);
    await tester.tap(find.byTooltip('Naqd summani tozalash'));
    for (final digit in ['5', '0', '0', '0', '0']) {
      await tester.ensureVisible(find.widgetWithText(FilledButton, digit));
      await tester.tap(find.widgetWithText(FilledButton, digit));
      await tester.pump();
    }
    expect(find.text('23 000'), findsOneWidget);
    await capture(tester, 'payment');
    sales.gate = Completer<void>();
    await tester.tap(find.text('Savdoni yakunlash'));
    await tester.pump();
    expect(sales.submissions, 1);
    expect(sales.submitted!['total'], 27000);
    expect(sales.submitted!['received'], 50000);
    expect(sales.submitted!['cash'], 27000);
    expect(sales.submitted!['items'].single['discount'], '3000.00');
    expect(find.text('Saqlanmoqda...'), findsOneWidget);
    sales.gate!.complete();
    await tester.pumpAndSettle();
    expect(find.text('Savdo muvaffaqiyatli'), findsOneWidget);
    expect(sales.submissions, 1);
    await capture(tester, 'success');
    await tester.tap(find.text('Chekni ko‘rish'));
    await tester.pumpAndSettle();
    final receipt =
        tester.widget<SelectableText>(find.byType(SelectableText)).data!;
    expect(receipt, contains('receipt-12345678'));
    expect(receipt, contains('Chegirma: −3 000 so‘m'));
    expect(receipt, contains('Jami: 27 000 so‘m'));
    expect(receipt, contains('Qaytim: 23 000 so‘m'));
    await capture(tester, 'receipt');
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'insufficient cash stays in payment and retains a draft after closing',
      (tester) async {
    await pumpPage(tester);
    await add(tester);
    await payment(tester);
    await tester.tap(find.byTooltip('Naqd summani tozalash'));
    await tester.tap(find.widgetWithText(FilledButton, '1'));
    await tester.pump();
    await tester.tap(find.text('Savdoni yakunlash'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Qabul qilingan naqd summa'), findsOneWidget);
    expect(sales.submissions, 0);
    await tester.tap(find.byTooltip('To‘lov oynasini yopish'));
    await tester.pumpAndSettle();
    await payment(tester);
    expect(
        tester.widget<Text>(find.byKey(const ValueKey('cash-received'))).data,
        '1');
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'credit sale requires a customer and submits the selected customer',
      (tester) async {
    await pumpPage(tester);
    await add(tester);
    await payment(tester);
    await tester.ensureVisible(find.text('Qarzga'));
    await tester.tap(find.text('Qarzga'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Savdoni yakunlash'));
    await tester.pumpAndSettle();
    expect(find.text('Bu to‘lov turi uchun mijozni tanlang.'), findsOneWidget);
    expect(sales.submissions, 0);
    await tester.tap(find.text('Mijozni tanlang'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).last, '901234567');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ali Valiyev'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Savdoni yakunlash'));
    await tester.pumpAndSettle();
    expect(sales.submitted!['customer'], '42');
    expect(sales.submitted!['type'], 'debt');
    expect(sales.submitted!['paid'], 0);
    expect(sales.submitted!['remaining'], 30000);
    expect(tester.takeException(), isNull);
  });

  testWidgets('multiple sizes keep their distinct stock and size identities',
      (tester) async {
    await pumpPage(tester);
    await add(tester, name: 'Razmerli atir');
    final plus = find.byTooltip('Miqdorni oshirish');
    await tester.tap(plus.at(0));
    await tester.tap(plus.at(1));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Saqlash'));
    await tester.pumpAndSettle();
    await payment(tester);
    await tester.tap(find.text('Savdoni yakunlash'));
    await tester.pumpAndSettle();
    final items = sales.submitted!['items'] as List;
    expect(items.map((item) => item['stock_id']), ['71', '72']);
    expect(items.map((item) => item['product_size_id']), ['21', '22']);
    expect(items.map((item) => item['quantity']), [1, 1]);
    expect(tester.takeException(), isNull);
  });

  for (final scenario in [
    ('card', null, null, true, 0.0),
    ('transfer', null, null, true, 0.0),
    ('mixed', '20000', '10000', true, 0.0),
    ('mixed', '10000', '10000', false, 0.0),
    ('partial', '10000', '5000', true, 15000.0),
    ('partial', '30000', '0', false, 0.0),
    ('preorder', null, null, true, 30000.0),
  ]) {
    testWidgets(
        'payment ${scenario.$1} ${scenario.$2} ${scenario.$3} validates allocation before saving',
        (tester) async {
      await pumpPage(tester);
      await add(tester);
      await payment(tester);
      final method = {
        'card': 'Karta',
        'transfer': 'O‘tkazma',
        'mixed': 'Aralash',
        'partial': 'Qisman',
        'preorder': 'Oldindan buyurtma'
      }[scenario.$1]!;
      await tester.ensureVisible(find.text(method));
      await tester.tap(find.text(method));
      await tester.pumpAndSettle();
      if (scenario.$2 != null) {
        for (final field in {
          'Naqd (so‘m)': scenario.$2!,
          'Karta (so‘m)': scenario.$3!
        }.entries) {
          final finder = find.byWidgetPredicate((widget) =>
              widget is TextField && widget.decoration?.labelText == field.key);
          await tester.ensureVisible(finder);
          await tester.enterText(finder, field.value);
          await tester.pumpAndSettle();
        }
      }
      if (scenario.$1 != 'card') {
        await tester.ensureVisible(find.text('Mijozni tanlang'));
        await tester.tap(find.text('Mijozni tanlang'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Ali Valiyev'));
        await tester.pumpAndSettle();
      }
      await tester.tap(find.text('Savdoni yakunlash'));
      await tester.pumpAndSettle();
      if (scenario.$4) {
        expect(sales.submissions, 1);
        expect(sales.submitted!['type'], scenario.$1);
        expect(sales.submitted!['remaining'], scenario.$5);
        expect(sales.submitted!['paid'], 30000 - scenario.$5);
        expect(find.text('Savdo muvaffaqiyatli'), findsOneWidget);
      } else {
        expect(sales.submissions, 0);
        expect(find.byKey(const ValueKey('payment-error')), findsOneWidget);
      }
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('late search responses do not replace the newest query',
      (tester) async {
    await pumpPage(tester);
    products.delayedSearches['old'] = Completer<List<LocalProduct>>();
    await tester.enterText(find.byType(TextField).first, 'old');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.enterText(find.byType(TextField).first, 'Sovun');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(find.byTooltip('Sovun — savatga qo‘shish'), findsOneWidget);
    products.delayedSearches['old']!
        .complete([products.product('90', 'Old result')]);
    await tester.pumpAndSettle();
    expect(find.text('Old result'), findsNothing);
    expect(find.byTooltip('Sovun — savatga qo‘shish'), findsOneWidget);
  });

  testWidgets('narrow screen and keyboard preserve visible payment controls',
      (tester) async {
    await pumpPage(tester, width: 360, cost: true);
    expect(find.textContaining('Kelgan narxi'), findsWidgets);
    await add(tester);
    await payment(tester);
    tester.view.viewInsets = const FakeViewPadding(bottom: 280);
    await tester.pumpAndSettle();
    expect(find.text('Savdoni yakunlash').hitTestable(), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('catalog errors can be retried without losing the cart',
      (tester) async {
    products.failSearch = true;
    await pumpPage(tester);
    expect(find.text('Qayta urinish'), findsOneWidget);
    products.failSearch = false;
    await tester.tap(find.text('Qayta urinish'));
    await tester.pumpAndSettle();
    expect(find.text('Bahor atiri'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  test('public catalog metadata survives drafts without persisting cost prices',
      () {
    final product = LocalProduct.fromMap({
      'id': '11',
      'store_id': '1',
      'name': 'Atir',
      'initial_price': 18000,
      'image_url': 'https://example.test/photo.jpg',
      'catalog_json':
          '{"category":{"id":"9","name":"Atirlar"},"thumbnail":"https://example.test/thumb.jpg"}'
    });
    final restored = LocalProduct.fromMap(product.toMap());
    expect(restored.categoryId, '9');
    expect(restored.categoryName, 'Atirlar');
    expect(restored.imageUrl, 'https://example.test/photo.jpg');
    expect(restored.thumbnailUrl, 'https://example.test/thumb.jpg');
    expect(restored.initialPrice, isNull);
    expect(product.toMap().containsKey('initial_price'), isFalse);
    expect(PosStyle.money(1234.5), '1 234.5');
  });
}
