import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import '../../../core/api/api_client.dart';
import '../../../core/storage/secure_storage.dart';

class SmsBalance {
  final double? amount;
  final DateTime? updatedAt;
  final bool stale;
  final String status;

  const SmsBalance(
      {this.amount,
      this.updatedAt,
      this.stale = true,
      this.status = 'unavailable'});

  factory SmsBalance.fromJson(Map<String, dynamic> json) {
    final raw = json['balance'];
    final amount = raw == null ? null : double.tryParse(raw.toString());
    if (raw != null && (amount == null || !amount.isFinite)) {
      throw const FormatException('Invalid SMS balance');
    }
    final updatedAt = DateTime.tryParse(json['updated_at']?.toString() ?? '');
    if (amount != null && updatedAt == null) {
      throw const FormatException('Missing SMS balance timestamp');
    }
    return SmsBalance(
        amount: amount,
        updatedAt: updatedAt,
        stale: json['stale'] != false,
        status: json['status']?.toString() ?? 'unavailable');
  }

  SmsBalance asStale() => SmsBalance(
      amount: amount, updatedAt: updatedAt, stale: true, status: status);

  bool isStale(DateTime now) =>
      stale ||
      updatedAt == null ||
      now.difference(updatedAt!) >= const Duration(minutes: 5);

  Map<String, dynamic> toJson() => {
        'balance': amount,
        'updated_at': updatedAt?.toUtc().toIso8601String(),
        'stale': stale,
        'status': status
      };
}

class SmsBalanceController extends ChangeNotifier {
  final ApiClient apiClient;
  final SecureStorageService storage;
  final DateTime Function() now;
  SmsBalanceController(
      {required this.apiClient,
      required this.storage,
      DateTime Function()? now})
      : now = now ?? DateTime.now;

  SmsBalance? balance;
  bool enabled = false;
  bool loading = false;
  String? _userId;
  DateTime? _lastAttempt;
  int _epoch = 0;
  bool _disposed = false;

  Future<void> updateSession(Map<String, dynamic> session) async {
    if (_disposed) return;
    final userId = (session['user'] as Map?)?['id']?.toString();
    final permitted = (session['permissions'] as Map?)?['sms_balance'] == true;
    if (!permitted || userId == null) {
      final previous = _userId;
      _epoch++;
      enabled = false;
      loading = false;
      balance = null;
      _userId = null;
      _lastAttempt = null;
      if (!_disposed) notifyListeners();
      if (previous != null) await storage.clearSmsBalance(previous);
      return;
    }
    if (_userId != userId || !enabled) {
      final epoch = ++_epoch;
      _userId = userId;
      enabled = true;
      loading = false;
      balance = null;
      _lastAttempt = null;
      try {
        final cached = await storage.getSmsBalance(userId);
        if (_disposed || epoch != _epoch) return;
        if (cached != null) balance = SmsBalance.fromJson(cached).asStale();
      } catch (_) {}
      if (_disposed || epoch != _epoch) return;
      notifyListeners();
    }
    await refresh();
  }

  Future<void> refresh({bool force = false}) async {
    if (_disposed || !enabled || loading || _userId == null) return;
    final interval =
        force ? const Duration(seconds: 10) : const Duration(minutes: 5);
    if (_lastAttempt != null && now().difference(_lastAttempt!) < interval) {
      return;
    }
    final epoch = _epoch;
    final userId = _userId!;
    loading = true;
    _lastAttempt = now();
    notifyListeners();
    try {
      final response = force
          ? await apiClient.post('/sms-balance/refresh')
          : await apiClient.get('/sms-balance');
      final received = SmsBalance.fromJson(
          Map<String, dynamic>.from(response.data['data'] as Map));
      if (_disposed || epoch != _epoch) return;
      balance = received.amount == null && balance?.amount != null
          ? balance!.asStale()
          : received;
      if (balance?.amount != null) {
        await storage.setSmsBalance(userId, balance!.toJson());
      }
    } on DioException catch (error) {
      if (_disposed || epoch != _epoch) return;
      if (error.response?.statusCode == 401 ||
          error.response?.statusCode == 403) {
        enabled = false;
        balance = null;
        await storage.clearSmsBalance(userId);
      } else {
        balance = balance?.asStale();
      }
    } catch (_) {
      if (_disposed || epoch != _epoch) return;
      balance = balance?.asStale();
    } finally {
      if (!_disposed && epoch == _epoch) {
        loading = false;
        notifyListeners();
      }
    }
  }

  @override
  void dispose() {
    _disposed = true;
    _epoch++;
    super.dispose();
  }
}
