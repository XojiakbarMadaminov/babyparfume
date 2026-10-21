class BarcodeGuard {
  bool _completed = false;

  bool accept(String value, {bool verifyChecksum = false}) {
    if (_completed || value.isEmpty || value.length > 100) return false;
    if (verifyChecksum && !validChecksum(value)) return false;
    _completed = true;
    return true;
  }

  static bool validChecksum(String value) {
    if (!RegExp(r'^\d+$').hasMatch(value) ||
        ![8, 12, 13, 14].contains(value.length)) return false;
    var sum = 0;
    for (var index = value.length - 2; index >= 0; index--) {
      final position = value.length - 2 - index;
      sum += int.parse(value[index]) * (position.isEven ? 3 : 1);
    }
    return (10 - sum % 10) % 10 == int.parse(value[value.length - 1]);
  }
}
