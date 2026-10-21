import 'dart:async';
import 'package:flutter/services.dart';
import '../storage/secure_storage.dart';

class LeaseState {
  final bool isLocked;
  final String? reason;
  final Duration? remainingTime;
  final DateTime? expiresAt;
  const LeaseState(
      {required this.isLocked,
      this.reason,
      this.remainingTime,
      this.expiresAt});
  factory LeaseState.active(DateTime expiresAt) {
    final remaining = expiresAt.difference(DateTime.now());
    return LeaseState(
        isLocked: remaining <= Duration.zero,
        reason: remaining <= Duration.zero
            ? "10 soatlik oflayn litsenziya muddati tugadi"
            : null,
        remainingTime: remaining <= Duration.zero ? Duration.zero : remaining,
        expiresAt: expiresAt);
  }
  factory LeaseState.locked(String reason) =>
      LeaseState(isLocked: true, reason: reason, remainingTime: Duration.zero);
}

class LeaseManager {
  final SecureStorageService storage;
  final Future<Map<String, dynamic>> Function() readClock;
  Timer? _ticker;
  final _controller = StreamController<LeaseState>.broadcast();
  Stream<LeaseState> get stateStream => _controller.stream;
  LeaseManager(
      {required this.storage,
      Future<Map<String, dynamic>> Function()? readClock})
      : readClock = readClock ?? deviceClock;

  static Future<Map<String, dynamic>> deviceClock() async =>
      Map<String, dynamic>.from(
          await const MethodChannel('babyparfume/device_clock')
                  .invokeMethod<Map>('read') ??
              {});

  Future<void> initialize() async {
    _ticker?.cancel();
    await checkLease();
    _ticker = Timer.periodic(const Duration(seconds: 15), (_) => checkLease());
  }

  Future<LeaseState> checkLease() async {
    LeaseState state;
    try {
      final lease = await storage.getLease();
      final clock = await readClock();
      if (lease == null ||
          lease['boot_id'] != clock['boot_id'] ||
          lease['user_id'] != await storage.getUserId() ||
          lease['store_id'] != await storage.getSelectedStoreId()) {
        state =
            LeaseState.locked("Server bilan ulanib oflayn ruxsatni yangilang.");
      } else {
        final elapsed = (clock['uptime_ms'] as num).toInt() -
            (lease['uptime_ms'] as num).toInt();
        final remaining = (lease['duration_ms'] as num).toInt() - elapsed;
        state = elapsed < 0 || remaining <= 0
            ? LeaseState.locked("10 soatlik oflayn litsenziya muddati tugadi")
            : LeaseState(
                isLocked: false,
                remainingTime: Duration(milliseconds: remaining),
                expiresAt: DateTime.parse(lease['offline_until'] as String));
      }
    } catch (_) {
      state =
          LeaseState.locked("Oflayn ruxsat tekshirilmadi. Serverga ulaning.");
    }
    if (!_controller.isClosed) _controller.add(state);
    return state;
  }

  Future<void> updateLease(Map<String, dynamic> lease,
      {Map<String, dynamic>? requestClock}) async {
    final issued = DateTime.parse(lease['server_time'] as String);
    final until = DateTime.parse(lease['offline_until'] as String);
    final duration = until.difference(issued).inMilliseconds;
    if (duration <= 0 ||
        duration > const Duration(hours: 10).inMilliseconds ||
        lease['user_id']?.toString() != await storage.getUserId() ||
        lease['store_id']?.toString() != await storage.getSelectedStoreId() ||
        lease['device_uuid'] != await storage.getOrCreateDeviceUuid()) {
      throw StateError("Server oflayn ruxsati yaroqsiz.");
    }
    final clock = await readClock();
    if (clock['boot_id'] == null || clock['uptime_ms'] is! num) {
      throw StateError("Qurilma soati tekshirilmadi.");
    }
    final roundTrip = requestClock == null
        ? 0
        : (clock['uptime_ms'] as num).toInt() -
            (requestClock['uptime_ms'] as num).toInt();
    if (roundTrip < 0 ||
        roundTrip >= duration ||
        (requestClock != null && requestClock['boot_id'] != clock['boot_id'])) {
      throw StateError('Server tekshiruvi kechikkan. Qayta ulaning.');
    }
    await storage
        .setLease({...lease, ...clock, 'duration_ms': duration - roundTrip});
    await checkLease();
  }

  Future<void> requireActive() async {
    final state = await checkLease();
    if (state.isLocked) throw StateError(state.reason!);
  }

  Future<DateTime> serverNow() async {
    await requireActive();
    final lease = (await storage.getLease())!;
    final clock = await readClock();
    final elapsed = (clock['uptime_ms'] as num).toInt() -
        (lease['uptime_ms'] as num).toInt();
    if (clock['boot_id'] != lease['boot_id'] ||
        elapsed < 0 ||
        elapsed >= (lease['duration_ms'] as num).toInt()) {
      throw StateError('Oflayn ruxsat tugagan. Serverga ulaning.');
    }
    return DateTime.parse(lease['server_time'] as String)
        .add(Duration(milliseconds: elapsed));
  }

  void dispose() {
    _ticker?.cancel();
    _controller.close();
  }
}
