import '../data/clients_repository.dart';
import 'dart:convert';
import 'receipts_screen.dart';
import 'package:flutter/services.dart';
import 'package:sqflite/sqflite.dart';
import '../../../core/database/app_database.dart';
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
  double? unitPrice;
  double discount;

  CartItem({
    required this.product,
    this.quantity = 1,
    this.unitPrice,
    this.discount = 0,
  });

  double get price => unitPrice ?? product.price;
  double get total => price * quantity - discount;
  bool get hasDeficit => quantity > product.stockQuantity;
  int get deficitQuantity => (quantity - product.stockQuantity) > 0
      ? (quantity - product.stockQuantity)
      : 0;
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
  late final ClientsRepository _clientsRepo;
  late final OfflineSaleService _saleService;

  final List<CartItem> _cart = [];
  List<LocalProduct> _searchResults = [];
  bool _isSearching = false;
  bool _checkingOut = false;
  String _cartId = '1';
  double _receiptDiscount = 0;
  String? _selectedCustomerId;
  String _paymentType = 'cash';
  String _paymentNote = '';
  String? _cashDraft;
  String? _cardDraft;
  List<String> _cartIds = ['1'];
  Future<void> _draftSaving = Future.value();

  @override
  void initState() {
    super.initState();
    final outbox = OutboxManager();
    _productsRepo =
        widget.productsRepository ?? ProductsRepository(outboxManager: outbox);
    _clientsRepo = ClientsRepository(outbox: outbox);
    _saleService =
        widget.saleService ?? OfflineSaleService(outboxManager: outbox);
    _restoreCart();
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
    final results =
        await _productsRepo.searchProducts(widget.storeId, query: query);
    if (mounted) {
      setState(() {
        _searchResults = results;
        _isSearching = false;
      });
    }
  }

  Future<void> _addToCart(LocalProduct product) async {
    final variants = await _productsRepo.variants(product);
    if (!mounted) return;
    if (variants.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text(
              'Mahsulot uchun ombor yoki razmer ma’lumoti yo‘q. Server bilan yangilang.')));
      return;
    }
    LocalProduct? selected = variants.first;
    if (variants.length > 1 || product.type == 'size') {
      selected = await showDialog<LocalProduct>(
          context: context,
          builder: (ctx) => SimpleDialog(
              title: const Text('Ombor va razmerni tanlang'),
              children: variants
                  .map((v) => SimpleDialogOption(
                      onPressed: () => Navigator.pop(ctx, v),
                      child: Text(
                          '${v.sizeName ?? 'Qadoq'} · Ombor ${v.stockId} · ${v.stockQuantity} dona')))
                  .toList()));
    }
    if (selected == null || !mounted) return;
    product = selected;
    setState(() {
      final index = _cart.indexWhere((c) =>
          c.product.id == product.id &&
          c.product.stockId == product.stockId &&
          c.product.sizeId == product.sizeId);
      if (index >= 0) {
        _cart[index].quantity += 1;
      } else {
        _cart.add(CartItem(product: product));
      }
      _searchResults = [];
      _searchController.clear();
    });
    _persistCart();
  }

  void _updateQuantity(int index, int delta) {
    setState(() {
      _cart[index].quantity += delta;
      if (_cart[index].quantity <= 0) {
        _cart.removeAt(index);
      } else if (_cart[index].discount >
          _cart[index].price * _cart[index].quantity) {
        _cart[index].discount = _cart[index].price * _cart[index].quantity;
      }
      final subtotal = _cart.fold<double>(0, (sum, item) => sum + item.total);
      if (_receiptDiscount > subtotal) _receiptDiscount = subtotal;
    });
    _persistCart();
  }

  Future<void> _restoreCart() async {
    final db = await AppDatabase.getInstance();
    final active = await db
        .query('sync_state', where: 'key = ?', whereArgs: ['active_cart']);
    if (active.isNotEmpty) _cartId = active.first['value'] as String;
    final drafts = await db.query('cart_drafts', columns: ['id']);
    _cartIds = {...drafts.map((row) => row['id'] as String), _cartId}.toList()
      ..sort((a, b) => int.parse(a).compareTo(int.parse(b)));
    await _loadCart();
  }

  Future<void> _loadCart() async {
    final db = await AppDatabase.getInstance();
    final rows =
        await db.query('cart_drafts', where: 'id = ?', whereArgs: [_cartId]);
    final cart = <CartItem>[];
    _receiptDiscount = 0;
    _selectedCustomerId = null;
    _paymentType = 'cash';
    _paymentNote = '';
    _cashDraft = null;
    _cardDraft = null;
    if (rows.isNotEmpty) {
      final saved = jsonDecode(rows.first['payload_json'] as String);
      final items = saved is List ? saved : saved['items'] as List;
      if (saved is Map) {
        _selectedCustomerId = saved['customer_id'] as String?;
        _paymentType = saved['payment_type'] as String? ?? 'cash';
        _paymentNote = saved['note'] as String? ?? '';
        _cashDraft = saved['cash'] as String?;
        _cardDraft = saved['card'] as String?;
      }
      _receiptDiscount = saved is Map
          ? (saved['receipt_discount'] as num?)?.toDouble() ?? 0
          : 0;
      for (final raw in items) {
        final product = Map<String, dynamic>.from(raw['product'] as Map);
        cart.add(CartItem(
            product: LocalProduct.fromMap(product,
                stockQuantity: (product['stock_quantity'] as num).toInt()),
            quantity: (raw['quantity'] as num).toInt(),
            unitPrice: (raw['unit_price'] as num?)?.toDouble(),
            discount: (raw['discount'] as num?)?.toDouble() ?? 0));
      }
    }
    if (mounted) {
      setState(() {
        _cart.clear();
        _cart.addAll(cart);
      });
    }
  }

  void _persistCart() {
    final id = _cartId;
    final payload = jsonEncode({
      'items': _cart
          .map((c) => {
                'product': c.product.toMap(),
                'quantity': c.quantity,
                'unit_price': c.price,
                'discount': c.discount
              })
          .toList(),
      'receipt_discount': _receiptDiscount,
      'customer_id': _selectedCustomerId,
      'payment_type': _paymentType,
      'note': _paymentNote,
      'cash': _cashDraft,
      'card': _cardDraft
    });
    _draftSaving = _draftSaving.then((_) async {
      final db = await AppDatabase.getInstance();
      await db.insert('cart_drafts', {'id': id, 'payload_json': payload},
          conflictAlgorithm: ConflictAlgorithm.replace);
    });
  }

  Future<void> _switchCart(String id) async {
    await _draftSaving;
    if (id == 'new') {
      id = (_cartIds.map(int.parse).reduce((a, b) => a > b ? a : b) + 1)
          .toString();
      _cartIds.add(id);
    }
    final db = await AppDatabase.getInstance();
    await db.insert('sync_state', {'key': 'active_cart', 'value': id},
        conflictAlgorithm: ConflictAlgorithm.replace);
    _cartId = id;
    await _loadCart();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  double get _totalAmount =>
      _cart.fold(0.0, (sum, item) => sum + item.total) - _receiptDiscount;

  Future<void> _editDiscount([CartItem? item]) async {
    final price =
        TextEditingController(text: item?.price.toStringAsFixed(2) ?? '0');
    final discount = TextEditingController(
        text: (item?.discount ?? _receiptDiscount).toStringAsFixed(2));
    final accepted = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
                title: Text(item == null
                    ? 'Butun chek chegirmasi'
                    : 'Narx va chegirma'),
                content: Column(mainAxisSize: MainAxisSize.min, children: [
                  if (item != null)
                    TextField(
                        controller: price,
                        keyboardType: TextInputType.number,
                        decoration:
                            const InputDecoration(labelText: 'Dona narxi')),
                  TextField(
                      controller: discount,
                      keyboardType: TextInputType.number,
                      decoration:
                          const InputDecoration(labelText: 'Chegirma summasi')),
                ]),
                actions: [
                  TextButton(
                      onPressed: () => Navigator.pop(ctx, false),
                      child: const Text('Bekor qilish')),
                  TextButton(
                      onPressed: () => Navigator.pop(ctx, true),
                      child: const Text('Qo‘llash'))
                ]));
    if (accepted != true || !mounted) return;
    final value = double.tryParse(discount.text);
    final newPrice = item == null ? 0.0 : double.tryParse(price.text);
    final maximum = item == null
        ? _cart.fold<double>(0, (sum, c) => sum + c.total)
        : (newPrice ?? 0) * item.quantity;
    if (value == null ||
        !value.isFinite ||
        value < 0 ||
        value > maximum ||
        newPrice == null ||
        !newPrice.isFinite ||
        newPrice < 0) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Narx va chegirma summasini tekshiring.')));
      return;
    }
    setState(() {
      if (item == null) {
        _receiptDiscount = value;
      } else {
        item.unitPrice = newPrice;
        item.discount = value;
        _receiptDiscount = 0;
      }
    });
    _persistCart();
  }

  void _openBarcodeScanner() {
    final messenger = ScaffoldMessenger.of(context);
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (context) => BarcodeScannerSheet(
        onBarcodeScanned: (barcode) async {
          final product =
              await _productsRepo.findByBarcode(widget.storeId, barcode);
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

  Future<LocalClient?> _newClient() async {
    final name = TextEditingController();
    final phone = TextEditingController();
    var saving = false;
    String? error;
    return showDialog<LocalClient>(
        context: context,
        builder: (ctx) => StatefulBuilder(
            builder: (ctx, modalState) => AlertDialog(
                    title: const Text('Yangi mijoz'),
                    content: Column(mainAxisSize: MainAxisSize.min, children: [
                      TextField(
                          controller: name,
                          decoration: const InputDecoration(labelText: 'Ism')),
                      TextField(
                          controller: phone,
                          keyboardType: TextInputType.phone,
                          decoration:
                              const InputDecoration(labelText: '+998 telefon')),
                      if (error != null)
                        Text(error!, style: const TextStyle(color: Colors.red)),
                    ]),
                    actions: [
                      TextButton(
                          onPressed: saving ? null : () => Navigator.pop(ctx),
                          child: const Text('Bekor qilish')),
                      TextButton(
                          onPressed: saving
                              ? null
                              : () async {
                                  modalState(() => saving = true);
                                  try {
                                    final client = await _clientsRepo.create(
                                        widget.storeId, name.text, phone.text);
                                    if (ctx.mounted) Navigator.pop(ctx, client);
                                  } catch (exception) {
                                    if (ctx.mounted) {
                                      modalState(() {
                                        error = exception.toString();
                                        saving = false;
                                      });
                                    }
                                  }
                                },
                          child: Text(saving ? 'Saqlanmoqda...' : 'Saqlash')),
                    ])));
  }

  Future<void> _checkout() async {
    if (_cart.isEmpty || _checkingOut) return;
    _checkingOut = true;
    try {
      await _draftSaving;
      final customers = await _clientsRepo.all(widget.storeId);
      if (!mounted) return;
      var paymentType = _paymentType;
      LocalClient? customer;
      for (final client in customers) {
        if (client.id == _selectedCustomerId) customer = client;
      }
      var saving = false;
      final cash = TextEditingController(
          text: _cashDraft ?? _totalAmount.toStringAsFixed(0));
      final card = TextEditingController(text: _cardDraft ?? '0');
      final note = TextEditingController(text: _paymentNote);
      await showModalBottomSheet(
          context: context,
          isScrollControlled: true,
          builder: (ctx) => StatefulBuilder(
              builder: (ctx, modalState) => Padding(
                    padding: EdgeInsets.fromLTRB(
                        24, 24, 24, MediaQuery.of(ctx).viewInsets.bottom + 24),
                    child: SingleChildScrollView(
                        child: Column(
                            mainAxisSize: MainAxisSize.min,
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                          Text('Jami: ${CurrencyFormat.format(_totalAmount)}',
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold, fontSize: 20)),
                          const SizedBox(height: 16),
                          DropdownButtonFormField<String>(
                              value: paymentType,
                              decoration: const InputDecoration(
                                  labelText: 'To‘lov turi'),
                              items: const {
                                'cash': 'Naqd',
                                'card': 'Karta',
                                'debt': 'Nasiya',
                                'transfer': 'O‘tkazma',
                                'mixed': 'Naqd va karta',
                                'partial': 'Qisman to‘lov',
                                'preorder': 'Oldindan buyurtma'
                              }
                                  .entries
                                  .map((e) => DropdownMenuItem(
                                      value: e.key, child: Text(e.value)))
                                  .toList(),
                              onChanged: saving
                                  ? null
                                  : (value) => modalState(() {
                                        paymentType = value!;
                                        _paymentType = value;
                                        _persistCart();
                                      })),
                          const SizedBox(height: 12),
                          Autocomplete<LocalClient>(
                            key: ValueKey(customer?.id),
                            initialValue:
                                TextEditingValue(text: customer?.name ?? ''),
                            displayStringForOption: (client) => client.name,
                            optionsBuilder: (value) => customers.where(
                                (client) =>
                                    client.name
                                        .toLowerCase()
                                        .contains(value.text.toLowerCase()) ||
                                    (client.phone ?? '').contains(value.text)),
                            onSelected: (client) {
                              modalState(() {
                                customer = client;
                                _selectedCustomerId = client.id;
                              });
                              _persistCart();
                            },
                            fieldViewBuilder: (context, controller, focus,
                                    submit) =>
                                TextField(
                                    controller: controller,
                                    focusNode: focus,
                                    onSubmitted: (_) => submit(),
                                    onChanged: (text) {
                                      if (text != customer?.name) {
                                        customer = null;
                                        _selectedCustomerId = null;
                                        _persistCart();
                                      }
                                    },
                                    decoration: const InputDecoration(
                                        labelText:
                                            'Mijozni ism yoki telefon bilan qidiring')),
                          ),
                          TextButton.icon(
                              icon: const Icon(Icons.person_add_alt_1),
                              label: const Text('Yangi mijoz'),
                              onPressed: saving
                                  ? null
                                  : () async {
                                      final client = await _newClient();
                                      if (client != null && ctx.mounted) {
                                        modalState(() {
                                          customers.add(client);
                                          customer = client;
                                          _selectedCustomerId = client.id;
                                        });
                                        _persistCart();
                                      }
                                    }),
                          TextField(
                              controller: note,
                              decoration: const InputDecoration(
                                  labelText: 'To‘lov izohi'),
                              onChanged: (value) {
                                _paymentNote = value;
                                _persistCart();
                              }),
                          if (paymentType == 'cash' ||
                              paymentType == 'mixed' ||
                              paymentType == 'partial') ...[
                            const SizedBox(height: 12),
                            TextField(
                                controller: cash,
                                onChanged: (value) {
                                  modalState(() => _cashDraft = value);
                                  _persistCart();
                                },
                                keyboardType: TextInputType.number,
                                decoration: const InputDecoration(
                                    labelText: 'Naqd summa')),
                            if (paymentType == 'cash')
                              Text(
                                  'Qaytim: ${CurrencyFormat.format(((double.tryParse(cash.text.replaceAll(' ', '')) ?? 0) - _totalAmount).clamp(0, double.infinity))}'),
                            const SizedBox(height: 12),
                            if (paymentType != 'cash')
                              TextField(
                                  controller: card,
                                  onChanged: (value) {
                                    _cardDraft = value;
                                    _persistCart();
                                  },
                                  keyboardType: TextInputType.number,
                                  decoration: const InputDecoration(
                                      labelText: 'Karta summasi')),
                          ],
                          const SizedBox(height: 16),
                          if (paymentType == 'card' ||
                              paymentType == 'transfer')
                            const Text(
                                'Bu to‘lov qaydi. Bank tasdig‘ini alohida tekshiring.'),
                          ElevatedButton(
                              onPressed: saving
                                  ? null
                                  : () async {
                                      modalState(() => saving = true);
                                      try {
                                        final total = _totalAmount;
                                        final cashValue = double.tryParse(cash
                                                .text
                                                .replaceAll(' ', '')) ??
                                            0;
                                        final cardValue = double.tryParse(card
                                                .text
                                                .replaceAll(' ', '')) ??
                                            0;
                                        final paid = ['debt', 'preorder']
                                                .contains(paymentType)
                                            ? 0.0
                                            : ['mixed', 'partial']
                                                    .contains(paymentType)
                                                ? cashValue + cardValue
                                                : total;
                                        if (!['cash', 'card']
                                                .contains(paymentType) &&
                                            customer == null) {
                                          throw StateError('Mijozni tanlang.');
                                        }
                                        final result = await _saleService
                                            .processOfflineSale(
                                          storeId: widget.storeId,
                                          customerId: customer?.id,
                                          receivedCash: paymentType == 'cash'
                                              ? cashValue
                                              : null,
                                          totalAmount: total,
                                          paidAmount: paid,
                                          remainingAmount: total - paid,
                                          paymentType: paymentType,
                                          cashAmount: paymentType == 'cash'
                                              ? total
                                              : ['mixed', 'partial']
                                                      .contains(paymentType)
                                                  ? cashValue
                                                  : 0,
                                          cardAmount: paymentType == 'card'
                                              ? total
                                              : ['mixed', 'partial']
                                                      .contains(paymentType)
                                                  ? cardValue
                                                  : 0,
                                          draftId: _cartId,
                                          receiptDiscount: _receiptDiscount,
                                          paymentNote: note.text.trim(),
                                          items: _cart
                                              .map((c) => <String, dynamic>{
                                                    'product_id': c.product.id,
                                                    if (c.product.revision > 0)
                                                      'product_revision':
                                                          c.product.revision,
                                                    'stock_id':
                                                        c.product.stockId,
                                                    if (c.product.sizeId !=
                                                        null)
                                                      'product_size_id':
                                                          c.product.sizeId,
                                                    'name': c.product.name,
                                                    'quantity': c.quantity,
                                                    'unit_price': c.price
                                                        .toStringAsFixed(2),
                                                    'discount': c.discount
                                                        .toStringAsFixed(2),
                                                  })
                                              .toList(),
                                        );
                                        if (!mounted || !ctx.mounted) return;
                                        setState(() {
                                          _cart.clear();
                                          _receiptDiscount = 0;
                                          _selectedCustomerId = null;
                                          _paymentType = 'cash';
                                          _paymentNote = '';
                                          _cashDraft = null;
                                          _cardDraft = null;
                                        });
                                        Navigator.pop(ctx);
                                        _showReceiptDialog(result, paymentType);
                                      } catch (error) {
                                        if (ctx.mounted) {
                                          modalState(() => saving = false);
                                          ScaffoldMessenger.of(ctx)
                                              .showSnackBar(SnackBar(
                                                  content:
                                                      Text(error.toString())));
                                        }
                                      }
                                    },
                              child: Text(saving
                                  ? 'Saqlanmoqda...'
                                  : 'Savdoni yakunlash')),
                        ])),
                  )));
      cash.dispose();
      card.dispose();
      note.dispose();
    } finally {
      _checkingOut = false;
    }
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
                  "${result.deficits.length} ta mahsulot uchun qoldiq yetishmasligi qayd etildi.",
                  style:
                      const TextStyle(fontSize: 12, color: AppColors.warning),
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
          IconButton(
              icon: const Icon(Icons.receipt_long),
              tooltip: 'Saqlangan cheklar',
              onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute(
                      builder: (_) =>
                          ReceiptsScreen(storeId: widget.storeId)))),
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text("Yopish"),
          ),
          ElevatedButton.icon(
            icon: const Icon(Icons.print),
            label: const Text("Chek nusxasini olish"),
            onPressed: () {
              Clipboard.setData(ClipboardData(
                  text:
                      'Chek: ${result.localSaleId}\nJami: ${CurrencyFormat.format(result.totalAmount)}\nHolati: server tasdig‘i kutilmoqda.'));
              ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Chek matni nusxalandi.')));
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
          PopupMenuButton<String>(
              tooltip: 'Savat $_cartId',
              onSelected: _switchCart,
              itemBuilder: (_) => [
                    ..._cartIds.map((id) =>
                        PopupMenuItem(value: id, child: Text('Savat $id'))),
                    const PopupMenuItem(
                        value: 'new', child: Text('Yangi savat'))
                  ]),
          IconButton(
              icon: const Icon(Icons.discount_outlined),
              tooltip: 'Chek chegirmasi',
              onPressed: _cart.isEmpty ? null : () => _editDiscount()),
          IconButton(
            icon:
                const Icon(Icons.qr_code_scanner, color: AppColors.lightAccent),
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
                                child:
                                    CircularProgressIndicator(strokeWidth: 2),
                              ),
                            )
                          : const Icon(Icons.search,
                              color: AppColors.lightMuted),
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
                    title: Text(p.name,
                        style: const TextStyle(
                            fontWeight: FontWeight.w600, fontSize: 13)),
                    subtitle: Text(
                      "${CurrencyFormat.format(p.price)} • Qoldiq: ${p.stockQuantity} dona",
                      style: const TextStyle(fontSize: 12),
                    ),
                    trailing: const Icon(Icons.add_shopping_cart,
                        color: AppColors.lightAccent, size: 20),
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
                        Icon(Icons.shopping_cart_outlined,
                            size: 48,
                            color: AppColors.lightMuted.withOpacity(0.5)),
                        const SizedBox(height: 12),
                        const Text(
                          "Savat bo'sh",
                          style: TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.w600,
                              color: AppColors.lightMuted),
                        ),
                        const SizedBox(height: 4),
                        const Text(
                          "Mahsulotni tanlang yoki shtrix-kodni skanerlang",
                          style: TextStyle(
                              fontSize: 13, color: AppColors.lightMuted),
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
                            color: item.hasDeficit
                                ? AppColors.warning
                                : AppColors.lightLine,
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
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        item.product.name,
                                        style: const TextStyle(
                                            fontWeight: FontWeight.w600,
                                            fontSize: 14),
                                      ),
                                      TextButton(
                                          onPressed: () => _editDiscount(item),
                                          child: const Text('Narx / chegirma')),
                                      const SizedBox(height: 4),
                                      Text(
                                        "${CurrencyFormat.format(item.price)} × ${item.quantity} = ${CurrencyFormat.format(item.total)}",
                                        style: const TextStyle(
                                            color: AppColors.lightMuted,
                                            fontSize: 13),
                                      ),
                                    ],
                                  ),
                                ),
                                Row(
                                  children: [
                                    IconButton(
                                      icon: const Icon(
                                          Icons.remove_circle_outline,
                                          size: 20),
                                      onPressed: () =>
                                          _updateQuantity(index, -1),
                                    ),
                                    Text(
                                      "${item.quantity}",
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w700,
                                          fontSize: 15),
                                    ),
                                    IconButton(
                                      icon: const Icon(Icons.add_circle_outline,
                                          size: 20),
                                      onPressed: () =>
                                          _updateQuantity(index, 1),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                            if (item.hasDeficit) ...[
                              const SizedBox(height: 6),
                              Row(
                                children: [
                                  const Icon(Icons.warning_amber_rounded,
                                      size: 14, color: AppColors.warning),
                                  const SizedBox(width: 4),
                                  Text(
                                    "Omborda ${item.product.stockQuantity} dona mavjud (${item.deficitQuantity} dona yetishmovchilik bilan savdo davom etadi)",
                                    style: const TextStyle(
                                        fontSize: 11,
                                        color: AppColors.warning,
                                        fontWeight: FontWeight.w500),
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
                          style: TextStyle(
                              fontSize: 12, color: AppColors.lightMuted),
                        ),
                        Text(
                          CurrencyFormat.format(_totalAmount),
                          style: const TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.w800,
                              color: AppColors.lightAccent),
                        ),
                      ],
                    ),
                    const Spacer(),
                    ElevatedButton(
                      style: ElevatedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 24, vertical: 12),
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
