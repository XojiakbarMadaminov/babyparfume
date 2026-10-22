import 'dart:async';
import 'package:flutter/services.dart';
import '../../../core/utils/photo_picker.dart';
import 'debtor_form_dialog.dart';
import 'package:flutter/material.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/database/app_database.dart';
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
  bool _transactionSaving = false;
  bool _photoSaving = false;
  Uint8List? _photo;
  StreamSubscription<String>? _mediaSubscription;

  @override
  void initState() {
    super.initState();
    _currentDebtor = widget.debtor;
    _mediaSubscription = AppDatabase.mediaChanges.listen((id) async {
      if (id != _currentDebtor.id) return;
      final photo = await widget.repository.getPhoto(id);
      if (mounted) setState(() => _photo = photo);
    });
    _loadTransactions();
  }

  @override
  void dispose() {
    _mediaSubscription?.cancel();
    super.dispose();
  }

  Future<void> _loadTransactions() async {
    setState(() => _isLoading = true);
    final txns = await widget.repository.getTransactions(_currentDebtor.id);
    final debtor = await widget.repository.getDebtor(_currentDebtor.id);
    final photo = await widget.repository.getPhoto(_currentDebtor.id);
    if (mounted) {
      setState(() {
        _transactions = txns;
        _photo = photo;
        if (debtor != null) _currentDebtor = debtor;
        _isLoading = false;
      });
    }
  }

  void _showTransactionModal({required bool isPayment}) {
    final amountController = TextEditingController();
    final noteController = TextEditingController();
    var paymentMethod = 'cash';
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
                style:
                    const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
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
              if (isPayment)
                DropdownButtonFormField<String>(
                    value: paymentMethod,
                    decoration: const InputDecoration(labelText: 'To‘lov turi'),
                    items: const [
                      DropdownMenuItem(value: 'cash', child: Text('Naqd')),
                      DropdownMenuItem(value: 'card', child: Text('Karta')),
                    ],
                    onChanged: (value) => paymentMethod = value ?? 'cash'),
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
                  backgroundColor:
                      isPayment ? AppColors.success : AppColors.primaryButton,
                ),
                onPressed: () async {
                  if (_transactionSaving) return;
                  final entered =
                      double.tryParse(amountController.text.trim()) ?? 0.0;
                  if (entered <= 0) return;

                  _transactionSaving = true;
                  try {
                    await widget.repository.addTransaction(
                        storeId: _currentDebtor.storeId,
                        debtorId: _currentDebtor.id,
                        type: isPayment ? 'payment' : 'debt',
                        paymentMethod: paymentMethod,
                        amount: entered,
                        note: noteController.text.trim().isEmpty
                            ? null
                            : noteController.text.trim());
                  } catch (error) {
                    messenger.showSnackBar(
                        SnackBar(content: Text(error.toString())));
                    return;
                  } finally {
                    _transactionSaving = false;
                  }
                  if (!bottomSheetContext.mounted) return;
                  Navigator.pop(bottomSheetContext);
                  // Update UI
                  final updatedDebtors = await widget.repository
                      .getDebtors(_currentDebtor.storeId);
                  final matching = updatedDebtors
                      .firstWhere((d) => d.id == _currentDebtor.id);
                  if (mounted) {
                    setState(() => _currentDebtor = matching);
                    await _loadTransactions();
                    messenger.showSnackBar(
                      SnackBar(
                        content: Text(isPayment
                            ? "To'lov qayd etildi!"
                            : "Qarz qo'shildi!"),
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

  Future<void> _changePhoto(bool remove) async {
    if (_photoSaving) return;
    setState(() => _photoSaving = true);
    try {
      Uint8List? bytes;
      if (!remove) {
        bytes = await PhotoPicker.pick(context);
        if (bytes == null || !mounted) return;
      }
      await widget.repository.setPhoto(_currentDebtor, bytes);
      await _loadTransactions();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.toString())));
      }
    } finally {
      if (mounted) setState(() => _photoSaving = false);
    }
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
        actions: [
          if (_currentDebtor.canUpdate)
            IconButton(
                icon: const Icon(Icons.edit),
                onPressed: () => showDialog(
                    context: context,
                    builder: (_) => DebtorFormDialog(
                        storeId: _currentDebtor.storeId,
                        repository: widget.repository,
                        existing: _currentDebtor,
                        onCreated: (_) => _loadTransactions())))
        ],
      ),
      body: Column(
        children: [
          if (_photo != null)
            SizedBox(
                height: 140, child: Image.memory(_photo!, fit: BoxFit.contain)),
          if (_photo == null)
            const Padding(
                padding: EdgeInsets.all(8), child: Text('Foto yuklanmagan')),
          if (_currentDebtor.canUpdate)
            Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              TextButton.icon(
                  onPressed: _photoSaving ? null : () => _changePhoto(false),
                  icon: const Icon(Icons.photo),
                  label: const Text('Foto tanlash')),
              TextButton.icon(
                  onPressed: _photoSaving ? null : () => _changePhoto(true),
                  icon: const Icon(Icons.delete_outline),
                  label: const Text('Foto o‘chirish')),
            ]),
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
                  CurrencyFormat.format(_currentDebtor.amount,
                      suffix: _currentDebtor.currency == 'UZS'
                          ? "so'm"
                          : _currentDebtor.currency),
                  style: TextStyle(
                    fontSize: 26,
                    fontWeight: FontWeight.w800,
                    color: _currentDebtor.amount > 0
                        ? AppColors.lightDanger
                        : AppColors.success,
                  ),
                ),
                if (_transactions.any((t) => t.syncStatus != 'synced'))
                  const Text(
                      'Lokal balansga server tasdiqlamagan amallar ham kiritilgan. Holatini tarixdan tekshiring.',
                      textAlign: TextAlign.center),
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
                    style: ElevatedButton.styleFrom(
                        backgroundColor: AppColors.success),
                    icon: const Icon(Icons.payment),
                    label: const Text("To'lov olish"),
                    onPressed: _currentDebtor.canUpdate
                        ? () => _showTransactionModal(isPayment: true)
                        : null,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                        backgroundColor: AppColors.primaryButton),
                    icon: const Icon(Icons.add),
                    label: const Text("Qarz qo'shish"),
                    onPressed: _currentDebtor.canUpdate
                        ? () => _showTransactionModal(isPayment: false)
                        : null,
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
                          style: TextStyle(
                              color: AppColors.lightMuted, fontSize: 14),
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
                                    color: isPayment
                                        ? AppColors.successBg
                                        : AppColors.lightDangerBg,
                                    borderRadius: BorderRadius.circular(10),
                                  ),
                                  child: Icon(
                                    isPayment
                                        ? Icons.arrow_downward
                                        : Icons.arrow_upward,
                                    size: 18,
                                    color: isPayment
                                        ? AppColors.success
                                        : AppColors.lightDanger,
                                  ),
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        isPayment ? "To'lov" : "Qarz berildi",
                                        style: const TextStyle(
                                            fontWeight: FontWeight.w600,
                                            fontSize: 14),
                                      ),
                                      const SizedBox(height: 2),
                                      Text(
                                        AppDateFormat.fromIsoString(txn.date),
                                        style: const TextStyle(
                                            color: AppColors.lightMuted,
                                            fontSize: 11),
                                      ),
                                      if (txn.note != null &&
                                          txn.note!.isNotEmpty) ...[
                                        const SizedBox(height: 2),
                                        Text(
                                          txn.note!,
                                          style: const TextStyle(
                                              color: AppColors.lightMuted,
                                              fontSize: 12),
                                        ),
                                      ],
                                    ],
                                  ),
                                ),
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    Text(
                                      (isPayment ? "- " : "+ ") +
                                          CurrencyFormat.format(txn.amount,
                                              suffix: _currentDebtor.currency ==
                                                      'UZS'
                                                  ? "so'm"
                                                  : _currentDebtor.currency),
                                      style: TextStyle(
                                        fontWeight: FontWeight.w700,
                                        fontSize: 14,
                                        color: isPayment
                                            ? AppColors.success
                                            : AppColors.lightDanger,
                                      ),
                                    ),
                                    if (txn.syncStatus != 'synced')
                                      Text(
                                        const {
                                              'pending': 'Tasdiq kutilmoqda',
                                              'conflict': 'Admin qarori kerak',
                                              'blocked':
                                                  'Oldingi amal kutilmoqda',
                                              'rejected': 'Server rad etgan'
                                            }[txn.syncStatus] ??
                                            'Tasdiqlanmagan',
                                        style: const TextStyle(
                                            fontSize: 10,
                                            color: AppColors.warning),
                                      ),
                                    IconButton(
                                        icon: const Icon(Icons.receipt_long,
                                            size: 18),
                                        tooltip: 'Amal cheki nusxasi',
                                        onPressed: () async {
                                          await Clipboard.setData(ClipboardData(
                                              text:
                                                  'Baby Parfume · AMAL CHEKI NUSXASI\nID: ${txn.id}\nMijoz: ${_currentDebtor.fullName}\n${isPayment ? 'To‘lov' : 'Qarz'}: ${CurrencyFormat.format(txn.amount, suffix: _currentDebtor.currency == 'UZS' ? "so'm" : _currentDebtor.currency)}\nSana: ${txn.date}\nIzoh: ${txn.note ?? ''}\n${txn.syncStatus == 'synced' ? 'Server tasdiqlagan' : 'Server tasdiqlamagan; holatini ilovada tekshiring.'}'));
                                          if (context.mounted) {
                                            ScaffoldMessenger.of(context)
                                                .showSnackBar(const SnackBar(
                                                    content: Text(
                                                        'Amal cheki nusxalandi.')));
                                          }
                                        }),
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
