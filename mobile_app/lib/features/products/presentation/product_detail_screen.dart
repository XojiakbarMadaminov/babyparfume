import 'package:flutter/material.dart';
import '../data/products_repository.dart';
import '../../../core/utils/currency_format.dart';
import 'product_form_dialog.dart';

class ProductDetailScreen extends StatefulWidget {
  final LocalProduct product;
  final ProductsRepository repository;
  const ProductDetailScreen(
      {super.key, required this.product, required this.repository});
  @override
  State<ProductDetailScreen> createState() => _ProductDetailScreenState();
}

class _ProductDetailScreenState extends State<ProductDetailScreen> {
  late LocalProduct _product;
  List<LocalProduct> _variants = [];
  @override
  void initState() {
    super.initState();
    _product = widget.product;
    _load();
  }

  Future<void> _load() async {
    final variants = await widget.repository.variants(_product);
    if (mounted) setState(() => _variants = variants);
  }

  Future<void> _delete() async {
    final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
                title: const Text('Mahsulotni o‘chirish'),
                content: Text(_product.name),
                actions: [
                  TextButton(
                      onPressed: () => Navigator.pop(ctx, false),
                      child: const Text('Bekor qilish')),
                  TextButton(
                      onPressed: () => Navigator.pop(ctx, true),
                      child: const Text('O‘chirish'))
                ]));
    if (confirmed != true) return;
    try {
      await widget.repository.deleteProduct(_product.id);
      if (mounted) Navigator.pop(context);
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text(
                'O‘chirish amalga oshmadi. Internet va ruxsatni tekshiring.')));
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(_product.name), actions: [
          if (_product.canUpdate)
            IconButton(
                icon: const Icon(Icons.edit),
                tooltip: 'Tahrirlash',
                onPressed: () => showDialog(
                    context: context,
                    builder: (_) => ProductFormDialog(
                        storeId: _product.storeId,
                        repository: widget.repository,
                        existing: _product,
                        onCreated: (product) {
                          setState(() => _product = product);
                          _load();
                        }))),
          if (_product.canDelete)
            IconButton(
                icon: const Icon(Icons.delete_outline),
                tooltip: 'O‘chirish',
                onPressed: _delete),
        ]),
        body: ListView(padding: const EdgeInsets.all(20), children: [
          if (_product.imageUrl != null)
            SizedBox(
                height: 220,
                child: Image.network(_product.imageUrl!,
                    fit: BoxFit.contain,
                    errorBuilder: (_, __, ___) =>
                        const Center(child: Text('Foto yuklanmagan')))),
          Text(_product.name,
              style:
                  const TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          Text('Shtrix-kod: ${_product.barcode ?? '—'}'),
          const SizedBox(height: 12),
          Text('Sotish narxi: ${CurrencyFormat.format(_product.price)}'),
          if (_product.initialPrice != null)
            Text(
                'Kelish narxi: ${CurrencyFormat.format(_product.initialPrice!)}'),
          const SizedBox(height: 12),
          Text('Oxirgi yangilanish: ${_product.updatedAt ?? 'Noma’lum'}'),
          const SizedBox(height: 16),
          const Text('Saqlangan ombor va razmer qoldiqlari'),
          ..._variants.map((v) => ListTile(
              title: Text(v.sizeName ?? 'Qadoq'),
              subtitle: Text('Ombor ${v.stockId}'),
              trailing: Text('${v.stockQuantity} dona'))),
        ]),
      );
}
