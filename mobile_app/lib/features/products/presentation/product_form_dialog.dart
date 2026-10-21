import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../data/products_repository.dart';
import 'barcode_scanner_sheet.dart';

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

class _ProductFormDialogState extends State<ProductFormDialog> {
  final _name = TextEditingController();
  final _barcode = TextEditingController();
  final _price = TextEditingController();
  final _cost = TextEditingController();
  final _stockValues = <String, TextEditingController>{};
  final _sizes = <Map<String, dynamic>>[];
  List<Map<String, dynamic>> _stocks = [];
  String _type = 'package';
  Uint8List? _photo;
  bool _removePhoto = false;
  bool _loading = true;
  bool _saving = false;
  String? _error;
  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final response =
          await widget.repository.formData(widget.storeId, widget.existing?.id);
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
        _sizes.add({
          'id': size['id'],
          'name': TextEditingController(text: size['size'].toString()),
          'stocks': {
            for (final stock in size['stocks'] as List? ?? [])
              stock['stock_id'].toString():
                  TextEditingController(text: stock['quantity'].toString())
          }
        });
      }
      if (mounted) setState(() => _loading = false);
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = 'Mahsulot formasini olish uchun serverga ulaning.';
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
      (size['name'] as TextEditingController).dispose();
      for (final controller in (size['stocks'] as Map).values) {
        (controller as TextEditingController).dispose();
      }
    }
    super.dispose();
  }

  int _integer(TextEditingController controller) {
    final value = int.tryParse(controller.text.trim());
    if (value == null || value < 0) {
      throw ArgumentError(
          'Narx va qoldiqlar butun manfiy bo‘lmagan son bo‘lsin.');
    }
    return value;
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final payload = <String, dynamic>{
        'name': _name.text.trim(),
        'barcode': _barcode.text.trim(),
        'price': _integer(_price),
        'initial_price': _integer(_cost),
        'type': _type,
        'remove_image': _removePhoto ? 1 : 0
      };
      if (_type == 'package') {
        payload['stocks'] = _stockValues.entries
            .map((e) => {'stock_id': e.key, 'quantity': _integer(e.value)})
            .toList();
      } else {
        payload['sizes'] = _sizes
            .map((size) => {
                  if (size['id'] != null) 'id': size['id'],
                  'size': (size['name'] as TextEditingController).text.trim(),
                  'stocks': (size['stocks']
                          as Map<String, TextEditingController>)
                      .entries
                      .map((e) =>
                          {'stock_id': e.key, 'quantity': _integer(e.value)})
                      .toList(),
                })
            .toList();
      }
      final product = await widget.repository.saveProduct(
          widget.storeId, payload,
          productId: widget.existing?.id, photo: _photo);
      if (mounted) {
        Navigator.pop(context);
        widget.onCreated(product);
      }
    } catch (error) {
      if (mounted) {
        setState(() {
          _saving = false;
          _error = error.toString();
        });
      }
    }
  }

  Future<void> _pickPhoto() async {
    final image = await ImagePicker().pickImage(
        source: ImageSource.gallery,
        maxWidth: 1600,
        maxHeight: 1600,
        imageQuality: 80);
    if (image == null) return;
    final bytes = await image.readAsBytes();
    if (mounted) {
      setState(() {
        _photo = bytes;
        _removePhoto = false;
      });
    }
  }

  Widget _field(TextEditingController controller, String label,
          {bool numeric = false}) =>
      Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: TextField(
              controller: controller,
              keyboardType: numeric ? TextInputType.number : TextInputType.text,
              decoration: InputDecoration(labelText: label)));

  @override
  Widget build(BuildContext context) => Dialog(
      child: SizedBox(
          width: 600,
          height: MediaQuery.sizeOf(context).height * 0.85,
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : Column(children: [
                  Padding(
                      padding: const EdgeInsets.all(16),
                      child: Text(
                          widget.existing == null
                              ? 'Yangi mahsulot'
                              : 'Mahsulotni tahrirlash',
                          style: const TextStyle(
                              fontSize: 18, fontWeight: FontWeight.bold))),
                  Expanded(
                      child: ListView(
                          padding: const EdgeInsets.symmetric(horizontal: 20),
                          children: [
                        const Text(
                            'Mahsulot o‘zgarishlari serverga ulangan holda saqlanadi.'),
                        if (_error != null)
                          Text(_error!,
                              style: const TextStyle(color: Colors.red)),
                        _field(_name, 'Mahsulot nomi'),
                        _field(_barcode, 'Shtrix-kod', numeric: true),
                        Row(children: [
                          TextButton(
                              onPressed: _saving
                                  ? null
                                  : () async {
                                      try {
                                        final code = await widget.repository
                                            .requestBarcode(widget.storeId);
                                        if (mounted) {
                                          setState(() => _barcode.text = code);
                                        }
                                      } catch (_) {
                                        if (mounted) {
                                          setState(() => _error =
                                              'Shtrix-kod olish uchun serverga ulaning.');
                                        }
                                      }
                                    },
                              child: const Text('Kod yaratish')),
                          TextButton(
                              onPressed: _saving
                                  ? null
                                  : () => showModalBottomSheet(
                                      context: context,
                                      isScrollControlled: true,
                                      builder: (_) => BarcodeScannerSheet(
                                          onBarcodeScanned: (value) => setState(
                                              () => _barcode.text = value))),
                              child: const Text('Skanerlash')),
                        ]),
                        _field(_cost, 'Kelish narxi (so‘m)', numeric: true),
                        _field(_price, 'Sotish narxi (so‘m)', numeric: true),
                        DropdownButtonFormField<String>(
                            value: _type,
                            decoration: const InputDecoration(
                                labelText: 'Mahsulot turi'),
                            items: const [
                              DropdownMenuItem(
                                  value: 'package', child: Text('Qadoq')),
                              DropdownMenuItem(
                                  value: 'size', child: Text('Razmerli'))
                            ],
                            onChanged: _saving
                                ? null
                                : (value) async {
                                    if (value == null || value == _type) return;
                                    final confirmed = widget.existing == null ||
                                        await showDialog<bool>(
                                                context: context,
                                                builder: (ctx) => AlertDialog(
                                                        title: const Text(
                                                            'Mahsulot turini almashtirish'),
                                                        content: const Text(
                                                            'Qoldiq tuzilmasi o‘zgaradi. Davom etilsinmi?'),
                                                        actions: [
                                                          TextButton(
                                                              onPressed: () =>
                                                                  Navigator.pop(
                                                                      ctx,
                                                                      false),
                                                              child: const Text(
                                                                  'Bekor qilish')),
                                                          TextButton(
                                                              onPressed: () =>
                                                                  Navigator.pop(
                                                                      ctx,
                                                                      true),
                                                              child: const Text(
                                                                  'Davom etish'))
                                                        ])) ==
                                            true;
                                    if (confirmed && mounted) {
                                      setState(() => _type = value);
                                    }
                                  }),
                        if (_type == 'package')
                          ..._stocks.map((stock) => _field(
                              _stockValues[stock['id'].toString()]!,
                              'Ombor: ${stock['name']}',
                              numeric: true)),
                        if (_type == 'size') ...[
                          ..._sizes.map((size) => Card(
                              child: Padding(
                                  padding: const EdgeInsets.all(12),
                                  child: Column(children: [
                                    _field(
                                        size['name'] as TextEditingController,
                                        'Razmer'),
                                    ..._stocks.map((stock) {
                                      final values = size['stocks']
                                          as Map<String, TextEditingController>;
                                      final controller = values.putIfAbsent(
                                          stock['id'].toString(),
                                          () =>
                                              TextEditingController(text: '0'));
                                      return _field(
                                          controller, 'Ombor: ${stock['name']}',
                                          numeric: true);
                                    }),
                                    TextButton(
                                        onPressed: () =>
                                            setState(() => _sizes.remove(size)),
                                        child: const Text(
                                            'Razmerni olib tashlash')),
                                  ])))),
                          TextButton.icon(
                              onPressed: () => setState(() => _sizes.add({
                                    'name': TextEditingController(),
                                    'stocks': <String, TextEditingController>{}
                                  })),
                              icon: const Icon(Icons.add),
                              label: const Text('Razmer qo‘shish')),
                        ],
                        if (_photo != null)
                          SizedBox(height: 120, child: Image.memory(_photo!)),
                        TextButton.icon(
                            onPressed: _saving ? null : _pickPhoto,
                            icon: const Icon(Icons.photo),
                            label: const Text('Foto tanlash')),
                        if (widget.existing != null)
                          CheckboxListTile(
                              value: _removePhoto,
                              title: const Text('Mavjud fotoni o‘chirish'),
                              onChanged: (value) => setState(() {
                                    _removePhoto = value ?? false;
                                    if (_removePhoto) _photo = null;
                                  })),
                      ])),
                  Padding(
                      padding: const EdgeInsets.all(16),
                      child: Row(
                          mainAxisAlignment: MainAxisAlignment.end,
                          children: [
                            TextButton(
                                onPressed: _saving
                                    ? null
                                    : () => Navigator.pop(context),
                                child: const Text('Bekor qilish')),
                            const SizedBox(width: 12),
                            ElevatedButton(
                                onPressed: _saving ? null : _save,
                                child: Text(
                                    _saving ? 'Saqlanmoqda...' : 'Saqlash')),
                          ])),
                ])));
}
