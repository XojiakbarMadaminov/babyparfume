import 'package:flutter/material.dart';
import '../../../core/sync/outbox_manager.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_format.dart';
import '../data/products_repository.dart';
import 'barcode_scanner_sheet.dart';
import 'product_form_dialog.dart';
import 'product_detail_screen.dart';

class ProductsScreen extends StatefulWidget {
  final String storeId;
  final ProductsRepository? repository;
  final bool canCreate;
  final int revision;

  const ProductsScreen({
    super.key,
    required this.storeId,
    this.repository,
    this.canCreate = false,
    this.revision = 0,
  });

  @override
  State<ProductsScreen> createState() => _ProductsScreenState();
}

class _ProductsScreenState extends State<ProductsScreen> {
  final TextEditingController _searchController = TextEditingController();
  late final ProductsRepository _repository;
  List<LocalProduct> _products = [];
  bool _isLoading = true;
  String _searchQuery = '';
  int _loadRequest = 0;

  @override
  void initState() {
    super.initState();
    _repository =
        widget.repository ?? ProductsRepository(outboxManager: OutboxManager());
    _loadProducts();
  }

  @override
  void didUpdateWidget(covariant ProductsScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.revision != widget.revision) _loadProducts();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _loadProducts() async {
    final request = ++_loadRequest;
    if (_products.isEmpty) setState(() => _isLoading = true);
    final list =
        await _repository.searchProducts(widget.storeId, query: _searchQuery);
    if (mounted && request == _loadRequest) {
      setState(() {
        _products = list;
        _isLoading = false;
      });
    }
  }

  void _openScanner() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (context) => BarcodeScannerSheet(
        onBarcodeScanned: (scannedBarcode) {
          _searchController.text = scannedBarcode;
          _searchQuery = scannedBarcode;
          _loadProducts();
        },
      ),
    );
  }

  void _openAddProductDialog() {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (context) => ProductFormDialog(
        storeId: widget.storeId,
        repository: _repository,
        onCreated: (newProduct) {
          _loadProducts();
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text("Mahsulot muvaffaqiyatli saqlandi!"),
              backgroundColor: AppColors.success,
            ),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text(
          "Mahsulotlar",
          style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
        ),
        backgroundColor: AppColors.lightCard,
        elevation: 0,
        actions: [
          IconButton(
            icon:
                const Icon(Icons.qr_code_scanner, color: AppColors.lightAccent),
            tooltip: "Shtrix-kod skaneri",
            onPressed: _openScanner,
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _searchController,
                    onChanged: (val) {
                      _searchQuery = val;
                      _loadProducts();
                    },
                    decoration: InputDecoration(
                      hintText: "Nomi yoki shtrix-kodi bo'yicha qidirish...",
                      prefixIcon:
                          const Icon(Icons.search, color: AppColors.lightMuted),
                      contentPadding: const EdgeInsets.symmetric(
                          horizontal: 14, vertical: 12),
                      fillColor: AppColors.lightSoft,
                      filled: true,
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(12),
                        borderSide: BorderSide.none,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                IconButton.filled(
                  style: IconButton.styleFrom(
                    backgroundColor: AppColors.lightTint,
                    foregroundColor: AppColors.lightAccent,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12)),
                  ),
                  icon: const Icon(Icons.qr_code_scanner),
                  onPressed: _openScanner,
                ),
              ],
            ),
          ),
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _products.isEmpty
                    ? const Center(
                        child: Text(
                          "Mahsulot topilmadi",
                          style: TextStyle(
                              color: AppColors.lightMuted, fontSize: 14),
                        ),
                      )
                    : ListView.separated(
                        padding: const EdgeInsets.all(16),
                        itemCount: _products.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 8),
                        itemBuilder: (context, index) {
                          final item = _products[index];
                          final isOutOfStock = item.stockQuantity <= 0;

                          return GestureDetector(
                              onTap: () async {
                                await Navigator.push(
                                    context,
                                    MaterialPageRoute(
                                        builder: (_) => ProductDetailScreen(
                                            product: item,
                                            repository: _repository)));
                                if (mounted) _loadProducts();
                              },
                              child: Container(
                                padding: const EdgeInsets.all(14),
                                decoration: BoxDecoration(
                                  color: AppColors.lightCard,
                                  borderRadius: BorderRadius.circular(12),
                                  border:
                                      Border.all(color: AppColors.lightLine),
                                ),
                                child: Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Container(
                                      width: 44,
                                      height: 44,
                                      decoration: BoxDecoration(
                                        color: AppColors.lightSoft,
                                        borderRadius: BorderRadius.circular(10),
                                      ),
                                      child: const Icon(
                                          Icons.inventory_2_outlined,
                                          color: AppColors.lightMuted),
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: [
                                          Text(
                                            item.name,
                                            style: const TextStyle(
                                                fontWeight: FontWeight.w600,
                                                fontSize: 14),
                                          ),
                                          const SizedBox(height: 4),
                                          if (item.barcode != null &&
                                              item.barcode!.isNotEmpty)
                                            Text(
                                              "Barkod: ${item.barcode}",
                                              style: const TextStyle(
                                                  color: AppColors.lightMuted,
                                                  fontSize: 12),
                                            ),
                                          const SizedBox(height: 6),
                                          Text(
                                            CurrencyFormat.format(item.price),
                                            style: const TextStyle(
                                              fontWeight: FontWeight.w700,
                                              fontSize: 14,
                                              color: AppColors.lightAccent,
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                    Container(
                                      padding: const EdgeInsets.symmetric(
                                          horizontal: 8, vertical: 4),
                                      decoration: BoxDecoration(
                                        color: isOutOfStock
                                            ? AppColors.lightDangerBg
                                            : AppColors.successBg,
                                        borderRadius: BorderRadius.circular(8),
                                      ),
                                      child: Text(
                                        isOutOfStock
                                            ? "Tugagan"
                                            : "${item.stockQuantity} dona",
                                        style: TextStyle(
                                          color: isOutOfStock
                                              ? AppColors.lightDanger
                                              : AppColors.success,
                                          fontWeight: FontWeight.w600,
                                          fontSize: 12,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ));
                        },
                      ),
          ),
        ],
      ),
      floatingActionButton: !widget.canCreate
          ? null
          : FloatingActionButton.extended(
              backgroundColor: AppColors.primaryButton,
              foregroundColor: Colors.white,
              icon: const Icon(Icons.add),
              label: const Text("Mahsulot qo'shish"),
              onPressed: _openAddProductDialog,
            ),
    );
  }
}
