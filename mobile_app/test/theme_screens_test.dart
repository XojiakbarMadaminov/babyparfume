import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:babyparfume_mobile/core/api/api_client.dart';
import 'package:babyparfume_mobile/core/lease/lease_manager.dart';
import 'package:babyparfume_mobile/core/storage/secure_storage.dart';
import 'package:babyparfume_mobile/core/sync/outbox_manager.dart';
import 'package:babyparfume_mobile/core/sync/sync_coordinator.dart';
import 'package:babyparfume_mobile/core/sync/sync_models.dart';
import 'package:babyparfume_mobile/core/theme/app_colors.dart';
import 'package:babyparfume_mobile/core/theme/app_theme.dart';
import 'package:babyparfume_mobile/features/conflicts/presentation/conflicts_screen.dart';
import 'package:babyparfume_mobile/features/debtors/data/debtors_repository.dart';
import 'package:babyparfume_mobile/features/debtors/presentation/debtors_screen.dart';
import 'package:babyparfume_mobile/features/lease_lock/presentation/lease_lock_screen.dart';
import 'package:babyparfume_mobile/features/pos/presentation/pos_widgets.dart';
import 'package:babyparfume_mobile/features/products/presentation/products_screen.dart';
import 'package:babyparfume_mobile/features/statistics/presentation/statistics_widgets.dart';
import 'package:babyparfume_mobile/main.dart';
import 'photo_picker_test.dart' show PhotoDebtorsRepository;
import 'product_screens_test.dart' show PreviewProductsRepository;

double contrast(Color foreground, Color background) {
  final first = foreground.computeLuminance();
  final second = background.computeLuminance();
  return first > second
      ? (first + .05) / (second + .05)
      : (second + .05) / (first + .05);
}

void expectReadable(WidgetTester tester, Finder finder, Color background) {
  final element = tester.element(finder.first);
  final text = tester.widget<Text>(finder.first);
  final color = DefaultTextStyle.of(element).style.merge(text.style).color!;
  expect(contrast(color, background), greaterThanOrEqualTo(4.5),
      reason: '${text.data} must remain readable on its surface');
}

class ThemeDebtors extends PhotoDebtorsRepository {
  @override
  Future<List<LocalDebtor>> getDebtors(String storeId,
          {String? query, String filter = 'all'}) async =>
      [debtor];
}

class ThemeOutbox extends OutboxManager {
  @override
  Future<List<OutboxItem>> getPendingOperations(
          {String? storeId, bool includeAll = false}) async =>
      [
        OutboxItem(
            operationId: 'theme-preview',
            entityType: 'sale',
            operationType: 'sale.create',
            payload: {},
            status: 'failed',
            lastError: 'Internet bilan ulanish uzildi',
            createdAt: DateTime(2026, 10, 24)),
      ];
}

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));

  for (final brightness in Brightness.values) {
    final dark = brightness == Brightness.dark;
    final palette = AppColors.forBrightness(brightness);
    final theme = dark ? AppTheme.darkTheme() : AppTheme.lightTheme();

    Future<void> pumpPage(WidgetTester tester, Widget page) async {
      tester.view.physicalSize = const Size(393, 852);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(MaterialApp(theme: theme, home: page));
      await tester.pumpAndSettle();
    }

    test('${brightness.name} palettes preserve readable text and status colors',
        () {
      for (final colors in [
        palette,
        PosPalette(dark),
        StatisticsPalette(dark)
      ]) {
        for (final background in [
          colors.background,
          colors.card,
          colors.soft
        ]) {
          for (final foreground in [colors.text, colors.muted, colors.accent]) {
            expect(contrast(foreground, background), greaterThanOrEqualTo(4.5),
                reason: '${colors.runtimeType}: $foreground on $background');
          }
        }
        for (final pair in [
          (colors.danger, colors.dangerBackground),
          (colors.success, colors.successBackground),
          (colors.warning, colors.warningBackground),
          (colors.accent, colors.tint),
          (colors.onButton, colors.button),
        ]) {
          expect(contrast(pair.$1, pair.$2), greaterThanOrEqualTo(4.5));
        }
      }
    });

    testWidgets(
        '${brightness.name} product list and pushed detail stay readable',
        (tester) async {
      final repository = PreviewProductsRepository();
      await pumpPage(
          tester,
          ProductsScreen(
              storeId: '1', repository: repository, canCreate: true));
      expectReadable(tester, find.text(repository.product.name), palette.card);
      await tester.tap(find.text(repository.product.name));
      await tester.pumpAndSettle();
      expect(
          Theme.of(tester.element(find.text(repository.product.name).first))
              .brightness,
          brightness);
      expectReadable(tester, find.text(repository.product.name), palette.card);
      expect(tester.takeException(), isNull);
    });

    testWidgets(
        '${brightness.name} debtor detail and payment sheet use the theme',
        (tester) async {
      final repository = ThemeDebtors();
      await pumpPage(tester,
          DebtorsScreen(storeId: '1', repository: repository, canCreate: true));
      expectReadable(tester, find.text('Ali Valiyev'), palette.card);
      await tester.tap(find.text('Ali Valiyev'));
      await tester.pumpAndSettle();
      expect(
          Theme.of(tester.element(find.text('Ali Valiyev').first)).brightness,
          brightness);
      expectReadable(tester, find.text('Ali Valiyev'), palette.card);
      await tester.tap(find.text("To'lov olish"));
      await tester.pumpAndSettle();
      final input = tester.element(find.byType(TextField).first);
      expect(Theme.of(input).brightness, brightness);
      expect(Theme.of(input).inputDecorationTheme.fillColor, palette.card);
      expect(tester.takeException(), isNull);
    });

    testWidgets('${brightness.name} sync warning and errors stay readable',
        (tester) async {
      final storage = SecureStorageService();
      final outbox = ThemeOutbox();
      final lease = LeaseManager(storage: storage);
      final sync = SyncCoordinator(
          apiClient: ApiClient(storage: storage),
          outboxManager: outbox,
          leaseManager: lease);
      addTearDown(sync.dispose);
      addTearDown(lease.dispose);
      await pumpPage(
          tester,
          ConflictsScreen(
              storeId: '1', syncCoordinator: sync, outboxManager: outbox));
      expectReadable(tester, find.textContaining('1 ta operatsiya'),
          palette.warningBackground);
      expectReadable(tester,
          find.text('Xatolik: Internet bilan ulanish uzildi'), palette.card);
      expect(tester.takeException(), isNull);
    });

    testWidgets('${brightness.name} lock screen explanations stay readable',
        (tester) async {
      await pumpPage(tester,
          LeaseLockScreen(reason: 'Internetga ulaning', onRetry: () {}));
      expectReadable(
          tester, find.text('Oflayn muddat yakunlandi'), palette.background);
      expectReadable(
          tester, find.text('Internetga ulaning'), palette.background);
      expect(tester.takeException(), isNull);
    });

    testWidgets(
        '${brightness.name} saved preference reaches login and new routes',
        (tester) async {
      FlutterSecureStorage.setMockInitialValues(
          {'flutter_theme_mode': brightness.name});
      await tester.pumpWidget(const BabyParfumeApp());
      await tester.pumpAndSettle();
      final login = tester.element(find.text('Tizimga kirish'));
      expect(Theme.of(login).brightness, brightness);
      expectReadable(
          tester, find.text('Savdo va ombor boshqaruvi'), palette.background);
      Navigator.of(login).push(MaterialPageRoute<void>(
          builder: (_) => const Scaffold(body: Text('New route'))));
      await tester.pumpAndSettle();
      expect(Theme.of(tester.element(find.text('New route'))).brightness,
          brightness);
      expect(await SecureStorageService().getThemeMode(), brightness.name);
      expect(tester.takeException(), isNull);
    });
  }
}
