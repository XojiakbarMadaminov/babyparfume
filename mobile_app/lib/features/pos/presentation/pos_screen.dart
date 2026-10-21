import 'package:flutter/material.dart';
import '../../../core/sync/offline_sale_service.dart';
import '../../../core/sync/outbox_manager.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_format.dart';
import '../../debtors/data/debtors_repository.dart';
import '../../products/data/products_repository.dart';
import '../../products/presentation/barcode_scanner_sheet.dart';

class CartItem {
  final LocalProduct product;
  int quantity;

  CartItem({
    required this.product,
    this.quantity = 1,
  });

  double get price => product.price;
  double get total => price * quantity;
  bool get hasDeficit => quantity > product.stockQuantity;
  int get deficitQuantity => (quantity - product.stockQuantity) > 0 ? (quantity - product.stockQuantity) : 0;
}

class PosScreen extends StatefulWidget {
  final String storeId;
  final ProductsRepository? productsRepository;
  final DebtorsRepository? debtorsRepository;
  final OfflineSaleService? saleService;

  const PosScreen({
    super.key,
    required this.storeId,
    this.productsRepository,
    this.debtorsRepository,
    this.saleService,
  });

  @override
  State<PosScreen> createState() => _PosScreenState();
}

class _PosScreenState extends State<PosScreen> {
  final TextEditingController _searchController = TextEditingController();
  late final ProductsRepository _productsRepo;
  late final DebtorsRepository _debtorsRepo;
  late final OfflineSaleService _saleService;

  final List<CartItem> _cart = [];
  List<LocalProduct> _searchResults = [];
  bool _isSearching = false;

  @override
  void initState() {
    super.initState();
    final outbox = OutboxManager();
    _productsRepo = widget.productsRepository ?? ProductsRepository(outboxManager: outbox);
    _debtorsRepo = widget.debtorsRepository ?? DebtorsRepository(outboxManager: outbox);
    _saleService = widget.saleService ?? OfflineSaleService(outboxManager: outbox);
  }

  void _onSearch(String query) async {
    if (query.trim().isEmpty) {
      setState(() {
        _searchResults = [];
        _isSearching = false;
      });
      return;
    }

    setState(() => _isSearching = true);
    final results = await _productsRepo.searchProducts(widget.storeId, query: query);
    if (mounted) {
      setState(() {
        _searchResults = results;
        _isSearching = false;
      });
    }
  }

  void _addToCart(LocalProduct product) {
    setState(() {
      final index = _cart.indexWhere((c) => c.product.id == product.id);
      if (index >= 0) {
        _cart[index].quantity += 1;
      } else {
        _cart.add(CartItem(product: product));
      }
      _searchResults = [];
      _searchController.clear();
    });
  }

  void _updateQuantity(int index, int delta) {
    setState(() {
      _cart[index].quantity += delta;
      if (_cart[index].quantity <= 0) {
        _cart.removeAt(index);
      }
    });
  }

  double get _totalAmount => _cart.fold(0.0, (sum, item) => sum + item.total);

  void _openBarcodeScanner() {
    final messenger = ScaffoldMessenger.of(context);
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (context) => BarcodeScannerSheet(
        onBarcodeScanned: (barcode) async {
          final product = await _productsRepo.findByBarcode(widget.storeId, barcode);
          if (product != null) {
            _addToCart(product);
            messenger.showSnackBar(
              SnackBar(
                content: Text("${product.name} savatga qo'shildi!"),
                backgroundColor: AppColors.success,
              ),
            );
          } else {
            messenger.showSnackBar(
              SnackBar(
                content: Text("Barkod bo'yicha mahsulot topilmadi: $barcode"),
                backgroundColor: AppColors.lightDanger,
              ),
            );
          }
        },
      ),
    );
  }

  void _checkout() async {
    if (_cart.isEmpty) return;

    final debtors = await _debtorsRepo.getDebtors(widget.storeId);
    String selectedPaymentType = 'cash'; // 'cash', 'card', 'debt'
    LocalDebtor? selectedDebtor;
    final cashController = TextEditingController(text: CurrencyFormat.formatExact(_totalAmount));
    final cardController = TextEditingController(text: '0');
    final debtController = TextEditingController(text: '0');

    if (!mounted) return;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => StatefulBuilder(
        builder: (modalCtx, setModalState) {
          final total = _totalAmount;
          final debt = double.tryParse(debtController.text.trim()) ?? 0.0;

          return Padding(
            padding: EdgeInsets.only(bottom: MediaQuery.of(modalCtx).viewInsets.bottom),
            child: Container(
              padding: const EdgeInsets.all(24),
              decoration: const BoxDecoration(
                color: AppColors.lightCard,
                borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
              ),
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Text(
                      "To'lovni qabul qilish",
                      style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 12),
                    Text(
                      "Jami summa: ${CurrencyFormat.format(total)}",
                      style: const TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.w800,
                        color: AppColors.lightAccent,
                      ),
                    ),
                    const SizedBox(height: 16),

                    // Payment Type Selector
                    Row(
                      children: [
                        Expanded(
                          child: ChoiceChip(
                            label: const Text("Naqd"),
                            selected: selectedPaymentType == 'cash',
                            onSelected: (val) {
                              if (val) {
                                setModalState(() {
                                  selectedPaymentType = 'cash';
                                  cashController.text = CurrencyFormat.formatExact(total);
                                  cardController.text = '0';
                                  debtController.text = '0';
                                });
                              }
                            },
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: ChoiceChip(
                            label: const Text("Karta"),
                            selected: selectedPaymentType == 'card',
                            onSelected: (val) {
                              if (val) {
                                setModalState(() {
                                  selectedPaymentType = 'card';
                                  cardController.text = CurrencyFormat.formatExact(total);
                                  cashController.text = '0';
                                  debtController.text = '0';
                                });
                              }
                            },
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: ChoiceChip(
                            label: const Text("Nasiya"),
                            selected: selectedPaymentType == 'debt',
                            onSelected: (val) {
                              if (val) {
                                setModalState(() {
                                  selectedPaymentType = 'debt';
                                  debtController.text = CurrencyFormat.formatExact(total);
                                  cashController.text = '0';
                                  cardController.text = '0';
                                });
                              }
                            },
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),

                    // If debt is involved, show Debtor picker
                    if (selectedPaymentType == 'debt' || debt > 0) ...[
                      const Text(
                        "Qarzdorni tanlang:",
                        style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.lightMuted),
                      ),
                      const SizedBox(height: 6),
                      DropdownButtonFormField<LocalDebtor>(
                        value: selectedDebtor,
                        hint: const Text("Mijozni tanlang"),
                        decoration: const InputDecoration(contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 10)),
                        items: debtors.map((d) => DropdownMenuItem(
                          value: d,
                          child: Text("${d.fullName} (${CurrencyFormat.format(d.amount)})"),
                        )).toList(),
                        onChanged: (d) => setModalState(() => selectedDebtor = d),
                      ),
                      const SizedBox(height: 16),
                    ],

                    ElevatedButton(
                      onPressed: () async {
                        if ((selectedPaymentType == 'debt' || debt > 0) && selectedDebtor == null) {
                          ScaffoldMessenger.of(modalCtx).showSnackBar(
                            const SnackBar(content: Text("Iltimos, qarzdor mijozni tanlang")),
                          );
                          return;
                        }

                        Navigator.pop(modalCtx);

                        final itemsPayload = _cart.map((c) => {
                          'product_id': c.product.id,
                          'quantity': c.quantity,
                          'price': c.price,
                          'total': c.total,
                        }).toList();

                        double paid = total;
                        double remaining = 0;
                        if (selectedPaymentType == 'debt') {
                          paid = 0;
                          remaining = total;
                        }

                        // Atomic offline transaction & ledger posting (PH08-03, PH08-04)
                        final result = await _saleService.processOfflineSale(
                          storeId: widget.storeId,
                          clientId: selectedDebtor?.id,
                          items: itemsPayload,
                          totalAmount: total,
                          paidAmount: paid,
                          remainingAmount: remaining,
                          paymentType: selectedPaymentType,
                        );

                        // If sale involved debt, update debtor in SQLite too
                        if (remaining > 0 && selectedDebtor != null) {
                          await _debtorsRepo.addTransaction(
                            storeId: widget.storeId,
                            debtorId: selectedDebtor!.id,
                            type: 'debt',
                            amount: remaining,
                            note: "Savdo cheki #${result.localSaleId.substring(0, 8)}",
                          );
                        }

                        setState(() => _cart.clear());

                        if (mounted) {
                          _showReceiptDialog(result, selectedPaymentType);
                        }
                      },
                      child: const Text("Savdoni yakunlash"),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  void _showReceiptDialog(OfflineSaleResult result, String paymentType) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Row(
          children: [
            Icon(Icons.receipt_long, color: AppColors.lightAccent),
            SizedBox(width: 8),
            Text("Savdo cheki"),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text("Chek ID: ${result.localSaleId.substring(0, 8)}"),
            const SizedBox(height: 6),
            Text("Jami summa: ${CurrencyFormat.format(result.totalAmount)}"),
            Text("To'lov turi: ${paymentType.toUpperCase()}"),
            if (result.deficits.isNotEmpty) ...[
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: AppColors.warningBg,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  "${result.deficits.length} ta mahsulot omborda yetarli emas edi (zero-stock deficit qayd etildi).",
                  style: const TextStyle(fontSize: 12, color: AppColors.warning),
                ),
              ),
            ],
            const SizedBox(height: 8),
            const Text(
              "Chek oflayn ma'lumotlar bazasiga saqlandi va aloqa tiklanganda serverga yuboriladi.",
              style: TextStyle(fontSize: 12, color: AppColors.lightMuted),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text("Yopish"),
          ),
          ElevatedButton.icon(
            icon: const Icon(Icons.print),
            label: const Text("Chop etish (ESC/POS)"),
            onPressed: () {
              Navigator.pop(ctx);
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(
                  content: Text("Bluetooth printerga chek yuborildi!"),
                  backgroundColor: AppColors.success,
                ),
              );
            },
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text(
          "Savdo (POS)",
          style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
        ),
        backgroundColor: AppColors.lightCard,
        elevation: 0,
        actions: [
          IconButton(
            icon: const Icon(Icons.qr_code_scanner, color: AppColors.lightAccent),
            tooltip: "Shtrix-kod skaneri",
            onPressed: _openBarcodeScanner,
          ),
        ],
      ),
      body: Column(
        children: [
          // Search & Barcode Scan Bar
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _searchController,
                    onChanged: _onSearch,
                    decoration: InputDecoration(
                      hintText: "Mahsulot qidirish yoki shtrix-kod...",
                      prefixIcon: _isSearching
                          ? const Padding(
                              padding: EdgeInsets.all(12),
                              child: SizedBox(
                                width: 16,
                                height: 16,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              ),
                            )
                          : const Icon(Icons.search, color: AppColors.lightMuted),
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
                const SizedBox(width: 8),
                IconButton.filled(
                  style: IconButton.styleFrom(
                    backgroundColor: AppColors.lightTint,
                    foregroundColor: AppColors.lightAccent,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  icon: const Icon(Icons.qr_code_scanner),
                  onPressed: _openBarcodeScanner,
                ),
              ],
            ),
          ),

          // Search Dropdown Overlay if searching
          if (_searchResults.isNotEmpty)
            Container(
              constraints: const BoxConstraints(maxHeight: 200),
              margin: const EdgeInsets.symmetric(horizontal: 16),
              decoration: BoxDecoration(
                color: AppColors.lightCard,
                borderRadius: BorderRadius.circular(12),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withOpacity(0.08),
                    blurRadius: 10,
                    offset: const Offset(0, 4),
                  ),
                ],
              ),
              child: ListView.separated(
                shrinkWrap: true,
                itemCount: _searchResults.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, index) {
                  final p = _searchResults[index];
                  return ListTile(
                    title: Text(p.name, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                    subtitle: Text(
                      "${CurrencyFormat.format(p.price)} • Qoldiq: ${p.stockQuantity} dona",
                      style: const TextStyle(fontSize: 12),
                    ),
                    trailing: const Icon(Icons.add_shopping_cart, color: AppColors.lightAccent, size: 20),
                    onTap: () => _addToCart(p),
                  );
                },
              ),
            ),

          // Cart or Empty state
          Expanded(
            child: _cart.isEmpty
                ? Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.shopping_cart_outlined, size: 48, color: AppColors.lightMuted.withOpacity(0.5)),
                        const SizedBox(height: 12),
                        const Text(
                          "Savat bo'sh",
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: AppColors.lightMuted),
                        ),
                        const SizedBox(height: 4),
                        const Text(
                          "Mahsulotni tanlang yoki shtrix-kodni skanerlang",
                          style: TextStyle(fontSize: 13, color: AppColors.lightMuted),
                        ),
                      ],
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.all(16),
                    itemCount: _cart.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (context, index) {
                      final item = _cart[index];
                      return Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: AppColors.lightCard,
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(
                            color: item.hasDeficit ? AppColors.warning : AppColors.lightLine,
                            width: item.hasDeficit ? 1.5 : 1,
                          ),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        item.product.name,
                                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                                      ),
                                      const SizedBox(height: 4),
                                      Text(
                                        "${CurrencyFormat.format(item.price)} × ${item.quantity} = ${CurrencyFormat.format(item.total)}",
                                        style: const TextStyle(color: AppColors.lightMuted, fontSize: 13),
                                      ),
                                    ],
                                  ),
                                ),
                                Row(
                                  children: [
                                    IconButton(
                                      icon: const Icon(Icons.remove_circle_outline, size: 20),
                                      onPressed: () => _updateQuantity(index, -1),
                                    ),
                                    Text(
                                      "${item.quantity}",
                                      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
                                    ),
                                    IconButton(
                                      icon: const Icon(Icons.add_circle_outline, size: 20),
                                      onPressed: () => _updateQuantity(index, 1),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                            if (item.hasDeficit) ...[
                              const SizedBox(height: 6),
                              Row(
                                children: [
                                  const Icon(Icons.warning_amber_rounded, size: 14, color: AppColors.warning),
                                  const SizedBox(width: 4),
                                  Text(
                                    "Omborda ${item.product.stockQuantity} dona mavjud (${item.deficitQuantity} dona yetishmovchilik bilan savdo davom etadi)",
                                    style: const TextStyle(fontSize: 11, color: AppColors.warning, fontWeight: FontWeight.w500),
                                  ),
                                ],
                              ),
                            ],
                          ],
                        ),
                      );
                    },
                  ),
          ),

          // Bottom Checkout Bar
          if (_cart.isNotEmpty)
            Container(
              padding: const EdgeInsets.all(16),
              decoration: const BoxDecoration(
                color: AppColors.lightCard,
                border: Border(top: BorderSide(color: AppColors.lightLine)),
              ),
              child: SafeArea(
                child: Row(
                  children: [
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Text(
                          "Jami:",
                          style: TextStyle(fontSize: 12, color: AppColors.lightMuted),
                        ),
                        Text(
                          CurrencyFormat.format(_totalAmount),
                          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.lightAccent),
                        ),
                      ],
                    ),
                    const Spacer(),
                    ElevatedButton(
                      style: ElevatedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
                        minimumSize: const Size(140, 48),
                      ),
                      onPressed: _checkout,
                      child: const Text("To'lov"),
                    ),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}
