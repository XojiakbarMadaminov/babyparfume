import '../../../core/theme/app_colors.dart';
import 'package:flutter/material.dart';
import '../../../core/sync/offline_sale_service.dart';
import '../data/clients_repository.dart';
import 'pos_widgets.dart';

class PosPaymentDraft {
  String type;
  String cash;
  String card;
  String note;
  LocalClient? customer;

  PosPaymentDraft(
      {required this.type,
      required this.cash,
      required this.card,
      required this.note,
      this.customer});

  double get cashAmount =>
      double.tryParse(cash.replaceAll(' ', '').replaceAll(',', '.')) ?? 0;
  double get cardAmount =>
      double.tryParse(card.replaceAll(' ', '').replaceAll(',', '.')) ?? 0;
}

class PosCheckoutSheet extends StatefulWidget {
  final double total;
  final PosPaymentDraft draft;
  final List<LocalClient> customers;
  final Future<LocalClient?> Function() onNewCustomer;
  final ValueChanged<PosPaymentDraft> onChanged;
  final Future<OfflineSaleResult> Function(PosPaymentDraft) onSubmit;

  const PosCheckoutSheet(
      {super.key,
      required this.total,
      required this.draft,
      required this.customers,
      required this.onNewCustomer,
      required this.onChanged,
      required this.onSubmit});

  @override
  State<PosCheckoutSheet> createState() => _PosCheckoutSheetState();
}

class _PosCheckoutSheetState extends State<PosCheckoutSheet> {
  bool _saving = false;
  String? _error;
  late final TextEditingController _note;
  PosPaymentDraft get _draft => widget.draft;
  bool get _needsCustomer => !['cash', 'card'].contains(_draft.type);
  bool get _split => ['mixed', 'partial'].contains(_draft.type);
  bool get _compact => MediaQuery.sizeOf(context).height < 950;

  static const _methods = <String, (String, IconData)>{
    'cash': ('Naqd', Icons.payments_outlined),
    'card': ('Karta', Icons.credit_card),
    'mixed': ('Aralash', Icons.grid_view_outlined),
    'debt': ('Qarzga', Icons.menu_book_outlined),
    'partial': ('Qisman', Icons.payments_outlined),
    'transfer': ('O‘tkazma', Icons.account_balance_outlined),
    'preorder': ('Oldindan buyurtma', Icons.schedule),
  };

  @override
  void initState() {
    super.initState();
    _note = TextEditingController(text: _draft.note);
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  void _change(VoidCallback change) {
    setState(() {
      change();
      _error = null;
    });
    widget.onChanged(_draft);
  }

  Future<void> _customer() async {
    final selected = await showModalBottomSheet<LocalClient>(
        context: context,
        isScrollControlled: true,
        builder: (_) => FractionallySizedBox(
            heightFactor: .88,
            child: PosCustomerPicker(
                customers: widget.customers,
                selectedId: _draft.customer?.id,
                onNewCustomer: widget.onNewCustomer)));
    if (selected != null && mounted) _change(() => _draft.customer = selected);
  }

  Future<void> _submit() async {
    if (_saving) return;
    String? error;
    if (_needsCustomer && _draft.customer == null) {
      error = 'Bu to‘lov turi uchun mijozni tanlang.';
    } else if (_draft.type == 'cash' && _draft.cashAmount < widget.total) {
      error = 'Qabul qilingan naqd summa jami summadan kam.';
    } else if (_split &&
        (!(_draft.cashAmount >= 0 && _draft.cardAmount >= 0) ||
            (_draft.type == 'mixed' &&
                (_draft.cashAmount + _draft.cardAmount - widget.total).abs() >
                    .001) ||
            (_draft.type == 'partial' &&
                (_draft.cashAmount + _draft.cardAmount <= 0 ||
                    _draft.cashAmount + _draft.cardAmount >= widget.total)))) {
      error = _draft.type == 'mixed'
          ? 'Naqd va karta summalari jamiga teng bo‘lishi kerak.'
          : 'Qisman to‘lov 0 dan katta va jami summadan kam bo‘lishi kerak.';
    }
    if (error != null) {
      setState(() => _error = error);
      return;
    }
    setState(() => _saving = true);
    try {
      final result = await widget.onSubmit(_draft);
      if (mounted) Navigator.pop(context, result);
    } catch (error) {
      if (mounted) {
        setState(() {
          _saving = false;
          _error = error.toString().replaceFirst(RegExp(r'^[^:]+: '), '');
        });
      }
    }
  }

  Widget _amountField(
          String label, String value, ValueChanged<String> onChanged) =>
      TextFormField(
          initialValue: value,
          enabled: !_saving,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(labelText: label),
          onChanged: onChanged);

  Widget _cash() {
    final quick = <double>{
      widget.total,
      (widget.total / 10000).ceil() * 10000.0,
      (widget.total / 50000).ceil() * 50000.0,
      (widget.total / 100000).ceil() * 100000.0,
    }.toList()
      ..sort();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      PosPanel(
          borderColor: PosStyle.colors(context).blue,
          padding:
              EdgeInsets.symmetric(horizontal: 14, vertical: _compact ? 6 : 14),
          child: Row(children: [
            Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  Text('OLINDI  (Qabul qilindi)',
                      style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          color: PosStyle.colors(context).muted)),
                  const SizedBox(height: 2),
                  Text(
                      '${PosStyle.money(_draft.cashAmount)}${_draft.cash.endsWith('.') ? '.' : ''}',
                      key: const ValueKey('cash-received'),
                      style: TextStyle(
                          fontSize: _compact ? 22 : 26,
                          height: 1.2,
                          fontWeight: FontWeight.w800)),
                ])),
            IconButton(
                tooltip: 'Naqd summani tozalash',
                onPressed:
                    _saving ? null : () => _change(() => _draft.cash = ''),
                icon: Icon(Icons.close, color: PosStyle.colors(context).muted))
          ])),
      SizedBox(height: _compact ? 6 : 10),
      Wrap(spacing: 8, runSpacing: 8, children: [
        for (final amount in quick)
          SizedBox(
              width:
                  (MediaQuery.sizeOf(context).width - (_compact ? 32 : 40)) / 2,
              child: PosButton(
                  label: PosStyle.money(amount),
                  outlined: true,
                  height: _compact ? 40 : 50,
                  onPressed: _saving
                      ? null
                      : () => _change(() => _draft.cash = amount.toString()))),
      ]),
      SizedBox(height: _compact ? 6 : 10),
      PosPanel(
          padding:
              EdgeInsets.symmetric(horizontal: 14, vertical: _compact ? 8 : 14),
          color: AppColors.of(context).successBackground,
          borderColor: PosStyle.colors(context).green.withOpacity(.3),
          child:
              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('Qaytim',
                style: TextStyle(
                    color: PosStyle.colors(context).green,
                    fontWeight: FontWeight.w600)),
            Flexible(
                child: Text(
                    PosStyle.money((_draft.cashAmount - widget.total)
                        .clamp(0, double.infinity)),
                    key: const ValueKey('cash-change'),
                    style: TextStyle(
                        color: PosStyle.colors(context).green,
                        fontSize: 18,
                        fontWeight: FontWeight.w800))),
          ])),
      SizedBox(height: _compact ? 6 : 10),
      PosKeypad(
          keyHeight: _compact ? 44 : 52,
          spacing: _compact ? 6 : 8,
          onKey: _saving
              ? null
              : (key) =>
                  _change(() => _draft.cash = posNumericKey(_draft.cash, key))),
    ]);
  }

  @override
  Widget build(BuildContext context) => PopScope(
      canPop: !_saving,
      child: SafeArea(
          top: false,
          child: Padding(
              padding: EdgeInsets.only(
                  bottom: MediaQuery.viewInsetsOf(context).bottom),
              child: Column(children: [
                PosSheetHeader(
                    title: 'To‘lov',
                    leading: PosIconButton(
                        icon: Icons.arrow_back,
                        label: 'Savatga qaytish',
                        onPressed:
                            _saving ? null : () => Navigator.pop(context)),
                    onClose: _saving ? null : () => Navigator.pop(context)),
                Expanded(
                    child: SingleChildScrollView(
                        padding: EdgeInsets.all(_compact ? 12 : 16),
                        child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              Text('TO‘LOVGA',
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                      fontSize: 11,
                                      height: 1.2,
                                      color: PosStyle.colors(context).muted,
                                      letterSpacing: .8)),
                              const SizedBox(height: 2),
                              Text('${PosStyle.money(widget.total)} UZS',
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                      fontSize: _compact ? 24 : 28,
                                      height: 1.1,
                                      fontWeight: FontWeight.w800)),
                              SizedBox(height: _compact ? 10 : 18),
                              SingleChildScrollView(
                                  scrollDirection: Axis.horizontal,
                                  child: Row(children: [
                                    for (final entry in _methods.entries) ...[
                                      if (entry.key != 'cash')
                                        const SizedBox(width: 8),
                                      Semantics(
                                          selected: _draft.type == entry.key,
                                          child: InkWell(
                                              borderRadius:
                                                  BorderRadius.circular(12),
                                              onTap: _saving
                                                  ? null
                                                  : () => _change(() =>
                                                      _draft.type = entry.key),
                                              child: PosPanel(
                                                  color: _draft.type ==
                                                          entry.key
                                                      ? PosStyle.colors(context)
                                                          .blue
                                                          .withOpacity(.14)
                                                      : PosStyle.colors(context)
                                                          .inset,
                                                  borderColor: _draft.type ==
                                                          entry.key
                                                      ? PosStyle.colors(context)
                                                          .blue
                                                      : null,
                                                  padding: EdgeInsets.symmetric(
                                                      horizontal: 16,
                                                      vertical:
                                                          _compact ? 8 : 10),
                                                  child: Column(children: [
                                                    Icon(entry.value.$2,
                                                        color: _draft.type ==
                                                                entry.key
                                                            ? PosStyle.colors(
                                                                    context)
                                                                .blue
                                                            : PosStyle.colors(
                                                                    context)
                                                                .muted,
                                                        size: 20),
                                                    const SizedBox(height: 5),
                                                    Text(entry.value.$1,
                                                        style: const TextStyle(
                                                            fontSize: 11,
                                                            fontWeight:
                                                                FontWeight
                                                                    .w600))
                                                  ]))))
                                    ]
                                  ])),
                              SizedBox(height: _compact ? 8 : 16),
                              if (_draft.type == 'cash') _cash(),
                              if (['card', 'transfer'].contains(_draft.type))
                                PosPanel(
                                    child: Text(
                                        'To‘lov summasi: ${PosStyle.money(widget.total)} so‘m. Bank yoki terminal orqali to‘lovni tekshiring.',
                                        style: TextStyle(
                                            color:
                                                PosStyle.colors(context).muted,
                                            height: 1.5))),
                              if (_split) ...[
                                _amountField(
                                    'Naqd (so‘m)',
                                    _draft.cash,
                                    (value) =>
                                        _change(() => _draft.cash = value)),
                                const SizedBox(height: 12),
                                _amountField(
                                    'Karta (so‘m)',
                                    _draft.card,
                                    (value) =>
                                        _change(() => _draft.card = value)),
                                if (_draft.type == 'partial') ...[
                                  const SizedBox(height: 12),
                                  Text(
                                      'Qarz: ${PosStyle.money((widget.total - _draft.cashAmount - _draft.cardAmount).clamp(0, double.infinity))} so‘m',
                                      style: TextStyle(
                                          color:
                                              PosStyle.colors(context).accent)),
                                ]
                              ],
                              if (_needsCustomer ||
                                  _draft.customer != null) ...[
                                const SizedBox(height: 16),
                                PosButton(
                                    label: _draft.customer?.name ??
                                        'Mijozni tanlang',
                                    icon: Icons.person_add_alt_1_outlined,
                                    outlined: true,
                                    onPressed: _saving ? null : _customer),
                                if (_draft.customer == null)
                                  Padding(
                                      padding: const EdgeInsets.only(top: 8),
                                      child: Text(
                                          'Mijozsiz faqat naqd yoki karta. Boshqa to‘lov uchun mijozni tanlang.',
                                          style: TextStyle(
                                              color: PosStyle.colors(context)
                                                  .danger,
                                              fontSize: 12))),
                              ],
                              const SizedBox(height: 16),
                              TextField(
                                  controller: _note,
                                  enabled: !_saving,
                                  maxLength: 500,
                                  maxLines: 2,
                                  decoration: const InputDecoration(
                                      labelText: 'To‘lov izohi (ixtiyoriy)',
                                      counterText: ''),
                                  onChanged: (value) =>
                                      _change(() => _draft.note = value)),
                              if (_error != null)
                                Padding(
                                    padding: const EdgeInsets.only(top: 12),
                                    child: Text(_error!,
                                        key: const ValueKey('payment-error'),
                                        style: TextStyle(
                                            color: PosStyle.colors(context)
                                                .danger))),
                            ]))),
                Padding(
                    padding: const EdgeInsets.fromLTRB(16, 10, 16, 16),
                    child: Row(children: [
                      PosButton(
                          label: 'Orqaga',
                          outlined: true,
                          onPressed:
                              _saving ? null : () => Navigator.pop(context)),
                      const SizedBox(width: 10),
                      Expanded(
                          child: PosButton(
                              label: _saving
                                  ? 'Saqlanmoqda...'
                                  : 'Savdoni yakunlash',
                              icon: Icons.check,
                              color: PosStyle.colors(context).blue,
                              loading: _saving,
                              onPressed: _submit))
                    ]))
              ]))));
}

class PosCustomerPicker extends StatefulWidget {
  final List<LocalClient> customers;
  final String? selectedId;
  final Future<LocalClient?> Function() onNewCustomer;

  const PosCustomerPicker(
      {super.key,
      required this.customers,
      this.selectedId,
      required this.onNewCustomer});

  @override
  State<PosCustomerPicker> createState() => _PosCustomerPickerState();
}

class _PosCustomerPickerState extends State<PosCustomerPicker> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final customers = widget.customers.where((client) =>
        client.name.toLowerCase().contains(_query.toLowerCase()) ||
        (client.phone ?? '').contains(_query));
    return SafeArea(
        top: false,
        child: Column(children: [
          PosSheetHeader(title: 'Mijoz', onClose: () => Navigator.pop(context)),
          Padding(
              padding: const EdgeInsets.all(16),
              child: TextField(
                  decoration: const InputDecoration(
                      hintText: 'Ism yoki telefon bilan qidiring',
                      prefixIcon: Icon(Icons.search)),
                  onChanged: (value) => setState(() => _query = value))),
          Expanded(
              child: customers.isEmpty
                  ? Center(
                      child: Text('Mijoz topilmadi',
                          style:
                              TextStyle(color: PosStyle.colors(context).muted)))
                  : ListView(children: [
                      for (final client in customers)
                        ListTile(
                            leading: CircleAvatar(
                                backgroundColor: PosStyle.colors(context).card,
                                child: Icon(Icons.person_outline,
                                    color: PosStyle.colors(context).muted)),
                            title: Text(client.name),
                            subtitle: Text(client.phone ?? '',
                                style: TextStyle(
                                    color: PosStyle.colors(context).muted)),
                            trailing: client.id == widget.selectedId
                                ? Icon(Icons.check,
                                    color: PosStyle.colors(context).accent)
                                : null,
                            onTap: () => Navigator.pop(context, client))
                    ])),
          Padding(
              padding: const EdgeInsets.all(16),
              child: SizedBox(
                  width: double.infinity,
                  child: PosButton(
                      label: 'Yangi mijoz',
                      icon: Icons.person_add_alt_1,
                      onPressed: () async {
                        final client = await widget.onNewCustomer();
                        if (client != null && context.mounted) {
                          Navigator.pop(context, client);
                        }
                      })))
        ]));
  }
}
