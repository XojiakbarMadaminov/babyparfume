import 'package:flutter/material.dart';
import '../../products/data/products_repository.dart';
import 'pos_widgets.dart';

class PosVariantsSheet extends StatefulWidget {
  final String name;
  final List<LocalProduct> variants;
  final List<int> quantities;

  const PosVariantsSheet(
      {super.key,
      required this.name,
      required this.variants,
      required this.quantities});

  @override
  State<PosVariantsSheet> createState() => _PosVariantsSheetState();
}

class _PosVariantsSheetState extends State<PosVariantsSheet> {
  late final List<TextEditingController> _controllers;

  @override
  void initState() {
    super.initState();
    _controllers = [
      for (final quantity in widget.quantities)
        TextEditingController(text: '$quantity')
    ];
  }

  @override
  void dispose() {
    for (final controller in _controllers) {
      controller.dispose();
    }
    super.dispose();
  }

  void _step(int index, int delta) {
    final value = int.tryParse(_controllers[index].text) ?? 0;
    setState(() =>
        _controllers[index].text = '${(value + delta).clamp(0, 1000000)}');
  }

  @override
  Widget build(BuildContext context) => SafeArea(
      top: false,
      child: Column(children: [
        PosSheetHeader(
            title: widget.name, onClose: () => Navigator.pop(context)),
        Padding(
            padding: const EdgeInsets.all(16),
            child: Text('Ombor, razmer va miqdorni tanlang',
                style: TextStyle(color: PosStyle.colors(context).muted))),
        Expanded(
            child: ListView.separated(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                itemCount: widget.variants.length,
                separatorBuilder: (_, __) => const SizedBox(height: 10),
                itemBuilder: (_, index) {
                  final variant = widget.variants[index];
                  return PosPanel(
                      child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                        Text(
                            '${variant.sizeName ?? 'Qadoq'} · ${variant.stockName ?? 'Ombor ${variant.stockId}'}',
                            style:
                                const TextStyle(fontWeight: FontWeight.w600)),
                        const SizedBox(height: 6),
                        Text('Qoldiq: ${variant.stockQuantity} dona',
                            style: TextStyle(
                                color: PosStyle.colors(context).muted,
                                fontSize: 12)),
                        const SizedBox(height: 8),
                        Row(children: [
                          IconButton(
                              tooltip: 'Miqdorni kamaytirish',
                              onPressed: () => _step(index, -1),
                              icon: const Icon(Icons.remove)),
                          Expanded(
                              child: TextField(
                                  controller: _controllers[index],
                                  textAlign: TextAlign.center,
                                  keyboardType: TextInputType.number,
                                  decoration: const InputDecoration(
                                      suffixText: 'dona'))),
                          IconButton(
                              tooltip: 'Miqdorni oshirish',
                              onPressed: () => _step(index, 1),
                              icon: Icon(Icons.add,
                                  color: PosStyle.colors(context).blue)),
                        ])
                      ]));
                })),
        Padding(
            padding: const EdgeInsets.all(16),
            child: SizedBox(
                width: double.infinity,
                child: PosButton(
                    label: 'Saqlash',
                    onPressed: () => Navigator.pop(context, [
                          for (final controller in _controllers)
                            (int.tryParse(controller.text) ?? 0)
                                .clamp(0, 1000000)
                        ])))),
      ]));
}
