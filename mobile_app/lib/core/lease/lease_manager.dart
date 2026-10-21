import 'dart:async';
import '../storage/secure_storage.dart';

class LeaseState {
  final bool isLocked;
  final String? reason;
  final Duration? remainingTime;
  final DateTime? expiresAt;

  const LeaseState({
    required this.isLocked,
    this.reason,
    this.remainingTime,
    this.expiresAt,
  });

  factory LeaseState.active(DateTime expiresAt) {
    final remaining = expiresAt.difference(DateTime.now());
    return LeaseState(
      isLocked: remaining.isNegative,
      reason: remaining.isNegative ? "10 soatlik oflayn litsenziya muddati tugadi" : null,
      remainingTime: remaining.isNegative ? Duration.zero : remaining,
      expiresAt: expiresAt,
    );
  }

  factory LeaseState.locked(String reason) {
    return LeaseState(
      isLocked: true,
      reason: reason,
      remainingTime: Duration.zero,
    );
  }
}

class LeaseManager {
  final SecureStorageService storage;
  Timer? _ticker;
  DateTime? _lastObservedSystemTime;
  final StreamController<LeaseState> _stateController =
      StreamController<LeaseState>.broadcast();

  Stream<LeaseState> get stateStream => _stateController.stream;

  LeaseManager({required this.storage});

  Future<void> initialize() async {
    _lastObservedSystemTime = DateTime.now();
    await checkLease();
    _ticker = Timer.periodic(const Duration(seconds: 30), (_) => checkLease());
  }

  Future<LeaseState> checkLease() async {
    final now = DateTime.now();

    // Clock rollback detection
    if (_lastObservedSystemTime != null && now.isBefore(_lastObservedSystemTime!.subtract(const Duration(minutes: 5)))) {
      final state = LeaseState.locked("Qurilma tizim vaqti orqaga surilgan. Xavfsizlik sababli qulflangan.");
      _stateController.add(state);
      return state;
    }
    _lastObservedSystemTime = now;

    final expiresStr = await storage.getLeaseExpiresAt();
    if (expiresStr == null || expiresStr.isEmpty) {
      // Lease not issued yet, locked until server sync
      final state = LeaseState.locked("Litsenziya mavjud emas. Server bilan sinxronlaning.");
      _stateController.add(state);
      return state;
    }

    try {
      final expiresAt = DateTime.parse(expiresStr);
      final state = LeaseState.active(expiresAt);
      _stateController.add(state);
      return state;
    } catch (_) {
      final state = LeaseState.locked("Litsenziya formati yaroqsiz.");
      _stateController.add(state);
      return state;
    }
  }

  Future<void> updateLease(String expiresAtIso) async {
    await storage.setLeaseExpiresAt(expiresAtIso);
    await checkLease();
  }

  void dispose() {
    _ticker?.cancel();
    _stateController.close();
  }
}
