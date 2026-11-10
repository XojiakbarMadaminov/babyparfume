import 'dart:async';
import 'dart:typed_data';

import 'package:babyparfume_mobile/features/products/data/product_photo_effects.dart';
import 'package:babyparfume_mobile/features/products/presentation/product_photo_preview.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as image;

void main() {
  final original = _jpeg(0);
  final blurred = _jpeg(200);
  final effect = ProductPhotoEffect(blurred, 1, []);
  Uint8List? selected;

  setUp(() => selected = null);

  Future<void> openPreview(
    WidgetTester tester,
    Future<ProductPhotoEffect?> Function(Uint8List) processor,
  ) async {
    await tester.pumpWidget(MaterialApp(
      home: Builder(
        builder: (context) => Scaffold(
          body: TextButton(
            onPressed: () async {
              selected = await showProductPhotoPreview(
                context,
                original,
                blurBackground: processor,
              );
            },
            child: const Text('Rasmni ochish'),
          ),
        ),
      ),
    ));
    await tester.tap(find.text('Rasmni ochish'));
    await tester.pumpAndSettle();
  }

  testWidgets('selects the original without running segmentation',
      (tester) async {
    await openPreview(tester, (_) async {
      fail('Segmentation should start only when blur is requested.');
    });
    await tester.tap(find.text('Asl rasmni tanlash'));
    await tester.pumpAndSettle();
    expect(selected, same(original));
  });

  testWidgets('compares the two versions and returns the selected JPEG',
      (tester) async {
    await openPreview(tester, (input) async {
      expect(input, same(original));
      return effect;
    });
    final button = find.text('Fonni xiralashtirish');
    await tester.runAsync(() async {
      await precacheImage(
        ResizeImage(MemoryImage(blurred),
            width: 960, height: 960, policy: ResizeImagePolicy.fit),
        tester.element(button),
      );
    });
    await tester.tap(button);
    await tester.pumpAndSettle();
    expect(find.text('Xira fonli rasmni tanlash'), findsOneWidget);

    await tester.tap(find.text('Asl rasm'));
    await tester.pumpAndSettle();
    expect(find.text('Asl rasmni tanlash'), findsOneWidget);
    await tester.tap(find.text('Xira fon'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Xira fonli rasmni tanlash'));
    await tester.pumpAndSettle();
    expect(selected, same(blurred));
  });

  testWidgets('keeps the original available when blur fails', (tester) async {
    await openPreview(tester, (_) async => throw StateError('Unavailable'));
    await tester.tap(find.text('Fonni xiralashtirish'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Fonni xiralashtirib bo‘lmadi'), findsOneWidget);
    await tester.tap(find.text('Asl rasmni tanlash'));
    await tester.pumpAndSettle();
    expect(selected, same(original));
  });

  testWidgets('cancel during blur returns no replacement', (tester) async {
    final processing = Completer<ProductPhotoEffect?>();
    await openPreview(tester, (_) => processing.future);
    await tester.tap(find.text('Fonni xiralashtirish'));
    await tester.pump();
    expect(find.text('Fon xiralashtirilmoqda…'), findsOneWidget);
    await tester.tap(find.text('Bekor qilish'));
    await tester.pumpAndSettle();
    processing.complete(effect);
    await tester.pump();
    expect(selected, isNull);
    expect(tester.takeException(), isNull);
  });
}

Uint8List _jpeg(int red) => image.encodeJpg(
      image.Image(width: 32, height: 32)..clear(image.ColorRgb8(red, 20, 50)),
    );
