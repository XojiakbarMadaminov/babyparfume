import 'package:babyparfume_mobile/core/api/api_client.dart';
import 'package:babyparfume_mobile/core/storage/secure_storage.dart';
import 'package:babyparfume_mobile/features/auth/data/auth_repository.dart';
import 'package:babyparfume_mobile/features/auth/presentation/login_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('password starts hidden and toggles without changing input',
      (tester) async {
    FlutterSecureStorage.setMockInitialValues({});
    final storage = SecureStorageService();
    final api = ApiClient(storage: storage);
    addTearDown(() => api.dio.close());
    await tester.pumpWidget(MaterialApp(
        home: LoginScreen(
            authRepository: AuthRepository(apiClient: api, storage: storage),
            storage: storage,
            onLoginSuccess: () {})));

    final passwordField = find.byWidgetPredicate((widget) =>
        widget is TextField && widget.decoration?.labelText == 'Parol');
    expect(tester.widget<TextField>(passwordField).obscureText, isTrue);
    await tester.enterText(passwordField, 'Example-password-123');

    await tester.ensureVisible(find.byTooltip('Parolni ko‘rsatish'));
    await tester.tap(find.byTooltip('Parolni ko‘rsatish'));
    await tester.pump();
    expect(tester.widget<TextField>(passwordField).obscureText, isFalse);
    expect(tester.widget<TextField>(passwordField).controller?.text,
        'Example-password-123');

    await tester.tap(find.byTooltip('Parolni yashirish'));
    await tester.pump();
    expect(tester.widget<TextField>(passwordField).obscureText, isTrue);
    expect(tester.widget<TextField>(passwordField).controller?.text,
        'Example-password-123');
    expect(tester.takeException(), isNull);
  });
}
