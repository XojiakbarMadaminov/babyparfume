import 'dart:async';
import '../../printing/data/printing_repository.dart';
import '../../printing/presentation/print_dialog.dart';
import 'pos_widgets.dart';
import 'pos_variants_sheet.dart';
import 'pos_discount_sheet.dart';
import 'pos_checkout_sheet.dart';
import '../data/clients_repository.dart';
import 'dart:convert';
import 'receipts_screen.dart';
import '../../products/presentation/product_widgets.dart';
import 'package:flutter/services.dart';
import 'package:sqflite/sqflite.dart';
import '../../../core/database/app_database.dart';
import '../../../core/utils/phone_format.dart';
import 'package:flutter/material.dart';
import '../../../core/sync/offline_sale_service.dart';
import '../../../core/sync/outbox_manager.dart';
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
  final String storeName;
  final Brightness brightness;
  final int revision;
  final bool canSeeCostPrice;
  final VoidCallback? onViewStatistics;
  final ClientsRepository? clientsRepository;
  final Future<Database> Function()? getDb;
  final ProductsRepository? productsRepository;
  final DebtorsRepository? debtorsRepository;
  final OfflineSaleService? saleService;
  final bool canPrintReceipts;
  final PrintingRepository? printingRepository;

  const PosScreen({
    super.key,
    required this.storeId,
    this.storeName = '',
    this.brightness = Brightness.dark,
    this.revision = 0,
    this.canSeeCostPrice = false,
    this.onViewStatistics,
    this.clientsRepository,
    this.getDb,
    this.productsRepository,
    this.debtorsRepository,
    this.saleService,
    this.canPrintReceipts = false,
    this.printingRepository,
  });

  @override
  State<PosScreen> createState() => _PosScreenState();
}

class _PosScreenState extends State<PosScreen> {
  PosPalette get _colors => PosPalette(widget.brightness == Brightness.dark);
  final TextEditingController _searchController = TextEditingController();
  late final ProductsRepository _productsRepo;
  late final ClientsRepository _clientsRepo;
  late final OfflineSaleService _saleService;

  final List<CartItem> _cart = [];
  final _cartChanges = ValueNotifier(0);
  final Map<String, int> _cartQuantities = {};
  Map<String, String> _categories = {};
  String? _category;
  String? _loadError;
  String? _addedProduct;
  String? _customerName;
  Timer? _searchTimer;
  Timer? _addedTimer;
  int _searchRequest = 0;
  int _page = 0;
  static const _pageSize = 10;
  bool _adding = false;
  OfflineSaleResult? _receipt;
  String _receiptText = '';
  int _receiptQuantity = 0;
  Future<Database> _getDb() => (widget.getDb ?? AppDatabase.getInstance)();
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
  late final PrintingRepository _printing;

  @override
  void initState() {
    super.initState();
    _printing =
        widget.printingRepository ?? PrintingRepository(getDb: widget.getDb);
    if (widget.canPrintReceipts) {
      _refreshPrintingOptions();
    }
    final outbox = OutboxManager();
    _productsRepo =
        widget.productsRepository ?? ProductsRepository(outboxManager: outbox);
    _clientsRepo =
        widget.clientsRepository ?? ClientsRepository(outbox: outbox);
    _saleService =
        widget.saleService ?? OfflineSaleService(outboxManager: outbox);
    _restoreCart();
    _loadProducts();
  }

  @override
  void didUpdateWidget(covariant PosScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.revision != widget.revision) _loadProducts();
    if (widget.canPrintReceipts &&
        (oldWidget.revision != widget.revision ||
            !oldWidget.canPrintReceipts)) {
      _refreshPrintingOptions();
    }
  }

  Future<void> _refreshPrintingOptions() async {
    try {
      await _printing.options(widget.storeId);
    } catch (_) {}
  }

  Future<void> _autoPrint(String receiptId) async {
    try {
      final job = await _printing.automatic(widget.storeId, receiptId);
      if (mounted && job != null) {
        _notice(PrintingRepository.statusLabel(job['status'].toString()));
      }
    } catch (e) {
      if (mounted) {
        _notice(
            'Savdo saqlandi. Avtomatik chek: ${PrintingRepository.error(e)}');
      }
    }
  }

  void _onSearch(String query) {
    _searchTimer?.cancel();
    _searchRequest++;
    _searchTimer = Timer(const Duration(milliseconds: 350), _loadProducts);
  }

  Future<void> _loadProducts() async {
    final request = ++_searchRequest;
    setState(() {
      _isSearching = true;
      _loadError = null;
    });
    try {
      final products = await _productsRepo.searchProducts(widget.storeId,
          query: _searchController.text);
      if (!mounted || request != _searchRequest) return;
      setState(() {
        _searchResults = products;
        if (_searchController.text.trim().isEmpty) {
          _categories = {
            for (final product in products)
              if (product.categoryId != null && product.categoryName != null)
                product.categoryId!: product.categoryName!
          };
        }
        _page = 0;
        _isSearching = false;
      });
    } catch (_) {
      if (mounted && request == _searchRequest) {
        setState(() {
          _isSearching = false;
          _loadError = 'Mahsulotlarni yuklab bo‘lmadi. Qayta urinib ko‘ring.';
        });
      }
    }
  }

  Future<void> _addToCart(LocalProduct product) async {
    if (_adding) return;
    _adding = true;
    try {
      final variants = await _productsRepo.variants(product);
      if (!mounted) return;
      if (variants.isEmpty) {
        _notice(
            'Mahsulot uchun ombor yoki razmer ma’lumoti yo‘q. Server bilan yangilang.');
        return;
      }
      if (variants.length > 1 || product.type == 'size') {
        final quantities = [
          for (final variant in variants)
            _cart
                .where((item) =>
                    item.product.id == product.id &&
                    item.product.stockId == variant.stockId &&
                    item.product.sizeId == variant.sizeId)
                .fold<int>(0, (sum, item) => sum + item.quantity)
        ];
        final chosen = await _sheet<List<int>>(
            PosVariantsSheet(
                name: product.name, variants: variants, quantities: quantities),
            height: .8);
        if (chosen == null || !mounted) return;
        setState(() {
          for (var i = 0; i < variants.length; i++) {
            final variant = variants[i];
            final index = _cart.indexWhere((item) =>
                item.product.id == product.id &&
                item.product.stockId == variant.stockId &&
                item.product.sizeId == variant.sizeId);
            if (chosen[i] == 0) {
              if (index >= 0) _cart.removeAt(index);
            } else if (index >= 0) {
              _cart[index].quantity = chosen[i];
              _cart[index].discount = _cart[index]
                  .discount
                  .clamp(0, _cart[index].price * chosen[i]);
            } else {
              _cart.add(CartItem(product: variant, quantity: chosen[i]));
            }
          }
          final subtotal =
              _cart.fold<double>(0, (sum, item) => sum + item.total);
          _receiptDiscount = _receiptDiscount.clamp(0, subtotal);
        });
      } else {
        setState(() {
          final variant = variants.first;
          final index = _cart.indexWhere((item) =>
              item.product.id == product.id &&
              item.product.stockId == variant.stockId &&
              item.product.sizeId == variant.sizeId);
          if (index >= 0) {
            _cart[index].quantity++;
          } else {
            _cart.add(CartItem(product: variant));
          }
        });
      }
      _persistCart();
      setState(() => _addedProduct = product.id);
      _addedTimer?.cancel();
      _addedTimer = Timer(const Duration(seconds: 1), () {
        if (mounted) setState(() => _addedProduct = null);
      });
    } catch (_) {
      if (mounted) {
        _notice('Mahsulotni savatga qo‘shib bo‘lmadi. Qayta urinib ko‘ring.');
      }
    } finally {
      _adding = false;
    }
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
    final db = await _getDb();
    final active = await db
        .query('sync_state', where: 'key = ?', whereArgs: ['active_cart']);
    if (active.isNotEmpty) _cartId = active.first['value'] as String;
    final drafts = await db.query('cart_drafts');
    for (final row in drafts) {
      final saved = jsonDecode(row['payload_json'] as String);
      final items = saved is List ? saved : saved['items'] as List;
      _cartQuantities[row['id'] as String] = items.fold<int>(
          0, (sum, item) => sum + (item['quantity'] as num).toInt());
    }
    _cartIds = {...drafts.map((row) => row['id'] as String), _cartId}.toList()
      ..sort((a, b) => int.parse(a).compareTo(int.parse(b)));
    await _loadCart();
  }

  Future<void> _loadCart() async {
    final db = await _getDb();
    final rows =
        await db.query('cart_drafts', where: 'id = ?', whereArgs: [_cartId]);
    final cart = <CartItem>[];
    _receiptDiscount = 0;
    _selectedCustomerId = null;
    _customerName = null;
    _paymentType = 'cash';
    _paymentNote = '';
    _cashDraft = null;
    _cardDraft = null;
    if (rows.isNotEmpty) {
      final saved = jsonDecode(rows.first['payload_json'] as String);
      final items = saved is List ? saved : saved['items'] as List;
      if (saved is Map) {
        _selectedCustomerId = saved['customer_id'] as String?;
        _customerName = saved['customer_name'] as String?;
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
        _receipt = null;
      });
    }
  }

  void _persistCart() {
    if (mounted) _cartChanges.value++;
    _cartQuantities[_cartId] =
        _cart.fold(0, (sum, item) => sum + item.quantity);
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
      'customer_name': _customerName,
      'payment_type': _paymentType,
      'note': _paymentNote,
      'cash': _cashDraft,
      'card': _cardDraft
    });
    _draftSaving = _draftSaving.then((_) async {
      final db = await _getDb();
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
    final db = await _getDb();
    await db.insert('sync_state', {'key': 'active_cart', 'value': id},
        conflictAlgorithm: ConflictAlgorithm.replace);
    _cartId = id;
    await _loadCart();
  }

  @override
  void dispose() {
    _searchTimer?.cancel();
    _addedTimer?.cancel();
    _cartChanges.dispose();
    _searchController.dispose();
    super.dispose();
  }

  double get _totalAmount =>
      _cart.fold(0.0, (sum, item) => sum + item.total) - _receiptDiscount;

  Future<T?> _sheet<T>(Widget child,
          {double height = .94, bool dismissible = true}) =>
      showModalBottomSheet<T>(
          context: context,
          isScrollControlled: true,
          useSafeArea: true,
          isDismissible: dismissible,
          enableDrag: dismissible,
          backgroundColor: _colors.background,
          clipBehavior: Clip.antiAlias,
          shape: const RoundedRectangleBorder(
              borderRadius: BorderRadius.vertical(top: Radius.circular(22))),
          builder: (_) => Theme(
              data: PosStyle.theme(context, brightness: widget.brightness),
              child: Material(
                  color: _colors.background,
                  child: FractionallySizedBox(
                      heightFactor: height, child: child))));

  Future<void> _editDiscount([CartItem? item]) async {
    final amount = await _sheet<double>(
        PosDiscountSheet(
            title: item?.product.name ?? 'Butun chekka chegirma',
            total: item == null
                ? _cart.fold<double>(0, (sum, item) => sum + item.total)
                : item.price * item.quantity,
            discount: item?.discount ?? _receiptDiscount),
        height: .94);
    if (amount == null || !mounted) return;
    setState(() {
      if (item == null) {
        _receiptDiscount = amount;
      } else {
        item.discount = amount;
        final subtotal = _cart.fold<double>(0, (sum, item) => sum + item.total);
        _receiptDiscount = _receiptDiscount.clamp(0, subtotal);
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
              ),
            );
          } else {
            messenger.showSnackBar(
              SnackBar(
                content: Text("Barkod bo'yicha mahsulot topilmadi: $barcode"),
              ),
            );
          }
        },
      ),
    );
  }

  Future<LocalClient?> _newClient() async {
    var name = '';
    var phone = PhoneFormat.defaultPrefix;
    var saving = false;
    String? error;
    final result = await showDialog<LocalClient>(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => Theme(
            data: PosStyle.theme(context, brightness: widget.brightness),
            child: StatefulBuilder(
                builder: (ctx, update) => PopScope(
                    canPop: !saving,
                    child: AlertDialog(
                        title: const Text('Yangi mijoz'),
                        content: SingleChildScrollView(
                            child: Column(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                              TextFormField(
                                  initialValue: name,
                                  onChanged: (value) => name = value,
                                  enabled: !saving,
                                  decoration:
                                      const InputDecoration(labelText: 'Ism')),
                              const SizedBox(height: 12),
                              TextFormField(
                                  initialValue: phone,
                                  onChanged: (value) => phone = value,
                                  enabled: !saving,
                                  keyboardType: TextInputType.phone,
                                  decoration: const InputDecoration(
                                      labelText: 'Telefon raqami')),
                              if (error != null)
                                Padding(
                                    padding: const EdgeInsets.only(top: 12),
                                    child: Text(error!,
                                        style:
                                            TextStyle(color: _colors.danger))),
                            ])),
                        actions: [
                          TextButton(
                              onPressed:
                                  saving ? null : () => Navigator.pop(ctx),
                              child: const Text('Bekor qilish')),
                          TextButton(
                              onPressed: saving
                                  ? null
                                  : () async {
                                      update(() => saving = true);
                                      try {
                                        final client =
                                            await _clientsRepo.create(
                                                widget.storeId, name, phone);
                                        if (ctx.mounted) {
                                          Navigator.pop(ctx, client);
                                        }
                                      } catch (exception) {
                                        if (ctx.mounted) {
                                          update(() {
                                            error = exception.toString();
                                            saving = false;
                                          });
                                        }
                                      }
                                    },
                              child:
                                  Text(saving ? 'Saqlanmoqda...' : 'Saqlash')),
                        ])))));
    return result;
  }

  Future<void> _checkout() async {
    if (_cart.isEmpty || _checkingOut) return;
    _checkingOut = true;
    try {
      await _draftSaving;
      final customers = await _clientsRepo.all(widget.storeId);
      if (!mounted) return;
      LocalClient? customer;
      for (final client in customers) {
        if (client.id == _selectedCustomerId) customer = client;
      }
      final draft = PosPaymentDraft(
          type: _paymentType,
          cash: _cashDraft ?? _totalAmount.toStringAsFixed(2),
          card: _cardDraft ?? '0',
          note: _paymentNote,
          customer: customer);
      final result = await _sheet<OfflineSaleResult>(
          PosCheckoutSheet(
              total: _totalAmount,
              draft: draft,
              customers: customers,
              onNewCustomer: _newClient,
              onChanged: (payment) {
                _selectedCustomerId = payment.customer?.id;
                _customerName = payment.customer?.name;
                _paymentType = payment.type;
                _cashDraft = payment.cash;
                _cardDraft = payment.card;
                _paymentNote = payment.note;
                _persistCart();
              },
              onSubmit: (payment) async {
                await _draftSaving;
                final total = _totalAmount;
                final split = ['mixed', 'partial'].contains(payment.type);
                final paid = ['debt', 'preorder'].contains(payment.type)
                    ? 0.0
                    : split
                        ? payment.cashAmount + payment.cardAmount
                        : total;
                return _saleService.processOfflineSale(
                    storeId: widget.storeId,
                    customerId: payment.customer?.id,
                    receivedCash:
                        payment.type == 'cash' ? payment.cashAmount : null,
                    totalAmount: total,
                    paidAmount: paid,
                    remainingAmount: total - paid,
                    paymentType: payment.type,
                    cashAmount: payment.type == 'cash'
                        ? total
                        : split
                            ? payment.cashAmount
                            : 0,
                    cardAmount: payment.type == 'card'
                        ? total
                        : split
                            ? payment.cardAmount
                            : 0,
                    draftId: _cartId,
                    receiptDiscount: _receiptDiscount,
                    paymentNote: payment.note.trim(),
                    items: _cart
                        .map((item) => <String, dynamic>{
                              'product_id': item.product.id,
                              if (item.product.revision > 0)
                                'product_revision': item.product.revision,
                              'stock_id': item.product.stockId,
                              if (item.product.sizeId != null)
                                'product_size_id': item.product.sizeId,
                              'name': item.product.name,
                              if (item.product.sizeName != null)
                                'size_name': item.product.sizeName,
                              'quantity': item.quantity,
                              'unit_price': item.price.toStringAsFixed(2),
                              'discount': item.discount.toStringAsFixed(2),
                            })
                        .toList());
              }),
          dismissible: false);
      if (result == null || !mounted) return;
      final receiptText = [
        'BABY PARFUME · CHEK NUSXASI',
        widget.storeName,
        'Lokal raqam: ${result.localSaleId}',
        for (final item in _cart) ...[
          '${item.product.name}\n${item.quantity} dona × ${PosStyle.money(item.price)} = ${PosStyle.money(item.price * item.quantity)} so‘m',
          if (item.discount > 0)
            'Chegirma: −${PosStyle.money(item.discount)} so‘m',
        ],
        'Chek chegirmasi: ${PosStyle.money(_receiptDiscount)} so‘m',
        'Jami: ${PosStyle.money(result.totalAmount)} so‘m',
        if (draft.customer != null) 'Mijoz: ${draft.customer!.name}',
        'To‘lov: ${const {
          'cash': 'Naqd',
          'card': 'Karta',
          'mixed': 'Aralash',
          'debt': 'Qarzga',
          'partial': 'Qisman',
          'transfer': 'O‘tkazma',
          'preorder': 'Oldindan buyurtma'
        }[draft.type]}',
        if (draft.type == 'cash') ...[
          'Olindi: ${PosStyle.money(draft.cashAmount)} so‘m',
          'Qaytim: ${PosStyle.money((draft.cashAmount - result.totalAmount).clamp(0, double.infinity))} so‘m',
        ],
        if (['mixed', 'partial'].contains(draft.type)) ...[
          'Naqd: ${PosStyle.money(draft.cashAmount)} so‘m',
          'Karta: ${PosStyle.money(draft.cardAmount)} so‘m',
        ],
        if (draft.note.trim().isNotEmpty) 'Izoh: ${draft.note.trim()}',
        'Qurilmaga saqlangan chek nusxasi.',
      ].where((line) => line.isNotEmpty).join('\n');

      setState(() {
        _receiptText = receiptText;
        _receiptQuantity =
            _cart.fold<int>(0, (sum, item) => sum + item.quantity);
        _receipt = result;
        _cart.clear();
        _receiptDiscount = 0;
        _selectedCustomerId = null;
        _customerName = null;
        _paymentType = 'cash';
        _paymentNote = '';
        _cashDraft = null;
        _cardDraft = null;
      });
      _persistCart();
      _loadProducts();
      if (widget.canPrintReceipts) {
        unawaited(_autoPrint(result.localSaleId));
      }
    } catch (error) {
      if (mounted) _notice(error.toString());
    } finally {
      _checkingOut = false;
    }
  }

  void _notice(String message) => ScaffoldMessenger.of(context)
      .showSnackBar(SnackBar(content: Text(message)));

  Future<bool> _confirm(String message) async =>
      await showDialog<bool>(
          context: context,
          builder: (ctx) => Theme(
              data: PosStyle.theme(context, brightness: widget.brightness),
              child: AlertDialog(title: Text(message), actions: [
                TextButton(
                    onPressed: () => Navigator.pop(ctx, false),
                    child: const Text('Bekor qilish')),
                TextButton(
                    onPressed: () => Navigator.pop(ctx, true),
                    child: const Text('Tasdiqlash')),
              ]))) ??
      false;

  Future<void> _removeItem(CartItem item) async {
    if (!await _confirm('${item.product.name} savatdan o‘chirilsinmi?') ||
        !mounted) return;
    setState(() {
      _cart.remove(item);
      final subtotal = _cart.fold<double>(0, (sum, item) => sum + item.total);
      _receiptDiscount = _receiptDiscount.clamp(0, subtotal);
    });
    _persistCart();
  }

  Future<void> _clearCart() async {
    if (!await _confirm('Savat tozalansinmi?') || !mounted) return;
    setState(() {
      _cart.clear();
      _receiptDiscount = 0;
    });
    _persistCart();
  }

  Future<void> _selectCustomer() async {
    final customers = await _clientsRepo.all(widget.storeId);
    if (!mounted) return;
    final selected = await _sheet<LocalClient>(
        PosCustomerPicker(
            customers: customers,
            selectedId: _selectedCustomerId,
            onNewCustomer: _newClient),
        height: .88);
    if (selected == null || !mounted) return;
    setState(() {
      _selectedCustomerId = selected.id;
      _customerName = selected.name;
    });
    _persistCart();
  }

  Future<void> _editNote() async {
    final controller = TextEditingController(text: _paymentNote);
    final accepted = await showDialog<bool>(
        context: context,
        builder: (ctx) => Theme(
            data: PosStyle.theme(context, brightness: widget.brightness),
            child: AlertDialog(
                title: const Text('Izoh qo‘shish'),
                content: TextField(
                    controller: controller,
                    maxLength: 500,
                    maxLines: 4,
                    decoration:
                        const InputDecoration(hintText: 'Savdoga izoh yozing')),
                actions: [
                  TextButton(
                      onPressed: () => Navigator.pop(ctx),
                      child: const Text('Bekor qilish')),
                  TextButton(
                      onPressed: () => Navigator.pop(ctx, true),
                      child: const Text('Saqlash')),
                ])));
    if (accepted == true && mounted) {
      setState(() => _paymentNote = controller.text.trim());
      _persistCart();
    }
    controller.dispose();
  }

  void _openReceipts() => Navigator.push(
      context,
      MaterialPageRoute(
          builder: (_) => ReceiptsScreen(
              storeId: widget.storeId, canPrint: widget.canPrintReceipts)));

  Widget _breakdown(String label, String value, {bool bold = false}) =>
      Row(children: [
        Expanded(
            child: Text(label,
                style: TextStyle(
                    fontSize: bold ? 16 : 12,
                    color: bold
                        ? Theme.of(context).colorScheme.onSurface
                        : _colors.muted))),
        const SizedBox(width: 8),
        Flexible(
            child: Text(value,
                textAlign: TextAlign.right,
                style: TextStyle(
                    fontSize: bold ? 18 : 13,
                    fontWeight: bold ? FontWeight.w800 : FontWeight.w600))),
      ]);

  Widget _stat(String value, String label, {Color? color}) => Expanded(
          child: Column(children: [
        FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(value,
                style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: color ?? Theme.of(context).colorScheme.onSurface))),
        const SizedBox(height: 4),
        Text(label,
            style: TextStyle(
                fontSize: 10, color: _colors.muted, letterSpacing: .5)),
      ]));

  Widget _actionCard(
          IconData icon, String title, String subtitle, VoidCallback onTap) =>
      InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: PosPanel(
              child: Row(children: [
            Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                    color: _colors.blue.withOpacity(.12),
                    borderRadius: BorderRadius.circular(12)),
                child: Icon(icon, color: _colors.blue)),
            const SizedBox(width: 12),
            Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  Text(title,
                      style: const TextStyle(
                          fontWeight: FontWeight.w600, fontSize: 14)),
                  const SizedBox(height: 4),
                  Text(subtitle,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(color: _colors.muted, fontSize: 12)),
                ])),
            Icon(Icons.chevron_right, color: _colors.muted),
          ])));

  Widget _cartCard(CartItem item, int index) => PosPanel(
      borderColor: item.discount > 0 ? _colors.danger.withOpacity(.35) : null,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(
              child: Text(item.product.name,
                  style: const TextStyle(
                      fontWeight: FontWeight.w600, fontSize: 14))),
          const SizedBox(width: 8),
          Text(PosStyle.money(item.total),
              style:
                  const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
        ]),
        const SizedBox(height: 6),
        Text(
            '${item.quantity} dona × ${PosStyle.money(item.price)} · qoldiq: ${item.product.stockQuantity} dona',
            style: TextStyle(fontSize: 12, color: _colors.muted)),
        if (item.product.sizeName != null)
          Text(
              '${item.product.sizeName} · ${item.product.stockName ?? 'Ombor ${item.product.stockId}'}',
              style: TextStyle(fontSize: 12, color: _colors.muted)),
        if (widget.canSeeCostPrice && item.product.initialPrice != null)
          Text(
              'Kelgan narxi: ${PosStyle.money(item.product.initialPrice!)} so‘m / dona',
              style: TextStyle(fontSize: 12, color: _colors.muted)),
        if (item.discount > 0)
          Text('Chegirma: −${PosStyle.money(item.discount)} so‘m',
              style: TextStyle(fontSize: 12, color: _colors.danger)),
        if (item.hasDeficit)
          Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                  '${item.deficitQuantity} dona yetishmovchilik qayd etiladi.',
                  style: TextStyle(color: _colors.accent, fontSize: 12))),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(
              child: Container(
                  decoration: BoxDecoration(
                      color: _colors.card,
                      border: Border.all(color: _colors.line),
                      borderRadius: BorderRadius.circular(12)),
                  child: Row(children: [
                    IconButton(
                        tooltip: '${item.product.name} — miqdorni kamaytirish',
                        visualDensity: VisualDensity.compact,
                        onPressed: item.quantity <= 1
                            ? null
                            : () => _updateQuantity(index, -1),
                        icon: const Icon(Icons.remove, size: 18)),
                    Expanded(
                        child: TextFormField(
                            key: ValueKey(
                                '${item.product.id}-${item.product.stockId}-${item.product.sizeId}-${item.quantity}'),
                            initialValue: '${item.quantity}',
                            keyboardType: TextInputType.number,
                            textAlign: TextAlign.center,
                            style: const TextStyle(
                                fontSize: 14, fontWeight: FontWeight.w700),
                            decoration: const InputDecoration(
                                border: InputBorder.none,
                                enabledBorder: InputBorder.none,
                                focusedBorder: InputBorder.none,
                                filled: false,
                                isDense: true,
                                contentPadding:
                                    EdgeInsets.symmetric(vertical: 8)),
                            onFieldSubmitted: (value) {
                              final quantity =
                                  int.tryParse(value) ?? item.quantity;
                              if (quantity > 0) {
                                _updateQuantity(
                                    index, quantity - item.quantity);
                              }
                            })),
                    IconButton(
                        tooltip: '${item.product.name} — miqdorni oshirish',
                        visualDensity: VisualDensity.compact,
                        onPressed: () => _updateQuantity(index, 1),
                        icon: Icon(Icons.add, color: _colors.blue, size: 18)),
                  ]))),
          const SizedBox(width: 8),
          PosIconButton(
              icon: Icons.percent,
              label: '${item.product.name} — chegirma',
              color: item.discount > 0 ? _colors.danger : _colors.muted,
              onPressed: () => _editDiscount(item)),
          const SizedBox(width: 8),
          PosIconButton(
              icon: Icons.delete_outline,
              label: '${item.product.name} — o‘chirish',
              color: _colors.danger,
              onPressed: () => _removeItem(item)),
        ])
      ]));

  Future<void> _openCart() async {
    final pay = await _sheet<bool>(ValueListenableBuilder<int>(
        valueListenable: _cartChanges,
        builder: (ctx, _, __) {
          final quantity =
              _cart.fold<int>(0, (sum, item) => sum + item.quantity);
          final subtotal = _cart.fold<double>(
              0, (sum, item) => sum + item.price * item.quantity);
          final discount =
              _cart.fold<double>(0, (sum, item) => sum + item.discount);
          return SafeArea(
              top: false,
              child: Column(children: [
                PosSheetHeader(
                    title: 'Savat',
                    onClose: () => Navigator.pop(ctx),
                    action: PosIconButton(
                        icon: Icons.pause,
                        label: 'Yangi savat ochish',
                        color: _colors.accent,
                        onPressed: () async {
                          await _switchCart('new');
                          if (ctx.mounted) Navigator.pop(ctx);
                        })),
                Expanded(
                    child: SingleChildScrollView(
                        padding: const EdgeInsets.all(16),
                        child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              PosPanel(
                                  child: Row(children: [
                                _stat(PosStyle.money(_totalAmount), 'SUMMA'),
                                _stat('$quantity', 'DONA',
                                    color: _colors.green),
                                _stat('${_cart.length}', 'POZITSIYA'),
                              ])),
                              const SizedBox(height: 12),
                              if (_cart.isEmpty)
                                Padding(
                                    padding: const EdgeInsets.all(24),
                                    child: Text('Savat bo‘sh',
                                        textAlign: TextAlign.center,
                                        style:
                                            TextStyle(color: _colors.muted))),
                              for (var i = 0; i < _cart.length; i++) ...[
                                _cartCard(_cart[i], i),
                                const SizedBox(height: 10),
                              ],
                              _actionCard(
                                  Icons.person_add_alt_1_outlined,
                                  _customerName ?? 'Mijoz qo‘shish',
                                  _selectedCustomerId == null
                                      ? 'Tanlanmagan · mijozsiz savdo'
                                      : 'Mijozni o‘zgartirish',
                                  _selectCustomer),
                              if (_selectedCustomerId != null)
                                TextButton(
                                    onPressed: () {
                                      setState(() {
                                        _selectedCustomerId = null;
                                        _customerName = null;
                                      });
                                      _persistCart();
                                    },
                                    child: const Text('Mijozsiz savdo')),
                              const SizedBox(height: 10),
                              _actionCard(
                                  Icons.edit_note,
                                  'Izoh qo‘shish',
                                  _paymentNote.isEmpty
                                      ? 'Savdo uchun qo‘shimcha izoh'
                                      : _paymentNote,
                                  _editNote),
                              const SizedBox(height: 16),
                              PosPanel(
                                  child: Column(children: [
                                _breakdown('Mahsulotlar summasi',
                                    PosStyle.money(subtotal)),
                                const SizedBox(height: 12),
                                _breakdown('Mahsulotlarga chegirma',
                                    '−${PosStyle.money(discount)}'),
                                const SizedBox(height: 12),
                                Row(children: [
                                  Expanded(
                                      child: Text('Butun chekka chegirma',
                                          style: TextStyle(
                                              fontSize: 12,
                                              color: _colors.muted))),
                                  TextButton(
                                      onPressed: _cart.isEmpty
                                          ? null
                                          : () => _editDiscount(),
                                      style: TextButton.styleFrom(
                                          foregroundColor: _colors.blue),
                                      child: Text(_receiptDiscount > 0
                                          ? '−${PosStyle.money(_receiptDiscount)}'
                                          : 'Kiritish')),
                                ]),
                                const Divider(height: 24),
                                _breakdown('Jami',
                                    '${PosStyle.money(_totalAmount)} UZS',
                                    bold: true),
                              ])),
                            ]))),
                Padding(
                    padding: const EdgeInsets.all(16),
                    child: Row(children: [
                      PosIconButton(
                          icon: Icons.delete_outline,
                          label: 'Savatni tozalash',
                          color: _colors.danger,
                          onPressed: _cart.isEmpty ? null : _clearCart),
                      const SizedBox(width: 10),
                      Expanded(
                          child: PosButton(
                              label:
                                  'To‘lash · ${PosStyle.money(_totalAmount)} UZS',
                              icon: Icons.arrow_forward,
                              color: _colors.blue,
                              onPressed: _cart.isEmpty
                                  ? null
                                  : () => Navigator.pop(ctx, true))),
                    ])),
              ]));
        }));
    if (pay == true && mounted) _checkout();
  }

  void _preview(LocalProduct product) => showDialog(
      context: context,
      builder: (ctx) => Theme(
          data: PosStyle.theme(context, brightness: widget.brightness),
          child: Dialog(
              child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    Row(children: [
                      Expanded(child: Text(product.name)),
                      IconButton(
                          tooltip: 'Rasmni yopish',
                          onPressed: () => Navigator.pop(ctx),
                          icon: const Icon(Icons.close)),
                    ]),
                    InteractiveViewer(
                        child:
                            ProductImage(url: product.imageUrl, height: 300)),
                  ])))));

  Widget _productCard(BuildContext context, LocalProduct product) => PosPanel(
      color: _colors.card,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      child: Row(children: [
        SizedBox(
            width: 50,
            height: 58,
            child: InkWell(
                onTap:
                    product.imageUrl == null ? null : () => _preview(product),
                child: ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: Container(
                        color: Theme.of(context).brightness == Brightness.dark
                            ? const Color(0xFF252830)
                            : const Color(0xFFF0F1F5),
                        child: product.thumbnailUrl == null &&
                                product.imageUrl == null
                            ? Icon(Icons.inventory_2_outlined,
                                color: _colors.muted)
                            : Image.network(
                                product.thumbnailUrl ?? product.imageUrl!,
                                fit: BoxFit.contain,
                                errorBuilder: (_, __, ___) => Icon(
                                    Icons.inventory_2_outlined,
                                    color: _colors.muted)))))),
        const SizedBox(width: 12),
        Expanded(
            child:
                Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(product.name,
              style: const TextStyle(
                  fontSize: 14, fontWeight: FontWeight.w600, height: 1.35)),
          const SizedBox(height: 2),
          Text(
              '${product.categoryName ?? 'Mahsulot'}${product.barcode == null || product.barcode!.isEmpty ? '' : ' · ${product.barcode}'}',
              style: TextStyle(fontSize: 12, color: _colors.muted),
              maxLines: 2,
              overflow: TextOverflow.ellipsis),
          const SizedBox(height: 4),
          Text.rich(TextSpan(children: [
            TextSpan(
                text: PosStyle.money(product.price),
                style: TextStyle(
                    color: Theme.of(context).brightness == Brightness.dark
                        ? _colors.accent
                        : const Color(0xFF9A5D00),
                    fontWeight: FontWeight.w700,
                    fontSize: 14)),
            TextSpan(
                text: ' so‘m',
                style: TextStyle(color: _colors.muted, fontSize: 11)),
          ])),
          if (widget.canSeeCostPrice && product.initialPrice != null)
            Text('Kelgan narxi: ${PosStyle.money(product.initialPrice!)} so‘m',
                style: TextStyle(color: _colors.muted, fontSize: 12)),
        ])),
        const SizedBox(width: 8),
        IconButton(
            tooltip: '${product.name} — savatga qo‘shish',
            onPressed: () => _addToCart(product),
            icon: Icon(_addedProduct == product.id ? Icons.check : Icons.add,
                size: 20),
            style: IconButton.styleFrom(
                backgroundColor: _addedProduct == product.id
                    ? _colors.green
                    : _colors.accent,
                foregroundColor: _colors.accentText,
                minimumSize: const Size(44, 44),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14)))),
      ]));

  void _showReceipt() => _sheet<void>(
      Builder(
          builder: (ctx) => SafeArea(
              top: false,
              child: Column(children: [
                PosSheetHeader(
                    title: 'Chek', onClose: () => Navigator.pop(ctx)),
                Expanded(
                    child: SingleChildScrollView(
                        padding: const EdgeInsets.all(16),
                        child: SizedBox(
                            width: double.infinity,
                            child: PosPanel(
                                color: Colors.white,
                                child: SelectableText(_receiptText,
                                    style: const TextStyle(
                                        color: Colors.black,
                                        fontSize: 14,
                                        height: 1.6)))))),
                Padding(
                    padding: const EdgeInsets.all(16),
                    child: SizedBox(
                        width: double.infinity,
                        child: PosButton(
                            label: 'Nusxa olish',
                            icon: Icons.copy,
                            color: _colors.blue,
                            onPressed: () {
                              Clipboard.setData(
                                  ClipboardData(text: _receiptText));
                              _notice('Chek matni nusxalandi.');
                            }))),
              ]))),
      height: .88);

  Widget _success() => Center(
      child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(children: [
            Container(
                padding: const EdgeInsets.all(24),
                decoration: BoxDecoration(
                    color: _colors.green.withOpacity(.14),
                    shape: BoxShape.circle),
                child: Icon(Icons.check, size: 44, color: _colors.green)),
            const SizedBox(height: 20),
            const Text('Savdo muvaffaqiyatli',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700)),
            const SizedBox(height: 14),
            Text('Jami summa', style: TextStyle(color: _colors.muted)),
            const SizedBox(height: 8),
            Text('${PosStyle.money(_receipt!.totalAmount)} so‘m',
                style:
                    const TextStyle(fontSize: 30, fontWeight: FontWeight.w800)),
            Text('$_receiptQuantity mahsulot',
                style: TextStyle(color: _colors.muted)),
            const SizedBox(height: 12),
            Text('Chek qurilmaga saqlandi. Server tasdig‘i kutilmoqda.',
                textAlign: TextAlign.center,
                style: TextStyle(color: _colors.muted, fontSize: 13)),
            if (_receipt!.deficits.isNotEmpty)
              Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: Text(
                      '${_receipt!.deficits.length} ta mahsulotda yetishmovchilik qayd etildi.',
                      style: TextStyle(color: _colors.accent))),
            const SizedBox(height: 24),
            SizedBox(
                width: double.infinity,
                child: PosButton(
                    label: 'Chekni ko‘rish',
                    icon: Icons.receipt_long_outlined,
                    outlined: true,
                    onPressed: _showReceipt)),
            if (widget.canPrintReceipts) ...[
              const SizedBox(height: 10),
              SizedBox(
                  width: double.infinity,
                  child: PosButton(
                      label: 'Chekni chop etish',
                      icon: Icons.print_outlined,
                      onPressed: () => showDialog<void>(
                          context: context,
                          barrierDismissible: false,
                          builder: (_) => PrintDialog(
                              storeId: widget.storeId,
                              receiptId: _receipt!.localSaleId,
                              repository: _printing)))),
            ],
            const SizedBox(height: 10),
            SizedBox(
                width: double.infinity,
                child: PosButton(
                    label: 'Yangi savdo',
                    icon: Icons.add,
                    onPressed: () => setState(() => _receipt = null))),
            TextButton(
                onPressed: () {
                  Clipboard.setData(ClipboardData(text: _receiptText));
                  _notice('Chek matni nusxalandi.');
                },
                child: const Text('Chek nusxasini olish')),
          ])));

  @override
  Widget build(BuildContext context) {
    final filtered = _searchResults
        .where((p) => _category == null || p.categoryId == _category)
        .toList();
    final pageCount = (filtered.length / _pageSize).ceil();
    final products = filtered.skip(_page * _pageSize).take(_pageSize).toList();
    final quantity = _cart.fold<int>(0, (sum, item) => sum + item.quantity);
    final accentForeground = widget.brightness == Brightness.dark
        ? const Color(0xFFEFB66E)
        : const Color(0xFF9A5D00);
    return Theme(
        data: PosStyle.theme(context, brightness: widget.brightness),
        child: Builder(
            builder: (context) => Material(
                color: Theme.of(context).scaffoldBackgroundColor,
                child: Column(children: [
                  if (widget.onViewStatistics != null)
                    Padding(
                        padding: const EdgeInsets.fromLTRB(14, 0, 14, 4),
                        child: InkWell(
                            onTap: widget.onViewStatistics,
                            borderRadius: BorderRadius.circular(12),
                            child: Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 10, vertical: 8),
                                decoration: BoxDecoration(
                                    gradient: LinearGradient(colors: [
                                      _colors.accent.withOpacity(.16),
                                      _colors.accent.withOpacity(.07),
                                    ]),
                                    borderRadius: BorderRadius.circular(12),
                                    border: Border.all(
                                        color:
                                            _colors.accent.withOpacity(.45))),
                                child: Row(children: [
                                  Container(
                                      width: 36,
                                      height: 36,
                                      decoration: BoxDecoration(
                                          color: _colors.accent.withOpacity(.1),
                                          borderRadius:
                                              BorderRadius.circular(8)),
                                      child: Icon(Icons.bar_chart_rounded,
                                          color: accentForeground, size: 24)),
                                  const SizedBox(width: 10),
                                  Expanded(
                                      child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                        Text('Sotuv statistikasi',
                                            style: TextStyle(
                                                color: accentForeground,
                                                fontWeight: FontWeight.w600,
                                                fontSize: 13)),
                                        const SizedBox(height: 2),
                                        Text('Sotuvlar, hisobotlar va tahlil',
                                            style: TextStyle(
                                                color: PosStyle.secondaryText(
                                                    context),
                                                fontSize: 10)),
                                      ])),
                                  Icon(Icons.chevron_right,
                                      color: accentForeground, size: 20),
                                ])))),
                  Expanded(
                      child: _receipt != null
                          ? _success()
                          : Stack(children: [
                              ListView(
                                  padding: EdgeInsets.fromLTRB(
                                      14, 16, 14, _cart.isEmpty ? 20 : 90),
                                  children: [
                                    Row(children: [
                                      Expanded(
                                          child: Column(
                                              crossAxisAlignment:
                                                  CrossAxisAlignment.start,
                                              children: [
                                            if (widget.storeName.isNotEmpty)
                                              Text(
                                                  widget.storeName
                                                      .toUpperCase(),
                                                  style: TextStyle(
                                                      fontSize: 12,
                                                      fontWeight:
                                                          FontWeight.w600,
                                                      color: PosStyle
                                                          .secondaryText(
                                                              context),
                                                      letterSpacing: .5)),
                                            const Text('Savdo',
                                                style: TextStyle(
                                                    fontSize: 24,
                                                    fontWeight: FontWeight.w700,
                                                    letterSpacing: -.4)),
                                          ])),
                                      if (_cartIds.length > 1)
                                        Text('Savat #$_cartId',
                                            style: TextStyle(
                                                color: _colors.accent,
                                                fontSize: 12)),
                                      IconButton(
                                          tooltip: 'Saqlangan cheklar',
                                          onPressed: _openReceipts,
                                          icon: Icon(
                                              Icons.receipt_long_outlined,
                                              size: 21,
                                              color: _colors.muted)),
                                    ]),
                                    const SizedBox(height: 12),
                                    Row(children: [
                                      Expanded(
                                          child: SizedBox(
                                              height: 54,
                                              child: TextField(
                                                  controller: _searchController,
                                                  onChanged: _onSearch,
                                                  onSubmitted: (barcode) async {
                                                    final product =
                                                        await _productsRepo
                                                            .findByBarcode(
                                                                widget.storeId,
                                                                barcode.trim());
                                                    if (!mounted) return;
                                                    if (product != null) {
                                                      _addToCart(product);
                                                    } else {
                                                      _notice(
                                                          'Shtrix-kod bo‘yicha mahsulot topilmadi.');
                                                    }
                                                  },
                                                  textInputAction:
                                                      TextInputAction.search,
                                                  decoration: InputDecoration(
                                                      hintText:
                                                          'Mahsulot qidirish',
                                                      prefixIcon: Icon(
                                                          Icons.search,
                                                          size: 22,
                                                          color:
                                                              PosStyle.colors(
                                                                      context)
                                                                  .muted),
                                                      suffixIcon: _isSearching
                                                          ? const Padding(
                                                              padding:
                                                                  EdgeInsets.all(
                                                                      16),
                                                              child: SizedBox(
                                                                  width: 16,
                                                                  height: 16,
                                                                  child: CircularProgressIndicator(
                                                                      strokeWidth:
                                                                          2)))
                                                          : _searchController
                                                                  .text.isEmpty
                                                              ? null
                                                              : IconButton(
                                                                  tooltip:
                                                                      'Qidiruvni tozalash',
                                                                  onPressed:
                                                                      () {
                                                                    _searchController
                                                                        .clear();
                                                                    _onSearch(
                                                                        '');
                                                                  },
                                                                  icon: const Icon(
                                                                      Icons
                                                                          .close,
                                                                      size:
                                                                          18)),
                                                      fillColor:
                                                          PosStyle.surface(
                                                              context))))),
                                      const SizedBox(width: 8),
                                      SizedBox(
                                          width: 68,
                                          height: 54,
                                          child: FilledButton(
                                              onPressed: _openBarcodeScanner,
                                              style: FilledButton.styleFrom(
                                                  backgroundColor: PosStyle
                                                      .accent
                                                      .withOpacity(.12),
                                                  foregroundColor:
                                                      accentForeground,
                                                  padding: EdgeInsets.zero,
                                                  shape: RoundedRectangleBorder(
                                                      borderRadius:
                                                          BorderRadius.circular(
                                                              16),
                                                      side: BorderSide(
                                                          color: PosStyle.colors(
                                                                  context)
                                                              .accent
                                                              .withOpacity(
                                                                  .35)))),
                                              child: const Column(
                                                  mainAxisAlignment:
                                                      MainAxisAlignment.center,
                                                  children: [
                                                    Icon(Icons.qr_code,
                                                        size: 20),
                                                    SizedBox(height: 3),
                                                    Text('Skanerlash',
                                                        style: TextStyle(
                                                            fontSize: 10)),
                                                  ]))),
                                    ]),
                                    const SizedBox(height: 10),
                                    SingleChildScrollView(
                                        scrollDirection: Axis.horizontal,
                                        child: Row(children: [
                                          PosChip(
                                              label: 'Barchasi',
                                              selected: _category == null,
                                              onPressed: () => setState(() {
                                                    _category = null;
                                                    _page = 0;
                                                  })),
                                          for (final category
                                              in _categories.entries) ...[
                                            const SizedBox(width: 8),
                                            PosChip(
                                                label: category.value,
                                                selected:
                                                    _category == category.key,
                                                onPressed: () => setState(() {
                                                      _category = category.key;
                                                      _page = 0;
                                                    })),
                                          ],
                                          const SizedBox(width: 8),
                                          for (final id in _cartIds) ...[
                                            PosChip(
                                                label:
                                                    '#$id · ${id == _cartId ? quantity : _cartQuantities[id] ?? 0}',
                                                selected: id == _cartId,
                                                onPressed: () =>
                                                    _switchCart(id)),
                                            const SizedBox(width: 8),
                                          ],
                                          PosChip(
                                              label: '+ Savat',
                                              onPressed: () =>
                                                  _switchCart('new')),
                                        ])),
                                    const SizedBox(height: 16),
                                    if (_loadError != null) ...[
                                      Text(_loadError!,
                                          style:
                                              TextStyle(color: _colors.danger)),
                                      TextButton(
                                          onPressed: _loadProducts,
                                          child: const Text('Qayta urinish')),
                                    ] else if (products.isEmpty &&
                                        !_isSearching)
                                      Padding(
                                          padding: const EdgeInsets.symmetric(
                                              vertical: 52),
                                          child: Column(children: [
                                            Icon(Icons.search,
                                                size: 42, color: _colors.muted),
                                            const SizedBox(height: 16),
                                            Text(
                                                _searchController.text.isEmpty
                                                    ? 'Mahsulot qo‘shishni boshlang'
                                                    : 'Mahsulot topilmadi',
                                                style: const TextStyle(
                                                    fontSize: 17,
                                                    fontWeight:
                                                        FontWeight.w600)),
                                            const SizedBox(height: 8),
                                            Text(
                                                'Nomini qidiring yoki shtrix-kodni skanerlang.',
                                                textAlign: TextAlign.center,
                                                style: TextStyle(
                                                    fontSize: 13,
                                                    color: _colors.muted)),
                                          ])),
                                    for (final product in products) ...[
                                      _productCard(context, product),
                                      const SizedBox(height: 10),
                                    ],
                                    if (pageCount > 1)
                                      Row(
                                          mainAxisAlignment:
                                              MainAxisAlignment.spaceBetween,
                                          children: [
                                            TextButton(
                                                onPressed: _page == 0
                                                    ? null
                                                    : () =>
                                                        setState(() => _page--),
                                                child: const Text('← Oldingi')),
                                            Text('${_page + 1} / $pageCount',
                                                style: TextStyle(
                                                    color: _colors.muted,
                                                    fontSize: 12)),
                                            TextButton(
                                                onPressed: _page + 1 >=
                                                        pageCount
                                                    ? null
                                                    : () =>
                                                        setState(() => _page++),
                                                child: const Text('Keyingi →')),
                                          ]),
                                  ]),
                              if (_cart.isNotEmpty)
                                Positioned(
                                    left: 14,
                                    right: 14,
                                    bottom: 14,
                                    child: SizedBox(
                                        height: 58,
                                        child: FilledButton(
                                            onPressed: _openCart,
                                            style: FilledButton.styleFrom(
                                                backgroundColor: _colors.accent,
                                                foregroundColor:
                                                    _colors.accentText,
                                                padding:
                                                    const EdgeInsets.symmetric(
                                                        horizontal: 16),
                                                shape: RoundedRectangleBorder(
                                                    borderRadius:
                                                        BorderRadius.circular(
                                                            18))),
                                            child: Row(children: [
                                              const Icon(
                                                  Icons.shopping_cart_outlined,
                                                  size: 22),
                                              const SizedBox(width: 10),
                                              Expanded(
                                                  child: Text(
                                                      '$quantity mahsulot · ${PosStyle.money(_totalAmount)} so‘m',
                                                      style: const TextStyle(
                                                          fontSize: 12,
                                                          fontWeight: FontWeight
                                                              .w700))),
                                              const SizedBox(width: 8),
                                              const Text('Savatni ochish',
                                                  style: TextStyle(
                                                      fontSize: 11,
                                                      fontWeight:
                                                          FontWeight.w700)),
                                              const SizedBox(width: 4),
                                              const Icon(Icons.arrow_forward,
                                                  size: 18),
                                            ])))),
                            ])),
                ]))));
  }
}
