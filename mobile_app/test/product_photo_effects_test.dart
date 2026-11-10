import 'dart:math';

import 'package:babyparfume_mobile/features/products/data/product_photo_effects.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as image;

const _channel = MethodChannel('babyparfume/product_photo');

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  final messenger =
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;

  tearDown(() => messenger.setMockMethodCallHandler(_channel, null));

  test('keeps the masked subject sharp and blurs detail in the background',
      () async {
    final photo = image.Image(width: 160, height: 120);
    for (final pixel in photo) {
      final inSubject = pixel.x >= 48 && pixel.x < 112;
      final stripe = (pixel.x ~/ 4).isEven ? 16 : 240;
      pixel.setRgb(stripe, inSubject ? 40 : stripe, inSubject ? 30 : stripe);
    }

    messenger.setMockMethodCallHandler(_channel, (call) async {
      expect(call.method, 'segment');
      expect(call.arguments['width'], 160);
      expect(call.arguments['height'], 120);
      expect((call.arguments['rgba'] as Uint8List).length, 160 * 120 * 4);
      return _mask(40, 30, (x, _) => x >= 12 && x < 28);
    });

    final effect =
        await ProductPhotoEffects.blurBackground(image.encodePng(photo));
    expect(effect, isNotNull);
    final processed = image.decodeJpg(effect!.jpeg)!;
    final backgroundContrast =
        (processed.getPixel(5, 60).r - processed.getPixel(10, 60).r).abs();
    final subjectContrast =
        (processed.getPixel(65, 60).r - processed.getPixel(70, 60).r).abs();
    expect(backgroundContrast, lessThan(30));
    expect(subjectContrast, greaterThan(160));
    expect(effect.particles, isNotEmpty);
    expect(
        effect.particles
            .every((particle) => particle.x < 0.3 || particle.x >= 0.7),
        isTrue);
  });

  test('compresses a detailed oversized image below the upload limit',
      () async {
    final photo = image.Image(width: 1660, height: 1610);
    final random = Random(12);
    for (final pixel in photo) {
      pixel.setRgb(
          random.nextInt(256), random.nextInt(256), random.nextInt(256));
    }
    messenger.setMockMethodCallHandler(_channel, (call) async {
      expect(call.arguments['width'], 512);
      expect(call.arguments['height'], lessThan(512));
      return _mask(32, 32, (x, y) => x > 1 && x < 30 && y > 1 && y < 30);
    });

    final effect = await ProductPhotoEffects.blurBackground(
        image.encodeJpg(photo, quality: 95));
    expect(effect, isNotNull);
    expect(effect!.jpeg.length, lessThanOrEqualTo(productPhotoMaxBytes));
    final processed = image.decodeJpg(effect.jpeg)!;
    expect(max(processed.width, processed.height), lessThanOrEqualTo(1600));
    expect(processed.width / processed.height, closeTo(1660 / 1610, 0.002));
  });

  test('rejects a mask without a subject or without a background', () async {
    final bytes = image.encodePng(image.Image(width: 32, height: 24));
    for (final hasSubject in [false, true]) {
      messenger.setMockMethodCallHandler(
          _channel, (_) async => _mask(8, 8, (_, __) => hasSubject));
      expect(await ProductPhotoEffects.blurBackground(bytes), isNull);
    }
  });

  test('returns the original as an option when segmentation is unavailable',
      () async {
    messenger.setMockMethodCallHandler(_channel, (_) async => null);
    final bytes = image.encodePng(image.Image(width: 32, height: 24));
    expect(await ProductPhotoEffects.blurBackground(bytes), isNull);
  });
}

Map<String, Object> _mask(
    int width, int height, bool Function(int x, int y) inSubject) {
  return {
    'width': width,
    'height': height,
    'alpha': Uint8List.fromList(List.generate(
        width * height, (i) => inSubject(i % width, i ~/ width) ? 255 : 0)),
  };
}
