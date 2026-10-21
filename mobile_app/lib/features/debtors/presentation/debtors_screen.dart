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

  const DebtorsScreen({
    super.key,
    required this.storeId,
    this.repository,
  });

  @override
  State<DebtorsScreen> createState() => _DebtorsScreenState();
}

class _DebtorsScreenState extends State<DebtorsScreen> {
  final TextEditingController _searchController = TextEditingController();
  late final DebtorsRepository _repository;
  List<LocalDebtor> _debtors = [];
  double _totalDebt = 0.0;
  bool _isLoading = true;
  String _searchQuery = '';

  @override
  void initState() {
    super.initState();
    _repository = widget.repository ?? DebtorsRepository(outboxManager: OutboxManager());
    _loadDebtors();
  }

  Future<void> _loadDebtors() async {
    setState(() => _isLoading = true);
    final list = await _repository.getDebtors(widget.storeId, query: _searchQuery);
    final total = await _repository.getTotalDebt(widget.storeId);
    if (mounted) {
      setState(() {
        _debtors = list;
        _totalDebt = total;
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
              backgroundColor: AppColors.success,
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
        title: const Text(
          "Qarzdorlar",
          style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
        ),
        backgroundColor: AppColors.lightCard,
        elevation: 0,
      ),
      body: Column(
        children: [
          // Total debt summary card
          Container(
            margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppColors.lightTint,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: AppColors.lightAccent.withOpacity(0.2)),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      "Jami qarz miqdori",
                      style: TextStyle(color: AppColors.lightMuted, fontSize: 13),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      "${_debtors.length} ta qarzdor",
                      style: const TextStyle(color: AppColors.lightMuted, fontSize: 11),
                    ),
                  ],
                ),
                Text(
                  CurrencyFormat.format(_totalDebt),
                  style: const TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.w800,
                    color: AppColors.lightAccent,
                  ),
                ),
              ],
            ),
          ),

          // Search bar
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: TextField(
              controller: _searchController,
              onChanged: (val) {
                _searchQuery = val;
                _loadDebtors();
              },
              decoration: InputDecoration(
                hintText: "Ism yoki telefon raqami bo'yicha qidirish...",
                prefixIcon: const Icon(Icons.search, color: AppColors.lightMuted),
                contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                fillColor: AppColors.lightSoft,
                filled: true,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: BorderSide.none,
                ),
              ),
            ),
          ),

          // List
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _debtors.isEmpty
                    ? const Center(
                        child: Text(
                          "Qarzdor topilmadi",
                          style: TextStyle(color: AppColors.lightMuted, fontSize: 14),
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
                                color: AppColors.lightCard,
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(color: AppColors.lightLine),
                              ),
                              child: Row(
                                children: [
                                  Container(
                                    width: 44,
                                    height: 44,
                                    decoration: const BoxDecoration(
                                      color: AppColors.lightSoft,
                                      shape: BoxShape.circle,
                                    ),
                                    child: const Icon(Icons.person_outline, color: AppColors.lightMuted),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          debtor.fullName,
                                          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                                        ),
                                        const SizedBox(height: 2),
                                        Text(
                                          PhoneFormat.format(debtor.phone),
                                          style: const TextStyle(color: AppColors.lightMuted, fontSize: 12),
                                        ),
                                      ],
                                    ),
                                  ),
                                  Text(
                                    CurrencyFormat.format(debtor.amount),
                                    style: TextStyle(
                                      fontWeight: FontWeight.w700,
                                      fontSize: 14,
                                      color: debtor.amount > 0 ? AppColors.lightDanger : AppColors.success,
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
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AppColors.primaryButton,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.person_add_alt_1),
        label: const Text("Qarz qo'shish"),
        onPressed: _openAddDebtorDialog,
      ),
    );
  }
}
