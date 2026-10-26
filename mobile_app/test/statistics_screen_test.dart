import 'dart:async';
import 'dart:io';
import 'dart:ui' as ui;
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:babyparfume_mobile/features/statistics/data/statistics_repository.dart';
import 'package:babyparfume_mobile/features/statistics/presentation/statistics_screen.dart';
import 'package:babyparfume_mobile/features/statistics/presentation/statistics_trend_chart.dart';
import 'package:babyparfume_mobile/features/statistics/presentation/statistics_widgets.dart';
import 'statistics_repository_test.dart' show StatisticsApi, statisticsData;

class ScreenStatistics extends StatisticsRepository {
  final filters = <StatisticsFilter>[];
  final stores = <String>[];
  final tokens = <CancelToken?>[];
  final waiting = <Completer<StatisticsResult>>[];
  bool delayed = false;
  Object? error;
  StatisticsResult result;
  ScreenStatistics(this.result) : super(apiClient: StatisticsApi());
  @override
  Future<StatisticsResult> load(String storeId, StatisticsFilter filter,
      {CancelToken? cancelToken}) async {
    filters.add(filter);
    stores.add(storeId);
    tokens.add(cancelToken);
    if (delayed) {
      final completer = Completer<StatisticsResult>();
      waiting.add(completer);
      return completer.future;
    }
    if (error != null) throw error!;
    return result;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  WidgetController.hitTestWarningShouldBeFatal = true;
  final now = DateTime.utc(2026, 10, 23, 12);
  final filter = StatisticsFilter.forPeriod('today', now);
  final api = StatisticsApi();
  final previewKey = GlobalKey();
  late ScreenStatistics repository;
  int back = 0;
  final selectedProducts = <StatisticsProduct>[];

  StatisticsResult report(
          {double total = 1250000,
          double? profit = 250000,
          double? average = 125000,
          double? change = 12.5,
          int count = 10}) =>
      StatisticsResult(
          report: StatisticsReport(
              salesAmount: total,
              salesCount: count,
              averageReceipt: average,
              profit: profit,
              changePercent: change,
              trend: const [
            StatisticsPoint('09:00', 200000),
            StatisticsPoint('12:00', 750000),
            StatisticsPoint('18:00', 300000)
          ],
              payments: const [
            StatisticsPayment(
                key: 'cash', label: 'Naqd', amount: 750000, percent: 60),
            StatisticsPayment(
                key: 'card', label: 'Karta', amount: 500000, percent: 40)
          ],
              products: const [
            StatisticsProduct(
                id: '11', name: 'Bahor atiri', quantity: 8, amount: 640000)
          ]));

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
    repository = ScreenStatistics(report());
    back = 0;
    selectedProducts.clear();
  });

  Future<void> pumpPage(WidgetTester tester,
      {String store = '1',
      bool products = true,
      double width = 393,
      Brightness brightness = Brightness.dark,
      double textScale = 1,
      bool settle = true}) async {
    tester.view.physicalSize = Size(width, 852);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(RepaintBoundary(
        key: previewKey,
        child: MaterialApp(
            debugShowCheckedModeBanner: false,
            theme: StatisticsStyle.theme(brightness: brightness),
            builder: (context, child) => MediaQuery(
                data: MediaQuery.of(context)
                    .copyWith(textScaler: TextScaler.linear(textScale)),
                child: child!),
            home: Scaffold(
                appBar: AppBar(
                    toolbarHeight: 68,
                    title: const Text('BABY PARFUME',
                        style: TextStyle(fontSize: 13))),
                body: StatisticsScreen(
                    storeId: store,
                    apiClient: api,
                    repository: repository,
                    now: () => now,
                    embedded: true,
                    canViewProducts: products,
                    onBack: () => back++,
                    onProductSelected: selectedProducts.add),
                bottomNavigationBar: const SizedBox(
                    height: 68,
                    child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceAround,
                        children: [
                          Text('Savdo'),
                          Text('Mahsulotlar'),
                          Text('Qarzdorlar')
                        ]))))));
    if (settle) {
      await tester.pumpAndSettle();
    } else {
      await tester.pump();
    }
  }

  Future<void> scrollTo(WidgetTester tester, Finder finder) async {
    await tester.scrollUntilVisible(finder, 300,
        scrollable: find.byType(Scrollable).first);
    await Scrollable.ensureVisible(tester.element(finder), alignment: .5);
    await tester.pumpAndSettle();
  }

  Future<void> capture(WidgetTester tester, String name) async {
    final directory = Platform.environment['STATISTICS_PREVIEW_DIR'];
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

  for (final brightness in Brightness.values) {
    testWidgets('report and calendar follow ${brightness.name} mode',
        (tester) async {
      await pumpPage(tester, brightness: brightness);
      expect(Theme.of(tester.element(find.text('Jami savdo'))).brightness,
          brightness);
      await tester.tap(find.text('Oraliq'));
      await tester.pumpAndSettle();
      await tester.tap(find.byTooltip('Boshlanish sanasini tanlash'));
      await tester.pumpAndSettle();
      expect(Theme.of(tester.element(find.byType(DatePickerDialog))).brightness,
          brightness);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets(
      'matches mobile report hierarchy, palette, values and back navigation',
      (tester) async {
    await pumpPage(tester);
    expect(find.text('Sotuv statistikasi'), findsOneWidget);
    expect(find.text('Savdo natijalarini kuzating va solishtiring.'),
        findsOneWidget);
    expect(find.text('Bugun'), findsOneWidget);
    expect(find.text('Hafta'), findsOneWidget);
    expect(find.text('Oy'), findsOneWidget);
    expect(find.text('Oraliq'), findsOneWidget);
    expect(find.text('23.10.2026'), findsOneWidget);
    expect(find.text('1 250 000 so‘m'), findsOneWidget);
    expect(find.text('+12.5% oldingi davrga nisbatan'), findsOneWidget);
    expect(find.text('10 ta'), findsOneWidget);
    expect(
        Theme.of(tester.element(find.text('Jami savdo')))
            .scaffoldBackgroundColor,
        StatisticsStyle.background);
    await capture(tester, 'statistics-overview');
    await tester.tap(find.text('Savdoga qaytish'));
    expect(back, 1);
    await scrollTo(tester, find.text('To‘lov turlari'));
    expect(find.text('60%'), findsOneWidget);
    expect(find.text('40%'), findsOneWidget);
    await scrollTo(tester, find.text('Bahor atiri'));
    await capture(tester, 'statistics-details');
    await tester.tap(find.text('Bahor atiri'));
    expect(selectedProducts.single.id, '11');
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'switches preset filters using current store and clears stale values while loading',
      (tester) async {
    await pumpPage(tester);
    repository.delayed = true;
    await tester.tap(find.text('Hafta'));
    await tester.pump();
    expect(repository.filters.last.parameters,
        {'period': 'week', 'start': '2026-10-19', 'end': '2026-10-23'});
    expect(find.byType(StatisticsSkeleton), findsOneWidget);
    expect(find.text('1 250 000 so‘m'), findsNothing);
    await tester.tap(find.text('Oy'));
    await tester.pump();
    expect(repository.tokens[1]!.isCancelled, isTrue);
    expect(repository.filters.last.parameters['start'], '2026-10-01');
    repository.waiting[1].complete(report(total: 888000));
    await tester.pumpAndSettle();
    repository.waiting[0].complete(report(total: 111000));
    await tester.pumpAndSettle();
    expect(find.text('888 000 so‘m'), findsOneWidget);
    expect(find.text('111 000 so‘m'), findsNothing);
    expect(repository.stores.toSet(), {'1'});
  });

  testWidgets('changing store discards a late result from previous store',
      (tester) async {
    repository.delayed = true;
    await pumpPage(tester, settle: false);
    await pumpPage(tester, store: '2', settle: false);
    repository.waiting[1].complete(report(total: 222000));
    await tester.pumpAndSettle();
    repository.waiting[0].complete(report(total: 111000));
    await tester.pumpAndSettle();
    expect(repository.stores, ['1', '2']);
    expect(find.text('222 000 so‘m'), findsOneWidget);
    expect(find.text('111 000 so‘m'), findsNothing);
  });

  testWidgets(
      'custom range applies explicitly and rejects invalid, reversed, future and overlong dates',
      (tester) async {
    await pumpPage(tester);
    await tester.tap(find.text('Oraliq'));
    await tester.pumpAndSettle();
    final requests = repository.filters.length;
    final start = find.widgetWithText(TextField, 'Boshlanish');
    final end = find.widgetWithText(TextField, 'Tugash');
    await tester.enterText(start, '01.10.2026');
    await tester.pump();
    expect(repository.filters.length, requests);
    expect(
        find.textContaining('Yangi oraliq hali qo‘llanmagan'), findsOneWidget);
    await scrollTo(tester, find.text('Oraliqni qo‘llash'));
    await capture(tester, 'statistics-range');
    await tester.tap(find.text('Oraliqni qo‘llash'));
    await tester.pumpAndSettle();
    expect(repository.filters.last.parameters,
        {'period': 'range', 'start': '2026-10-01', 'end': '2026-10-23'});
    final applied = repository.filters.length;
    for (final dates in [
      ['31.02.2026', '23.10.2026'],
      ['23.10.2026', '01.10.2026'],
      ['01.10.2026', '24.10.2026'],
      ['01.01.2025', '23.10.2026']
    ]) {
      await tester.enterText(start, dates[0]);
      await tester.enterText(end, dates[1]);
      await scrollTo(tester, find.text('Oraliqni qo‘llash'));
      await tester.tap(find.text('Oraliqni qo‘llash'));
      await tester.pumpAndSettle();
      expect(repository.filters.length, applied);
    }
    expect(find.text('Davr 366 kundan oshmasin.'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'failure displays unavailable figures and retry without inventing empty sales',
      (tester) async {
    repository.error = StateError('offline');
    await pumpPage(tester);
    expect(
        find.text('Hisobot olinmadi. Qayta urinib ko‘ring.'), findsOneWidget);
    expect(find.text('0 so‘m'), findsNothing);
    expect(find.text('Sotuv ma’lumotlari topilmadi'), findsNothing);
    repository.error = null;
    await tester.tap(find.text('Qayta urinish'));
    await tester.pumpAndSettle();
    expect(find.text('1 250 000 so‘m'), findsOneWidget);
    expect(repository.filters.length, 2);
  });

  testWidgets(
      'real zero report has empty state and unavailable profit stays a dash',
      (tester) async {
    repository.result =
        report(total: 0, profit: null, average: null, change: null, count: 0);
    await pumpPage(tester);
    expect(find.text('0 so‘m'), findsOneWidget);
    await scrollTo(tester, find.text('Ma’lumot mavjud emas'));
    expect(
        find.descendant(
            of: find.ancestor(
                of: find.text('Ma’lumot mavjud emas'),
                matching: find.byType(StatisticsPanel)),
            matching: find.text('—')),
        findsOneWidget);
    await scrollTo(tester, find.text('Sotuv ma’lumotlari topilmadi'));
    expect(find.text('Sotuv ma’lumotlari topilmadi'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'cache age and pending local sales are separate from reported server total',
      (tester) async {
    repository.result = StatisticsResult(
        report: report().report,
        cachedAt: DateTime.utc(2026, 10, 23, 8, 15),
        pendingCount: 2,
        pendingAmount: 40000);
    await pumpPage(tester);
    expect(find.text('Saqlangan server hisoboti'), findsOneWidget);
    expect(find.text('23.10.2026 13:15'), findsOneWidget);
    expect(find.text('2 ta tasdiqlanmagan lokal savdo · 40 000 so‘m'),
        findsOneWidget);
    await scrollTo(tester, find.byKey(const ValueKey('statistics-total')));
    expect(find.text('1 250 000 so‘m'), findsOneWidget);
    expect(find.text('1 290 000 so‘m'), findsNothing);
  });

  testWidgets('top products do not navigate when product permission is absent',
      (tester) async {
    await pumpPage(tester, products: false);
    await scrollTo(tester, find.text('Bahor atiri'));
    await tester.tap(find.text('Bahor atiri'));
    expect(selectedProducts, isEmpty);
    expect(find.byIcon(Icons.chevron_right), findsNothing);
  });

  testWidgets(
      'chart supports tapping, keyboard navigation and expandable exact values',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
        theme: StatisticsStyle.theme(),
        home: Scaffold(
            body: SizedBox(
                width: 350,
                child: StatisticsTrendChart(points: report().report!.trend)))));
    final chart = find.byKey(const ValueKey('statistics-trend'));
    await tester.tapAt(tester.getCenter(chart));
    await tester.pump();
    expect(find.byKey(const ValueKey('trend-readout')), findsOneWidget);
    expect(find.text('750 000 so‘m'), findsOneWidget);
    await tester.sendKeyEvent(LogicalKeyboardKey.end);
    await tester.pump();
    expect(find.text('300 000 so‘m'), findsOneWidget);
    await tester.sendKeyEvent(LogicalKeyboardKey.arrowLeft);
    await tester.pump();
    expect(find.text('750 000 so‘m'), findsOneWidget);
    await tester.tap(find.text('Qiymatlarni ro‘yxatda ko‘rish'));
    await tester.pump();
    expect(find.text('200 000 so‘m'), findsOneWidget);
    expect(find.text('300 000 so‘m'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'large values and enlarged text fit a narrow phone without overflow',
      (tester) async {
    repository.result = report(
        total: 9876543210123.45,
        profit: -987654321.45,
        average: 1234567890.55,
        change: -32.5);
    await pumpPage(tester, width: 320, textScale: 1.4);
    await capture(tester, 'statistics-narrow');
    await scrollTo(tester, find.text('Bahor atiri'));
    expect(tester.takeException(), isNull);
  });

  test(
      'report retains null and decimal values without converting them into zero',
      () {
    final data = {
      ...statisticsData(filter),
      'sales_amount': '12345.67',
      'profit': null,
      'average_receipt': null
    };
    final parsed = StatisticsReport.fromJson(data, '1', filter);
    expect(parsed.salesAmount, 12345.67);
    expect(parsed.profit, isNull);
    expect(parsed.averageReceipt, isNull);
  });
}
