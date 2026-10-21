enum SyncStatus {
  idle,
  syncing,
  offlineReady,
  hasPendingOperations,
  hasConflicts,
  error,
}

class SyncProgress {
  final SyncStatus status;
  final String? message;
  final int pendingOperationsCount;
  final int? currentWatermark;
  final DateTime? lastSyncTime;
  final String? lastError;

  const SyncProgress({
    this.status = SyncStatus.idle,
    this.message,
    this.pendingOperationsCount = 0,
    this.currentWatermark,
    this.lastSyncTime,
    this.lastError,
  });

  SyncProgress copyWith({
    SyncStatus? status,
    String? message,
    int? pendingOperationsCount,
    int? currentWatermark,
    DateTime? lastSyncTime,
    String? lastError,
  }) {
    return SyncProgress(
      status: status ?? this.status,
      message: message ?? this.message,
      pendingOperationsCount: pendingOperationsCount ?? this.pendingOperationsCount,
      currentWatermark: currentWatermark ?? this.currentWatermark,
      lastSyncTime: lastSyncTime ?? this.lastSyncTime,
      lastError: lastError ?? this.lastError,
    );
  }
}

class OutboxItem {
  final String operationId;
  final String entityType;
  final String operationType;
  final Map<String, dynamic> payload;
  final String status;
  final DateTime createdAt;
  final String? lastError;

  OutboxItem({
    required this.operationId,
    required this.entityType,
    required this.operationType,
    required this.payload,
    this.status = 'pending',
    required this.createdAt,
    this.lastError,
  });
}
