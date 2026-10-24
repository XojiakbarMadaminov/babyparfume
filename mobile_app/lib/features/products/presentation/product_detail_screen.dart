import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../data/products_repository.dart';
import '../../../core/utils/currency_format.dart';
import '../../../core/utils/date_format.dart';
import 'product_form_dialog.dart';
import 'product_stock_in_dialog.dart';
import 'product_widgets.dart';

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
  bool _loading = true;
  bool _deleting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _product = widget.product;
    _load();
  }

  Future<void> _load() async {
    try {
      final product =
          await widget.repository.getProduct(_product.storeId, _product.id);
      final variants = await widget.repository.variants(product ?? _product);
      if (!mounted) return;
      setState(() {
        _product = product ?? _product;
        _variants = variants;
        _loading = false;
        _error = null;
      });
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = 'Ombor qoldiqlarini o‘qib bo‘lmadi.';
        });
      }
    }
  }

  Future<void> _edit() async {
    await showDialog<void>(
        context: context,
        barrierDismissible: false,
        builder: (_) => ProductFormDialog(
            storeId: _product.storeId,
            repository: widget.repository,
            existing: _product,
            onCreated: (product) {
              if (!mounted) return;
              setState(() => _product = product);
              _load();
              ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Mahsulot saqlandi.')));
            }));
  }

  Future<void> _receiveStock() async {
    final product = await showDialog<LocalProduct>(
        context: context,
        barrierDismissible: false,
        builder: (_) => ProductStockInDialog(
            product: _product, repository: widget.repository));
    if (!mounted || product == null) return;
    setState(() => _product = product);
    await _load();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Kirim mahsulot qoldig‘iga qo‘shildi.')));
  }

  Future<void> _delete() async {
    final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
                title: const Text('Mahsulotni o‘chirish'),
                content: Text('“${_product.name}” o‘chirilsinmi?'),
                actions: [
                  TextButton(
                      onPressed: () => Navigator.pop(ctx, false),
                      child: const Text('Bekor qilish')),
                  TextButton(
                      onPressed: () => Navigator.pop(ctx, true),
                      style: TextButton.styleFrom(
                          foregroundColor: Theme.of(ctx).colorScheme.error),
                      child: const Text('O‘chirish')),
                ]));
    if (confirmed != true || !mounted) return;
    setState(() => _deleting = true);
    try {
      await widget.repository.deleteProduct(_product.id);
      if (mounted) Navigator.pop(context);
    } catch (_) {
      if (mounted) {
        setState(() => _deleting = false);
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text(
                'O‘chirish amalga oshmadi. Internet va ruxsatni tekshiring.')));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = theme.colorScheme;
    final groups = <String, List<LocalProduct>>{};
    for (final variant in _variants) {
      final name = variant.stockName ?? 'Ombor #${variant.stockId ?? '—'}';
      groups.putIfAbsent(name, () => []).add(variant);
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Mahsulot'), actions: [
        if (_product.canDelete)
          PopupMenuButton<String>(
              tooltip: 'Amallar',
              enabled: !_deleting,
              onSelected: (_) => _delete(),
              itemBuilder: (_) => [
                    PopupMenuItem(
                        value: 'delete',
                        child: Row(children: [
                          Icon(Icons.delete_outline, color: colors.error),
                          const SizedBox(width: 10),
                          Text('O‘chirish',
                              style: TextStyle(color: colors.error)),
                        ]))
                  ]),
      ]),
      body: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
              padding: const EdgeInsets.all(16),
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                ProductImage(
                    url: _product.imageUrl,
                    height: _product.imageUrl?.isNotEmpty == true ? 200 : 112),
                const SizedBox(height: 20),
                Text(_product.name,
                    style: theme.textTheme.headlineSmall
                        ?.copyWith(fontWeight: FontWeight.w700)),
                const SizedBox(height: 10),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  Chip(
                      avatar: const Icon(Icons.category_outlined, size: 18),
                      label: Text(_product.type == 'size'
                          ? 'Razmerli mahsulot'
                          : 'Qadoq')),
                  Chip(
                      avatar: Icon(Icons.inventory_2_outlined,
                          size: 18,
                          color: _product.stockQuantity > 0
                              ? colors.primary
                              : colors.error),
                      label: Text(_product.stockQuantity > 0
                          ? '${_product.stockQuantity} dona'
                          : 'Qoldiq yo‘q')),
                ]),
                const SizedBox(height: 16),
                ProductSection(
                    title: 'Narxlar',
                    icon: Icons.payments_outlined,
                    children: [
                      Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Sotish narxi',
                                style: theme.textTheme.bodyMedium),
                            const SizedBox(height: 4),
                            Text(CurrencyFormat.format(_product.price),
                                style: theme.textTheme.headlineSmall?.copyWith(
                                    color: colors.primary,
                                    fontWeight: FontWeight.w800)),
                          ]),
                      if (_product.initialPrice != null)
                        Row(children: [
                          const Expanded(child: Text('Kelish narxi')),
                          Flexible(
                              child: Text(
                                  CurrencyFormat.format(_product.initialPrice!),
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w600))),
                        ]),
                    ]),
                const SizedBox(height: 16),
                ProductSection(
                    title: 'Shtrix-kod',
                    icon: Icons.qr_code_2,
                    children: [
                      Row(children: [
                        Expanded(
                            child: SelectableText(
                                _product.barcode?.isNotEmpty == true
                                    ? _product.barcode!
                                    : 'Kiritilmagan',
                                style: theme.textTheme.titleMedium
                                    ?.copyWith(letterSpacing: 1))),
                        if (_product.barcode?.isNotEmpty == true)
                          IconButton(
                              tooltip: 'Shtrix-kodni nusxalash',
                              icon: const Icon(Icons.copy_outlined, size: 20),
                              onPressed: () async {
                                await Clipboard.setData(
                                    ClipboardData(text: _product.barcode!));
                                if (context.mounted) {
                                  ScaffoldMessenger.of(context).showSnackBar(
                                      const SnackBar(
                                          content:
                                              Text('Shtrix-kod nusxalandi.')));
                                }
                              }),
                      ]),
                    ]),
                const SizedBox(height: 16),
                ProductSection(
                    title: 'Ombor qoldiqlari',
                    icon: Icons.warehouse_outlined,
                    children: [
                      if (_loading)
                        const LinearProgressIndicator()
                      else if (_error != null)
                        Column(children: [
                          Text(_error!),
                          TextButton.icon(
                              onPressed: _load,
                              icon: const Icon(Icons.refresh),
                              label: const Text('Qayta urinish'))
                        ])
                      else if (_variants.isEmpty)
                        const Text('Omborlarda qoldiq mavjud emas.')
                      else
                        for (final group in groups.entries)
                          Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                Text(group.key,
                                    style: theme.textTheme.titleSmall),
                                const SizedBox(height: 8),
                                if (_product.type == 'size')
                                  Wrap(spacing: 8, runSpacing: 8, children: [
                                    for (final variant in group.value)
                                      Chip(
                                          label: Text(
                                              '${variant.sizeName ?? 'Razmer'} · ${variant.stockQuantity} dona')),
                                  ])
                                else
                                  Text(
                                      '${group.value.fold<int>(0, (total, variant) => total + variant.stockQuantity)} dona',
                                      style: theme.textTheme.titleMedium
                                          ?.copyWith(
                                              fontWeight: FontWeight.w700)),
                              ]),
                    ]),
                if (_product.updatedAt != null) ...[
                  const SizedBox(height: 20),
                  Text(
                      'Yangilangan: ${AppDateFormat.fromIsoString(_product.updatedAt)}',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodySmall),
                ],
                const SizedBox(height: 16),
              ])),
      bottomNavigationBar: !_product.canUpdate
          ? null
          : SafeArea(
              top: false,
              child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
                  child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                            flex: 3,
                            child: OutlinedButton(
                                style: OutlinedButton.styleFrom(
                                    minimumSize: const Size(0, 60),
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 8)),
                                onPressed: _deleting ? null : _edit,
                                child: const Text('Mahsulotni tahrirlash',
                                    textAlign: TextAlign.center))),
                        const SizedBox(width: 10),
                        Expanded(
                            flex: 2,
                            child: ElevatedButton(
                                style: ElevatedButton.styleFrom(
                                    minimumSize: const Size(0, 60),
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 8)),
                                onPressed: _deleting ? null : _receiveStock,
                                child: const Text('Kirim qilish',
                                    textAlign: TextAlign.center))),
                      ]))),
    );
  }
}
