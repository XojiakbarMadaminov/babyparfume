import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:intl/intl.dart';
import 'package:sqflite/sqflite.dart';
import '../../../core/api/api_client.dart';
import '../../../core/database/app_database.dart';

class StatisticsFilter {
  final String period;
  final DateTime start;
  final DateTime end;
  const StatisticsFilter(
      {required this.period, required this.start, required this.end});

  static DateTime tashkentDate(DateTime now) {
    final local = now.toUtc().add(const Duration(hours: 5));
    return DateTime(local.year, local.month, local.day);
  }

  factory StatisticsFilter.forPeriod(String period, DateTime now) {
    final today = tashkentDate(now);
    final start = switch (period) {
      'week' => today.subtract(Duration(days: today.weekday - 1)),
      'month' => DateTime(today.year, today.month),
      _ => today,
    };
    return StatisticsFilter(period: period, start: start, end: today);
  }

  Map<String, String> get parameters => {
        'period': period,
        'start': DateFormat('yyyy-MM-dd').format(start),
        'end': DateFormat('yyyy-MM-dd').format(end),
      };
  String get cacheKey =>
      'statistics_${period}_${parameters['start']}_${parameters['end']}';
}

class StatisticsPoint {
  final String label;
  final double amount;
  const StatisticsPoint(this.label, this.amount);
}

class StatisticsPayment {
  final String key;
  final String label;
  final double amount;
  final double percent;
  const StatisticsPayment(
      {required this.key,
      required this.label,
      required this.amount,
      required this.percent});
}

class StatisticsProduct {
  final String? id;
  final String name;
  final double quantity;
  final double amount;
  final String? image;
  const StatisticsProduct(
      {this.id,
      required this.name,
      required this.quantity,
      required this.amount,
      this.image});
}

double? statisticsNumber(dynamic value) {
  final number = double.tryParse(value?.toString() ?? '');
  return number != null && number.isFinite ? number : null;
}

class StatisticsReport {
  final double salesAmount;
  final int salesCount;
  final double? averageReceipt;
  final double? profit;
  final double? changePercent;
  final List<StatisticsPoint> trend;
  final List<StatisticsPayment> payments;
  final List<StatisticsProduct> products;

  const StatisticsReport(
      {required this.salesAmount,
      required this.salesCount,
      required this.averageReceipt,
      required this.profit,
      required this.changePercent,
      required this.trend,
      required this.payments,
      required this.products});

  factory StatisticsReport.fromJson(
      Map<String, dynamic> data, String storeId, StatisticsFilter filter) {
    if (data['store_id']?.toString() != storeId ||
        filter.parameters.entries
            .any((entry) => data[entry.key] != entry.value) ||
        statisticsNumber(data['sales_amount']) == null ||
        data['sales_count'] is! int ||
        (data['sales_count'] as int) < 0) {
      throw const FormatException(
          'Hisobot do‘kon yoki tanlangan davrga mos emas.');
    }
    final rawPayments =
        (data['payments'] as List? ?? []).whereType<Map>().toList();
    final paymentTotal = rawPayments.fold<double>(
        0, (sum, payment) => sum + (statisticsNumber(payment['amount']) ?? 0));
    return StatisticsReport(
      salesAmount: statisticsNumber(data['sales_amount'])!,
      salesCount: data['sales_count'] as int,
      averageReceipt: statisticsNumber(data['average_receipt']),
      profit: statisticsNumber(data['profit']),
      changePercent: statisticsNumber(data['change_percent']),
      trend: [
        for (final point in (data['trend'] as List? ?? []).whereType<Map>())
          if (statisticsNumber(point['amount']) != null)
            StatisticsPoint(point['label']?.toString() ?? '',
                statisticsNumber(point['amount'])!)
      ],
      payments: [
        for (final payment in rawPayments)
          if (statisticsNumber(payment['amount']) != null)
            StatisticsPayment(
                key: payment['key']?.toString() ?? '',
                label: (payment['name'] ?? payment['label'] ?? payment['key'])
                    .toString(),
                amount: statisticsNumber(payment['amount'])!,
                percent: statisticsNumber(payment['percent']) ??
                    (paymentTotal > 0
                        ? statisticsNumber(payment['amount'])! /
                            paymentTotal *
                            100
                        : 0))
      ],
      products: [
        for (final product
            in (data['top_products'] as List? ?? []).whereType<Map>())
          StatisticsProduct(
              id: product['id']?.toString(),
              name: product['name']?.toString() ?? '',
              quantity: statisticsNumber(product['quantity']) ?? 0,
              amount: statisticsNumber(product['amount']) ?? 0,
              image: product['image']?.toString())
      ],
    );
  }
}

class StatisticsResult {
  final StatisticsReport? report;
  final DateTime? cachedAt;
  final int pendingCount;
  final double pendingAmount;
  const StatisticsResult(
      {this.report,
      this.cachedAt,
      this.pendingCount = 0,
      this.pendingAmount = 0});
}

class StatisticsRepository {
  final ApiClient apiClient;
  final Future<Database> Function() _getDb;
  final DateTime Function() _now;

  StatisticsRepository(
      {required this.apiClient,
      Future<Database> Function()? getDb,
      DateTime Function()? now})
      : _getDb = getDb ?? AppDatabase.getInstance,
        _now = now ?? DateTime.now;

  Future<StatisticsResult> load(String storeId, StatisticsFilter filter,
      {CancelToken? cancelToken}) async {
    final db = await _getDb();
    StatisticsReport? report;
    DateTime? cachedAt;
    try {
      final response = await apiClient.get('/stores/$storeId/statistics',
          queryParameters: filter.parameters, cancelToken: cancelToken);
      if (cancelToken?.isCancelled == true) throw cancelToken!.cancelError!;
      final data = Map<String, dynamic>.from(response.data['data'] as Map);
      report = StatisticsReport.fromJson(data, storeId, filter);
      data['cached_at'] = _now().toUtc().toIso8601String();
      await db.insert(
          'sync_state', {'key': filter.cacheKey, 'value': jsonEncode(data)},
          conflictAlgorithm: ConflictAlgorithm.replace);
    } on DioException catch (error) {
      if (CancelToken.isCancel(error)) rethrow;
      if (error.response != null) {
        await db.delete('sync_state',
            where: 'key = ?', whereArgs: [filter.cacheKey]);
        rethrow;
      }
      final cached = await db
          .query('sync_state', where: 'key = ?', whereArgs: [filter.cacheKey]);
      if (cached.isNotEmpty) {
        try {
          final data = jsonDecode(cached.first['value'] as String)
              as Map<String, dynamic>;
          report = StatisticsReport.fromJson(data, storeId, filter);
          cachedAt = DateTime.tryParse(data['cached_at']?.toString() ?? '');
          if (cachedAt == null) report = null;
        } on FormatException {
          report = null;
        } on TypeError {
          report = null;
        }
      }
    }
    if (cancelToken?.isCancelled == true) throw cancelToken!.cancelError!;
    final pending = await db.query('offline_sales',
        where:
            "store_id = ? AND sync_status != 'synced' AND payment_type != 'preorder'",
        whereArgs: [storeId]);
    var pendingCount = 0;
    var pendingAmount = 0.0;
    for (final sale in pending) {
      final occurred = DateTime.tryParse(sale['occurred_at']?.toString() ?? '');
      if (occurred == null) continue;
      final day = StatisticsFilter.tashkentDate(occurred);
      if (!day.isBefore(filter.start) && !day.isAfter(filter.end)) {
        pendingCount++;
        pendingAmount += statisticsNumber(sale['total_amount']) ?? 0;
      }
    }
    return StatisticsResult(
        report: report,
        cachedAt: cachedAt,
        pendingCount: pendingCount,
        pendingAmount: pendingAmount);
  }
}
