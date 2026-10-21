import 'package:flutter_test/flutter_test.dart';
import 'package:babyparfume_mobile/core/utils/currency_format.dart';
import 'package:babyparfume_mobile/core/utils/phone_format.dart';
import 'package:babyparfume_mobile/core/utils/date_format.dart';
import 'package:babyparfume_mobile/core/lease/lease_manager.dart';
import 'package:babyparfume_mobile/core/sync/sync_models.dart';
import 'package:babyparfume_mobile/core/sync/outbox_manager.dart';
import 'package:babyparfume_mobile/features/debtors/data/debtors_repository.dart';
import 'package:babyparfume_mobile/features/products/data/products_repository.dart';

void main() {
  group('Formatting utilities', () {
    test('CurrencyFormat formats numbers correctly with uzs suffix', () {
      expect(CurrencyFormat.format(150000), "150 000 so'm");
      expect(CurrencyFormat.format(1250000), "1 250 000 so'm");
      expect(CurrencyFormat.format(0), "0 so'm");
      expect(CurrencyFormat.formatExact(2500000), "2 500 000");
    });

    test('PhoneFormat formats and normalizes phone numbers canonical to Uzbekistan', () {
      expect(PhoneFormat.format('+998901234567'), '+998 90 123 45 67');
      expect(PhoneFormat.format('901234567'), '+998 90 123 45 67');
      expect(PhoneFormat.normalize('+998 (90) 123-45-67'), '+998901234567');
      expect(PhoneFormat.normalize('901234567'), '+998901234567');
    });

    test('AppDateFormat formats dates predictably', () {
      final date = DateTime(2026, 10, 21, 14, 30);
      expect(AppDateFormat.formatDate(date), '21.10.2026');
      expect(AppDateFormat.formatTime(date), '14:30');
    });
  });

  group('LeaseState tests', () {
    test('active lease is unlocked when expiry is in future', () {
      final futureExpiry = DateTime.now().add(const Duration(hours: 5));
      final lease = LeaseState.active(futureExpiry);
      expect(lease.isLocked, isFalse);
      expect(lease.remainingTime!.inHours, greaterThanOrEqualTo(4));
    });

    test('expired lease triggers lock state', () {
      final pastExpiry = DateTime.now().subtract(const Duration(minutes: 1));
      final lease = LeaseState.active(pastExpiry);
      expect(lease.isLocked, isTrue);
      expect(lease.reason, contains('10 soatlik oflayn litsenziya muddati tugadi'));
    });
  });

  group('SyncModels tests', () {
    test('SyncProgress default and copyWith states', () {
      const initial = SyncProgress(status: SyncStatus.idle);
      expect(initial.status, SyncStatus.idle);
      expect(initial.pendingOperationsCount, 0);

      final updated = initial.copyWith(
        status: SyncStatus.hasPendingOperations,
        pendingOperationsCount: 3,
        currentWatermark: 42,
      );

      expect(updated.status, SyncStatus.hasPendingOperations);
      expect(updated.pendingOperationsCount, 3);
      expect(updated.currentWatermark, 42);
    });

    test('OutboxItem stores required fields', () {
      final item = OutboxItem(
        operationId: 'test-uuid-123',
        entityType: 'sale',
        operationType: 'sale.create',
        payload: {'total_amount': '150000.00'},
        createdAt: DateTime.now(),
      );

      expect(item.operationId, 'test-uuid-123');
      expect(item.status, 'pending');
      expect(item.payload['total_amount'], '150000.00');
    });
  });

  group('Debtors data models tests', () {
    test('LocalDebtor fromMap correctly parses SQLite rows', () {
      final map = {
        'id': 'deb-1',
        'store_id': '1',
        'client_id': '10',
        'full_name': 'Ali Valiyev',
        'phone': '+998901234567',
        'amount': 350000.0,
        'currency': 'UZS',
        'date': '2026-10-21T12:00:00',
        'note': 'Eski qarz',
        'photo_path': null,
        'updated_at': '2026-10-21T12:00:00',
      };

      final debtor = LocalDebtor.fromMap(map);
      expect(debtor.id, 'deb-1');
      expect(debtor.fullName, 'Ali Valiyev');
      expect(debtor.amount, 350000.0);
      expect(debtor.phone, '+998901234567');
    });

    test('LocalDebtorTransaction fromMap correctly parses transactions', () {
      final map = {
        'id': 'txn-1',
        'debtor_id': 'deb-1',
        'type': 'payment',
        'amount': 100000.0,
        'date': '2026-10-21T14:00:00',
        'note': 'Karta orqali qaytarildi',
        'sync_status': 'pending',
      };

      final txn = LocalDebtorTransaction.fromMap(map);
      expect(txn.id, 'txn-1');
      expect(txn.type, 'payment');
      expect(txn.amount, 100000.0);
      expect(txn.syncStatus, 'pending');
    });
  });

  group('Products and Barcode tests', () {
    test('ProductsRepository generateBarcode produces valid 13-digit EAN', () {
      final repo = ProductsRepository(outboxManager: OutboxManager());
      final barcode = repo.generateBarcode();
      expect(barcode.length, 13);
      expect(barcode.startsWith('478'), isTrue);

      // Verify EAN-13 check digit algorithm
      int checksum = 0;
      for (int i = 0; i < 12; i++) {
        final digit = int.parse(barcode[i]);
        checksum += (i % 2 == 0) ? digit : digit * 3;
      }
      final expectedCheckDigit = (10 - (checksum % 10)) % 10;
      expect(int.parse(barcode[12]), expectedCheckDigit);
    });

    test('LocalProduct fromMap parses stock quantity and prices', () {
      final map = {
        'id': 'prod-1',
        'store_id': '1',
        'name': 'Dior Sauvage 100ml',
        'barcode': '4780012345678',
        'type': 'package',
        'price': 1400000.0,
        'initial_price': 1350000.0,
        'updated_at': '2026-10-21T12:00:00',
      };

      final product = LocalProduct.fromMap(map, stockQuantity: 5);
      expect(product.id, 'prod-1');
      expect(product.name, 'Dior Sauvage 100ml');
      expect(product.stockQuantity, 5);
      expect(product.price, 1400000.0);
    });
  });
}
