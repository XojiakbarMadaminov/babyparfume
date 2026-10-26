import 'package:flutter/material.dart';
import '../../../core/sync/outbox_manager.dart';
import '../../../core/sync/sync_coordinator.dart';
import '../../../core/sync/sync_models.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/date_format.dart';

class ConflictsScreen extends StatefulWidget {
  final String storeId;
  final SyncCoordinator syncCoordinator;
  final OutboxManager outboxManager;

  const ConflictsScreen({
    super.key,
    required this.storeId,
    required this.syncCoordinator,
    required this.outboxManager,
  });

  @override
  State<ConflictsScreen> createState() => _ConflictsScreenState();
}

class _ConflictsScreenState extends State<ConflictsScreen> {
  List<OutboxItem> _pendingItems = [];
  bool _isSyncing = false;

  @override
  void initState() {
    super.initState();
    _loadOutbox();
  }

  Future<void> _loadOutbox() async {
    final items = await widget.outboxManager
        .getPendingOperations(storeId: widget.storeId, includeAll: true);
    if (mounted) {
      setState(() => _pendingItems = items);
    }
  }

  Future<void> _triggerManualSync() async {
    setState(() => _isSyncing = true);
    final success = await widget.syncCoordinator.syncAll(widget.storeId);
    await _loadOutbox();
    if (mounted) {
      setState(() => _isSyncing = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(success
              ? widget.syncCoordinator.currentProgress.message ?? 'Yangilandi.'
              : widget.syncCoordinator.currentProgress.lastError ??
                  'Sinxronlash yakunlanmadi. Navbat saqlangan.'),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text(
          "Sinxronizatsiya va Ziddiyatlar",
          style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
        ),
        backgroundColor: AppColors.of(context).card,
        elevation: 0,
        actions: [
          IconButton(
            icon: _isSyncing
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Icon(Icons.sync, color: AppColors.of(context).accent),
            tooltip: "Sinxronlash",
            onPressed: _isSyncing ? null : _triggerManualSync,
          ),
        ],
      ),
      body: Column(
        children: [
          // Status Header
          Container(
            margin: const EdgeInsets.all(16),
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: _pendingItems.isEmpty
                  ? AppColors.of(context).successBackground
                  : AppColors.of(context).warningBackground,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Row(
              children: [
                Icon(
                  _pendingItems.isEmpty
                      ? Icons.cloud_done
                      : Icons.cloud_upload_outlined,
                  color: _pendingItems.isEmpty
                      ? AppColors.of(context).success
                      : AppColors.of(context).warning,
                  size: 28,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _pendingItems.isEmpty
                            ? "Barcha ma'lumotlar sinxronlangan"
                            : "${_pendingItems.length} ta operatsiya yuborilishini kutmoqda",
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          fontSize: 14,
                          color: _pendingItems.isEmpty
                              ? AppColors.of(context).success
                              : AppColors.of(context).warning,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        "Internet mavjud bo'lganda avtomatik serverga jo'natiladi.",
                        style: TextStyle(
                            fontSize: 12, color: AppColors.of(context).muted),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),

          const Padding(
            padding: EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(
                "Kutilayotgan operatsiyalar (Outbox)",
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
              ),
            ),
          ),

          // List
          Expanded(
            child: _pendingItems.isEmpty
                ? Center(
                    child: Text(
                      "Navbatda operatsiyalar mavjud emas",
                      style: TextStyle(
                          color: AppColors.of(context).muted, fontSize: 14),
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.all(16),
                    itemCount: _pendingItems.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (context, index) {
                      final item = _pendingItems[index];
                      return Container(
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          color: AppColors.of(context).card,
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: AppColors.of(context).line),
                        ),
                        child: Row(
                          children: [
                            Container(
                              width: 36,
                              height: 36,
                              decoration: BoxDecoration(
                                color: AppColors.of(context).soft,
                                borderRadius: BorderRadius.circular(8),
                              ),
                              child: Icon(Icons.outbox,
                                  color: AppColors.of(context).muted, size: 18),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    item.operationType == 'sale.create'
                                        ? 'Savdo'
                                        : item.operationType == 'debtor.create'
                                            ? 'Yangi qarzdor'
                                            : item.operationType ==
                                                    'debtor.photo'
                                                ? 'Qarzdor fotosi'
                                                : 'Qarz / to‘lov amali',
                                    style: const TextStyle(
                                        fontWeight: FontWeight.w600,
                                        fontSize: 13),
                                  ),
                                  const SizedBox(height: 2),
                                  Text(
                                    AppDateFormat.formatDate(item.createdAt),
                                    style: TextStyle(
                                        color: AppColors.of(context).muted,
                                        fontSize: 11),
                                  ),
                                  if (item.lastError != null) ...[
                                    const SizedBox(height: 4),
                                    Text(
                                      "Xatolik: ${item.lastError}",
                                      style: TextStyle(
                                          color: AppColors.of(context).danger,
                                          fontSize: 11),
                                    ),
                                  ],
                                ],
                              ),
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 8, vertical: 4),
                              decoration: BoxDecoration(
                                color: AppColors.of(context).warningBackground,
                                borderRadius: BorderRadius.circular(6),
                              ),
                              child: Text(
                                const {
                                      'conflict': 'Admin qarori',
                                      'rejected': 'Rad etilgan',
                                      'blocked': 'Oldingi amal kutilmoqda',
                                      'failed': 'Qayta tekshiriladi'
                                    }[item.status] ??
                                    'Kutilmoqda',
                                style: TextStyle(
                                  color: AppColors.of(context).warning,
                                  fontSize: 11,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ],
                        ),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}
