import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:babyparfume_mobile/core/theme/app_theme.dart';
import 'package:babyparfume_mobile/features/auth/data/auth_repository.dart';
import 'package:babyparfume_mobile/features/shell/presentation/shell_header.dart';

void main() {
  final stores = [
    StoreItem(id: 1, name: 'Million Parfume'),
    StoreItem(id: 2, name: 'Juda uzun nomli ikkinchi do‘kon'),
  ];

  for (final width in [320.0, 393.0]) {
    testWidgets('header fits a $width pixel screen with long names',
        (tester) async {
      tester.view.physicalSize = Size(width, 700);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(MaterialApp(
          theme: AppTheme.darkTheme(),
          home: Scaffold(
              appBar: ShellHeader(
                  stores: stores,
                  storeId: '2',
                  userName: 'Juda uzun ismli foydalanuvchi',
                  isDark: true,
                  onStoreChanged: (_) {},
                  onBrightnessChanged: (_) {},
                  onSync: () {},
                  onLogout: () {}))));
      await tester.pumpAndSettle();

      expect(find.image(const AssetImage('assets/images/logo.png')),
          findsOneWidget);
      expect(find.text('JUDA UZUN NOMLI IKKINCHI DO‘KON'), findsOneWidget);
      expect(find.byTooltip('Kunduzgi rejim'), findsOneWidget);
      expect(find.byTooltip('Tungi rejim'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('header selects a store and forwards action callbacks',
      (tester) async {
    String? selectedStore;
    Brightness? brightness;
    var syncs = 0;
    var logouts = 0;
    await tester.pumpWidget(MaterialApp(
        theme: AppTheme.darkTheme(),
        home: Scaffold(
            appBar: ShellHeader(
                stores: stores,
                storeId: '1',
                userName: 'Dilshod',
                isDark: true,
                onStoreChanged: (value) => selectedStore = value,
                onBrightnessChanged: (value) => brightness = value,
                onSync: () => syncs++,
                onLogout: () => logouts++))));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Do‘konni tanlash'));
    await tester.pumpAndSettle();
    await tester.tap(
        find.widgetWithText(CheckedPopupMenuItem<String>, stores.last.name));
    await tester.pumpAndSettle();
    expect(selectedStore, '2');

    await tester.tap(find.byTooltip('Kunduzgi rejim'));
    expect(brightness, Brightness.light);
    await tester.tap(find.byTooltip('Tungi rejim'));
    expect(brightness, Brightness.dark);
    await tester.tap(find.byTooltip('Server bilan sinxronlash'));
    await tester.tap(find.byTooltip('Hisobdan chiqish'));
    expect(syncs, 1);
    expect(logouts, 1);
  });

  testWidgets('busy header disables store switching, sync and logout',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
        home: Scaffold(
            appBar: ShellHeader(
                stores: stores,
                storeId: '1',
                userName: 'Dilshod',
                isDark: true,
                onStoreChanged: null,
                onBrightnessChanged: (_) {},
                onSync: null,
                onLogout: null))));
    expect(
        tester
            .widget<PopupMenuButton<String>>(
                find.byType(PopupMenuButton<String>))
            .enabled,
        isFalse);
    for (final label in ['Server bilan sinxronlash', 'Hisobdan chiqish']) {
      expect(
          tester
              .widget<IconButton>(find.byWidgetPredicate(
                  (widget) => widget is IconButton && widget.tooltip == label))
              .onPressed,
          isNull);
    }
  });
}
