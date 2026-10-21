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

  double _totalSales = 0.0;
  int _salesCount = 0;
  double _cashSales = 0.0;
  double _cardSales = 0.0;
  double _debtSales = 0.0;

  @override
  void initState() {
    super.initState();
    _loadStatistics();
  }

  Future<void> _loadStatistics() async {
    setState(() => _isLoading = true);

    try {
      // 1. Try fetching from server API
      final res = await widget.apiClient.get(
        '/stores/${widget.storeId}/statistics',
        queryParameters: {'period': _selectedPeriod},
      );

      if (res.statusCode == 200) {
        final data = res.data['data'] as Map<String, dynamic>;
        _totalSales = (data['total_sales'] as num?)?.toDouble() ?? 0.0;
        _salesCount = (data['sales_count'] as num?)?.toInt() ?? 0;
        _cashSales = (data['cash_sales'] as num?)?.toDouble() ?? 0.0;
        _cardSales = (data['card_sales'] as num?)?.toDouble() ?? 0.0;
        _debtSales = (data['debt_sales'] as num?)?.toDouble() ?? 0.0;
      }
    } catch (_) {
      // 2. Offline fallback: aggregate from local SQLite database
      final db = await AppDatabase.getInstance();
      final rows = await db.rawQuery(
        'SELECT COUNT(*) as count, SUM(total_amount) as total, SUM(paid_amount) as paid FROM offline_sales WHERE store_id = ?',
        [widget.storeId],
      );

      if (rows.isNotEmpty) {
        _salesCount = (rows.first['count'] as num?)?.toInt() ?? 0;
        _totalSales = (rows.first['total'] as num?)?.toDouble() ?? 0.0;
        _cashSales = (rows.first['paid'] as num?)?.toDouble() ?? 0.0;
        _debtSales = _totalSales - _cashSales;
      }
    } finally {
      if (mounted) {
        setState(() => _isLoading = false);
      }
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
                        onSelected: (val) {
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
                    border: Border.all(color: AppColors.lightAccent.withOpacity(0.2)),
                  ),
                  child: Column(
                    children: [
                      const Text(
                        "Jami savdo aylanmasi",
                        style: TextStyle(color: AppColors.lightMuted, fontSize: 13),
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
                        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
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

                _buildPaymentRow("Naqd to'lovlar", _cashSales, Icons.money, AppColors.success),
                const SizedBox(height: 8),
                _buildPaymentRow("Karta orqali", _cardSales, Icons.credit_card, AppColors.primaryButton),
                const SizedBox(height: 8),
                _buildPaymentRow("Nasiya (qarz)", _debtSales, Icons.pending_actions, AppColors.lightDanger),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildPaymentRow(String label, double amount, IconData icon, Color color) {
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
