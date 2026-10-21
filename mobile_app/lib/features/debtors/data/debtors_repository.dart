import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';
import '../../../core/database/app_database.dart';
import '../../../core/sync/outbox_manager.dart';

class LocalDebtor {
  final String id;
  final String storeId;
  final String? clientId;
  final String fullName;
  final String? phone;
  final double amount;
  final String currency;
  final String? date;
  final String? note;
  final String? photoPath;
  final String? updatedAt;

  LocalDebtor({
    required this.id,
    required this.storeId,
    this.clientId,
    required this.fullName,
    this.phone,
    required this.amount,
    this.currency = 'UZS',
    this.date,
    this.note,
    this.photoPath,
    this.updatedAt,
  });

  factory LocalDebtor.fromMap(Map<String, dynamic> map) {
    return LocalDebtor(
      id: map['id'] as String,
      storeId: map['store_id'] as String,
      clientId: map['client_id'] as String?,
      fullName: map['full_name'] as String,
      phone: map['phone'] as String?,
      amount: (map['amount'] as num?)?.toDouble() ?? 0.0,
      currency: map['currency'] as String? ?? 'UZS',
      date: map['date'] as String?,
      note: map['note'] as String?,
      photoPath: map['photo_path'] as String?,
      updatedAt: map['updated_at'] as String?,
    );
  }
}

class LocalDebtorTransaction {
  final String id;
  final String debtorId;
  final String type; // 'debt' or 'payment'
  final double amount;
  final String date;
  final String? note;
  final String syncStatus;

  LocalDebtorTransaction({
    required this.id,
    required this.debtorId,
    required this.type,
    required this.amount,
    required this.date,
    this.note,
    this.syncStatus = 'synced',
  });

  factory LocalDebtorTransaction.fromMap(Map<String, dynamic> map) {
    return LocalDebtorTransaction(
      id: map['id'] as String,
      debtorId: map['debtor_id'] as String,
      type: map['type'] as String,
      amount: (map['amount'] as num?)?.toDouble() ?? 0.0,
      date: map['date'] as String,
      note: map['note'] as String?,
      syncStatus: map['sync_status'] as String? ?? 'synced',
    );
  }
}

class DebtorsRepository {
  final OutboxManager outboxManager;
  final Future<Database> Function() _getDb;

  DebtorsRepository({
    required this.outboxManager,
    Future<Database> Function()? getDb,
  }) : _getDb = getDb ?? AppDatabase.getInstance;

  Future<List<LocalDebtor>> getDebtors(String storeId, {String? query}) async {
    final db = await _getDb();
    String? where = 'store_id = ?';
    List<dynamic> whereArgs = [storeId];

    if (query != null && query.trim().isNotEmpty) {
      final q = '%${query.trim().toLowerCase()}%';
      where += ' AND (LOWER(full_name) LIKE ? OR phone LIKE ?)';
      whereArgs.addAll([q, q]);
    }

    final rows = await db.query(
      'debtors',
      where: where,
      whereArgs: whereArgs,
      orderBy: 'amount DESC, full_name ASC',
    );

    return rows.map((r) => LocalDebtor.fromMap(r)).toList();
  }

  Future<double> getTotalDebt(String storeId) async {
    final db = await _getDb();
    final result = await db.rawQuery(
      'SELECT SUM(amount) as total FROM debtors WHERE store_id = ?',
      [storeId],
    );
    final val = result.first['total'] as num?;
    return val?.toDouble() ?? 0.0;
  }

  Future<LocalDebtor> createDebtor({
    required String storeId,
    required String fullName,
    String? phone,
    double initialAmount = 0.0,
    String? note,
    String? photoPath,
  }) async {
    final db = await _getDb();
    final debtorId = const Uuid().v4();
    final now = DateTime.now().toIso8601String();

    await db.insert('debtors', {
      'id': debtorId,
      'store_id': storeId,
      'client_id': null,
      'full_name': fullName,
      'phone': phone,
      'amount': initialAmount,
      'currency': 'UZS',
      'date': now,
      'note': note,
      'photo_path': photoPath,
      'updated_at': now,
    });

    if (initialAmount > 0) {
      await db.insert('debtor_transactions', {
        'id': const Uuid().v4(),
        'debtor_id': debtorId,
        'type': 'debt',
        'amount': initialAmount,
        'date': now,
        'note': note ?? "Boshlang'ich qarz",
        'sync_status': 'pending',
      });
    }

    // Outbox enqueue for server sync
    await outboxManager.enqueueOperation(
      entityType: 'debtor',
      operationType: 'debtor.create',
      payload: {
        'local_id': debtorId,
        'full_name': fullName,
        'phone': phone,
        'amount': initialAmount.toStringAsFixed(2),
        'note': note,
        'occurred_at': now,
      },
    );

    return LocalDebtor(
      id: debtorId,
      storeId: storeId,
      fullName: fullName,
      phone: phone,
      amount: initialAmount,
      date: now,
      note: note,
      photoPath: photoPath,
      updatedAt: now,
    );
  }

  Future<void> addTransaction({
    required String storeId,
    required String debtorId,
    required String type, // 'debt' or 'payment'
    required double amount,
    String? note,
  }) async {
    final db = await _getDb();
    final txnId = const Uuid().v4();
    final now = DateTime.now().toIso8601String();

    await db.transaction((txn) async {
      // 1. Get current debtor
      final rows = await txn.query('debtors', where: 'id = ?', whereArgs: [debtorId]);
      if (rows.isEmpty) throw Exception("Qarzdor topilmadi");
      final currentAmount = (rows.first['amount'] as num?)?.toDouble() ?? 0.0;

      // 2. Calculate new balance
      double newAmount;
      if (type == 'debt') {
        newAmount = currentAmount + amount;
      } else {
        newAmount = (currentAmount - amount) > 0 ? (currentAmount - amount) : 0.0;
      }

      // 3. Update debtor balance
      await txn.update(
        'debtors',
        {'amount': newAmount, 'updated_at': now},
        where: 'id = ?',
        whereArgs: [debtorId],
      );

      // 4. Insert transaction record
      await txn.insert('debtor_transactions', {
        'id': txnId,
        'debtor_id': debtorId,
        'type': type,
        'amount': amount,
        'date': now,
        'note': note,
        'sync_status': 'pending',
      });
    });

    // 5. Enqueue in Outbox
    await outboxManager.enqueueOperation(
      entityType: 'debtor',
      operationType: 'debtor.transaction',
      payload: {
        'local_txn_id': txnId,
        'debtor_id': debtorId,
        'type': type,
        'amount': amount.toStringAsFixed(2),
        'note': note,
        'occurred_at': now,
      },
    );
  }

  Future<List<LocalDebtorTransaction>> getTransactions(String debtorId) async {
    final db = await _getDb();
    final rows = await db.query(
      'debtor_transactions',
      where: 'debtor_id = ?',
      whereArgs: [debtorId],
      orderBy: 'date DESC',
    );
    return rows.map((r) => LocalDebtorTransaction.fromMap(r)).toList();
  }
}
