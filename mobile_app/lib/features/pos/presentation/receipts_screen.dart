import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../../core/database/app_database.dart';
import '../../../core/utils/currency_format.dart';
import '../../printing/presentation/print_dialog.dart';

class ReceiptsScreen extends StatefulWidget {
  final String storeId;
  final bool canPrint;
  const ReceiptsScreen(
      {super.key, required this.storeId, this.canPrint = false});
  @override
  State<ReceiptsScreen> createState() => _ReceiptsScreenState();
}

class _ReceiptsScreenState extends State<ReceiptsScreen> {
  List<Map<String, Object?>>? _sales;
  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final rows = await (await AppDatabase.getInstance()).query('offline_sales',
        where: 'store_id = ?',
        whereArgs: [widget.storeId],
        orderBy: 'occurred_at DESC');
    if (mounted) setState(() => _sales = rows);
  }

  String _status(String status) =>
      const {
        'synced': 'Server tasdiqlagan',
        'pending': 'Tasdiq kutilmoqda',
        'conflict': 'Administrator qarori kutilmoqda',
        'rejected': 'Server rad etgan',
        'blocked': 'Oldingi amal kutilmoqda'
      }[status] ??
      'Qayta tekshiriladi';
  void _show(Map<String, Object?> sale) {
    final lines = jsonDecode(sale['items_json'] as String) as List;
    final payment = jsonDecode(sale['payment_json'] as String? ?? '{}') as Map;
    final text = [
      'Baby Parfume · CHEK NUSXASI',
      'Lokal raqam: ${sale['local_sale_id']}',
      if (sale['server_sale_id'] != null)
        'Server raqami: ${sale['server_sale_id']}',
      'Vaqt: ${sale['occurred_at']}',
      _status(sale['sync_status'] as String),
      'To‘lov turi: ${const {
            'cash': 'Naqd',
            'card': 'Karta',
            'debt': 'Qarz',
            'transfer': 'O‘tkazma',
            'mixed': 'Aralash',
            'partial': 'Qisman',
            'preorder': 'Oldindan buyurtma'
          }[sale['payment_type']] ?? sale['payment_type']}',
      ...lines.map((line) =>
          '${line['name'] ?? line['product_id']} · ${line['quantity']} × ${line['unit_price']}'),
      'Jami: ${CurrencyFormat.format(sale['total_amount'] as num)}',
      'Chek chegirmasi: ${CurrencyFormat.format(payment['receipt_discount'] as num? ?? 0)}',
      'Naqd: ${CurrencyFormat.format(payment['cash'] as num? ?? 0)} · Karta: ${CurrencyFormat.format(payment['card'] as num? ?? 0)}',
      if ((payment['note']?.toString() ?? '').isNotEmpty)
        'Izoh: ${payment['note']}',
      'To‘langan: ${CurrencyFormat.format(sale['paid_amount'] as num)}',
      if (payment['received_cash'] != null)
        'Olingan naqd: ${CurrencyFormat.format(payment['received_cash'] as num)}',
      'Qaytim: ${CurrencyFormat.format(payment['change'] as num? ?? 0)}',
      'Qarz: ${CurrencyFormat.format(sale['remaining_amount'] as num)}'
    ].join('\n');
    showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
                title: const Text('Saqlangan chek'),
                content: SingleChildScrollView(child: SelectableText(text)),
                actions: [
                  if (widget.canPrint)
                    TextButton(
                        onPressed: () {
                          Navigator.pop(ctx);
                          showDialog<void>(
                              context: context,
                              barrierDismissible: false,
                              builder: (_) => PrintDialog(
                                  storeId: widget.storeId,
                                  receiptId: sale['local_sale_id'].toString()));
                        },
                        child: const Text('Chop etish')),
                  TextButton(
                      onPressed: () => Navigator.pop(ctx),
                      child: const Text('Yopish')),
                  TextButton(
                      onPressed: () async {
                        await Clipboard.setData(ClipboardData(text: text));
                        if (ctx.mounted) {
                          ScaffoldMessenger.of(ctx).showSnackBar(const SnackBar(
                              content: Text('Chek matni nusxalandi.')));
                        }
                      },
                      child: const Text('Nusxa olish')),
                ]));
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Saqlangan cheklar'), actions: [
          if (widget.canPrint)
            IconButton(
                tooltip: 'Chop etish tarixi',
                icon: const Icon(Icons.history),
                onPressed: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                        builder: (_) =>
                            PrintHistoryScreen(storeId: widget.storeId)))),
        ]),
        body: _sales == null
            ? const Center(child: CircularProgressIndicator())
            : _sales!.isEmpty
                ? const Center(child: Text('Lokal cheklar mavjud emas.'))
                : RefreshIndicator(
                    onRefresh: _load,
                    child: ListView.builder(
                        itemCount: _sales!.length,
                        itemBuilder: (_, index) {
                          final sale = _sales![index];
                          return ListTile(
                              onTap: () => _show(sale),
                              leading: const Icon(Icons.receipt_long),
                              title: Text(CurrencyFormat.format(
                                  sale['total_amount'] as num)),
                              subtitle: Text(
                                  '${sale['occurred_at']}\n${_status(sale['sync_status'] as String)}'),
                              isThreeLine: true);
                        })),
      );
}
