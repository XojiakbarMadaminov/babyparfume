import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:babyparfume_mobile/core/utils/phone_format.dart';
import 'package:babyparfume_mobile/features/debtors/presentation/debtor_form_dialog.dart';
import 'photo_picker_test.dart' show PhotoDebtorsRepository;

void main() {
  testWidgets('new debtor phone is prefilled and the country code is editable',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
        home: Scaffold(
            body: DebtorFormDialog(
                storeId: '1',
                repository: PhotoDebtorsRepository(),
                onCreated: (_) {}))));
    await tester.pumpAndSettle();
    final phone = find.byWidgetPredicate((widget) =>
        widget is TextField &&
        widget.decoration?.labelText == 'Telefon raqami');
    expect(tester.widget<TextField>(phone).controller!.text, '+998');
    await tester.enterText(phone, '+79031234567');
    expect(tester.widget<TextField>(phone).controller!.text, '+79031234567');
    await tester.enterText(phone, '901234569');
    expect(tester.widget<TextField>(phone).controller!.text, '901234569');
    expect(tester.takeException(), isNull);
  });

  test('phone storage cleans separators and preserves edited prefixes', () {
    expect(PhoneFormat.forStorage('+998 (90) 123-45-69'), '+998901234569');
    expect(PhoneFormat.forStorage('+7 (903) 123-45-67'), '+79031234567');
    expect(PhoneFormat.forStorage('901234569'), '901234569');
    expect(PhoneFormat.isValid('+998'), isFalse);
    expect(PhoneFormat.isValid('+79031234567'), isTrue);
  });
}
