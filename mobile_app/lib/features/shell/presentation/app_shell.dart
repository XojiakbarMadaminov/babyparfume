import 'dart:async';
import 'package:flutter/material.dart';
import '../../../core/database/app_database.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/theme/app_colors.dart';
import 'shell_header.dart';
import '../../../core/lease/lease_manager.dart';
import '../../../core/sync/outbox_manager.dart';
import '../../../core/sync/sync_coordinator.dart';
import '../../../core/sync/sync_models.dart';
import '../../auth/data/auth_repository.dart';
import '../../conflicts/presentation/conflicts_screen.dart';
import '../../debtors/presentation/debtors_screen.dart';
import '../../pos/presentation/pos_screen.dart';
import '../../pos/presentation/pos_widgets.dart';
import '../../products/presentation/products_screen.dart';
import '../../statistics/presentation/statistics_screen.dart';

class AppShell extends StatefulWidget {
  final AuthRepository authRepository;
  final VoidCallback onLogout;
  final String? initialStoreId;
  final Brightness brightness;
  final ValueChanged<Brightness> onBrightnessChanged;
  const AppShell(
      {super.key,
      required this.authRepository,
      required this.onLogout,
      required this.brightness,
      required this.onBrightnessChanged,
      this.initialStoreId});
  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> with WidgetsBindingObserver {
  late final LeaseManager _lease;
  late final OutboxManager _outbox;
  late final SyncCoordinator _sync;
  StreamSubscription<LeaseState>? _leaseSubscription;
  StreamSubscription<SyncProgress>? _syncSubscription;
  Timer? _timer;
  List<StoreItem> _stores = [];
  String? _storeId;
  bool _busy = true;
  bool _hasData = false;
  bool _silentRefresh = false;
  DateTime? _lastRefreshAttempt;
  Future<void>? _refreshTask;
  bool _legacyData = false;
  LeaseState? _leaseState;
  String? _error;
  String? _syncError;
  String _message = '';
  int _index = 0;
  Brightness get _brightness => widget.brightness;
  bool _showStatistics = false;
  int _revision = 0;
  Map<String, dynamic> _session = {};
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final auth = widget.authRepository;
    _lease = LeaseManager(storage: auth.storage);
    _outbox = OutboxManager();
    _sync = SyncCoordinator(
        apiClient: auth.apiClient,
        outboxManager: _outbox,
        leaseManager: _lease);
    _leaseSubscription = _lease.stateStream.listen((state) {
      if (mounted) {
        if (state.isLocked) {
          Navigator.of(context).popUntil((route) => route.isFirst);
        }
        setState(() => _leaseState = state);
      }
    });
    _syncSubscription = _sync.progressStream.listen((progress) {
      if (mounted) {
        setState(() {
          _message = progress.message ?? '';
          _syncError = progress.lastError;
        });
      }
    });
    _load();
    _timer = Timer.periodic(const Duration(minutes: 1), (_) {
      if (!_busy && _storeId != null) _refresh(silent: true);
    });
  }

  Future<void> _load() async {
    try {
      _legacyData = await AppDatabase.hasLegacyDatabase();
      _stores = await widget.authRepository.getStores();
      _session = await widget.authRepository.storage.getSession() ?? {};
      final saved = widget.initialStoreId ??
          await widget.authRepository.storage.getSelectedStoreId();
      if (_stores.isEmpty) throw StateError('Ruxsatli do‘kon mavjud emas.');
      await _select(_stores.any((s) => s.id.toString() == saved)
          ? saved!
          : _stores.first.id.toString());
    } catch (_) {
      if (mounted) {
        setState(() {
          _busy = false;
          _error =
              'Do‘konlarni olish imkoni bo‘lmadi. Internet va ruxsatlarni tekshiring.';
        });
      }
    }
  }

  Future<void> _select(String id) async {
    setState(() {
      _busy = true;
      _hasData = false;
      _storeId = null;
      _error = null;
      _syncError = null;
    });
    await _sync.cancelMediaDownloads();
    await AppDatabase.configure(widget.authRepository.storage, id);
    _storeId = id;
    _index = 0;
    _showStatistics = false;
    await _lease.initialize();
    final db = await AppDatabase.getInstance();
    final ready = await db.query('sync_state',
        where: 'key = ? AND value = ?', whereArgs: ['offline_ready', '1']);
    final lease = await _lease.checkLease();
    if (!mounted) return;
    setState(() {
      _hasData = ready.isNotEmpty;
      _leaseState = lease;
    });
    await _refresh();
  }

  Future<void> _refresh({bool silent = false}) async {
    if (_storeId == null) return;
    return _refreshTask ??= _performRefresh(silent: silent).whenComplete(() {
      _refreshTask = null;
    });
  }

  Future<void> _performRefresh({required bool silent}) async {
    final oldWatermark = _sync.currentProgress.currentWatermark;
    _lastRefreshAttempt = DateTime.now();
    setState(() {
      _busy = true;
      _silentRefresh = silent && _hasData;
    });
    await _sync.syncAll(_storeId!);
    final lease = await _lease.checkLease();
    final session = await widget.authRepository.storage.getSession() ?? {};
    if (mounted) {
      setState(() {
        _session = session;
        _leaseState = lease;
        _busy = false;
        if (!_hasData ||
            oldWatermark != _sync.currentProgress.currentWatermark) {
          _revision++;
        }
        _hasData = true;
      });
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed &&
        !_busy &&
        _storeId != null &&
        (_lastRefreshAttempt == null ||
            DateTime.now().difference(_lastRefreshAttempt!) >=
                const Duration(minutes: 1))) {
      _refresh(silent: true);
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _timer?.cancel();
    _leaseSubscription?.cancel();
    _syncSubscription?.cancel();
    _lease.dispose();
    _sync.dispose();
    super.dispose();
  }

  Future<void> _logout() async {
    final count = _storeId == null ? 0 : await _outbox.getPendingCount();
    if (!mounted) return;
    final accepted = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
                title: const Text('Hisobdan chiqish'),
                content: Text(count > 0
                    ? '$count ta tasdiqlanmagan amal saqlanadi. Shu akkauntga qayta kirib sinxronlang.'
                    : 'Hisobdan chiqilsinmi?'),
                actions: [
                  TextButton(
                      onPressed: () => Navigator.pop(ctx, false),
                      child: const Text('Bekor qilish')),
                  TextButton(
                      onPressed: () => Navigator.pop(ctx, true),
                      child: const Text('Chiqish'))
                ]));
    if (accepted == true) widget.onLogout();
  }

  @override
  Widget build(BuildContext context) {
    final navigationAccent = _brightness == Brightness.dark
        ? const Color(0xFFEFB66E)
        : const Color(0xFF9A5D00);
    final navigationMuted = _brightness == Brightness.dark
        ? const Color(0xFFA1ACBB)
        : const Color(0xFF6B7280);
    final permissions =
        Map<String, dynamic>.from(_session['permissions'] as Map? ?? {});
    final subscription =
        Map<String, dynamic>.from(_session['subscription'] as Map? ?? {});
    final id = _storeId;
    final pages = <Widget>[];
    final items = <BottomNavigationBarItem>[];
    void add(String permission, Widget page, IconData icon, String label) {
      if (permissions[permission] == true) {
        pages.add(page);
        items.add(BottomNavigationBarItem(icon: Icon(icon), label: label));
      }
    }

    if (id != null && (!_busy || _hasData)) {
      add(
          'sales',
          PosScreen(
              key: ValueKey('pos_$id'),
              storeId: id,
              brightness: _brightness,
              storeName: _stores
                      .where((store) => store.id.toString() == id)
                      .firstOrNull
                      ?.name ??
                  '',
              revision: _revision,
              canSeeCostPrice: permissions['sales_cost_price'] == true,
              canPrintReceipts:
                  (_session['printing'] as Map?)?['receipts'] == true,
              onViewStatistics: permissions['sales_statistics'] == true
                  ? () => setState(() => _showStatistics = true)
                  : null),
          Icons.shopping_cart_outlined,
          'Savdo');
      add(
          'products',
          ProductsScreen(
              key: ValueKey('products_$id'),
              storeId: id,
              revision: _revision,
              canPrintBarcodes:
                  (_session['printing'] as Map?)?['barcodes'] == true,
              canCreate: permissions['create_product'] == true),
          Icons.inventory_2_outlined,
          'Mahsulotlar');
      add(
          'debtors',
          DebtorsScreen(
              key: ValueKey('debtors_${id}_$_revision'),
              storeId: id,
              canCreate: permissions['create_debtor'] == true),
          Icons.people_outline,
          'Qarzdorlar');
      if (permissions['sales'] != true) {
        add(
            'sales_statistics',
            StatisticsScreen(
                key: ValueKey('statistics_${id}_$_revision'),
                storeId: id,
                embedded: true,
                canViewProducts: permissions['products'] == true,
                apiClient: widget.authRepository.apiClient),
            Icons.bar_chart,
            'Statistika');
      }
    }
    final salesActive = pages.isNotEmpty &&
        pages[_index < pages.length ? _index : 0] is PosScreen;
    final showingStatistics = salesActive &&
        _showStatistics &&
        permissions['sales_statistics'] == true;
    final content = <Widget>[
      ...pages,
      if (showingStatistics)
        StatisticsScreen(
            key: ValueKey('statistics_$id'),
            storeId: id!,
            apiClient: widget.authRepository.apiClient,
            embedded: true,
            canViewProducts: permissions['products'] == true,
            onBack: () => setState(() => _showStatistics = false)),
    ];
    return Theme(
        data: salesActive
            ? PosStyle.theme(context, brightness: _brightness)
            : _brightness == Brightness.dark
                ? AppTheme.darkTheme()
                : AppTheme.lightTheme(),
        child: PopScope(
            canPop: !showingStatistics,
            onPopInvokedWithResult: (didPop, result) {
              if (!didPop && showingStatistics) {
                setState(() => _showStatistics = false);
              }
            },
            child: Scaffold(
              backgroundColor: _brightness == Brightness.dark
                  ? PosStyle.background
                  : const Color(0xFFF6F7FA),
              appBar: ShellHeader(
                  stores: _stores,
                  storeId: id,
                  userName:
                      (_session['user'] as Map?)?['name']?.toString() ?? '',
                  isDark: _brightness == Brightness.dark,
                  onStoreChanged: _busy ? null : _select,
                  onBrightnessChanged: widget.onBrightnessChanged,
                  onSync: _busy || id == null
                      ? null
                      : () async {
                          await Navigator.push(
                              context,
                              MaterialPageRoute(
                                  builder: (_) => ConflictsScreen(
                                      storeId: id,
                                      syncCoordinator: _sync,
                                      outboxManager: _outbox)));
                          if (mounted) _refresh();
                        },
                  onLogout: _busy ? null : _logout),
              body: Column(children: [
                if (_legacyData)
                  Padding(
                      padding: const EdgeInsets.all(8),
                      child: Text(
                          'Eski versiya lokal bazasi saqlangan. Undagi yuborilmagan amallarni administrator bilan solishtiring; ilova ma’lumotlarini o‘chirmang.',
                          style:
                              TextStyle(color: AppColors.of(context).warning))),
                if (subscription['warning_active'] == true)
                  Padding(
                      padding: const EdgeInsets.all(12),
                      child: Text(
                          subscription['warning_text']?.toString() ?? '',
                          style:
                              TextStyle(color: AppColors.of(context).warning))),
                if (_message.isNotEmpty &&
                    (!_hasData || (_busy && !_silentRefresh)))
                  Padding(
                      padding: const EdgeInsets.all(8), child: Text(_message)),
                if (_syncError != null && _leaseState?.isLocked == false)
                  Padding(
                      padding: const EdgeInsets.all(8),
                      child: Text(_syncError!, textAlign: TextAlign.center)),
                if (_busy && _hasData && !_silentRefresh)
                  const LinearProgressIndicator(),
                Expanded(
                    child: _busy && !_hasData
                        ? const Center(child: CircularProgressIndicator())
                        : _error != null || _leaseState?.isLocked != false
                            ? SingleChildScrollView(
                                padding: const EdgeInsets.all(16),
                                child: Center(
                                    child: Column(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                      Text(
                                          _error ??
                                              _syncError ??
                                              _leaseState?.reason ??
                                              'Ma’lumotlarni serverdan yuklang.',
                                          textAlign: TextAlign.center),
                                      ElevatedButton(
                                          onPressed:
                                              id == null ? _load : _refresh,
                                          child: const Text(
                                              'Server bilan yangilash')),
                                    ])))
                            : pages.isEmpty
                                ? const Center(
                                    child:
                                        Text('Bo‘limlarga ruxsat berilmagan.'))
                                : IndexedStack(
                                    index: showingStatistics
                                        ? pages.length
                                        : _index < pages.length
                                            ? _index
                                            : 0,
                                    children: content)),
              ]),
              bottomNavigationBar: items.length < 2
                  ? null
                  : salesActive
                      ? Container(
                          decoration: BoxDecoration(
                              color: _brightness == Brightness.dark
                                  ? PosStyle.background
                                  : Colors.white,
                              border: Border(
                                  top: BorderSide(
                                      color: _brightness == Brightness.dark
                                          ? const Color(0xFF292C35)
                                          : const Color(0xFFE3E5EB)))),
                          child: SafeArea(
                              top: false,
                              child: Padding(
                                  padding:
                                      const EdgeInsets.symmetric(horizontal: 8),
                                  child: Row(children: [
                                    for (var i = 0; i < items.length; i++)
                                      Expanded(
                                          child: InkWell(
                                              onTap: () => setState(() {
                                                    _index = i;
                                                    _showStatistics = false;
                                                  }),
                                              child: Padding(
                                                  padding:
                                                      const EdgeInsets.fromLTRB(
                                                          8, 0, 8, 12),
                                                  child: Column(
                                                      mainAxisSize:
                                                          MainAxisSize.min,
                                                      children: [
                                                        Container(
                                                            width: 24,
                                                            height: 2,
                                                            color: _index == i
                                                                ? navigationAccent
                                                                : Colors
                                                                    .transparent),
                                                        const SizedBox(
                                                            height: 14),
                                                        IconTheme(
                                                            data: IconThemeData(
                                                                size: 21,
                                                                color: _index ==
                                                                        i
                                                                    ? navigationAccent
                                                                    : navigationMuted),
                                                            child:
                                                                items[i].icon),
                                                        const SizedBox(
                                                            height: 8),
                                                        Text(
                                                            items[i].label ??
                                                                '',
                                                            style: TextStyle(
                                                                fontSize: 12,
                                                                color: _index ==
                                                                        i
                                                                    ? navigationAccent
                                                                    : navigationMuted)),
                                                      ]))))
                                  ]))))
                      : BottomNavigationBar(
                          type: BottomNavigationBarType.fixed,
                          currentIndex: _index < items.length ? _index : 0,
                          items: items,
                          onTap: (value) => setState(() {
                                _index = value;
                                _showStatistics = false;
                              })),
            )));
  }
}
