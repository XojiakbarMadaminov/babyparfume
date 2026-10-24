import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';
import '../../../core/database/app_database.dart';
import '../../../core/sync/outbox_manager.dart';
import '../../../core/utils/phone_format.dart';

class LocalClient {
  final String id;
  final String name;
  final String? phone;
  LocalClient({required this.id, required this.name, this.phone});
  factory LocalClient.fromMap(Map<String, Object?> row) => LocalClient(
      id: row['id'] as String,
      name: row['full_name'] as String,
      phone: row['phone'] as String?);
}

class ClientsRepository {
  final OutboxManager outbox;
  final Future<Database> Function() _getDb;
  final Future<void> Function() _ensureWrite;
  ClientsRepository(
      {required this.outbox,
      Future<Database> Function()? getDb,
      Future<void> Function()? ensureWrite})
      : _getDb = getDb ?? AppDatabase.getInstance,
        _ensureWrite = ensureWrite ?? AppDatabase.requireWriteAccess;
  Future<List<LocalClient>> all(String storeId) async =>
      (await (await _getDb()).query('clients',
              where: 'store_id = ?',
              whereArgs: [storeId],
              orderBy: 'full_name'))
          .map(LocalClient.fromMap)
          .toList();
  Future<LocalClient> create(
      String storeId, String name, String rawPhone) async {
    await _ensureWrite();
    final phone = PhoneFormat.forStorage(rawPhone);
    if (name.trim().isEmpty ||
        name.length > 255 ||
        !PhoneFormat.isValid(phone)) {
      throw ArgumentError('Ism va telefon raqamini tekshiring.');
    }
    final db = await _getDb();
    final id = const Uuid().v4();
    final row = <String, Object?>{
      'id': id,
      'store_id': storeId,
      'full_name': name.trim(),
      'phone': phone
    };
    await db.transaction((txn) async {
      final aliases = PhoneFormat.aliases(phone);
      if ((await txn.query('clients',
              where: 'phone IN (${List.filled(aliases.length, '?').join(',')})',
              whereArgs: aliases))
          .isNotEmpty) {
        throw StateError('Bu telefonli mijoz mavjud. Ro‘yxatdan tanlang.');
      }
      await txn.insert('clients', row);
      await outbox.enqueueOperation(
          executor: txn,
          storeId: storeId,
          entityType: 'client',
          operationType: 'client.create',
          localId: id,
          payload: {'full_name': name.trim(), 'phone': phone});
    });
    return LocalClient.fromMap(row);
  }
}
