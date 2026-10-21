import 'package:flutter/material.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/phone_format.dart';
import '../data/debtors_repository.dart';

class DebtorFormDialog extends StatefulWidget {
  final String storeId;
  final DebtorsRepository repository;
  final void Function(LocalDebtor newDebtor) onCreated;

  const DebtorFormDialog({
    super.key,
    required this.storeId,
    required this.repository,
    required this.onCreated,
  });

  @override
  State<DebtorFormDialog> createState() => _DebtorFormDialogState();
}

class _DebtorFormDialogState extends State<DebtorFormDialog> {
  final _nameController = TextEditingController();
  final _phoneController = TextEditingController();
  final _amountController = TextEditingController();
  final _noteController = TextEditingController();
  bool _isSaving = false;
  String? _error;

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _amountController.dispose();
    _noteController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final name = _nameController.text.trim();
    if (name.isEmpty) {
      setState(() => _error = "Iltimos, qarzdor ismini kiriting.");
      return;
    }

    final rawPhone = _phoneController.text.trim();
    final normalizedPhone = rawPhone.isEmpty ? null : PhoneFormat.normalize(rawPhone);
    final initialAmount = double.tryParse(_amountController.text.trim()) ?? 0.0;
    final note = _noteController.text.trim().isEmpty ? null : _noteController.text.trim();

    setState(() {
      _isSaving = true;
      _error = null;
    });

    try {
      final debtor = await widget.repository.createDebtor(
        storeId: widget.storeId,
        fullName: name,
        phone: normalizedPhone,
        initialAmount: initialAmount,
        note: note,
      );

      if (mounted) {
        Navigator.pop(context);
        widget.onCreated(debtor);
      }
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                "Yangi qarzdor qo'shish",
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 16),

              if (_error != null) ...[
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: AppColors.lightDangerBg,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    _error!,
                    style: const TextStyle(color: AppColors.lightDanger, fontSize: 12),
                  ),
                ),
                const SizedBox(height: 12),
              ],

              TextField(
                controller: _nameController,
                textCapitalization: TextCapitalization.words,
                decoration: const InputDecoration(
                  labelText: "Ism va familiya *",
                  hintText: "Masalan: Rustam Karimov",
                ),
              ),
              const SizedBox(height: 12),

              TextField(
                controller: _phoneController,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(
                  labelText: "Telefon raqami",
                  hintText: "+998 90 123 45 67",
                ),
              ),
              const SizedBox(height: 12),

              TextField(
                controller: _amountController,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: "Boshlang'ich qarz summasi (so'm)",
                  hintText: "0",
                ),
              ),
              const SizedBox(height: 12),

              TextField(
                controller: _noteController,
                decoration: const InputDecoration(
                  labelText: "Izoh",
                  hintText: "Qo'shimcha ma'lumot",
                ),
              ),
              const SizedBox(height: 24),

              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  TextButton(
                    onPressed: _isSaving ? null : () => Navigator.pop(context),
                    child: const Text("Bekor qilish"),
                  ),
                  const SizedBox(width: 8),
                  ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      minimumSize: const Size(120, 45),
                    ),
                    onPressed: _isSaving ? null : _submit,
                    child: _isSaving
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                          )
                        : const Text("Saqlash"),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
