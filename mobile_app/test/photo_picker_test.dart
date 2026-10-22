import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';
import 'package:babyparfume_mobile/core/sync/outbox_manager.dart';
import 'package:babyparfume_mobile/core/utils/photo_picker.dart';
import 'package:babyparfume_mobile/features/debtors/data/debtors_repository.dart';
import 'package:babyparfume_mobile/features/debtors/presentation/debtor_detail_screen.dart';

class FakeImagePicker extends ImagePicker {
  XFile? image = XFile.fromData(Uint8List.fromList([1, 2, 3]));
  PlatformException? failure;
  ImageSource? source;
  double? width;
  double? height;
  int? quality;
  bool? fullMetadata;
  int calls = 0;

  @override
  Future<XFile?> pickImage(
      {required ImageSource source,
      double? maxWidth,
      double? maxHeight,
      int? imageQuality,
      CameraDevice preferredCameraDevice = CameraDevice.rear,
      bool requestFullMetadata = true}) async {
    calls++;
    this.source = source;
    width = maxWidth;
    height = maxHeight;
    quality = imageQuality;
    fullMetadata = requestFullMetadata;
    if (failure != null) throw failure!;
    return image;
  }
}

class PhotoDebtorsRepository extends DebtorsRepository {
  final debtor = LocalDebtor(
      id: '12',
      storeId: '1',
      fullName: 'Ali Valiyev',
      amount: 50000,
      canUpdate: true);
  int photoChanges = 0;
  PhotoDebtorsRepository() : super(outboxManager: OutboxManager());
  @override
  Future<LocalDebtor?> getDebtor(String id) async => debtor;
  @override
  Future<List<LocalDebtorTransaction>> getTransactions(String debtorId) async =>
      [];
  @override
  Future<Uint8List?> getPhoto(String debtorId) async => null;
  @override
  Future<void> setPhoto(LocalDebtor debtor, Uint8List? bytes) async {
    photoChanges++;
  }
}

void main() {
  late FakeImagePicker picker;
  Uint8List? result;
  Object? failure;

  setUp(() {
    picker = FakeImagePicker();
    result = null;
    failure = null;
  });

  Future<void> openPicker(WidgetTester tester,
      {int maxBytes = 10 * 1024 * 1024, bool dark = false}) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(
      theme: ThemeData(brightness: dark ? Brightness.dark : Brightness.light),
      home: Builder(
          builder: (context) => Scaffold(
              body: TextButton(
                  child: const Text('Rasm tanlash'),
                  onPressed: () async {
                    try {
                      result = await PhotoPicker.pick(context,
                          picker: picker, maxBytes: maxBytes);
                    } catch (error) {
                      failure = error;
                    }
                  }))),
    ));
    await tester.tap(find.text('Rasm tanlash'));
    await tester.pumpAndSettle();
  }

  for (final source in ImageSource.values) {
    for (final dark in [false, true]) {
      testWidgets('picks ${source.name} in ${dark ? 'dark' : 'light'} mode',
          (tester) async {
        await openPicker(tester, dark: dark);
        expect(find.text('Kamera'), findsOneWidget);
        expect(find.text('Galereya'), findsOneWidget);
        await tester.tap(
            find.text(source == ImageSource.camera ? 'Kamera' : 'Galereya'));
        await tester.pumpAndSettle();
        expect(picker.source, source);
        expect(picker.calls, 1);
        expect(picker.width, 1600);
        expect(picker.height, 1600);
        expect(picker.quality, 80);
        expect(picker.fullMetadata, isFalse);
        expect(result, [1, 2, 3]);
        expect(failure, isNull);
        expect(tester.takeException(), isNull);
      });
    }
  }

  testWidgets('cancelling the source chooser never opens the native picker',
      (tester) async {
    await openPicker(tester);
    await tester.tap(find.text('Bekor qilish'));
    await tester.pumpAndSettle();
    expect(picker.calls, 0);
    expect(result, isNull);
    expect(failure, isNull);
  });

  testWidgets('cancelling camera capture returns no replacement photo',
      (tester) async {
    picker.image = null;
    await openPicker(tester);
    await tester.tap(find.text('Kamera'));
    await tester.pumpAndSettle();
    expect(result, isNull);
    expect(failure, isNull);
  });

  for (final code in [
    'camera_access_denied',
    'photo_access_denied',
    'no_available_camera'
  ]) {
    testWidgets('$code gives an actionable error', (tester) async {
      picker.failure = PlatformException(code: code);
      await openPicker(tester);
      await tester.tap(
          find.text(code == 'photo_access_denied' ? 'Galereya' : 'Kamera'));
      await tester.pumpAndSettle();
      expect(result, isNull);
      expect(failure, isA<PhotoPickerException>());
      expect(
          failure.toString(),
          contains(
              code == 'no_available_camera' ? 'Kamera mavjud emas' : 'ruxsat'));
    });
  }

  testWidgets('product photo size limit is checked before upload',
      (tester) async {
    picker.image = XFile.fromData(Uint8List(1024 * 1024 + 1));
    await openPicker(tester, maxBytes: 1024 * 1024);
    await tester.tap(find.text('Galereya'));
    await tester.pumpAndSettle();
    expect(result, isNull);
    expect(failure, isA<PhotoPickerException>());
    expect(failure.toString(), contains('1 MB'));
  });

  testWidgets(
      'debtor photo offers both sources and preserves the photo on cancel',
      (tester) async {
    final repository = PhotoDebtorsRepository();
    await tester.pumpWidget(MaterialApp(
        home: DebtorDetailScreen(
            debtor: repository.debtor, repository: repository)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Foto tanlash'));
    await tester.pumpAndSettle();
    expect(find.text('Kamera'), findsOneWidget);
    expect(find.text('Galereya'), findsOneWidget);
    await tester.tap(find.text('Bekor qilish'));
    await tester.pumpAndSettle();
    expect(repository.photoChanges, 0);
    expect(tester.takeException(), isNull);
  });
}
