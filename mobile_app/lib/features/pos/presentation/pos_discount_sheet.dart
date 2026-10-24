import 'package:flutter/material.dart';
import 'pos_widgets.dart';

class PosDiscountSheet extends StatefulWidget {
  final String title;
  final double total;
  final double discount;

  const PosDiscountSheet(
      {super.key,
      required this.title,
      required this.total,
      required this.discount});

  @override
  State<PosDiscountSheet> createState() => _PosDiscountSheetState();
}

class _PosDiscountSheetState extends State<PosDiscountSheet> {
  late String _value;
  bool _percent = false;
  bool _replaceValue = true;

  double get _amount => _percent
      ? widget.total * (double.tryParse(_value) ?? 0) / 100
      : double.tryParse(_value) ?? 0;

  @override
  void initState() {
    super.initState();
    _value = widget.discount.toStringAsFixed(2);
  }

  void _switchMode(bool percent) {
    if (percent == _percent) return;
    final amount = _amount;
    setState(() {
      _percent = percent;
      _replaceValue = true;
      _value =
          (percent && widget.total > 0 ? amount / widget.total * 100 : amount)
              .toStringAsFixed(2);
    });
  }

  @override
  Widget build(BuildContext context) => SafeArea(
      top: false,
      child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
        PosSheetHeader(
            title: 'Chegirma', onClose: () => Navigator.pop(context)),
        Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(widget.title,
                      style: TextStyle(color: PosStyle.colors(context).muted)),
                  const SizedBox(height: 16),
                  Row(children: [
                    Expanded(
                        child: PosChip(
                            label: 'Summa (UZS)',
                            selected: !_percent,
                            onPressed: () => _switchMode(false))),
                    const SizedBox(width: 8),
                    Expanded(
                        child: PosChip(
                            label: 'Foiz (%)',
                            selected: _percent,
                            onPressed: () => _switchMode(true))),
                  ]),
                  const SizedBox(height: 16),
                  PosPanel(
                      borderColor: PosStyle.colors(context).blue,
                      child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('CHEGIRMA',
                                style: TextStyle(
                                    fontSize: 11,
                                    color: PosStyle.colors(context).muted)),
                            Text(
                                '${PosStyle.money(double.tryParse(_value) ?? 0)} ${_percent ? '%' : 'UZS'}',
                                style: const TextStyle(
                                    fontSize: 30, fontWeight: FontWeight.w800)),
                            const Divider(height: 24),
                            Text('Jami: ${PosStyle.money(widget.total)} UZS'),
                            const SizedBox(height: 6),
                            Text(
                                'Chegirmadan so‘ng: ${PosStyle.money((widget.total - _amount).clamp(0, widget.total))} UZS',
                                style: TextStyle(
                                    color: PosStyle.colors(context).green)),
                          ])),
                  const SizedBox(height: 12),
                  Row(children: [
                    for (final percent in [5, 10, 15, 20]) ...[
                      if (percent != 5) const SizedBox(width: 8),
                      Expanded(
                          child: PosChip(
                              label: '$percent%',
                              onPressed: () => setState(() {
                                    _percent = true;
                                    _value = '$percent';
                                    _replaceValue = true;
                                  })))
                    ]
                  ]),
                  const SizedBox(height: 12),
                  PosKeypad(
                      onKey: (key) => setState(() {
                            final next = posNumericKey(
                                _replaceValue && key != 'backspace'
                                    ? ''
                                    : _value,
                                key);
                            if (!_percent ||
                                (double.tryParse(next) ?? 0) <= 100) {
                              _value = next;
                              _replaceValue = false;
                            }
                          })),
                  const SizedBox(height: 16),
                  Row(children: [
                    Expanded(
                        child: PosButton(
                            label: 'Olib tashlash',
                            outlined: true,
                            foreground: PosStyle.colors(context).danger,
                            onPressed: () => Navigator.pop(context, 0.0))),
                    const SizedBox(width: 10),
                    Expanded(
                        child: PosButton(
                            label: 'Qo‘llash',
                            color: PosStyle.colors(context).blue,
                            onPressed: _amount >= 0 && _amount <= widget.total
                                ? () => Navigator.pop(context,
                                    double.parse(_amount.toStringAsFixed(2)))
                                : null)),
                  ])
                ]))
      ])));
}
