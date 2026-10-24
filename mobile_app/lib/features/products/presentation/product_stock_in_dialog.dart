import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:uuid/uuid.dart';
import '../data/products_repository.dart';

class ProductStockInDialog extends StatefulWidget {
  final LocalProduct product;
  final ProductsRepository repository;

  const ProductStockInDialog(
      {super.key, required this.product, required this.repository});

  @override
  State<ProductStockInDialog> createState() => _ProductStockInDialogState();
}

class _ProductStockInDialogState extends State<ProductStockInDialog> {
  final _formKey = GlobalKey<FormState>();
  final _quantity = TextEditingController();
  List<Map<String, dynamic>> _stocks = [];
  List<Map<String, dynamic>> _sizes = [];
  Map<String, dynamic> _data = {};
  Map<String, dynamic> _edit = {};
  String? _stockId;
  String? _sizeId;
  String _requestId = const Uuid().v4();
  bool _loading = true;
  bool _loadFailed = false;
  bool _saving = false;
  bool _retryOnly = false;
  String? _error;

  bool get _sized => (_data['type'] ?? widget.product.type) == 'size';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _quantity.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _loadFailed = false;
      _error = null;
    });
    try {
      final response = await widget.repository
          .formData(widget.product.storeId, widget.product.id);
      if (!mounted) return;
      _data = Map<String, dynamic>.from(response['data'] as Map);
      _edit = Map<String, dynamic>.from(response['edit'] as Map? ?? {});
      _stocks = (_edit['stocks'] as List? ?? [])
          .map((item) => Map<String, dynamic>.from(item as Map))
          .where((stock) => stock['is_active'] != false)
          .toList();
      _sizes = (_edit['sizes'] as List? ?? [])
          .map((item) => Map<String, dynamic>.from(item as Map))
          .toList();
      _stockId = _stocks.length == 1 ? _stocks.first['id'].toString() : null;
      _sizeId = _sizes.length == 1 ? _sizes.first['id'].toString() : null;
      setState(() => _loading = false);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _loadFailed = true;
        _error = 'Ma’lumotlarni yuklab bo‘lmadi. Internetni tekshiring.';
      });
    }
  }

  int get _currentQuantity {
    final selected = _sizes.where((size) => size['id'].toString() == _sizeId);
    final rows = (_sized
            ? (selected.isEmpty ? null : selected.first['stocks'])
            : _edit['quantities']) as List? ??
        [];
    return rows
            .where((row) => row['stock_id'].toString() == _stockId)
            .map((row) => int.tryParse(row['quantity'].toString()) ?? 0)
            .firstOrNull ??
        0;
  }

  Future<void> _save() async {
    if (_saving || !_formKey.currentState!.validate()) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final product = await widget.repository.receiveStock(widget.product,
          requestId: _requestId,
          stockId: _stockId!,
          sizeId: _sized ? _sizeId : null,
          quantity: int.parse(_quantity.text));
      if (mounted) Navigator.pop(context, product);
    } catch (error) {
      if (!mounted) return;
      var message =
          'Kirim natijasini olib bo‘lmadi. Internetni tekshirib, qayta urining. Takroriy kirim yaratilmaydi.';
      var retryOnly = true;
      if (error is DioException) {
        final status = error.response?.statusCode;
        if (status != null && status >= 400 && status < 500) {
          retryOnly = false;
          _requestId = const Uuid().v4();
          final data = error.response?.data;
          message = 'Kirimni saqlab bo‘lmadi.';
          if (data is Map && data['errors'] is Map) {
            message = (data['errors'] as Map)
                .values
                .expand((value) => value is List ? value : [value])
                .join('\n');
          } else if (status == 403) {
            message = 'Kirim qilish uchun ruxsat berilmagan.';
          } else if (data is Map && data['message'] is String) {
            message = data['message'] as String;
          }
        }
      }
      setState(() {
        _saving = false;
        _retryOnly = retryOnly;
        _error = message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = theme.colorScheme;
    final ready = !_loading &&
        !_loadFailed &&
        _stocks.isNotEmpty &&
        (!_sized || _sizes.isNotEmpty);
    final locked = _saving || _retryOnly;
    final content = PopScope(
        canPop: !_saving,
        child: Scaffold(
          appBar: AppBar(
              automaticallyImplyLeading: false,
              leading: IconButton(
                  tooltip: 'Yopish',
                  onPressed: _saving ? null : () => Navigator.pop(context),
                  icon: const Icon(Icons.close)),
              title: const Text('Kirim qilish')),
          body: _loading
              ? const Center(child: CircularProgressIndicator())
              : _loadFailed
                  ? Center(
                      child: Padding(
                          padding: const EdgeInsets.all(24),
                          child:
                              Column(mainAxisSize: MainAxisSize.min, children: [
                            Text(_error!, textAlign: TextAlign.center),
                            const SizedBox(height: 16),
                            OutlinedButton(
                                onPressed: _load,
                                child: const Text('Qayta urinish')),
                          ])))
                  : Form(
                      key: _formKey,
                      child: ListView(
                          padding: const EdgeInsets.all(16),
                          children: [
                            TextFormField(
                                initialValue: _data['name']?.toString() ??
                                    widget.product.name,
                                enabled: false,
                                style: TextStyle(color: colors.onSurface),
                                decoration: const InputDecoration(
                                    labelText: 'Mahsulot nomi')),
                            const SizedBox(height: 16),
                            TextFormField(
                                initialValue: _data['barcode']?.toString() ??
                                    widget.product.barcode ??
                                    '',
                                enabled: false,
                                style: TextStyle(color: colors.onSurface),
                                decoration: const InputDecoration(
                                    labelText: 'Shtrix-kod')),
                            const SizedBox(height: 16),
                            DropdownButtonFormField<String>(
                                value: _stockId,
                                isExpanded: true,
                                decoration:
                                    const InputDecoration(labelText: 'Ombor'),
                                items: _stocks
                                    .map((stock) => DropdownMenuItem(
                                        value: stock['id'].toString(),
                                        child: Text(stock['name'].toString(),
                                            overflow: TextOverflow.ellipsis)))
                                    .toList(),
                                onChanged: locked
                                    ? null
                                    : (value) =>
                                        setState(() => _stockId = value),
                                validator: (value) =>
                                    value == null ? 'Omborni tanlang.' : null),
                            if (_sized) ...[
                              const SizedBox(height: 16),
                              DropdownButtonFormField<String>(
                                  value: _sizeId,
                                  isExpanded: true,
                                  decoration: const InputDecoration(
                                      labelText: 'Razmer'),
                                  items: _sizes
                                      .map((size) => DropdownMenuItem(
                                          value: size['id'].toString(),
                                          child: Text(size['size'].toString(),
                                              overflow: TextOverflow.ellipsis)))
                                      .toList(),
                                  onChanged: locked
                                      ? null
                                      : (value) =>
                                          setState(() => _sizeId = value),
                                  validator: (value) => value == null
                                      ? 'Razmerni tanlang.'
                                      : null),
                            ],
                            const SizedBox(height: 16),
                            TextFormField(
                                controller: _quantity,
                                autovalidateMode:
                                    AutovalidateMode.onUserInteraction,
                                readOnly: locked,
                                keyboardType: TextInputType.number,
                                inputFormatters: [
                                  FilteringTextInputFormatter.digitsOnly
                                ],
                                decoration: const InputDecoration(
                                    labelText: 'Kirim miqdori',
                                    suffixText: 'dona'),
                                onChanged: (_) => setState(() {}),
                                validator: (value) {
                                  final number = int.tryParse(value ?? '');
                                  if (number == null || number < 1) {
                                    return 'Kamida 1 dona kiriting.';
                                  }
                                  if (number > 2147483647) {
                                    return 'Miqdor juda katta.';
                                  }
                                  return null;
                                }),
                            const SizedBox(height: 16),
                            if (_stockId != null &&
                                (!_sized || _sizeId != null))
                              Text(
                                  'Joriy qoldiq: $_currentQuantity dona\n'
                                  'Kirimdan keyin: ${_currentQuantity + (int.tryParse(_quantity.text) ?? 0)} dona',
                                  style: theme.textTheme.bodyLarge),
                            if (!ready)
                              Text(
                                  _stocks.isEmpty
                                      ? 'Kirim uchun faol ombor mavjud emas.'
                                      : 'Mahsulot uchun razmer mavjud emas.',
                                  style: TextStyle(color: colors.error)),
                            if (_error != null) ...[
                              const SizedBox(height: 16),
                              Text(_error!,
                                  style: TextStyle(color: colors.error)),
                            ],
                          ])),
          bottomNavigationBar: SafeArea(
              top: false,
              child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: ElevatedButton.icon(
                      onPressed: ready && !_saving ? _save : null,
                      icon: _saving
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2))
                          : const Icon(Icons.add_box_outlined),
                      label: Text(_saving
                          ? 'Saqlanmoqda...'
                          : _retryOnly
                              ? 'Qayta urinish'
                              : 'Kirimni saqlash')))),
        ));
    if (MediaQuery.sizeOf(context).width < 600) {
      return Dialog.fullscreen(child: content);
    }
    return Dialog(
        clipBehavior: Clip.antiAlias,
        child: SizedBox(
            width: 560,
            height: MediaQuery.sizeOf(context).height * .9,
            child: content));
  }
}
