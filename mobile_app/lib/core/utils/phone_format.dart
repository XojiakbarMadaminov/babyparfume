class PhoneFormat {
  PhoneFormat._();

  static const defaultPrefix = '+998';

  static String forStorage(String phone) =>
      phone.trim().replaceAll(RegExp(r'[\s()\-]'), '');

  static bool isValid(String phone) =>
      RegExp(r'^\+?[0-9]{7,15}$').hasMatch(forStorage(phone));

  static List<String> aliases(String phone) {
    final value = forStorage(phone);
    final digits = value.replaceFirst(RegExp(r'^\+'), '');
    final values = {value, digits, '+$digits'};
    if ((digits.length == 9 && !value.startsWith('+')) ||
        (digits.length == 12 && digits.startsWith('998'))) {
      final local = digits.substring(digits.length - 9);
      values.addAll([local, '998$local', '+998$local']);
    }
    return values.toList();
  }

  /// Canonical string: +998901234567 -> +998 90 123 45 67
  static String format(String? phone) {
    if (phone == null || phone.isEmpty) return '';
    final digits = phone.replaceAll(RegExp(r'\D'), '');
    if (digits.length == 12 && digits.startsWith('998')) {
      final code = digits.substring(3, 5);
      final p1 = digits.substring(5, 8);
      final p2 = digits.substring(8, 10);
      final p3 = digits.substring(10, 12);
      return '+998 $code $p1 $p2 $p3';
    }
    if (digits.length == 9) {
      final code = digits.substring(0, 2);
      final p1 = digits.substring(2, 5);
      final p2 = digits.substring(5, 7);
      final p3 = digits.substring(7, 9);
      return '+998 $code $p1 $p2 $p3';
    }
    return phone;
  }

  /// Normalizes for API payload: +998901234567
  static String normalize(String? phone) {
    if (phone == null || phone.isEmpty) return '';
    final digits = phone.replaceAll(RegExp(r'\D'), '');
    if (digits.length == 9) {
      return '+998$digits';
    }
    if (digits.length == 12 && digits.startsWith('998')) {
      return '+$digits';
    }
    return phone.trim();
  }
}
