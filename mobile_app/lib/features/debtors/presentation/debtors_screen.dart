import 'package:flutter/material.dart';
import '../../../core/sync/outbox_manager.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_format.dart';
import '../../../core/utils/phone_format.dart';
import '../data/debtors_repository.dart';
import 'debtor_detail_screen.dart';
import 'debtor_form_dialog.dart';

class DebtorsScreen extends StatefulWidget {
  final String storeId;
  final DebtorsRepository? repository;
  final bool canCreate;
  final Widget? smsBalanceCard;

  const DebtorsScreen({
    super.key,
    required this.storeId,
    this.repository,
    this.canCreate = false,
    this.smsBalanceCard,
  });

  @override
  State<DebtorsScreen> createState() => _DebtorsScreenState();
}

class _DebtorsScreenState extends State<DebtorsScreen> {
  final TextEditingController _searchController = TextEditingController();
  late final DebtorsRepository _repository;
  List<LocalDebtor> _debtors = [];
  Map<String, double> _totals = {};
  Map<String, int> _counts = {};
  int _loadVersion = 0;
  bool _isLoading = true;
  String _searchQuery = '';
  String _filter = 'active';

  @override
  void initState() {
    super.initState();
    _repository =
        widget.repository ?? DebtorsRepository(outboxManager: OutboxManager());
    _loadDebtors();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _loadDebtors() async {
    final version = ++_loadVersion;
    setState(() => _isLoading = true);
    final list = await _repository.getDebtors(widget.storeId,
        query: _searchQuery, filter: _filter);
    final summary = await _repository.summary(widget.storeId);
    if (mounted && version == _loadVersion) {
      setState(() {
        _debtors = list;
        _totals = summary.totals;
        _counts = summary.counts;
        _isLoading = false;
      });
    }
  }

  void _openAddDebtorDialog() {
    showDialog(
      context: context,
      builder: (context) => DebtorFormDialog(
        storeId: widget.storeId,
        repository: _repository,
        onCreated: (newDebtor) {
          _loadDebtors();
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text("Yangi qarzdor muvaffaqiyatli saqlandi!"),
            ),
          );
        },
      ),
    );
  }

  void _openDebtorDetail(LocalDebtor debtor) async {
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (context) => DebtorDetailScreen(
          debtor: debtor,
          repository: _repository,
        ),
      ),
    );
    _loadDebtors();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 16,
        title: Row(children: [
          const Text('Qarzdorlar',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          const SizedBox(width: 12),
          Expanded(
            child: SizedBox(
              height: 42,
              child: TextField(
                key: const ValueKey('debtors-search'),
                controller: _searchController,
                style: const TextStyle(fontSize: 13),
                textInputAction: TextInputAction.search,
                onChanged: (value) {
                  _searchQuery = value;
                  _loadDebtors();
                },
                decoration: InputDecoration(
                  hintText: 'Ism yoki telefon',
                  hintStyle: const TextStyle(fontSize: 12),
                  prefixIcon: Icon(Icons.search,
                      size: 19, color: AppColors.of(context).muted),
                  prefixIconConstraints: const BoxConstraints(minWidth: 34),
                  suffixIcon: _searchQuery.isEmpty
                      ? null
                      : IconButton(
                          tooltip: 'Qidiruvni tozalash',
                          padding: EdgeInsets.zero,
                          icon: const Icon(Icons.close, size: 17),
                          onPressed: () {
                            _searchController.clear();
                            _searchQuery = '';
                            _loadDebtors();
                          }),
                  suffixIconConstraints: const BoxConstraints(minWidth: 32),
                  contentPadding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                  fillColor: AppColors.of(context).soft,
                  filled: true,
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(12),
                      borderSide: BorderSide.none),
                ),
              ),
            ),
          ),
        ]),
        backgroundColor: AppColors.of(context).card,
        elevation: 0,
      ),
      body: Column(
        children: [
          SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                  children: const {
                'active': 'Faol',
                'closed': 'Yopilgan',
                'overdue': 'Muddati o‘tgan'
              }
                      .entries
                      .map((entry) => Padding(
                          padding: const EdgeInsets.all(6),
                          child: ChoiceChip(
                              label: Text(
                                  '${entry.value} (${_counts[entry.key] ?? 0})'),
                              selected: _filter == entry.key,
                              onSelected: (_) {
                                setState(() => _filter = entry.key);
                                _loadDebtors();
                              })))
                      .toList())),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: IntrinsicHeight(
              child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Expanded(
                      flex: 2,
                      child: Container(
                        key: const ValueKey('debtors-summary'),
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: AppColors.of(context).tint,
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(
                              color: AppColors.of(context)
                                  .accent
                                  .withOpacity(0.2)),
                        ),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Jami qarz miqdori',
                                style: TextStyle(
                                    color: AppColors.of(context).muted,
                                    fontSize: 13)),
                            const SizedBox(height: 2),
                            Text('${_debtors.length} ta qarzdor',
                                style: TextStyle(
                                    color: AppColors.of(context).muted,
                                    fontSize: 11)),
                            const SizedBox(height: 8),
                            for (final entry in _totals.entries)
                              Text(
                                  CurrencyFormat.format(entry.value,
                                      suffix: entry.key == 'UZS'
                                          ? "so'm"
                                          : entry.key),
                                  style: TextStyle(
                                      fontSize: 19,
                                      fontWeight: FontWeight.w800,
                                      color: AppColors.of(context).accent)),
                          ],
                        ),
                      ),
                    ),
                    if (widget.smsBalanceCard != null) ...[
                      const SizedBox(width: 10),
                      Expanded(child: widget.smsBalanceCard!),
                    ],
                  ]),
            ),
          ),

          // List
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _debtors.isEmpty
                    ? Center(
                        child: Text(
                          "Qarzdor topilmadi",
                          style: TextStyle(
                              color: AppColors.of(context).muted, fontSize: 14),
                        ),
                      )
                    : ListView.separated(
                        padding: const EdgeInsets.all(16),
                        itemCount: _debtors.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 8),
                        itemBuilder: (context, index) {
                          final debtor = _debtors[index];
                          return InkWell(
                            borderRadius: BorderRadius.circular(12),
                            onTap: () => _openDebtorDetail(debtor),
                            child: Container(
                              padding: const EdgeInsets.all(14),
                              decoration: BoxDecoration(
                                color: AppColors.of(context).card,
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(
                                    color: AppColors.of(context).line),
                              ),
                              child: Row(
                                children: [
                                  Container(
                                    width: 44,
                                    height: 44,
                                    decoration: BoxDecoration(
                                      color: AppColors.of(context).soft,
                                      shape: BoxShape.circle,
                                    ),
                                    child: Icon(Icons.person_outline,
                                        color: AppColors.of(context).muted),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          debtor.fullName,
                                          style: const TextStyle(
                                              fontWeight: FontWeight.w600,
                                              fontSize: 14),
                                        ),
                                        const SizedBox(height: 2),
                                        Text(
                                          PhoneFormat.format(debtor.phone),
                                          style: TextStyle(
                                              color:
                                                  AppColors.of(context).muted,
                                              fontSize: 12),
                                        ),
                                      ],
                                    ),
                                  ),
                                  Text(
                                    CurrencyFormat.format(debtor.amount,
                                        suffix: debtor.currency == 'UZS'
                                            ? "so'm"
                                            : debtor.currency),
                                    style: TextStyle(
                                      fontWeight: FontWeight.w700,
                                      fontSize: 14,
                                      color: debtor.amount > 0
                                          ? AppColors.of(context).danger
                                          : AppColors.of(context).success,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          );
                        },
                      ),
          ),
        ],
      ),
      floatingActionButton: !widget.canCreate
          ? null
          : FloatingActionButton.extended(
              backgroundColor: AppColors.of(context).button,
              foregroundColor: AppColors.of(context).onButton,
              icon: const Icon(Icons.person_add_alt_1),
              label: const Text("Qarz qo'shish"),
              onPressed: _openAddDebtorDialog,
            ),
    );
  }
}
