import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../../core/utils/photo_picker.dart';
import '../data/products_repository.dart';
import 'barcode_scanner_sheet.dart';
import 'product_widgets.dart';

class ProductFormDialog extends StatefulWidget {
  final String storeId;
  final ProductsRepository repository;
  final void Function(LocalProduct product) onCreated;
  final LocalProduct? existing;
  const ProductFormDialog(
      {super.key,
      required this.storeId,
      required this.repository,
      required this.onCreated,
      this.existing});
  @override
  State<ProductFormDialog> createState() => _ProductFormDialogState();
}

class _SizeFields {
  final String? id;
  final TextEditingController name;
  final Map<String, TextEditingController> stocks;
  _SizeFields({this.id, String size = '', required this.stocks})
      : name = TextEditingController(text: size);
  void dispose() {
    name.dispose();
    for (final controller in stocks.values) {
      controller.dispose();
    }
  }
}

class _ProductFormDialogState extends State<ProductFormDialog> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _barcode = TextEditingController();
  final _price = TextEditingController();
  final _cost = TextEditingController();
  final _stockValues = <String, TextEditingController>{};
  final _sizes = <_SizeFields>[];
  List<Map<String, dynamic>> _stocks = [];
  String _type = 'package';
  String? _imageUrl;
  Uint8List? _photo;
  bool _removePhoto = false;
  bool _loading = true;
  bool _loadFailed = false;
  bool _saving = false;
  bool _generatingBarcode = false;
  bool _pickingPhoto = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _loadFailed = false;
      _error = null;
    });
    try {
      final response =
          await widget.repository.formData(widget.storeId, widget.existing?.id);
      if (!mounted) return;
      final data = Map<String, dynamic>.from(response['data'] as Map? ?? {});
      final edit =
          Map<String, dynamic>.from(response['edit'] as Map? ?? response);
      _stocks = (edit['stocks'] as List? ?? [])
          .map((v) => Map<String, dynamic>.from(v as Map))
          .toList();
      _name.text = data['name']?.toString() ?? '';
      _barcode.text = data['barcode']?.toString() ?? '';
      _price.text = data['price']?.toString() ?? '';
      _cost.text = data['initial_price']?.toString() ?? '';
      _type = data['type']?.toString() ?? 'package';
      _imageUrl = data['image']?.toString() ?? widget.existing?.imageUrl;
      for (final stock in _stocks) {
        final quantities = edit['quantities'] as List? ?? [];
        final matching = quantities
            .where((q) => q['stock_id'].toString() == stock['id'].toString());
        _stockValues[stock['id'].toString()] = TextEditingController(
            text:
                matching.isEmpty ? '0' : matching.first['quantity'].toString());
      }
      for (final raw in edit['sizes'] as List? ?? []) {
        final size = Map<String, dynamic>.from(raw as Map);
        final quantities = size['stocks'] as List? ?? [];
        _sizes.add(_SizeFields(
            id: size['id']?.toString(),
            size: size['size']?.toString() ?? '',
            stocks: {
              for (final stock in _stocks)
                stock['id'].toString(): TextEditingController(
                    text: quantities
                            .where((q) =>
                                q['stock_id'].toString() ==
                                stock['id'].toString())
                            .map((q) => q['quantity'].toString())
                            .firstOrNull ??
                        '0'),
            }));
      }
      setState(() => _loading = false);
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _loadFailed = true;
          _error =
              'Formani yuklab bo‘lmadi. Internetni tekshirib, qayta urinib ko‘ring.';
        });
      }
    }
  }

  @override
  void dispose() {
    for (final controller in [
      _name,
      _barcode,
      _price,
      _cost,
      ..._stockValues.values
    ]) {
      controller.dispose();
    }
    for (final size in _sizes) {
      size.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving || !_formKey.currentState!.validate()) return;
    if (_type == 'size' && _sizes.isEmpty) {
      setState(() => _error = 'Kamida bitta razmer qo‘shing.');
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final payload = <String, dynamic>{
        'name': _name.text.trim(),
        'barcode': _barcode.text.trim(),
        'price': int.parse(_price.text.trim()),
        'initial_price': int.parse(_cost.text.trim()),
        'type': _type,
        'remove_image': _removePhoto ? 1 : 0,
        if (_type == 'package')
          'stocks': _stockValues.entries
              .map((e) =>
                  {'stock_id': e.key, 'quantity': int.parse(e.value.text)})
              .toList(),
        if (_type == 'size')
          'sizes': _sizes
              .map((size) => {
                    if (size.id != null) 'id': size.id,
                    'size': size.name.text.trim(),
                    'stocks': size.stocks.entries
                        .map((e) => {
                              'stock_id': e.key,
                              'quantity': int.parse(e.value.text)
                            })
                        .toList(),
                  })
              .toList(),
      };
      final product = await widget.repository.saveProduct(
          widget.storeId, payload,
          productId: widget.existing?.id, photo: _photo);
      if (!mounted) return;
      Navigator.pop(context);
      widget.onCreated(product);
    } catch (error) {
      if (!mounted) return;
      String message =
          'Saqlab bo‘lmadi. Internetni tekshirib, qayta urinib ko‘ring.';
      if (error is DioException) {
        final data = error.response?.data;
        if (data is Map && data['errors'] is Map) {
          message = (data['errors'] as Map)
              .values
              .expand((value) => value is List ? value : [value])
              .join('\n');
        } else if (error.response?.statusCode == 403) {
          message = 'Mahsulotni o‘zgartirish uchun ruxsat berilmagan.';
        }
      }
      setState(() {
        _saving = false;
        _error = message;
      });
    }
  }

  Future<void> _pickPhoto() async {
    if (_pickingPhoto || _saving) return;
    setState(() => _pickingPhoto = true);
    try {
      final bytes = await PhotoPicker.pick(context, maxBytes: 1024 * 1024);
      if (bytes == null) return;
      if (mounted) {
        setState(() {
          _photo = bytes;
          _removePhoto = false;
        });
      }
    } catch (error) {
      if (mounted) {
        final message = error is PhotoPickerException
            ? error.message
            : 'Rasmni ochib bo‘lmadi. Qayta tanlang.';
        setState(() => _error = message);
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(message)));
      }
    } finally {
      if (mounted) setState(() => _pickingPhoto = false);
    }
  }

  Future<void> _generateBarcode() async {
    setState(() => _generatingBarcode = true);
    try {
      final code = await widget.repository.requestBarcode(widget.storeId);
      if (mounted) _barcode.text = code;
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'Shtrix-kod olish uchun serverga ulaning.');
      }
    } finally {
      if (mounted) setState(() => _generatingBarcode = false);
    }
  }

  Future<void> _changeType(String? value) async {
    if (value == null || value == _type) return;
    final confirmed = widget.existing == null ||
        await showDialog<bool>(
                context: context,
                builder: (ctx) => AlertDialog(
                      title: const Text('Mahsulot turini almashtirish'),
                      content: const Text(
                          'Ombor qoldiqlari tuzilmasi o‘zgaradi. Davom etilsinmi?'),
                      actions: [
                        TextButton(
                            onPressed: () => Navigator.pop(ctx, false),
                            child: const Text('Bekor qilish')),
                        TextButton(
                            onPressed: () => Navigator.pop(ctx, true),
                            child: const Text('Davom etish')),
                      ],
                    )) ==
            true;
    if (confirmed && mounted) setState(() => _type = value);
  }

  Widget _field(TextEditingController controller, String label,
          {bool numeric = false, int max = 255, bool barcode = false}) =>
      TextFormField(
        controller: controller,
        enabled: !_saving,
        keyboardType:
            numeric || barcode ? TextInputType.number : TextInputType.text,
        textInputAction: TextInputAction.next,
        inputFormatters: numeric || barcode
            ? [FilteringTextInputFormatter.digitsOnly]
            : null,
        decoration: InputDecoration(
            labelText: label,
            suffixText: numeric && max == 999999999999 ? 'so‘m' : null),
        validator: (text) {
          final value = (text ?? '').trim();
          if (value.isEmpty) return '$label kiriting.';
          if (numeric) {
            final number = int.tryParse(value);
            if (number == null || number < 0 || number > max) {
              return '0 dan $max gacha butun son kiriting.';
            }
          } else if (value.length > max) {
            return 'Ko‘pi bilan $max belgi kiriting.';
          }
          return null;
        },
      );

  Widget _sizeCard(_SizeFields size, int index) => Card(
        margin: EdgeInsets.zero,
        child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(children: [
              Row(children: [
                Expanded(
                    child: Text('${index + 1}-razmer',
                        style: Theme.of(context).textTheme.titleSmall)),
                IconButton(
                    tooltip: 'Razmerni olib tashlash',
                    icon: const Icon(Icons.close),
                    onPressed: _saving
                        ? null
                        : () {
                            setState(() => _sizes.remove(size));
                            size.dispose();
                          }),
              ]),
              _field(size.name, 'Razmer nomi'),
              for (final stock in _stocks) ...[
                const SizedBox(height: 12),
                _field(size.stocks[stock['id'].toString()]!,
                    stock['name'].toString(),
                    numeric: true, max: 2147483647),
              ],
            ])),
      );

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    final content = PopScope(
        canPop: !_saving,
        child: Scaffold(
          appBar: AppBar(
            automaticallyImplyLeading: false,
            leading: IconButton(
                tooltip: 'Yopish',
                icon: const Icon(Icons.close),
                onPressed: _saving ? null : () => Navigator.pop(context)),
            title: Text(
                widget.existing == null
                    ? 'Yangi mahsulot'
                    : 'Mahsulotni tahrirlash',
                style:
                    const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          ),
          body: _loading
              ? const Center(child: CircularProgressIndicator())
              : _loadFailed
                  ? Center(
                      child: Padding(
                          padding: const EdgeInsets.all(24),
                          child:
                              Column(mainAxisSize: MainAxisSize.min, children: [
                            const Icon(Icons.wifi_off, size: 40),
                            const SizedBox(height: 16),
                            Text(_error!, textAlign: TextAlign.center),
                            const SizedBox(height: 16),
                            OutlinedButton.icon(
                                onPressed: _load,
                                icon: const Icon(Icons.refresh),
                                label: const Text('Qayta urinish')),
                          ])))
                  : Form(
                      key: _formKey,
                      child: SingleChildScrollView(
                          padding: const EdgeInsets.all(16),
                          keyboardDismissBehavior:
                              ScrollViewKeyboardDismissBehavior.onDrag,
                          child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                if (_error != null) ...[
                                  Container(
                                      padding: const EdgeInsets.all(14),
                                      decoration: BoxDecoration(
                                          color: colors.errorContainer,
                                          borderRadius:
                                              BorderRadius.circular(12)),
                                      child: Text(_error!,
                                          style: TextStyle(
                                              color: colors.onErrorContainer))),
                                  const SizedBox(height: 16),
                                ],
                                ProductSection(
                                    title: 'Asosiy ma’lumotlar',
                                    icon: Icons.inventory_2_outlined,
                                    children: [
                                      _field(_name, 'Mahsulot nomi'),
                                      _field(_barcode, 'Shtrix-kod',
                                          barcode: true, max: 100),
                                      Wrap(
                                          spacing: 8,
                                          runSpacing: 8,
                                          children: [
                                            OutlinedButton.icon(
                                                onPressed: _saving ||
                                                        _generatingBarcode
                                                    ? null
                                                    : _generateBarcode,
                                                icon: const Icon(
                                                    Icons.auto_awesome_outlined,
                                                    size: 18),
                                                label: Text(_generatingBarcode
                                                    ? 'Yaratilmoqda...'
                                                    : 'Kod yaratish')),
                                            OutlinedButton.icon(
                                                onPressed: _saving
                                                    ? null
                                                    : () =>
                                                        showModalBottomSheet(
                                                            context: context,
                                                            isScrollControlled:
                                                                true,
                                                            builder: (_) =>
                                                                BarcodeScannerSheet(
                                                                    onBarcodeScanned:
                                                                        (value) {
                                                                  if (mounted) {
                                                                    _barcode.text =
                                                                        value;
                                                                  }
                                                                })),
                                                icon: const Icon(
                                                    Icons.qr_code_scanner,
                                                    size: 18),
                                                label:
                                                    const Text('Skanerlash')),
                                          ]),
                                    ]),
                                const SizedBox(height: 16),
                                ProductSection(
                                    title: 'Narxlar',
                                    icon: Icons.payments_outlined,
                                    children: [
                                      _field(_cost, 'Kelish narxi',
                                          numeric: true, max: 999999999999),
                                      _field(_price, 'Sotish narxi',
                                          numeric: true, max: 999999999999),
                                    ]),
                                const SizedBox(height: 16),
                                ProductSection(
                                    title: 'Ombor va qoldiqlar',
                                    icon: Icons.warehouse_outlined,
                                    children: [
                                      DropdownButtonFormField<String>(
                                          value: _type,
                                          isExpanded: true,
                                          decoration: const InputDecoration(
                                              labelText: 'Mahsulot turi'),
                                          items: const [
                                            DropdownMenuItem(
                                                value: 'package',
                                                child: Text('Qadoq')),
                                            DropdownMenuItem(
                                                value: 'size',
                                                child: Text('Razmerli'))
                                          ],
                                          onChanged:
                                              _saving ? null : _changeType),
                                      if (_stocks.isEmpty)
                                        const Text(
                                            'Do‘kon uchun ombor mavjud emas.'),
                                      if (_type == 'package')
                                        for (final stock in _stocks)
                                          _field(
                                              _stockValues[
                                                  stock['id'].toString()]!,
                                              stock['name'].toString(),
                                              numeric: true,
                                              max: 2147483647),
                                      if (_type == 'size') ...[
                                        for (var index = 0;
                                            index < _sizes.length;
                                            index++)
                                          _sizeCard(_sizes[index], index),
                                        OutlinedButton.icon(
                                            onPressed: _saving ||
                                                    _sizes.length >= 50
                                                ? null
                                                : () => setState(() =>
                                                    _sizes.add(
                                                        _SizeFields(stocks: {
                                                      for (final stock
                                                          in _stocks)
                                                        stock['id'].toString():
                                                            TextEditingController(
                                                                text: '0')
                                                    }))),
                                            icon: const Icon(Icons.add),
                                            label:
                                                const Text('Razmer qo‘shish')),
                                      ],
                                    ]),
                                const SizedBox(height: 16),
                                ProductSection(
                                    title: 'Mahsulot rasmi',
                                    icon: Icons.photo_outlined,
                                    children: [
                                      ProductImage(
                                          bytes: _photo,
                                          url: _removePhoto ? null : _imageUrl,
                                          height: 150),
                                      Wrap(
                                          spacing: 8,
                                          runSpacing: 8,
                                          children: [
                                            OutlinedButton.icon(
                                                onPressed:
                                                    _saving || _pickingPhoto
                                                        ? null
                                                        : _pickPhoto,
                                                icon: const Icon(Icons
                                                    .add_photo_alternate_outlined),
                                                label:
                                                    const Text('Rasm tanlash')),
                                            if (_photo != null ||
                                                (_imageUrl != null &&
                                                    !_removePhoto))
                                              TextButton.icon(
                                                  onPressed: _saving ||
                                                          _pickingPhoto
                                                      ? null
                                                      : () => setState(() {
                                                            _photo = null;
                                                            _removePhoto = true;
                                                          }),
                                                  icon: const Icon(
                                                      Icons.delete_outline),
                                                  label: const Text(
                                                      'Rasmni olib tashlash')),
                                          ]),
                                    ]),
                              ]))),
          bottomNavigationBar: _loading || _loadFailed
              ? null
              : SafeArea(
                  top: false,
                  child: Padding(
                      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
                      child: ElevatedButton.icon(
                          onPressed:
                              _saving || _generatingBarcode || _pickingPhoto
                                  ? null
                                  : _save,
                          icon: _saving
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child:
                                      CircularProgressIndicator(strokeWidth: 2))
                              : const Icon(Icons.check),
                          label:
                              Text(_saving ? 'Saqlanmoqda...' : 'Saqlash')))),
        ));
    if (MediaQuery.sizeOf(context).width < 600) {
      return Dialog.fullscreen(child: content);
    }
    return Dialog(
        clipBehavior: Clip.antiAlias,
        child: SizedBox(
            width: 640,
            height: MediaQuery.sizeOf(context).height * 0.9,
            child: content));
  }
}
