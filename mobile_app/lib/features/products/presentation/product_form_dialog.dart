import 'package:flutter/material.dart';
import '../../../core/theme/app_colors.dart';
import '../data/products_repository.dart';

class ProductFormDialog extends StatefulWidget {
  final String storeId;
  final ProductsRepository repository;
  final void Function(LocalProduct product) onCreated;

  const ProductFormDialog({
    super.key,
    required this.storeId,
    required this.repository,
    required this.onCreated,
  });

  @override
  State<ProductFormDialog> createState() => _ProductFormDialogState();
}

class _ProductFormDialogState extends State<ProductFormDialog> {
  final _nameController = TextEditingController();
  final _barcodeController = TextEditingController();
  final _priceController = TextEditingController();
  final _stockController = TextEditingController(text: '1');
  bool _isSaving = false;
  String? _error;

  @override
  void dispose() {
    _nameController.dispose();
    _barcodeController.dispose();
    _priceController.dispose();
    _stockController.dispose();
    super.dispose();
  }

  void _generateBarcode() {
    final code = widget.repository.generateBarcode();
    setState(() {
      _barcodeController.text = code;
    });
  }

  Future<void> _submit() async {
    final name = _nameController.text.trim();
    if (name.isEmpty) {
      setState(() => _error = "Mahsulot nomini kiriting.");
      return;
    }

    final price = double.tryParse(_priceController.text.trim()) ?? 0.0;
    if (price <= 0) {
      setState(() => _error = "Iltimos, to'g'ri narx kiriting.");
      return;
    }

    final stock = int.tryParse(_stockController.text.trim()) ?? 0;
    final barcode = _barcodeController.text.trim().isEmpty ? null : _barcodeController.text.trim();

    setState(() {
      _isSaving = true;
      _error = null;
    });

    try {
      final product = await widget.repository.createProduct(
        storeId: widget.storeId,
        name: name,
        barcode: barcode,
        price: price,
        initialStock: stock,
      );

      if (mounted) {
        Navigator.pop(context);
        widget.onCreated(product);
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
                "Yangi mahsulot qo'shish",
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
                textCapitalization: TextCapitalization.sentences,
                decoration: const InputDecoration(
                  labelText: "Mahsulot nomi *",
                  hintText: "Masalan: Chanel Bleu 100ml",
                ),
              ),
              const SizedBox(height: 12),

              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _barcodeController,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(
                        labelText: "Barkod (ixtiyoriy)",
                        hintText: "47800...",
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  IconButton.filled(
                    style: IconButton.styleFrom(
                      backgroundColor: AppColors.lightTint,
                      foregroundColor: AppColors.lightAccent,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    icon: const Icon(Icons.auto_fix_high),
                    tooltip: "Avtomatik generatsiya",
                    onPressed: _generateBarcode,
                  ),
                ],
              ),
              const SizedBox(height: 12),

              TextField(
                controller: _priceController,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: "Sotish narxi (so'm) *",
                  hintText: "Masalan: 1200000",
                ),
              ),
              const SizedBox(height: 12),

              TextField(
                controller: _stockController,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: "Boshlang'ich qoldiq (dona)",
                  hintText: "1",
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
