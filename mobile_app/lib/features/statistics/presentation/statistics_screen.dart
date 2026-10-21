import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:intl/intl.dart';
import 'package:sqflite/sqflite.dart';
import 'package:flutter/material.dart';
import '../../../core/api/api_client.dart';
import '../../../core/database/app_database.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_format.dart';

class StatisticsScreen extends StatefulWidget {
  final String storeId;
  final ApiClient apiClient;

  const StatisticsScreen({
    super.key,
    required this.storeId,
    required this.apiClient,
  });

  @override
  State<StatisticsScreen> createState() => _StatisticsScreenState();
}

class _StatisticsScreenState extends State<StatisticsScreen> {
  String _selectedPeriod = 'today'; // 'today', 'yesterday', 'week', 'month'
  bool _isLoading = true;
  bool _loadingRequest = false;
  DateTimeRange? _selectedRange;

  double _totalSales = 0.0;
  int _salesCount = 0;
  double _cashSales = 0.0;
  double _cardSales = 0.0;
  double _debtSales = 0.0;
  String? _reportStatus;
  double _pendingAmount = 0;
  int _pendingCount = 0;
  double _profit = 0;
  double _average = 0;
  double? _margin;
  double? _change;
  List<dynamic> _payments = [];
  List<dynamic> _trend = [];
  List<dynamic> _topProducts = [];

  @override
  void initState() {
    super.initState();
    _loadStatistics();
  }

  Future<void> _loadStatistics() async {
    if (_loadingRequest) return;
    _loadingRequest = true;
    setState(() => _isLoading = true);
    final today = DateTime.now().toUtc().add(const Duration(hours: 5));
    var start = DateTime(today.year, today.month, today.day);
    var end = start;
    if (_selectedPeriod == 'yesterday') {
      start = start.subtract(const Duration(days: 1));
      end = start;
    }
    if (_selectedPeriod == 'week') {
      start = start.subtract(Duration(days: start.weekday - 1));
    }
    if (_selectedPeriod == 'month') {
      start = DateTime(today.year, today.month, 1);
    }
    if (_selectedPeriod == 'range' && _selectedRange != null) {
      start = _selectedRange!.start;
      end = _selectedRange!.end;
    }
    final date = DateFormat('yyyy-MM-dd');
    final key =
        'statistics_${_selectedPeriod}_${date.format(start)}_${date.format(end)}';
    _totalSales = 0;
    _salesCount = 0;
    _cashSales = 0;
    _cardSales = 0;
    _debtSales = 0;
    _profit = 0;
    _average = 0;
    _margin = null;
    _change = null;
    _payments = [];
    _trend = [];
    _topProducts = [];
    _pendingAmount = 0;
    _pendingCount = 0;
    try {
      final db = await AppDatabase.getInstance();
      Map<String, dynamic>? report;
      try {
        final response = await widget.apiClient
            .get('/stores/${widget.storeId}/statistics', queryParameters: {
          'period': _selectedPeriod == 'yesterday' ? 'range' : _selectedPeriod,
          'start': date.format(start),
          'end': date.format(end)
        });
        report = Map<String, dynamic>.from(response.data['data'] as Map);
        report['cached_at'] = DateTime.now().toIso8601String();
        await db.insert('sync_state', {'key': key, 'value': jsonEncode(report)},
            conflictAlgorithm: ConflictAlgorithm.replace);
        _reportStatus =
            'Server hisoboti · ${date.format(start)} — ${date.format(end)}';
      } on DioException catch (error) {
        if (error.response != null) {
          await db.delete('sync_state', where: 'key = ?', whereArgs: [key]);
          rethrow;
        }
        final cached =
            await db.query('sync_state', where: 'key = ?', whereArgs: [key]);
        if (cached.isNotEmpty) {
          report = jsonDecode(cached.first['value'] as String)
              as Map<String, dynamic>;
          _reportStatus = 'Saqlangan server hisoboti: ${report['cached_at']}';
        } else {
          _reportStatus = 'Bu davr uchun server hisoboti yuklanmagan.';
        }
      }
      double amount(dynamic value) =>
          double.tryParse(value?.toString() ?? '') ?? 0;
      if (report != null) {
        _totalSales = amount(report['sales_amount']);
        _salesCount = (report['sales_count'] as num?)?.toInt() ?? 0;
        _profit = amount(report['profit']);
        _average = amount(report['average_receipt']);
        _margin = report['margin_percent'] == null
            ? null
            : amount(report['margin_percent']);
        _change = report['change_percent'] == null
            ? null
            : amount(report['change_percent']);
        _payments = report['payments'] as List? ?? [];
        _trend = report['trend'] as List? ?? [];
        _topProducts = report['top_products'] as List? ?? [];
        for (final payment in report['payments'] as List? ?? []) {
          if (payment['key'] == 'cash') _cashSales = amount(payment['amount']);
          if (payment['key'] == 'card') _cardSales = amount(payment['amount']);
          if (payment['key'] == 'debt') _debtSales = amount(payment['amount']);
        }
      }
      final pending = await db.query('offline_sales',
          where:
              "store_id = ? AND sync_status != 'synced' AND payment_type != 'preorder'",
          whereArgs: [widget.storeId]);
      for (final sale in pending) {
        final occurred = DateTime.parse(sale['occurred_at'] as String)
            .toUtc()
            .add(const Duration(hours: 5));
        final day = date.format(occurred);
        if (day.compareTo(date.format(start)) >= 0 &&
            day.compareTo(date.format(end)) <= 0) {
          _pendingCount++;
          _pendingAmount += (sale['total_amount'] as num).toDouble();
        }
      }
    } catch (_) {
      _reportStatus =
          'Hisobot olinmadi. Internet va statistika ruxsatini tekshiring.';
    } finally {
      _loadingRequest = false;
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text(
          "Statistika va Hisobotlar",
          style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
        ),
        backgroundColor: AppColors.lightCard,
        elevation: 0,
      ),
      body: RefreshIndicator(
        onRefresh: _loadStatistics,
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Period Filter
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    {'key': 'today', 'title': 'Bugun'},
                    {'key': 'yesterday', 'title': 'Kecha'},
                    {'key': 'week', 'title': 'Shu hafta'},
                    {'key': 'month', 'title': 'Shu oy'},
                  ].map((p) {
                    final isSelected = _selectedPeriod == p['key'];
                    return Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ChoiceChip(
                        label: Text(p['title']!),
                        selected: isSelected,
                        onSelected: _isLoading
                            ? null
                            : (val) {
                                if (val) {
                                  setState(() => _selectedPeriod = p['key']!);
                                  _loadStatistics();
                                }
                              },
                      ),
                    );
                  }).toList(),
                ),
              ),
              const SizedBox(height: 16),

              OutlinedButton.icon(
                  icon: const Icon(Icons.date_range),
                  label: const Text('Sanalar oralig‘ini tanlash'),
                  onPressed: _isLoading
                      ? null
                      : () async {
                          final today = DateTime.now()
                              .toUtc()
                              .add(const Duration(hours: 5));
                          final range = await showDateRangePicker(
                              context: context,
                              firstDate: DateTime(2000),
                              lastDate:
                                  DateTime(today.year, today.month, today.day),
                              initialDateRange: _selectedRange,
                              helpText: 'Hisobot davri (366 kungacha)',
                              saveText: 'Tanlash',
                              cancelText: 'Bekor qilish',
                              fieldStartLabelText: 'Boshlanish',
                              fieldEndLabelText: 'Tugash');
                          if (range == null || !context.mounted) return;
                          if (range.duration.inDays > 366) {
                            ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(
                                    content:
                                        Text('Davr 366 kundan oshmasin.')));
                            return;
                          }
                          _selectedRange = range;
                          _selectedPeriod = 'range';
                          await _loadStatistics();
                        }),

              if (_reportStatus != null) Text(_reportStatus!),
              if (_pendingCount > 0)
                Padding(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    child: Text(
                        'Server hisobidan alohida: $_pendingCount ta tasdiqlanmagan lokal savdo · ${CurrencyFormat.format(_pendingAmount)}')),
              if (_isLoading)
                const Center(
                  child: Padding(
                    padding: EdgeInsets.all(32),
                    child: CircularProgressIndicator(),
                  ),
                )
              else ...[
                // Main Total Sales Card
                Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    color: AppColors.lightTint,
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(
                        color: AppColors.lightAccent.withOpacity(0.2)),
                  ),
                  child: Column(
                    children: [
                      const Text(
                        "Jami savdo aylanmasi",
                        style: TextStyle(
                            color: AppColors.lightMuted, fontSize: 13),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        CurrencyFormat.format(_totalSales),
                        style: const TextStyle(
                          fontSize: 26,
                          fontWeight: FontWeight.w800,
                          color: AppColors.lightAccent,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        "Savdolar soni: $_salesCount ta",
                        style: const TextStyle(
                            fontSize: 13, fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),

                // Breakdown by payment methods
                const Text(
                  "To'lov turlari bo'yicha",
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 12),

                _buildPaymentRow("Naqd to'lovlar", _cashSales, Icons.money,
                    AppColors.success),
                const SizedBox(height: 8),
                _buildPaymentRow("Karta orqali", _cardSales, Icons.credit_card,
                    AppColors.primaryButton),
                const SizedBox(height: 8),
                _buildPaymentRow("Nasiya (qarz)", _debtSales,
                    Icons.pending_actions, AppColors.lightDanger),
                ..._payments
                    .where((p) => !['cash', 'card', 'debt'].contains(p['key']))
                    .map((p) => Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: _buildPaymentRow(
                            p['name'].toString(),
                            double.tryParse(p['amount'].toString()) ?? 0,
                            Icons.payments,
                            AppColors.primaryButton))),
                const SizedBox(height: 12),
                Text(
                    'Foyda: ${CurrencyFormat.format(_profit)} · O‘rtacha chek: ${CurrencyFormat.format(_average)}'),
                if (_margin != null)
                  Text('Marja: ${_margin!.toStringAsFixed(1)}%'),
                if (_change != null)
                  Text(
                      'Oldingi davrga nisbatan: ${_change!.toStringAsFixed(1)}%'),
                if (_trend.isNotEmpty) ...[
                  const SizedBox(height: 16),
                  const Text('Savdo dinamikasi'),
                  SizedBox(
                      height: 120,
                      child: ListView(
                          scrollDirection: Axis.horizontal,
                          children: _trend
                              .map((point) => Padding(
                                  padding: const EdgeInsets.all(6),
                                  child: Column(
                                      mainAxisAlignment: MainAxisAlignment.end,
                                      children: [
                                        Text(
                                            CurrencyFormat.format(
                                                point['amount'] as num? ?? 0),
                                            style:
                                                const TextStyle(fontSize: 10)),
                                        Container(
                                            width: 18,
                                            height: _barHeight(point['amount']),
                                            color: AppColors.primaryButton),
                                        Text(point['label'].toString(),
                                            style:
                                                const TextStyle(fontSize: 10)),
                                      ])))
                              .toList())),
                ],
                if (_topProducts.isNotEmpty) ...[
                  const SizedBox(height: 16),
                  const Text('Top mahsulotlar'),
                  ..._topProducts.map((p) => ListTile(
                      title: Text(p['name'].toString()),
                      subtitle: Text('${p['quantity']} dona'),
                      trailing: Text(CurrencyFormat.format(
                          double.tryParse(p['amount'].toString()) ?? 0)))),
                ],
              ],
            ],
          ),
        ),
      ),
    );
  }

  double _barHeight(dynamic value) {
    double number(dynamic amount) => double.tryParse(amount.toString()) ?? 0;
    final maximum = _trend.fold<double>(
        0,
        (result, p) =>
            number(p['amount']) > result ? number(p['amount']) : result);
    return maximum <= 0
        ? 1
        : (number(value) / maximum * 65).clamp(1, 65).toDouble();
  }

  Widget _buildPaymentRow(
      String label, double amount, IconData icon, Color color) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.lightCard,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.lightLine),
      ),
      child: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: color.withOpacity(0.12),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, color: color, size: 20),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              label,
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
            ),
          ),
          Text(
            CurrencyFormat.format(amount),
            style: TextStyle(
              fontWeight: FontWeight.w700,
              fontSize: 14,
              color: color,
            ),
          ),
        ],
      ),
    );
  }
}
