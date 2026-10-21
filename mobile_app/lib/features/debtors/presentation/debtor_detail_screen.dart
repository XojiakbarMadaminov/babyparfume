import 'package:flutter/material.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_format.dart';
import '../../../core/utils/date_format.dart';
import '../../../core/utils/phone_format.dart';
import '../data/debtors_repository.dart';

class DebtorDetailScreen extends StatefulWidget {
  final LocalDebtor debtor;
  final DebtorsRepository repository;

  const DebtorDetailScreen({
    super.key,
    required this.debtor,
    required this.repository,
  });

  @override
  State<DebtorDetailScreen> createState() => _DebtorDetailScreenState();
}

class _DebtorDetailScreenState extends State<DebtorDetailScreen> {
  late LocalDebtor _currentDebtor;
  List<LocalDebtorTransaction> _transactions = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _currentDebtor = widget.debtor;
    _loadTransactions();
  }

  Future<void> _loadTransactions() async {
    setState(() => _isLoading = true);
    final txns = await widget.repository.getTransactions(_currentDebtor.id);
    if (mounted) {
      setState(() {
        _transactions = txns;
        _isLoading = false;
      });
    }
  }

  void _showTransactionModal({required bool isPayment}) {
    final amountController = TextEditingController();
    final noteController = TextEditingController();
    final messenger = ScaffoldMessenger.of(context);

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (bottomSheetContext) => Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.of(bottomSheetContext).viewInsets.bottom,
        ),
        child: Container(
          padding: const EdgeInsets.all(24),
          decoration: const BoxDecoration(
            color: AppColors.lightCard,
            borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                isPayment ? "To'lov qabul qilish" : "Qarz qo'shish",
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 16),

              // Quick chip amounts
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [50000, 100000, 200000, 500000].map((amt) {
                    return Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ActionChip(
                        label: Text(CurrencyFormat.formatExact(amt)),
                        onPressed: () {
                          amountController.text = amt.toString();
                        },
                      ),
                    );
                  }).toList(),
                ),
              ),
              const SizedBox(height: 16),

              TextField(
                controller: amountController,
                keyboardType: TextInputType.number,
                autofocus: true,
                decoration: const InputDecoration(
                  labelText: "Summa (so'm)",
                  hintText: "0",
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: noteController,
                decoration: const InputDecoration(
                  labelText: "Izoh (ixtiyoriy)",
                  hintText: "Sabab yoki to'lov turi",
                ),
              ),
              const SizedBox(height: 20),

              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: isPayment ? AppColors.success : AppColors.primaryButton,
                ),
                onPressed: () async {
                  final entered = double.tryParse(amountController.text.trim()) ?? 0.0;
                  if (entered <= 0) return;

                  Navigator.pop(bottomSheetContext);
                  await widget.repository.addTransaction(
                    storeId: _currentDebtor.storeId,
                    debtorId: _currentDebtor.id,
                    type: isPayment ? 'payment' : 'debt',
                    amount: entered,
                    note: noteController.text.trim().isEmpty ? null : noteController.text.trim(),
                  );

                  // Update UI
                  final updatedDebtors = await widget.repository.getDebtors(_currentDebtor.storeId);
                  final matching = updatedDebtors.firstWhere((d) => d.id == _currentDebtor.id);
                  if (mounted) {
                    setState(() => _currentDebtor = matching);
                    await _loadTransactions();
                    messenger.showSnackBar(
                      SnackBar(
                        content: Text(isPayment ? "To'lov qayd etildi!" : "Qarz qo'shildi!"),
                        backgroundColor: AppColors.success,
                      ),
                    );
                  }
                },
                child: Text(isPayment ? "To'lovni saqlash" : "Qarzni saqlash"),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
          _currentDebtor.fullName,
          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
        ),
        backgroundColor: AppColors.lightCard,
        elevation: 0,
      ),
      body: Column(
        children: [
          // Profile summary card
          Container(
            width: double.infinity,
            margin: const EdgeInsets.all(16),
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: AppColors.lightTint,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppColors.lightAccent.withOpacity(0.2)),
            ),
            child: Column(
              children: [
                const Text(
                  "Hozirgi qarz balansi",
                  style: TextStyle(color: AppColors.lightMuted, fontSize: 13),
                ),
                const SizedBox(height: 6),
                Text(
                  CurrencyFormat.format(_currentDebtor.amount),
                  style: TextStyle(
                    fontSize: 26,
                    fontWeight: FontWeight.w800,
                    color: _currentDebtor.amount > 0 ? AppColors.lightDanger : AppColors.success,
                  ),
                ),
                if (_currentDebtor.phone != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    PhoneFormat.format(_currentDebtor.phone),
                    style: const TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: AppColors.lightText,
                    ),
                  ),
                ],
              ],
            ),
          ),

          // Actions
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Row(
              children: [
                Expanded(
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(backgroundColor: AppColors.success),
                    icon: const Icon(Icons.payment),
                    label: const Text("To'lov olish"),
                    onPressed: () => _showTransactionModal(isPayment: true),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(backgroundColor: AppColors.primaryButton),
                    icon: const Icon(Icons.add),
                    label: const Text("Qarz qo'shish"),
                    onPressed: () => _showTransactionModal(isPayment: false),
                  ),
                ),
              ],
            ),
          ),

          const SizedBox(height: 16),
          const Padding(
            padding: EdgeInsets.symmetric(horizontal: 16),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(
                "Tranzaksiyalar tarixi",
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
              ),
            ),
          ),
          const SizedBox(height: 8),

          // History list
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _transactions.isEmpty
                    ? const Center(
                        child: Text(
                          "Hozircha operatsiyalar yo'q",
                          style: TextStyle(color: AppColors.lightMuted, fontSize: 14),
                        ),
                      )
                    : ListView.separated(
                        padding: const EdgeInsets.all(16),
                        itemCount: _transactions.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 8),
                        itemBuilder: (context, index) {
                          final txn = _transactions[index];
                          final isPayment = txn.type == 'payment';

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
                                    color: isPayment ? AppColors.successBg : AppColors.lightDangerBg,
                                    borderRadius: BorderRadius.circular(10),
                                  ),
                                  child: Icon(
                                    isPayment ? Icons.arrow_downward : Icons.arrow_upward,
                                    size: 18,
                                    color: isPayment ? AppColors.success : AppColors.lightDanger,
                                  ),
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        isPayment ? "To'lov" : "Qarz berildi",
                                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                                      ),
                                      const SizedBox(height: 2),
                                      Text(
                                        AppDateFormat.fromIsoString(txn.date),
                                        style: const TextStyle(color: AppColors.lightMuted, fontSize: 11),
                                      ),
                                      if (txn.note != null && txn.note!.isNotEmpty) ...[
                                        const SizedBox(height: 2),
                                        Text(
                                          txn.note!,
                                          style: const TextStyle(color: AppColors.lightMuted, fontSize: 12),
                                        ),
                                      ],
                                    ],
                                  ),
                                ),
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    Text(
                                      (isPayment ? "- " : "+ ") + CurrencyFormat.format(txn.amount),
                                      style: TextStyle(
                                        fontWeight: FontWeight.w700,
                                        fontSize: 14,
                                        color: isPayment ? AppColors.success : AppColors.lightDanger,
                                      ),
                                    ),
                                    if (txn.syncStatus == 'pending')
                                      const Text(
                                        "Oflayn saqlangan",
                                        style: TextStyle(fontSize: 10, color: AppColors.warning),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          );
                        },
                      ),
          ),
        ],
      ),
    );
  }
}
