import 'dart:math' as math;
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../data/product_photo_effects.dart';

Future<Uint8List?> showProductPhotoPreview(
  BuildContext context,
  Uint8List original, {
  Future<ProductPhotoEffect?> Function(Uint8List) blurBackground =
      ProductPhotoEffects.blurBackground,
}) =>
    showDialog<Uint8List>(
      context: context,
      builder: (_) => _ProductPhotoPreview(
        original: original,
        blurBackground: blurBackground,
      ),
    );

class _ProductPhotoPreview extends StatefulWidget {
  final Uint8List original;
  final Future<ProductPhotoEffect?> Function(Uint8List) blurBackground;

  const _ProductPhotoPreview({
    required this.original,
    required this.blurBackground,
  });

  @override
  State<_ProductPhotoPreview> createState() => _ProductPhotoPreviewState();
}

class _ProductPhotoPreviewState extends State<_ProductPhotoPreview>
    with SingleTickerProviderStateMixin {
  late final AnimationController _animation;
  ProductPhotoEffect? _effect;
  bool _processing = false;
  bool _showBlurred = false;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _animation = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 700),
      value: 1,
    );
  }

  @override
  void dispose() {
    _animation.dispose();
    super.dispose();
  }

  Future<void> _blurBackground() async {
    if (_processing) return;
    setState(() {
      _processing = true;
      _failed = false;
    });
    ProductPhotoEffect? effect;
    try {
      effect = await widget.blurBackground(widget.original);
      if (!mounted) return;
      if (effect != null) {
        await precacheImage(_previewImage(effect.jpeg), context);
      }
    } catch (_) {
      effect = null;
    }
    if (!mounted) return;

    setState(() {
      _effect = effect;
      _showBlurred = effect != null;
      _failed = effect == null;
      _processing = false;
    });
    if (effect != null && !MediaQuery.disableAnimationsOf(context)) {
      _animation.forward(from: 0);
    }
  }

  void _showVersion(bool showBlurred) {
    _animation.value = 1;
    setState(() => _showBlurred = showBlurred);
  }

  @override
  Widget build(BuildContext context) {
    final effect = _effect;
    final displayedPhoto =
        _showBlurred && effect != null ? effect.jpeg : widget.original;
    return AlertDialog(
      title: const Text('Mahsulot rasmi'),
      scrollable: true,
      content: SizedBox(
        width: 400,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              height: math.min(300, MediaQuery.sizeOf(context).height * 0.38),
              child: ClipRect(
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    Image(
                      image: _previewImage(displayedPhoto),
                      fit: BoxFit.contain,
                      gaplessPlayback: true,
                      semanticLabel:
                          _showBlurred ? 'Xiralashtirilgan rasm' : 'Asl rasm',
                    ),
                    if (_showBlurred &&
                        effect != null &&
                        !MediaQuery.disableAnimationsOf(context))
                      IgnorePointer(
                        child: RepaintBoundary(
                          child: CustomPaint(
                            painter: _PhotoParticles(effect, _animation),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 12),
            if (_processing) ...[
              const LinearProgressIndicator(),
              const SizedBox(height: 8),
              const Text('Fon xiralashtirilmoqda…'),
            ] else if (effect != null)
              SegmentedButton<bool>(
                segments: const [
                  ButtonSegment(value: false, label: Text('Asl rasm')),
                  ButtonSegment(value: true, label: Text('Xira fon')),
                ],
                selected: {_showBlurred},
                onSelectionChanged: (value) => _showVersion(value.single),
              )
            else
              OutlinedButton.icon(
                onPressed: _blurBackground,
                icon: const Icon(Icons.blur_on),
                label: const Text('Fonni xiralashtirish'),
              ),
            if (_failed)
              const Text(
                'Fonni xiralashtirib bo‘lmadi. Asl rasmni ishlatishingiz mumkin.',
              ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Bekor qilish'),
        ),
        FilledButton(
          onPressed: () => Navigator.pop(context, displayedPhoto),
          child: Text(_showBlurred
              ? 'Xira fonli rasmni tanlash'
              : 'Asl rasmni tanlash'),
        ),
      ],
    );
  }
}

ResizeImage _previewImage(Uint8List bytes) => ResizeImage(
      MemoryImage(bytes),
      width: 960,
      height: 960,
      policy: ResizeImagePolicy.fit,
    );

class _PhotoParticles extends CustomPainter {
  final ProductPhotoEffect effect;
  final Animation<double> progress;

  _PhotoParticles(this.effect, this.progress) : super(repaint: progress);

  @override
  void paint(Canvas canvas, Size size) {
    final time = progress.value;
    if (time >= 1 || size.isEmpty) return;

    final width = math.min(size.width, size.height * effect.aspectRatio);
    final height = width / effect.aspectRatio;
    final bounds = Rect.fromCenter(
        center: size.center(Offset.zero), width: width, height: height);
    final movement = Curves.easeOut.transform(time);
    final opacity = (1 - time) * 0.86;
    final paint = Paint();

    canvas.save();
    canvas.clipRect(bounds);
    for (final particle in effect.particles) {
      final spread = 0.22 + (particle.color & 7) * 0.04;
      final dx = particle.x + (particle.x - 0.5) * spread * movement;
      final dy = particle.y - spread * movement * 0.48;
      paint.color = Color(particle.color).withOpacity(opacity);
      canvas.drawCircle(
        Offset(bounds.left + dx * width, bounds.top + dy * height),
        (1.0 + (particle.color & 3) * 0.28) * (1 - time * 0.5),
        paint,
      );
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant _PhotoParticles oldDelegate) =>
      oldDelegate.effect != effect || oldDelegate.progress != progress;
}
