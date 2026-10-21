import 'package:flutter/material.dart';
import '../../../core/api/api_client.dart';
import '../../../core/lease/lease_manager.dart';
import '../../../core/storage/secure_storage.dart';
import '../../../core/sync/outbox_manager.dart';
import '../../../core/sync/sync_coordinator.dart';
import '../../../core/theme/app_colors.dart';
import '../../auth/data/auth_repository.dart';
import '../../conflicts/presentation/conflicts_screen.dart';
import '../../debtors/presentation/debtors_screen.dart';
import '../../pos/presentation/pos_screen.dart';
import '../../products/presentation/products_screen.dart';
import '../../statistics/presentation/statistics_screen.dart';

class AppShell extends StatefulWidget {
  final AuthRepository authRepository;
  final VoidCallback onLogout;
  final String? initialStoreId;

  const AppShell({
    super.key,
    required this.authRepository,
    required this.onLogout,
    this.initialStoreId,
  });

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  int _currentIndex = 0;
  List<StoreItem> _stores = [];
  String? _selectedStoreId;
  static const bool _hasSubscriptionWarning = false;
  static const String _subscriptionWarningText = "Oylik obuna to'lovini to'lashingiz talab qilinadi";

  late final ApiClient _apiClient;
  late final OutboxManager _outboxManager;
  late final SyncCoordinator _syncCoordinator;

  @override
  void initState() {
    super.initState();
    _selectedStoreId = widget.initialStoreId;
    final storage = SecureStorageService();
    _apiClient = ApiClient(storage: storage);
    _outboxManager = OutboxManager();
    _syncCoordinator = SyncCoordinator(
      apiClient: _apiClient,
      outboxManager: _outboxManager,
      leaseManager: LeaseManager(storage: storage),
    );
    _loadStores();
  }

  Future<void> _loadStores() async {
    try {
      final stores = await widget.authRepository.getStores();
      if (mounted) {
        setState(() {
          _stores = stores;
          if (_selectedStoreId == null && stores.isNotEmpty) {
            _selectedStoreId = stores.first.id.toString();
          }
        });
      }
    } catch (_) {}
  }

  void _openConflictsScreen() {
    final storeId = _selectedStoreId ?? '1';
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (context) => ConflictsScreen(
          storeId: storeId,
          syncCoordinator: _syncCoordinator,
          outboxManager: _outboxManager,
        ),
      ),
    );
  }

  void _confirmLogout() {
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text("Chiqish"),
        content: const Text("Haqiqatan ham hisobingizdan chiqmoqchimisiz?"),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text("Bekor qilish"),
          ),
          TextButton(
            style: TextButton.styleFrom(foregroundColor: AppColors.lightDanger),
            onPressed: () {
              Navigator.pop(context);
              widget.onLogout();
            },
            child: const Text("Chiqish"),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final storeId = _selectedStoreId ?? '1';

    final screens = [
      PosScreen(storeId: storeId),
      ProductsScreen(storeId: storeId),
      DebtorsScreen(storeId: storeId),
      StatisticsScreen(storeId: storeId, apiClient: _apiClient),
    ];

    return Scaffold(
      appBar: PreferredSize(
        preferredSize: const Size.fromHeight(_hasSubscriptionWarning ? 104 : 64),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            AppBar(
              elevation: 0,
              backgroundColor: AppColors.lightCard,
              titleSpacing: 16,
              title: Row(
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(8),
                    child: Image.asset(
                      'assets/images/logo.png',
                      width: 32,
                      height: 32,
                      fit: BoxFit.cover,
                    ),
                  ),
                  const SizedBox(width: 8),
                  const Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'OSON POS',
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w800,
                          letterSpacing: 1.2,
                          color: AppColors.lightText,
                        ),
                      ),
                      Text(
                        'Mobil yordamchi',
                        style: TextStyle(
                          fontSize: 10,
                          color: AppColors.lightMuted,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
              actions: [
                // Outbox & Sync status button
                IconButton(
                  icon: const Icon(Icons.cloud_sync_outlined, size: 22, color: AppColors.lightAccent),
                  tooltip: "Sinxronlash holati",
                  onPressed: _openConflictsScreen,
                ),
                // Store Selector
                if (_stores.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(right: 4),
                    child: DropdownButton<String>(
                      value: _selectedStoreId,
                      underline: const SizedBox(),
                      icon: const Icon(Icons.storefront_outlined, size: 20, color: AppColors.lightAccent),
                      style: const TextStyle(fontSize: 13, color: AppColors.lightText, fontWeight: FontWeight.w600),
                      items: _stores.map((s) => DropdownMenuItem(
                        value: s.id.toString(),
                        child: Text(s.name),
                      )).toList(),
                      onChanged: (val) {
                        if (val != null) setState(() => _selectedStoreId = val);
                      },
                    ),
                  ),
                // Logout Button
                IconButton(
                  icon: const Icon(Icons.logout, size: 20, color: AppColors.lightMuted),
                  tooltip: "Hisobdan chiqish",
                  onPressed: _confirmLogout,
                ),
              ],
            ),

            // Subscription Marquee/Warning Banner
            if (_hasSubscriptionWarning)
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                color: AppColors.warningBg,
                child: const Row(
                  children: [
                    Icon(Icons.warning_amber_rounded, size: 18, color: AppColors.warning),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _subscriptionWarningText,
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: AppColors.warning,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
      body: IndexedStack(
        index: _currentIndex,
        children: screens,
      ),
      bottomNavigationBar: BottomNavigationBar(
        type: BottomNavigationBarType.fixed,
        currentIndex: _currentIndex,
        onTap: (index) => setState(() => _currentIndex = index),
        items: const [
          BottomNavigationBarItem(
            icon: Icon(Icons.point_of_sale_outlined),
            activeIcon: Icon(Icons.point_of_sale),
            label: "Savdo",
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.inventory_2_outlined),
            activeIcon: Icon(Icons.inventory_2),
            label: "Mahsulotlar",
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.people_outline),
            activeIcon: Icon(Icons.people),
            label: "Qarzdorlar",
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.bar_chart_outlined),
            activeIcon: Icon(Icons.bar_chart),
            label: "Statistika",
          ),
        ],
      ),
    );
  }
}
