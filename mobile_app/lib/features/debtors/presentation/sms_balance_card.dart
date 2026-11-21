import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_format.dart';
import '../data/sms_balance_controller.dart';

class SmsBalanceCard extends StatelessWidget {
  final SmsBalance? balance;
  final bool loading;
  final VoidCallback onRefresh;
  final bool compact;
  const SmsBalanceCard(
      {super.key,
      this.balance,
      this.compact = false,
      required this.loading,
      required this.onRefresh});

  @override
  Widget build(BuildContext context) {
    final colors = AppColors.of(context);
    final stale = balance?.isStale(DateTime.now()) ?? true;
    final time = balance?.updatedAt;
    final timestamp = time == null
        ? null
        : DateFormat('dd.MM HH:mm')
            .format(time.toUtc().add(const Duration(hours: 5)));
    final detail = timestamp == null
        ? (loading ? 'Eskiz · yuklanmoqda…' : 'Eskiz · balans olinmadi')
        : 'Eskiz · $timestamp${stale ? ' · eskirgan ma’lumot' : ''}';
    if (compact) {
      return Container(
        key: const ValueKey('sms-balance-summary'),
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
            color: colors.card,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: colors.line)),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Expanded(
                  child: Tooltip(
                      message: 'Umumiy SMS balansi · barcha do‘konlar uchun',
                      child: Text('SMS balansi',
                          style:
                              TextStyle(fontSize: 11, color: colors.muted)))),
              SizedBox(
                  width: 28,
                  height: 32,
                  child: loading
                      ? const Padding(
                          padding: EdgeInsets.all(6),
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : IconButton(
                          tooltip: 'SMS balansini yangilash',
                          padding: EdgeInsets.zero,
                          onPressed: onRefresh,
                          icon: Icon(Icons.refresh_rounded,
                              size: 17, color: colors.muted))),
            ]),
            const SizedBox(height: 4),
            Text(
                balance?.amount == null
                    ? '—'
                    : CurrencyFormat.formatExact(balance!.amount!),
                style: TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.w700,
                    color: colors.text)),
            Text('so‘m', style: TextStyle(fontSize: 10, color: colors.muted)),
            const SizedBox(height: 6),
            Text(
                timestamp == null
                    ? (loading ? 'Yuklanmoqda…' : 'Olinmadi')
                    : 'Eskiz · $timestamp',
                style: TextStyle(fontSize: 10, color: colors.muted)),
            if (stale && timestamp != null)
              Text('Eskirgan ma’lumot',
                  style: TextStyle(fontSize: 10, color: colors.muted)),
          ],
        ),
      );
    }
    return Container(
        margin: const EdgeInsets.fromLTRB(16, 8, 16, 4),
        padding: const EdgeInsets.fromLTRB(12, 10, 4, 10),
        decoration: BoxDecoration(
            color: colors.card,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: colors.line)),
        child: Row(children: [
          Icon(Icons.sms_outlined, size: 22, color: colors.muted),
          const SizedBox(width: 10),
          Expanded(
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                Text('Umumiy SMS balansi',
                    style: TextStyle(fontSize: 12, color: colors.muted)),
                const SizedBox(height: 3),
                Text(
                    balance?.amount == null
                        ? '—'
                        : CurrencyFormat.format(balance!.amount!,
                            suffix: 'so‘m'),
                    style: TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.w700,
                        color: colors.text)),
                const SizedBox(height: 3),
                Text(detail,
                    style: TextStyle(fontSize: 10, color: colors.muted)),
              ])),
          if (loading)
            const Padding(
                padding: EdgeInsets.all(14),
                child: SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2)))
          else
            IconButton(
                tooltip: 'SMS balansini yangilash',
                onPressed: onRefresh,
                icon: Icon(Icons.refresh_rounded, color: colors.muted)),
        ]));
  }
}
