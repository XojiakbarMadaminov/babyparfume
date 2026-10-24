import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/sync/outbox_manager.dart';
import '../../products/data/products_repository.dart';
import '../../products/presentation/product_detail_screen.dart';
import '../data/statistics_repository.dart';
import 'statistics_trend_chart.dart';
import 'statistics_widgets.dart';

class StatisticsScreen extends StatefulWidget {
  final String storeId;
  final ApiClient apiClient;
  final StatisticsRepository? repository;
  final ProductsRepository? productsRepository;
  final DateTime Function()? now;
  final bool embedded;
  final bool canViewProducts;
  final VoidCallback? onBack;
  final ValueChanged<StatisticsProduct>? onProductSelected;

  const StatisticsScreen(
      {super.key,
      required this.storeId,
      required this.apiClient,
      this.repository,
      this.productsRepository,
      this.now,
      this.embedded = false,
      this.canViewProducts = false,
      this.onBack,
      this.onProductSelected});

  @override
  State<StatisticsScreen> createState() => _StatisticsScreenState();
}

class _StatisticsScreenState extends State<StatisticsScreen> {
  late StatisticsRepository _repository;
  late StatisticsFilter _filter;
  final _draftStart = TextEditingController();
  final _draftEnd = TextEditingController();
  final _dateFormat = DateFormat('dd.MM.yyyy');
  StatisticsResult? _result;
  bool _loading = true;
  String? _error;
  String? _rangeError;
  int _request = 0;
  CancelToken? _cancelToken;

  DateTime get _now => (widget.now ?? DateTime.now)();
  DateTime get _today => StatisticsFilter.tashkentDate(_now);
  bool get _awaitingRange =>
      _filter.period == 'range' &&
      (_draftStart.text != _dateFormat.format(_filter.start) ||
          _draftEnd.text != _dateFormat.format(_filter.end));

  @override
  void initState() {
    super.initState();
    _repository =
        widget.repository ?? StatisticsRepository(apiClient: widget.apiClient);
    _filter = StatisticsFilter.forPeriod('today', _now);
    _resetDraft();
    _load();
  }

  @override
  void didUpdateWidget(covariant StatisticsScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.storeId != widget.storeId ||
        oldWidget.repository != widget.repository ||
        oldWidget.apiClient != widget.apiClient) {
      _repository = widget.repository ??
          StatisticsRepository(apiClient: widget.apiClient);
      _filter = StatisticsFilter.forPeriod('today', _now);
      _resetDraft();
      _load();
    }
  }

  @override
  void dispose() {
    _cancelToken?.cancel();
    _draftStart.dispose();
    _draftEnd.dispose();
    super.dispose();
  }

  void _resetDraft() {
    _draftStart.text = _dateFormat.format(_filter.start);
    _draftEnd.text = _dateFormat.format(_filter.end);
    _rangeError = null;
  }

  Future<void> _load() async {
    _cancelToken?.cancel();
    final token = CancelToken();
    _cancelToken = token;
    final request = ++_request;
    final storeId = widget.storeId;
    final filter = _filter;
    setState(() {
      _loading = true;
      _error = null;
      _result = null;
    });
    try {
      final result =
          await _repository.load(storeId, filter, cancelToken: token);
      if (!mounted || request != _request) return;
      setState(() {
        _result = result;
        if (result.report == null) {
          _error =
              'Bu davr uchun server hisoboti yuklanmagan. Internetga ulanib, qayta urinib ko‘ring.';
        }
      });
    } catch (error) {
      if (!mounted ||
          request != _request ||
          (error is DioException && CancelToken.isCancel(error))) return;
      setState(() => _error = _describeError(error));
    } finally {
      if (mounted && request == _request) setState(() => _loading = false);
    }
  }

  String _describeError(Object error) {
    if (error is DioException) {
      return switch (error.response?.statusCode) {
        401 => 'Sessiya tugagan. Akkauntga qayta kiring.',
        403 => 'Sotuv statistikasini ko‘rish uchun ruxsat berilmagan.',
        422 => 'Sanalarni tekshiring. Davr 366 kundan oshmasin.',
        _ =>
          'Statistikani yuklab bo‘lmadi. Internetni tekshirib, qayta urinib ko‘ring.',
      };
    }
    if (error is FormatException) {
      return 'Hisobot tanlangan do‘kon yoki davrga mos emas. Qayta urinib ko‘ring.';
    }
    return 'Hisobot olinmadi. Qayta urinib ko‘ring.';
  }

  void _choosePeriod(String period) {
    setState(() {
      _filter = period == 'range'
          ? StatisticsFilter(
              period: period, start: _filter.start, end: _filter.end)
          : StatisticsFilter.forPeriod(period, _now);
      _resetDraft();
    });
    _load();
  }

  void _applyRange() {
    DateTime? start;
    DateTime? end;
    try {
      start = _dateFormat.parseStrict(_draftStart.text);
      end = _dateFormat.parseStrict(_draftEnd.text);
    } on FormatException {
      setState(
          () => _rangeError = 'Sanalarni kk.oo.yyyy ko‘rinishida kiriting.');
      return;
    }
    if (start.isAfter(end) ||
        end.isAfter(_today) ||
        start.isBefore(DateTime(2000))) {
      setState(() => _rangeError =
          'Sanalarni tekshiring: boshlanish tugashdan keyin, tugash esa bugundan keyin bo‘lmasin.');
      return;
    }
    if (end.difference(start).inDays > 366) {
      setState(() => _rangeError = 'Davr 366 kundan oshmasin.');
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() {
      _filter = StatisticsFilter(period: 'range', start: start!, end: end!);
      _rangeError = null;
    });
    _load();
  }

  Future<void> _pickDate(TextEditingController controller, String label) async {
    DateTime initial;
    try {
      initial = _dateFormat.parseStrict(controller.text);
    } on FormatException {
      initial = _today;
    }
    if (initial.isAfter(_today) || initial.isBefore(DateTime(2000))) {
      initial = _today;
    }
    final selected = await showDatePicker(
        context: context,
        firstDate: DateTime(2000),
        lastDate: _today,
        initialDate: initial,
        helpText: label,
        cancelText: 'Bekor qilish',
        confirmText: 'Tanlash',
        builder: (context, child) => Theme(
            data:
                StatisticsStyle.theme(brightness: Theme.of(context).brightness),
            child: child!));
    if (selected != null && mounted) {
      setState(() {
        controller.text = _dateFormat.format(selected);
        _rangeError = null;
      });
    }
  }

  void _back() {
    if (widget.onBack != null) {
      widget.onBack!();
    } else {
      Navigator.maybePop(context);
    }
  }

  Future<void> _openProduct(StatisticsProduct product) async {
    if (!widget.canViewProducts || product.id == null) return;
    if (widget.onProductSelected != null) {
      widget.onProductSelected!(product);
      return;
    }
    final repository = widget.productsRepository ??
        ProductsRepository(
            apiClient: widget.apiClient, outboxManager: OutboxManager());
    final local = await repository.getProduct(widget.storeId, product.id!);
    if (!mounted) return;
    if (local == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content:
              Text('Mahsulot qurilmada topilmadi. Server bilan yangilang.')));
      return;
    }
    Navigator.push(
        context,
        MaterialPageRoute(
            builder: (_) =>
                ProductDetailScreen(product: local, repository: repository)));
  }

  Widget _dateField(String label, TextEditingController controller) =>
      Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label,
            style: TextStyle(
                fontSize: 12, color: StatisticsStyle.colors(context).muted)),
        const SizedBox(height: 8),
        TextField(
            controller: controller,
            keyboardType: TextInputType.datetime,
            style: const TextStyle(fontSize: 13),
            onChanged: (_) => setState(() => _rangeError = null),
            decoration: InputDecoration(
                hintText: 'kk.oo.yyyy',
                labelText: label,
                floatingLabelBehavior: FloatingLabelBehavior.never,
                suffixIcon: IconButton(
                    tooltip: '$label sanasini tanlash',
                    onPressed: () => _pickDate(controller, label),
                    icon: Icon(Icons.calendar_month_outlined,
                        size: 18,
                        color: StatisticsStyle.colors(context).muted)))),
      ]);

  Widget _range() => StatisticsPanel(
      padding: const EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(child: _dateField('Boshlanish', _draftStart)),
          const SizedBox(width: 12),
          Expanded(child: _dateField('Tugash', _draftEnd))
        ]),
        if (_rangeError != null)
          Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(_rangeError!,
                  style: TextStyle(
                      fontSize: 12,
                      color: StatisticsStyle.colors(context).danger))),
        const SizedBox(height: 12),
        OutlinedButton(
            onPressed: _applyRange,
            style: OutlinedButton.styleFrom(
                minimumSize: const Size.fromHeight(49),
                foregroundColor: StatisticsStyle.colors(context).accent,
                side: BorderSide(color: StatisticsStyle.colors(context).accent),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14)),
                textStyle: const TextStyle(
                    fontFamily: 'Roboto',
                    fontSize: 14,
                    fontWeight: FontWeight.w600)),
            child: const Text('Oraliqni qo‘llash')),
      ]));

  Widget _hero(StatisticsReport? report) {
    final change = report?.changePercent;
    final color = change == null || change == 0
        ? StatisticsStyle.colors(context).muted
        : change > 0
            ? StatisticsStyle.colors(context).green
            : StatisticsStyle.colors(context).danger;
    return StatisticsPanel(
        hero: true,
        padding: const EdgeInsets.all(20),
        child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 138),
            child:
                Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                const Expanded(
                    child: Text('Jami savdo',
                        style: TextStyle(
                            fontSize: 16, fontWeight: FontWeight.w600))),
                Container(
                    width: 34,
                    height: 34,
                    decoration: BoxDecoration(
                        color: StatisticsStyle.colors(context).tint,
                        borderRadius: BorderRadius.circular(10)),
                    child: Icon(Icons.trending_up,
                        color: StatisticsStyle.colors(context).accent,
                        size: 20)),
              ]),
              const SizedBox(height: 12),
              Text(StatisticsStyle.money(report?.salesAmount),
                  key: const ValueKey('statistics-total'),
                  style: const TextStyle(
                      fontSize: 30,
                      fontWeight: FontWeight.w700,
                      height: 1.25,
                      letterSpacing: -.7)),
              const SizedBox(height: 12),
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                if (change != null) ...[
                  Icon(change < 0 ? Icons.trending_down : Icons.trending_up,
                      color: color, size: 16),
                  const SizedBox(width: 6)
                ],
                Expanded(
                    child: Text(
                        change == null
                            ? 'Oldingi davr bilan taqqoslash mavjud emas'
                            : '${change > 0 ? '+' : ''}${StatisticsStyle.number(change, decimals: 1)}% oldingi davrga nisbatan',
                        style: TextStyle(fontSize: 12, color: color))),
              ]),
            ])));
  }

  Widget _kpi(String label, String value, IconData icon,
          {bool unavailable = false}) =>
      StatisticsPanel(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 16),
          child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 78),
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Icon(icon,
                          size: 15,
                          color: StatisticsStyle.colors(context).muted),
                      const SizedBox(width: 6),
                      Expanded(
                          child: Text(label,
                              style: TextStyle(
                                  color: StatisticsStyle.colors(context).muted,
                                  fontSize: 12)))
                    ]),
                    const SizedBox(height: 10),
                    Text(value,
                        style: const TextStyle(
                            fontSize: 20,
                            fontWeight: FontWeight.w600,
                            height: 1.35)),
                    if (unavailable)
                      Padding(
                          padding: const EdgeInsets.only(top: 5),
                          child: Text('Ma’lumot mavjud emas',
                              style: TextStyle(
                                  fontSize: 11,
                                  color:
                                      StatisticsStyle.colors(context).muted))),
                  ])));

  Widget _chart(StatisticsReport? report) => StatisticsPanel(
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        StatisticsSectionHeading(
            title: 'Sotuv dinamikasi',
            caption: _filter.period == 'today'
                ? 'Soatlar'
                : _filter.period == 'week'
                    ? 'Kunlar'
                    : 'Sanalar'),
        const SizedBox(height: 20),
        if (report?.trend.isNotEmpty == true)
          StatisticsTrendChart(points: report!.trend)
        else
          SizedBox(
              height: 216,
              child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(Icons.bar_chart,
                        size: 32, color: StatisticsStyle.colors(context).muted),
                    const SizedBox(height: 12),
                    Text('Bu davr uchun dinamika mavjud emas.',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                            fontSize: 13,
                            color: StatisticsStyle.colors(context).muted)),
                  ])),
      ]));

  Widget _payments(StatisticsReport? report) => StatisticsPanel(
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const StatisticsSectionHeading(
            title: 'To‘lov turlari',
            icon: Icons.account_balance_wallet_outlined),
        const SizedBox(height: 20),
        if (report == null || report.payments.isEmpty)
          Text('To‘lovlar bo‘yicha ma’lumot mavjud emas.',
              style: TextStyle(
                  fontSize: 13, color: StatisticsStyle.colors(context).muted))
        else
          for (var i = 0; i < report.payments.length; i++) ...[
            if (i > 0) const SizedBox(height: 20),
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Expanded(
                  child: Text(report.payments[i].label,
                      style: const TextStyle(fontSize: 13))),
              const SizedBox(width: 8),
              Expanded(
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                    Text(StatisticsStyle.money(report.payments[i].amount),
                        textAlign: TextAlign.right,
                        style: const TextStyle(
                            fontSize: 12, fontWeight: FontWeight.w600)),
                    const SizedBox(height: 4),
                    Text(
                        '${StatisticsStyle.number(report.payments[i].percent, decimals: 1)}%',
                        style: TextStyle(
                            fontSize: 11,
                            color: StatisticsStyle.colors(context).muted)),
                  ])),
            ]),
            const SizedBox(height: 8),
            Semantics(
                label: report.payments[i].label,
                value:
                    '${StatisticsStyle.number(report.payments[i].percent, decimals: 1)}%',
                child: ClipRRect(
                    borderRadius: BorderRadius.circular(8),
                    child: LinearProgressIndicator(
                        value: report.payments[i].percent.clamp(0, 100) / 100,
                        minHeight: 6,
                        backgroundColor: StatisticsStyle.colors(context).soft,
                        color: StatisticsStyle.colors(context)
                            .accent
                            .withOpacity(switch (report.payments[i].key) {
                              'card' => .78,
                              'transfer' => .58,
                              'debt' => .4,
                              _ => 1,
                            })))),
          ],
      ]));

  Widget _products(StatisticsReport? report) =>
      Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const StatisticsSectionHeading(
            title: 'Top mahsulotlar', caption: 'Savdo hajmi bo‘yicha'),
        const SizedBox(height: 12),
        if (report == null || report.products.isEmpty)
          StatisticsPanel(
              child: Text('Bu davr uchun mahsulotlar ro‘yxati mavjud emas.',
                  style: TextStyle(
                      fontSize: 13,
                      color: StatisticsStyle.colors(context).muted)))
        else
          for (var i = 0; i < report.products.length; i++) ...[
            if (i > 0) const SizedBox(height: 10),
            InkWell(
                onTap: widget.canViewProducts && report.products[i].id != null
                    ? () => _openProduct(report.products[i])
                    : null,
                borderRadius: BorderRadius.circular(16),
                child: StatisticsPanel(
                    padding: const EdgeInsets.all(13),
                    child: ConstrainedBox(
                        constraints: const BoxConstraints(minHeight: 52),
                        child: Row(children: [
                          Container(
                              width: 24,
                              height: 28,
                              alignment: Alignment.center,
                              decoration: BoxDecoration(
                                  color: StatisticsStyle.colors(context).tint,
                                  borderRadius: BorderRadius.circular(8)),
                              child: Text('${i + 1}',
                                  style: TextStyle(
                                      color: StatisticsStyle.colors(context)
                                          .accent,
                                      fontWeight: FontWeight.w600))),
                          const SizedBox(width: 12),
                          if (report.products[i].image != null &&
                              report.products[i].image!.isNotEmpty) ...[
                            ClipRRect(
                                borderRadius: BorderRadius.circular(8),
                                child: Image.network(report.products[i].image!,
                                    width: 36,
                                    height: 40,
                                    fit: BoxFit.contain,
                                    errorBuilder: (_, __, ___) => Icon(
                                        Icons.inventory_2_outlined,
                                        size: 28,
                                        color: StatisticsStyle.colors(context)
                                            .muted))),
                            const SizedBox(width: 12),
                          ],
                          Expanded(
                              child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                Text(report.products[i].name,
                                    style: const TextStyle(
                                        fontSize: 14,
                                        fontWeight: FontWeight.w600)),
                                const SizedBox(height: 4),
                                Text(
                                    '${StatisticsStyle.number(report.products[i].quantity)} dona · ${StatisticsStyle.money(report.products[i].amount)}',
                                    style: TextStyle(
                                        fontSize: 12,
                                        color: StatisticsStyle.colors(context)
                                            .muted)),
                              ])),
                          if (widget.canViewProducts &&
                              report.products[i].id != null)
                            Icon(Icons.chevron_right,
                                size: 16,
                                color: StatisticsStyle.colors(context).muted),
                        ])))),
          ]
      ]);

  @override
  Widget build(BuildContext context) {
    final report = _result?.report;
    final dateLabel = _filter.start == _filter.end
        ? _dateFormat.format(_filter.start)
        : '${_dateFormat.format(_filter.start)} — ${_dateFormat.format(_filter.end)}';
    final page = Material(
        color: StatisticsStyle.colors(context).background,
        child: RefreshIndicator(
            onRefresh: _load,
            color: StatisticsStyle.colors(context).accent,
            child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 24, 20, 28),
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  Align(
                      alignment: Alignment.centerLeft,
                      child: TextButton.icon(
                          onPressed: _back,
                          style: TextButton.styleFrom(
                              foregroundColor:
                                  StatisticsStyle.colors(context).muted,
                              minimumSize: const Size(0, 40),
                              padding: EdgeInsets.zero),
                          icon: const Icon(Icons.arrow_back, size: 17),
                          label: const Text('Savdoga qaytish'))),
                  const SizedBox(height: 16),
                  const Text('Sotuv statistikasi',
                      style: TextStyle(
                          fontSize: 26,
                          fontWeight: FontWeight.w700,
                          letterSpacing: -.8,
                          height: 1.25)),
                  const SizedBox(height: 8),
                  Text('Savdo natijalarini kuzating va solishtiring.',
                      style: TextStyle(
                          fontSize: 13,
                          color: StatisticsStyle.colors(context).muted)),
                  const SizedBox(height: 24),
                  Row(children: [
                    for (final option in const {
                      'today': 'Bugun',
                      'week': 'Hafta',
                      'month': 'Oy',
                      'range': 'Oraliq'
                    }.entries) ...[
                      if (option.key != 'today') const SizedBox(width: 8),
                      Expanded(
                          child: Semantics(
                              selected: _filter.period == option.key,
                              child: OutlinedButton(
                                  onPressed: () => _choosePeriod(option.key),
                                  style: OutlinedButton.styleFrom(
                                      minimumSize: const Size(0, 44),
                                      padding: const EdgeInsets.symmetric(
                                          horizontal: 5, vertical: 9),
                                      side: BorderSide(
                                          color: _filter.period == option.key
                                              ? StatisticsStyle.colors(context)
                                                  .accent
                                              : StatisticsStyle.colors(context)
                                                  .line),
                                      backgroundColor: _filter.period == option.key
                                          ? StatisticsStyle.colors(context).tint
                                          : StatisticsStyle.colors(context)
                                              .card,
                                      foregroundColor:
                                          _filter.period == option.key
                                              ? StatisticsStyle.colors(context)
                                                  .accent
                                              : StatisticsStyle.colors(context)
                                                  .muted,
                                      textStyle: const TextStyle(
                                          fontSize: 12, fontFamily: 'Roboto'),
                                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10))),
                                  child: Text(option.value)))),
                    ]
                  ]),
                  const SizedBox(height: 16),
                  if (_filter.period == 'range') ...[
                    _range(),
                    const SizedBox(height: 16)
                  ],
                  Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Icon(Icons.calendar_month_outlined,
                        size: 16, color: StatisticsStyle.colors(context).muted),
                    const SizedBox(width: 7),
                    Expanded(
                        child: Text(
                            '$dateLabel${_awaitingRange ? ' · Yangi oraliq hali qo‘llanmagan' : ''}',
                            key: const ValueKey('statistics-date'),
                            style: TextStyle(
                                fontSize: 12,
                                color: StatisticsStyle.colors(context).muted))),
                  ]),
                  const SizedBox(height: 16),
                  if (_error != null) ...[
                    StatisticsNotice(
                        title: _error!, error: true, onRetry: _load),
                    const SizedBox(height: 16)
                  ],
                  if (_result?.cachedAt != null) ...[
                    StatisticsNotice(
                        title: 'Saqlangan server hisoboti',
                        description: DateFormat('dd.MM.yyyy HH:mm').format(
                            _result!.cachedAt!
                                .toUtc()
                                .add(const Duration(hours: 5)))),
                    const SizedBox(height: 16),
                  ],
                  if ((_result?.pendingCount ?? 0) > 0) ...[
                    StatisticsNotice(
                        title: 'Server hisobidan alohida',
                        description:
                            '${_result!.pendingCount} ta tasdiqlanmagan lokal savdo · ${StatisticsStyle.money(_result!.pendingAmount)}'),
                    const SizedBox(height: 16),
                  ],
                  if (_loading)
                    const StatisticsSkeleton()
                  else ...[
                    _hero(report),
                    const SizedBox(height: 16),
                    Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                              child: _kpi(
                                  'Savdolar soni',
                                  report == null
                                      ? '—'
                                      : '${report.salesCount} ta',
                                  Icons.shopping_cart_outlined)),
                          const SizedBox(width: 10),
                          Expanded(
                              child: _kpi(
                                  'O‘rtacha chek',
                                  StatisticsStyle.money(report?.averageReceipt),
                                  Icons.receipt_long_outlined)),
                        ]),
                    const SizedBox(height: 10),
                    _kpi('Foyda', StatisticsStyle.money(report?.profit),
                        Icons.trending_up,
                        unavailable: report != null && report.profit == null),
                    const SizedBox(height: 16),
                    if (report != null) ...[
                      Text(
                          'Jami savdo va foyda qaytarish hamda almashuvlarni hisobga oladi. Dinamika, to‘lovlar va o‘rtacha chek yakunlangan savdolar bo‘yicha.',
                          style: TextStyle(
                              fontSize: 11,
                              color: StatisticsStyle.colors(context).muted,
                              height: 1.5)),
                      const SizedBox(height: 16),
                    ],
                    if (report != null &&
                        report.salesCount == 0 &&
                        report.salesAmount == 0) ...[
                      StatisticsPanel(
                          child: Column(children: [
                        Icon(Icons.bar_chart,
                            size: 30,
                            color: StatisticsStyle.colors(context).muted),
                        const SizedBox(height: 12),
                        const Text('Sotuv ma’lumotlari topilmadi',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                                fontSize: 16, fontWeight: FontWeight.w600)),
                        const SizedBox(height: 8),
                        Text(
                            'Boshqa davrni tanlang yoki keyinroq qayta tekshiring.',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                                fontSize: 13,
                                color: StatisticsStyle.colors(context).muted)),
                      ])),
                      const SizedBox(height: 16),
                    ],
                    _chart(report),
                    const SizedBox(height: 16),
                    _payments(report),
                    const SizedBox(height: 20),
                    _products(report),
                  ],
                ])));
    return Theme(
        data: StatisticsStyle.theme(brightness: Theme.of(context).brightness),
        child: widget.embedded
            ? page
            : Scaffold(
                backgroundColor: StatisticsStyle.colors(context).background,
                appBar: AppBar(
                    backgroundColor: StatisticsStyle.colors(context).background,
                    foregroundColor: StatisticsStyle.colors(context).text,
                    surfaceTintColor: Colors.transparent,
                    title: const Text('Sotuv statistikasi',
                        style: TextStyle(fontSize: 18))),
                body: page));
  }
}
