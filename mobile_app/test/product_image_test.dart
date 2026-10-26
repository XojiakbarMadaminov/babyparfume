import 'dart:convert';
import 'dart:typed_data';
import 'package:babyparfume_mobile/core/storage/secure_storage.dart';
import 'package:babyparfume_mobile/features/products/presentation/product_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  setUp(() async {
    FlutterSecureStorage.setMockInitialValues({});
    await SecureStorageService()
        .setServerUrl('https://shop.test/api/flutter/v1');
  });

  for (final path in [
    '/photos/12/conversions/product.webp',
    'photos/12/conversions/product.webp',
    'https://cdn.test/product.webp',
  ]) {
    testWidgets('resolves product image $path', (tester) async {
      await tester.pumpWidget(
          MaterialApp(home: Scaffold(body: ProductImage(url: path))));
      await tester.pumpAndSettle();

      final image = tester.widget<Image>(find.byType(Image));
      expect(
          (image.image as NetworkImage).url,
          path.startsWith('https:')
              ? path
              : 'https://shop.test/${path.replaceFirst(RegExp(r'^/'), '')}');
    });
  }

  testWidgets('updates image and clears it when removed', (tester) async {
    for (final path in ['/photos/first.webp', '/photos/second.webp', null]) {
      await tester.pumpWidget(
          MaterialApp(home: Scaffold(body: ProductImage(url: path))));
      await tester.pumpAndSettle();

      if (path == null) {
        expect(find.byType(Image), findsNothing);
        expect(find.byIcon(Icons.inventory_2_outlined), findsOneWidget);
      } else {
        final image = tester.widget<Image>(find.byType(Image));
        expect((image.image as NetworkImage).url, 'https://shop.test$path');
      }
    }
  });

  testWidgets('selected photo bytes take priority over server image',
      (tester) async {
    final bytes = Uint8List.fromList(base64Decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII='));
    await tester.pumpWidget(MaterialApp(
        home: Scaffold(
            body: ProductImage(url: '/photos/product.webp', bytes: bytes))));
    await tester.pumpAndSettle();

    final image = tester.widget<Image>(find.byType(Image));
    expect(image.image, isA<MemoryImage>());
    expect((image.image as MemoryImage).bytes, bytes);
  });
}
