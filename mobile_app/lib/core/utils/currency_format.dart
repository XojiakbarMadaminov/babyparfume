class CurrencyFormat {
  CurrencyFormat._();

  static String format(num? amount, {String suffix = "so'm"}) {
    if (amount == null) return "0 $suffix";
    final isNegative = amount < 0;
    final absAmount = amount.abs().round();
    final str = absAmount.toString();
    final buffer = StringBuffer();
    for (int i = 0; i < str.length; i++) {
      if (i > 0 && (str.length - i) % 3 == 0) {
        buffer.write(' ');
      }
      buffer.write(str[i]);
    }
    final sign = isNegative ? '-' : '';
    return "$sign${buffer.toString()} $suffix";
  }

  static String formatExact(num? amount) {
    if (amount == null) return "0";
    final absAmount = amount.abs().round();
    final str = absAmount.toString();
    final buffer = StringBuffer();
    for (int i = 0; i < str.length; i++) {
      if (i > 0 && (str.length - i) % 3 == 0) {
        buffer.write(' ');
      }
      buffer.write(str[i]);
    }
    return (amount < 0 ? '-' : '') + buffer.toString();
  }
}
