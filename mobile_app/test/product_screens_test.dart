import 'dart:async';
import 'dart:io';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:babyparfume_mobile/core/sync/outbox_manager.dart';
import 'package:babyparfume_mobile/core/theme/app_theme.dart';
import 'package:babyparfume_mobile/features/products/data/products_repository.dart';
import 'package:babyparfume_mobile/features/products/presentation/product_detail_screen.dart';
import 'package:babyparfume_mobile/features/products/presentation/product_form_dialog.dart';
import 'package:babyparfume_mobile/features/products/presentation/products_screen.dart';

LocalProduct sampleProduct({bool editable = true}) => LocalProduct(
    id: '11',
    storeId: '1',
    name: 'Dior Sauvage Eau de Parfum 100 ml',
    barcode: '0012345678905',
    type: 'package',
    price: 1500000,
    initialPrice: editable ? 1200000 : null,
    stockQuantity: 8,
    updatedAt: '2026-10-22T10:30:00+05:00',
    canUpdate: editable,
    canDelete: editable);

class PreviewProductsRepository extends ProductsRepository {
  LocalProduct product;
  bool failLoad = false;
  bool sized = false;
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
            stockQuantity: 8,
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
          {'id': '71', 'name': 'Asosiy savdo ombori'}
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
