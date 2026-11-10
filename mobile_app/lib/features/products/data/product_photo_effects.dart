import 'dart:isolate';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:image/image.dart' as image;

const productPhotoMaxBytes = 1024 * 1024;
const _maxPhotoSide = 1600;

typedef PhotoParticle = ({double x, double y, int color});

class ProductPhotoEffect {
  final Uint8List jpeg;
  final double aspectRatio;
  final List<PhotoParticle> particles;

  const ProductPhotoEffect(this.jpeg, this.aspectRatio, this.particles);
}

typedef _PhotoFrame = ({
  int width,
  int height,
  TransferableTypedData rgb,
  int inputWidth,
  int inputHeight,
  Uint8List inputRgba,
});

class ProductPhotoEffects {
  static const _channel = MethodChannel('babyparfume/product_photo');

  static Future<ProductPhotoEffect?> blurBackground(Uint8List original) async {
    final frame = await compute(_preparePhoto, original);
    if (frame == null) return null;
    final result = await _channel.invokeMapMethod<String, Object?>('segment', {
      'width': frame.inputWidth,
      'height': frame.inputHeight,
      'rgba': frame.inputRgba,
    });
    final mask = result?['alpha'];
    final width = result?['width'];
    final height = result?['height'];
    if (mask is! Uint8List ||
        width is! int ||
        height is! int ||
        width < 1 ||
        height < 1 ||
        mask.length != width * height) {
      return null;
    }

    return compute(_composePhoto, (
      frame: frame,
      alpha: mask,
      maskWidth: width,
      maskHeight: height,
    ));
  }
}

_PhotoFrame? _preparePhoto(Uint8List bytes) {
  final decoded = image.decodeImage(bytes, frame: 0);
  if (decoded == null) return null;
  final orientation = decoded.exif.imageIfd.orientation;
  var photo = _limitSide(
      orientation == null || orientation == 1
          ? decoded
          : image.bakeOrientation(decoded),
      _maxPhotoSide);
  if (photo.hasAlpha) {
    final white = image.Image(width: photo.width, height: photo.height)
      ..clear(image.ColorRgb8(255, 255, 255));
    photo = image.compositeImage(white, photo);
  } else if (photo.format != image.Format.uint8 || photo.numChannels != 3) {
    photo = photo.convert(format: image.Format.uint8, numChannels: 3);
  }

  final input = _limitSide(photo, 512);
  return (
    width: photo.width,
    height: photo.height,
    rgb: TransferableTypedData.fromList(
        [photo.getBytes(order: image.ChannelOrder.rgb)]),
    inputWidth: input.width,
    inputHeight: input.height,
    inputRgba: input.getBytes(order: image.ChannelOrder.rgba, alpha: 255),
  );
}

ProductPhotoEffect? _composePhoto(
    ({
      _PhotoFrame frame,
      Uint8List alpha,
      int maskWidth,
      int maskHeight,
    }) request) {
  final (:frame, :alpha, :maskWidth, :maskHeight) = request;
  final foregroundPixels = alpha.where((value) => value >= 128).length;
  if (foregroundPixels < alpha.length * 0.01 ||
      foregroundPixels > alpha.length * 0.98) {
    return null;
  }

  final photo = image.Image.fromBytes(
    width: frame.width,
    height: frame.height,
    bytes: frame.rgb.materialize(),
    numChannels: 3,
  );
  final mask = image.Image.fromBytes(
    width: maskWidth,
    height: maskHeight,
    bytes: alpha.buffer,
    bytesOffset: alpha.offsetInBytes,
    numChannels: 1,
  );

  final smallPhoto = _limitSide(photo, 200);
  final smallMask = _resize(mask, smallPhoto.width, smallPhoto.height);
  final background = image.Image(
      width: smallPhoto.width, height: smallPhoto.height, numChannels: 4);
  // Blur weighted background colors so the flacon does not bleed into its halo.
  for (final pixel in background) {
    final source = smallPhoto.getPixel(pixel.x, pixel.y);
    final weight = 1 - smallMask.getPixel(pixel.x, pixel.y).r / 255;
    pixel.setRgba(
        source.r * weight, source.g * weight, source.b * weight, weight * 255);
  }
  final blurRadius =
      math.min(8, math.min(background.width, background.height) - 1);
  image.gaussianBlur(background, radius: blurRadius);

  final particles = _sampleParticles(photo, mask);
  final fullMask = _resize(mask, photo.width, photo.height);
  final fullBackground = _resize(background, photo.width, photo.height);
  final maskPixel = fullMask.getPixel(0, 0);
  final blurred = fullBackground.getPixel(0, 0);
  for (final pixel in photo) {
    maskPixel.setPosition(pixel.x, pixel.y);
    final sharp = maskPixel.r / 255;
    if (sharp == 1) continue;
    blurred.setPosition(pixel.x, pixel.y);
    if (blurred.a < 4) continue;

    final weight = (1 - sharp) * 255 / blurred.a;
    pixel.setRgb(
      (pixel.r * sharp + blurred.r * weight).round().clamp(0, 255),
      (pixel.g * sharp + blurred.g * weight).round().clamp(0, 255),
      (pixel.b * sharp + blurred.b * weight).round().clamp(0, 255),
    );
  }

  final jpeg = _encodeWithinLimit(photo);
  return jpeg == null
      ? null
      : ProductPhotoEffect(jpeg, frame.width / frame.height, particles);
}

List<PhotoParticle> _sampleParticles(image.Image photo, image.Image mask) {
  final random = math.Random(17);
  const columns = 28;
  final rows = (columns * photo.height / photo.width).round().clamp(8, 32);
  return [
    for (var row = 0; row < rows; row++)
      for (var column = 0; column < columns; column++)
        if (_backgroundParticle(
                photo,
                mask,
                (column + random.nextDouble()) / columns,
                (row + random.nextDouble()) / rows)
            case final particle?)
          particle,
  ];
}

PhotoParticle? _backgroundParticle(
    image.Image photo, image.Image mask, double x, double y) {
  if (mask.getPixel((x * mask.width).floor(), (y * mask.height).floor()).r >
      32) {
    return null;
  }
  final pixel =
      photo.getPixel((x * photo.width).floor(), (y * photo.height).floor());
  final color = 0xff000000 |
      (pixel.r.toInt() << 16) |
      (pixel.g.toInt() << 8) |
      pixel.b.toInt();
  return (x: x, y: y, color: color);
}

Uint8List? _encodeWithinLimit(image.Image photo) {
  for (final maxSide in [_maxPhotoSide, 1280, 960, 640]) {
    final resized = _limitSide(photo, maxSide);
    for (final quality in [85, 75, 65]) {
      final jpeg = image.encodeJpg(resized,
          quality: quality, chroma: image.JpegChroma.yuv420);
      if (jpeg.length <= productPhotoMaxBytes) return jpeg;
    }
  }
  return null;
}

image.Image _limitSide(image.Image source, int maxSide) {
  final longestSide = math.max(source.width, source.height);
  if (longestSide <= maxSide) return source;
  return _resize(
    source,
    math.max(1, (source.width * maxSide / longestSide).round()),
    math.max(1, (source.height * maxSide / longestSide).round()),
  );
}

image.Image _resize(image.Image source, int width, int height) =>
    image.copyResize(source,
        width: width,
        height: height,
        interpolation: image.Interpolation.linear);
