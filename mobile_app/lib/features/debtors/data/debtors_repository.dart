import 'dart:convert';
import 'dart:typed_data';
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';
import '../../../core/database/app_database.dart';
import '../../../core/utils/phone_format.dart';
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
  final bool canUpdate;

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
    this.canUpdate = false,
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
      canUpdate: map['can_update'] == 1,
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
  final Future<void> Function() _ensureWrite;
  DebtorsRepository(
      {required this.outboxManager,
      Future<Database> Function()? getDb,
      Future<void> Function()? ensureWrite})
      : _getDb = getDb ?? AppDatabase.getInstance,
        _ensureWrite = ensureWrite ?? AppDatabase.requireWriteAccess;

  Future<List<LocalDebtor>> getDebtors(String storeId,
      {String? query, String filter = 'all'}) async {
    final db = await _getDb();
    final rows =
        await db.query('debtors', where: 'store_id = ?', whereArgs: [storeId]);
    final search = (query ?? '').trim().toLowerCase();
    final result = rows
        .map(LocalDebtor.fromMap)
        .where((d) =>
            (search.isEmpty ||
                d.fullName.toLowerCase().contains(search) ||
                (d.phone ?? '').contains(search)) &&
            (filter != 'active' || d.amount > 0) &&
            (filter != 'closed' || d.amount <= 0) &&
            (filter != 'overdue' ||
                (d.amount > 0 &&
                    d.date != null &&
                    DateTime.parse(d.date!).isBefore(
                        DateTime.now().subtract(const Duration(days: 20))))))
        .toList();
    result.sort((a, b) => b.amount.compareTo(a.amount));
    return result;
  }

  Future<double> getTotalDebt(String storeId,
          {String currency = 'UZS'}) async =>
      (await getDebtors(storeId))
          .where((debtor) => debtor.currency == currency)
          .fold<double>(0, (sum, debtor) => sum + debtor.amount);

  Future<({Map<String, double> totals, Map<String, int> counts})> summary(
      String storeId) async {
    final all = await getDebtors(storeId);
    final totals = <String, double>{};
    final counts = {'active': 0, 'closed': 0, 'overdue': 0};
    for (final debtor in all) {
      totals[debtor.currency] = (totals[debtor.currency] ?? 0) + debtor.amount;
      final status = debtor.amount > 0 ? 'active' : 'closed';
      counts[status] = counts[status]! + 1;
      if (debtor.amount > 0 &&
          debtor.date != null &&
          DateTime.parse(debtor.date!)
              .isBefore(DateTime.now().subtract(const Duration(days: 20)))) {
        counts['overdue'] = counts['overdue']! + 1;
      }
    }
    return (totals: totals, counts: counts);
  }

  Future<LocalDebtor?> getDebtor(String id) async {
    final db = await _getDb();
    final rows = await db.query('debtors', where: 'id = ?', whereArgs: [id]);
    return rows.isEmpty ? null : LocalDebtor.fromMap(rows.first);
  }

  Future<LocalDebtor> createDebtor(
      {required String storeId,
      required String fullName,
      String? phone,
      double initialAmount = 0,
      String? date,
      String? note,
      String? photoPath}) async {
    await _ensureWrite();
    phone = phone == null ? null : PhoneFormat.forStorage(phone);
    if (fullName.trim().isEmpty ||
        phone == null ||
        !PhoneFormat.isValid(phone) ||
        !initialAmount.isFinite ||
        initialAmount <= 0 ||
        initialAmount != initialAmount.roundToDouble()) {
      throw ArgumentError(
          "Ism, telefon va butun musbat qarz summasini kiriting.");
    }
    if (photoPath != null) throw StateError("Foto yuklash hozircha ulanmagan.");
    final db = await _getDb();
    final id = const Uuid().v4();
    final operationId = const Uuid().v4();
    final now = DateTime.now().toIso8601String();
    date ??= now.substring(0, 10);
    if (DateTime.tryParse(date) == null ||
        date.compareTo(now.substring(0, 10)) > 0) {
      throw ArgumentError('Qarz sanasini tekshiring.');
    }
    final row = <String, Object?>{
      'id': id,
      'store_id': storeId,
      'full_name': fullName.trim(),
      'phone': phone,
      'amount': initialAmount,
      'currency': 'UZS',
      'date': date,
      'note': note,
      'updated_at': now,
      'can_update': 1
    };
    await db.transaction((txn) async {
      final aliases = PhoneFormat.aliases(phone!);
      final duplicate = await txn.query('debtors',
          where:
              'phone IN (${List.filled(aliases.length, '?').join(',')}) AND amount > 0',
          whereArgs: aliases);
      if (duplicate.isNotEmpty) {
        throw StateError("Bu telefon uchun faol qarz mavjud.");
      }
      await txn.insert('debtors', row);
      await txn.insert('debtor_transactions', {
        'id': const Uuid().v4(),
        'debtor_id': id,
        'type': 'debt',
        'amount': initialAmount,
        'date': now,
        'note': note ?? "Boshlang‘ich qarz",
        'sync_status': 'pending',
        'operation_id': operationId
      });
      await outboxManager.enqueueOperation(
          executor: txn,
          storeId: storeId,
          customOperationId: operationId,
          entityType: 'debtor',
          operationType: 'debtor.create',
          localId: id,
          payload: {
            'full_name': fullName.trim(),
            'phone': phone,
            'amount': initialAmount.toInt().toString(),
            'date': date,
            'note': note
          });
    });
    return LocalDebtor.fromMap(row);
  }

  Future<void> addTransaction(
      {required String storeId,
      required String debtorId,
      required String type,
      required double amount,
      String? note,
      String paymentMethod = 'cash'}) async {
    await _ensureWrite();
    if (!['debt', 'payment'].contains(type) ||
        !amount.isFinite ||
        amount <= 0 ||
        amount != amount.roundToDouble()) {
      throw ArgumentError('Summani butun musbat son bilan kiriting.');
    }
    final db = await _getDb();
    final operationId = const Uuid().v4();
    final now = DateTime.now().toIso8601String();
    await db.transaction((txn) async {
      final rows = await txn.query('debtors',
          where: 'id = ? AND store_id = ?', whereArgs: [debtorId, storeId]);
      if (rows.isEmpty) throw StateError('Qarzdor topilmadi.');
      final row = rows.first;
      if (row['can_update'] != 1) {
        throw StateError('Bu qarzdorni o‘zgartirishga ruxsat yo‘q.');
      }
      final current = (row['amount'] as num).toDouble();
      if (type == 'payment' && amount > current) {
        throw ArgumentError('To‘lov qolgan qarzdan oshmasin.');
      }
      final previous = await txn.query('sync_outbox',
          where: "entity_type IN ('debtor','sale') AND status != 'applied'",
          orderBy: 'created_at DESC, rowid DESC');
      Map<String, Object?>? parent;
      for (final candidate in previous) {
        final envelope =
            jsonDecode(candidate['envelope_json'] as String) as Map;
        if (envelope['local_id'] == debtorId ||
            envelope['payload']?['local_debtor_id'] == debtorId ||
            envelope['entity_id'] == debtorId ||
            envelope['entity_id'] == (row['server_id'] ?? debtorId)) {
          parent = candidate;
          break;
        }
      }
      final parentId = parent?['operation_id'] as String?;
      await txn.update(
          'debtors',
          {
            'amount': current + (type == 'debt' ? amount : -amount),
            'updated_at': now
          },
          where: 'id = ?',
          whereArgs: [debtorId]);
      await txn.insert('debtor_transactions', {
        'id': const Uuid().v4(),
        'debtor_id': debtorId,
        'type': type,
        'amount': amount,
        'date': now,
        'note': type == 'payment'
            ? '${note == null || note.isEmpty ? '' : '$note · '}To‘lov usuli: ${paymentMethod == 'cash' ? 'Naqd' : 'Karta'}'
            : note,
        'sync_status': 'pending',
        'operation_id': operationId
      });
      await outboxManager.enqueueOperation(
          executor: txn,
          storeId: storeId,
          customOperationId: operationId,
          entityType: 'debtor',
          operationType: 'debtor.transact',
          entityId: (row['server_id'] ?? debtorId) as String,
          baseRevision:
              parentId == null ? (row['revision'] as num).toInt() : null,
          baseOperationId: parentId,
          dependsOn: parentId == null ? [] : [parentId],
          payload: {
            'debtor_id': (row['server_id'] ?? debtorId),
            'type': type,
            'amount': amount.toInt().toString(),
            'note': note,
            'payment_method': paymentMethod
          });
    });
  }

  Future<List<LocalDebtorTransaction>> getTransactions(String debtorId) async {
    final db = await _getDb();
    return (await db.query('debtor_transactions',
            where: 'debtor_id = ?',
            whereArgs: [debtorId],
            orderBy: 'date DESC'))
        .map(LocalDebtorTransaction.fromMap)
        .toList();
  }

  Future<void> updateDebtor(LocalDebtor debtor,
      {required String fullName,
      required String phone,
      required double amount,
      required String date,
      String? note}) async {
    await _ensureWrite();
    phone = PhoneFormat.forStorage(phone);
    if (fullName.trim().isEmpty ||
        !PhoneFormat.isValid(phone) ||
        !amount.isFinite ||
        amount < 0 ||
        amount != amount.roundToDouble() ||
        DateTime.tryParse(date) == null ||
        date.compareTo(DateTime.now().toIso8601String().substring(0, 10)) > 0) {
      throw ArgumentError(
          'Ism, telefon, sana va butun qarz summasini tekshiring.');
    }
    final db = await _getDb();
    await db.transaction((txn) async {
      final row = (await txn.query('debtors',
              where: 'id = ? AND store_id = ?',
              whereArgs: [debtor.id, debtor.storeId]))
          .single;
      if (row['can_update'] != 1) throw StateError('Tahrirlashga ruxsat yo‘q.');
      final operationId = await _enqueueChange(txn, row, 'debtor.update', {
        'full_name': fullName.trim(),
        'phone': phone,
        'amount': amount.toInt().toString(),
        'date': date,
        'note': note
      });
      final difference = amount - (row['amount'] as num).toDouble();
      if (difference != 0) {
        await txn.insert('debtor_transactions', {
          'id': const Uuid().v4(),
          'debtor_id': debtor.id,
          'type': difference > 0 ? 'debt' : 'payment',
          'amount': difference.abs(),
          'date': DateTime.now().toIso8601String(),
          'note': 'Qarz summasini tuzatish',
          'sync_status': 'pending',
          'operation_id': operationId
        });
      }
      await txn.update(
          'debtors',
          {
            'full_name': fullName.trim(),
            'phone': phone,
            'amount': amount,
            'date': date,
            'note': note
          },
          where: 'id = ?',
          whereArgs: [debtor.id]);
    });
  }

  Future<String> _enqueueChange(DatabaseExecutor txn, Map<String, Object?> row,
      String type, Map<String, dynamic> payload) async {
    final id = row['id'] as String;
    final serverId = row['server_id'] as String?;
    final previous = await txn.query('sync_outbox',
        where: "entity_type IN ('debtor','sale') AND status != 'applied'",
        orderBy: 'created_at DESC, rowid DESC');
    String? parent;
    for (final item in previous) {
      final envelope = jsonDecode(item['envelope_json'] as String) as Map;
      if (envelope['local_id'] == id ||
          envelope['payload']?['local_debtor_id'] == id ||
          envelope['entity_id'] == id ||
          (serverId != null && envelope['entity_id'] == serverId)) {
        parent = item['operation_id'] as String;
        break;
      }
    }
    return outboxManager.enqueueOperation(
        executor: txn,
        storeId: row['store_id'] as String,
        entityType: 'debtor',
        operationType: type,
        entityId: serverId ?? id,
        payload: payload,
        baseRevision: parent == null ? (row['revision'] as num).toInt() : null,
        baseOperationId: parent,
        dependsOn: parent == null ? [] : [parent]);
  }

  Future<Uint8List?> getPhoto(String debtorId) async {
    final rows = await (await _getDb())
        .query('debtor_media', where: 'debtor_id = ?', whereArgs: [debtorId]);
    return rows.isEmpty ? null : rows.first['bytes'] as Uint8List?;
  }

  Future<void> setPhoto(LocalDebtor debtor, Uint8List? bytes) async {
    await _ensureWrite();
    if (bytes != null && bytes.length > 10 * 1024 * 1024) {
      throw ArgumentError('Rasm 10 MB dan oshmasin.');
    }
    final db = await _getDb();
    await db.transaction((txn) async {
      final row = (await txn.query('debtors',
              where: 'id = ? AND store_id = ?',
              whereArgs: [debtor.id, debtor.storeId]))
          .single;
      if (row['can_update'] != 1) {
        throw StateError('Foto o‘zgartirishga ruxsat yo‘q.');
      }
      await _enqueueChange(txn, row, 'debtor.photo', {
        'action': bytes == null ? 'remove' : 'replace',
        if (bytes != null) 'image_base64': base64Encode(bytes)
      });
      await txn.insert('debtor_media',
          {'debtor_id': debtor.id, 'bytes': bytes, 'pending': 1},
          conflictAlgorithm: ConflictAlgorithm.replace);
    });
  }
}
