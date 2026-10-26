import 'dart:async';
import 'dart:io';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:dio/dio.dart';
import 'package:babyparfume_mobile/core/sync/outbox_manager.dart';
import 'package:babyparfume_mobile/core/theme/app_theme.dart';
import 'package:babyparfume_mobile/features/products/data/products_repository.dart';
import 'package:babyparfume_mobile/features/products/presentation/product_detail_screen.dart';
import 'package:babyparfume_mobile/features/products/presentation/product_form_dialog.dart';
import 'package:babyparfume_mobile/features/products/presentation/product_stock_in_dialog.dart';
import 'package:babyparfume_mobile/features/products/presentation/products_screen.dart';
import 'package:babyparfume_mobile/features/products/presentation/product_widgets.dart';

LocalProduct sampleProduct({bool editable = true, int quantity = 8}) =>
    LocalProduct(
        id: '11',
        storeId: '1',
        name: 'Dior Sauvage Eau de Parfum 100 ml',
        barcode: '0012345678905',
        type: 'package',
        price: 1500000,
        initialPrice: editable ? 1200000 : null,
        stockQuantity: quantity,
        updatedAt: '2026-10-22T10:30:00+05:00',
        canUpdate: editable,
        canDelete: editable);

class PreviewProductsRepository extends ProductsRepository {
  LocalProduct product;
  bool failLoad = false;
  bool sized = false;
  bool multipleStocks = false;
  bool includeInactiveStock = false;
  bool failReceiptOnce = false;
  int receipts = 0;
  final receiptRequests = <Map<String, dynamic>>[];
  int saves = 0;
  Map<String, dynamic>? savedPayload;
  Completer<void>? saveGate;
  final searches = <String?>[];
  PreviewProductsRepository({bool editable = true})
      : product = sampleProduct(editable: editable),
        super(outboxManager: OutboxManager());

  @override
  Future<LocalProduct?> getProduct(String storeId, String productId) async =>
      product;
  @override
  Future<List<LocalProduct>> searchProducts(String storeId,
      {String? query}) async {
    searches.add(query);
    return [product];
  }

  @override
  Future<List<LocalProduct>> variants(LocalProduct product) async => [
        LocalProduct(
            id: product.id,
            storeId: '1',
            name: product.name,
            type: 'package',
            price: product.price,
            stockQuantity: product.stockQuantity,
            stockId: '71',
            stockName: 'Asosiy savdo ombori'),
      ];
  @override
  Future<Map<String, dynamic>> formData(
      String storeId, String? productId) async {
    if (failLoad) throw StateError('Offline');
    return {
      if (productId != null)
        'data': {
          'name': product.name,
          'barcode': product.barcode,
          'price': '1500000',
          'initial_price': '1200000',
          'type': sized ? 'size' : 'package',
        },
      'edit': {
        'stocks': [
          {'id': '71', 'name': 'Asosiy savdo ombori'},
          if (multipleStocks) {'id': '72', 'name': 'Ikkinchi ombor'},
          if (includeInactiveStock)
            {'id': '73', 'name': 'Yopiq ombor', 'is_active': false},
        ],
        'quantities': [
          {'stock_id': '71', 'quantity': 8}
        ],
        'sizes': sized
            ? [
                {
                  'id': '21',
                  'size': '100 ml',
                  'stocks': [
                    {'stock_id': '71', 'quantity': 8}
                  ]
                }
              ]
            : [],
      },
    };
  }

  @override
  Future<LocalProduct> receiveStock(LocalProduct product,
      {required String requestId,
      required String stockId,
      String? sizeId,
      required int quantity}) async {
    receipts++;
    receiptRequests.add({
      'request_id': requestId,
      'stock_id': stockId,
      'product_size_id': sizeId,
      'quantity': quantity
    });
    await saveGate?.future;
    if (failReceiptOnce) {
      failReceiptOnce = false;
      throw DioException.connectionError(
          requestOptions: RequestOptions(path: '/stock-in'), reason: 'Offline');
    }
    this.product = sampleProduct(quantity: product.stockQuantity + quantity);
    return this.product;
  }

  @override
  Future<LocalProduct> saveProduct(String storeId, Map<String, dynamic> payload,
      {String? productId, Uint8List? photo}) async {
    saves++;
    savedPayload = payload;
    await saveGate?.future;
    product = LocalProduct(
        id: '11',
        storeId: storeId,
        name: payload['name'] as String,
        barcode: payload['barcode'] as String,
        type: payload['type'] as String,
        price: (payload['price'] as num).toDouble(),
        initialPrice: (payload['initial_price'] as num).toDouble(),
        stockQuantity: 8,
        canUpdate: true);
    return product;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  final previewKey = GlobalKey();

  setUpAll(() async {
    final root = Platform.environment['FLUTTER_ROOT'];
    if (root == null) return;
    final font =
        File('$root/bin/cache/artifacts/material_fonts/Roboto-Regular.ttf');
    if (await font.exists()) {
      final loader = FontLoader('Roboto');
      loader.addFont(
          Future.value(ByteData.sublistView(await font.readAsBytes())));
      await loader.load();
    }
    final icons = File(
        '$root/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf');
    if (await icons.exists()) {
      final loader = FontLoader('MaterialIcons');
      loader.addFont(
          Future.value(ByteData.sublistView(await icons.readAsBytes())));
      await loader.load();
    }
  });

  Future<void> capture(WidgetTester tester, String name) async {
    final directory = Platform.environment['PRODUCT_PREVIEW_DIR'];
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

  Future<void> pumpPage(WidgetTester tester, Widget page,
      {bool dark = false}) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    addTearDown(tester.view.resetViewInsets);
    final theme = dark ? AppTheme.darkTheme() : AppTheme.lightTheme();
    await tester.pumpWidget(RepaintBoundary(
        key: previewKey,
        child: MaterialApp(
            debugShowCheckedModeBanner: false,
            theme: theme.copyWith(
                elevatedButtonTheme: ElevatedButtonThemeData(
                    style: theme.elevatedButtonTheme.style?.copyWith(
                        textStyle: const WidgetStatePropertyAll(TextStyle(
                            fontFamily: 'Roboto',
                            fontSize: 14,
                            fontWeight: FontWeight.w600))))),
            home: page)));
    await tester.pumpAndSettle();
  }

  Future<void> pumpForm(
      WidgetTester tester, PreviewProductsRepository repository,
      {bool create = false, void Function(LocalProduct)? onSaved}) async {
    await pumpPage(
        tester,
        Builder(
            builder: (context) => Scaffold(
                body: Center(
                    child: TextButton(
                        onPressed: () => showDialog<void>(
                            context: context,
                            builder: (_) => ProductFormDialog(
                                storeId: '1',
                                repository: repository,
                                existing: create ? null : repository.product,
                                onCreated: onSaved ?? (_) {})),
                        child: const Text('Formani ochish'))))));
    await tester.tap(find.text('Formani ochish'));
    await tester.pumpAndSettle();
  }

  Finder field(String label) => find.widgetWithText(TextFormField, label);

  for (final dark in [false, true]) {
    testWidgets(
        'stock receipt is readable and adds quantity in ${dark ? 'dark' : 'light'} mode',
        (tester) async {
      final repository = PreviewProductsRepository()
        ..includeInactiveStock = true
        ..saveGate = Completer<void>();
      await pumpPage(
          tester,
          ProductDetailScreen(
              product: repository.product, repository: repository),
          dark: dark);
      expect(tester.getRect(find.text('Kirim qilish')).left,
          greaterThan(tester.getRect(find.text('Mahsulotni tahrirlash')).left));
      await tester.tap(find.text('Kirim qilish'));
      await tester.pumpAndSettle();
      expect(
          tester.widget<TextFormField>(field('Mahsulot nomi')).enabled, false);
      expect(tester.widget<TextFormField>(field('Shtrix-kod')).enabled, false);
      expect(find.text('Yopiq ombor'), findsNothing);
      await tester.tap(find.text('Kirimni saqlash'));
      await tester.pumpAndSettle();
      expect(repository.receipts, 0);
      expect(find.text('Kamida 1 dona kiriting.'), findsOneWidget);
      await tester.enterText(field('Kirim miqdori'), '5');
      await tester.pumpAndSettle();
      expect(find.text('Joriy qoldiq: 8 dona\nKirimdan keyin: 13 dona'),
          findsOneWidget);
      await capture(tester, 'product-stock-in-${dark ? 'dark' : 'light'}');
      tester.view.physicalSize = const Size(320, 640);
      tester.view.viewInsets = const FakeViewPadding(bottom: 260);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(tester.getRect(find.text('Kirimni saqlash')).bottom,
          lessThanOrEqualTo(380));
      await tester.tap(find.text('Kirimni saqlash'));
      await tester.pump();
      await tester.tap(find.text('Saqlanmoqda...'));
      await tester.pump();
      expect(repository.receipts, 1);
      expect(repository.receiptRequests.single['quantity'], 5);
      expect(repository.receiptRequests.single['stock_id'], '71');
      repository.saveGate!.complete();
      await tester.pumpAndSettle();
      expect(find.byType(ProductStockInDialog), findsNothing);
      expect(repository.product.stockQuantity, 13);
      expect(find.text('Kirim mahsulot qoldig‘iga qo‘shildi.'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets(
      'stock receipt requires a warehouse and submits the selected size',
      (tester) async {
    final repository = PreviewProductsRepository()
      ..sized = true
      ..multipleStocks = true;
    await pumpPage(
        tester,
        ProductDetailScreen(
            product: repository.product, repository: repository));
    await tester.tap(find.text('Kirim qilish'));
    await tester.pumpAndSettle();
    await tester.enterText(field('Kirim miqdori'), '2');
    await tester.tap(find.text('Kirimni saqlash'));
    await tester.pumpAndSettle();
    expect(repository.receipts, 0);
    expect(find.text('Omborni tanlang.'), findsOneWidget);
    await tester.tap(find.byType(DropdownButtonFormField<String>).first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ikkinchi ombor').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Kirimni saqlash'));
    await tester.pumpAndSettle();
    expect(repository.receiptRequests.single['stock_id'], '72');
    expect(repository.receiptRequests.single['product_size_id'], '21');
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'receipt retry retains the same identity after an uncertain network failure',
      (tester) async {
    final repository = PreviewProductsRepository()..failReceiptOnce = true;
    await pumpPage(
        tester,
        ProductDetailScreen(
            product: repository.product, repository: repository));
    await tester.tap(find.text('Kirim qilish'));
    await tester.pumpAndSettle();
    await tester.enterText(field('Kirim miqdori'), '3');
    await tester.tap(find.text('Kirimni saqlash'));
    await tester.pumpAndSettle();
    expect(
        tester
            .widget<TextField>(find.descendant(
                of: field('Kirim miqdori'), matching: find.byType(TextField)))
            .readOnly,
        true);
    await tester.tap(find.text('Qayta urinish'));
    await tester.pumpAndSettle();
    expect(repository.receiptRequests, hasLength(2));
    expect(repository.receiptRequests.first, repository.receiptRequests.last);
    expect(repository.product.stockQuantity, 11);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'stock receipt retries loading options before permitting submission',
      (tester) async {
    final repository = PreviewProductsRepository()..failLoad = true;
    await pumpPage(
        tester,
        ProductDetailScreen(
            product: repository.product, repository: repository));
    await tester.tap(find.text('Kirim qilish'));
    await tester.pumpAndSettle();
    expect(field('Kirim miqdori'), findsNothing);
    expect(
        tester
            .widget<ElevatedButton>(find.descendant(
                of: find.byType(ProductStockInDialog),
                matching: find
                    .byWidgetPredicate((widget) => widget is ElevatedButton)))
            .onPressed,
        isNull);
    repository.failLoad = false;
    await tester.tap(find.text('Qayta urinish'));
    await tester.pumpAndSettle();
    expect(field('Kirim miqdori'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'product detail has readable prices, named stocks and an accessible edit action',
      (tester) async {
    final repository = PreviewProductsRepository();
    await pumpPage(
        tester,
        ProductDetailScreen(
            product: repository.product, repository: repository));
    expect(find.text(repository.product.name), findsOneWidget);
    expect(find.text('Mahsulotni tahrirlash'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await capture(tester, 'product-detail');
    await tester.scrollUntilVisible(find.text('Asosiy savdo ombori'), 250,
        scrollable: find.byType(Scrollable).first);
    expect(find.text('Asosiy savdo ombori'), findsOneWidget);
    await tester.tap(find.text('Mahsulotni tahrirlash'));
    await tester.pumpAndSettle();
    expect(field('Mahsulot nomi'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('read only product hides cost and edit actions in dark mode',
      (tester) async {
    final repository = PreviewProductsRepository(editable: false);
    await pumpPage(
        tester,
        ProductDetailScreen(
            product: repository.product, repository: repository),
        dark: true);
    expect(find.text('Kelish narxi'), findsNothing);
    expect(find.text('Mahsulotni tahrirlash'), findsNothing);
    expect(find.text('Kirim qilish'), findsNothing);
    expect(find.byType(PopupMenuButton<String>), findsNothing);
    expect(tester.takeException(), isNull);
    await capture(tester, 'product-detail-dark');
  });

  testWidgets(
      'edit form fits a small phone with keyboard and prevents duplicate submission',
      (tester) async {
    final repository = PreviewProductsRepository()
      ..saveGate = Completer<void>();
    LocalProduct? saved;
    await pumpForm(tester, repository, onSaved: (product) => saved = product);
    await capture(tester, 'product-edit');
    tester.view.physicalSize = const Size(320, 640);
    tester.view.viewInsets = const FakeViewPadding(bottom: 260);
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    final button = find.text('Saqlash');
    expect(tester.getRect(button).bottom, lessThanOrEqualTo(380));
    await tester.tap(button);
    await tester.pump();
    expect(repository.saves, 1);
    expect(repository.savedPayload?['barcode'], '0012345678905');
    expect(repository.savedPayload?['stocks'], [
      {'stock_id': '71', 'quantity': 8}
    ]);
    expect(
        tester
            .widget<ElevatedButton>(
                find.byWidgetPredicate((widget) => widget is ElevatedButton))
            .onPressed,
        isNull);
    repository.saveGate!.complete();
    await tester.pumpAndSettle();
    expect(saved?.id, '11');
    expect(find.byType(ProductFormDialog), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('create validates offscreen prices before making a request',
      (tester) async {
    final repository = PreviewProductsRepository();
    await pumpForm(tester, repository, create: true);
    await tester.tap(find.text('Saqlash'));
    await tester.pumpAndSettle();
    expect(repository.saves, 0);
    expect(find.text('Mahsulot nomi kiriting.'), findsOneWidget);
    expect(find.text('Kelish narxi kiriting.'), findsOneWidget);
    expect(find.text('Sotish narxi kiriting.'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('sized edit retains size identifiers and warehouse quantities',
      (tester) async {
    final repository = PreviewProductsRepository()..sized = true;
    await pumpForm(tester, repository);
    await tester.tap(find.text('Saqlash'));
    await tester.pumpAndSettle();
    expect(repository.savedPayload?['sizes'], [
      {
        'id': '21',
        'size': '100 ml',
        'stocks': [
          {'stock_id': '71', 'quantity': 8}
        ]
      }
    ]);
    expect(repository.savedPayload?.containsKey('stocks'), isFalse);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'product photo offers both camera and gallery without changing the form on cancel',
      (tester) async {
    final repository = PreviewProductsRepository();
    await pumpForm(tester, repository);
    await tester.ensureVisible(find.text('Rasm tanlash'));
    await tester.tap(find.text('Rasm tanlash'));
    await tester.pumpAndSettle();
    expect(find.text('Kamera'), findsOneWidget);
    expect(find.text('Galereya'), findsOneWidget);
    await tester.tap(find.text('Bekor qilish'));
    await tester.pumpAndSettle();
    expect(find.byType(ProductFormDialog), findsOneWidget);
    expect(repository.saves, 0);
    expect(tester.takeException(), isNull);
  });

  testWidgets('failed form load offers retry and never submits a blank form',
      (tester) async {
    final repository = PreviewProductsRepository()..failLoad = true;
    await pumpForm(tester, repository);
    expect(find.text('Saqlash'), findsNothing);
    repository.failLoad = false;
    await tester.tap(find.text('Qayta urinish'));
    await tester.pumpAndSettle();
    expect(field('Mahsulot nomi'), findsOneWidget);
    expect(find.text('Saqlash'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('product list displays the product photo', (tester) async {
    final repository = PreviewProductsRepository();
    repository.product = LocalProduct.fromMap({
      ...repository.product.toMap(),
      'image': 'https://shop.test/photos/product.webp',
    });
    await pumpPage(
        tester, ProductsScreen(storeId: '1', repository: repository));

    expect(tester.widget<ProductImage>(find.byType(ProductImage)).url,
        repository.product.imageUrl);
    expect(find.byType(Image), findsOneWidget);
  });

  for (final dark in [false, true]) {
    testWidgets(
        'barcode selection preserves photos in ${dark ? 'dark' : 'light'} mode',
        (tester) async {
      final repository = PreviewProductsRepository();
      repository.product = LocalProduct.fromMap({
        ...repository.product.toMap(),
        'image': 'https://shop.test/photos/product.webp',
      });
      await pumpPage(
          tester,
          ProductsScreen(
              storeId: '1', repository: repository, canPrintBarcodes: true),
          dark: dark);

      final printButton = find.byWidgetPredicate((widget) =>
          widget is IconButton &&
          widget.tooltip == 'Tanlangan barcodelarni chop etish');
      expect(tester.widget<IconButton>(printButton).onPressed, isNull);
      expect(find.byTooltip('Chop etish tarixi'), findsOneWidget);
      expect(find.byType(ProductImage), findsOneWidget);

      await tester.tap(find.byType(Checkbox));
      await tester.pumpAndSettle();
      expect(tester.widget<Checkbox>(find.byType(Checkbox)).value, isTrue);
      expect(tester.widget<IconButton>(printButton).onPressed, isNotNull);

      await tester.tap(find.text(repository.product.name));
      await tester.pumpAndSettle();
      expect(tester.widget<Checkbox>(find.byType(Checkbox)).value, isFalse);
      expect(tester.widget<IconButton>(printButton).onPressed, isNull);
      expect(find.byType(ProductDetailScreen), findsNothing);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('barcode actions are hidden without printing permission',
      (tester) async {
    await pumpPage(tester,
        ProductsScreen(storeId: '1', repository: PreviewProductsRepository()));

    expect(find.byType(Checkbox), findsNothing);
    expect(find.byTooltip('Tanlangan barcodelarni chop etish'), findsNothing);
    expect(find.byTooltip('Chop etish tarixi'), findsNothing);
  });

  testWidgets('background product updates preserve the current search',
      (tester) async {
    final repository = PreviewProductsRepository();
    Widget page(int revision) => ProductsScreen(
        key: const ValueKey('products'),
        storeId: '1',
        repository: repository,
        revision: revision);
    await pumpPage(tester, page(0));
    await tester.enterText(find.byType(TextField), 'Sauvage');
    await tester.pumpAndSettle();
    await pumpPage(tester, page(1));
    expect(tester.widget<TextField>(find.byType(TextField)).controller?.text,
        'Sauvage');
    expect(repository.searches.last, 'Sauvage');
  });
}
