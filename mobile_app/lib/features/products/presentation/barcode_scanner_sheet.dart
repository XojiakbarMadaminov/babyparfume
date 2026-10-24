import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../data/barcode_guard.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import '../../../core/theme/app_colors.dart';

class BarcodeScannerSheet extends StatefulWidget {
  final void Function(String barcode) onBarcodeScanned;

  const BarcodeScannerSheet({
    super.key,
    required this.onBarcodeScanned,
  });

  @override
  State<BarcodeScannerSheet> createState() => _BarcodeScannerSheetState();
}

class _BarcodeScannerSheetState extends State<BarcodeScannerSheet> {
  final MobileScannerController _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    facing: CameraFacing.back,
    torchEnabled: false,
  );

  String? _lastScannedCode;
  final _guard = BarcodeGuard();
  DateTime? _lastScannedAt;
  bool _manualOpen = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_manualOpen || !mounted) return;
    final barcodes = capture.barcodes;
    if (barcodes.isEmpty) return;

    final code = barcodes.first.rawValue;
    if (code == null || code.trim().isEmpty) return;

    final now = DateTime.now();
    // Duplicate scan guard (1.5 seconds cooldown)
    if (_lastScannedCode == code &&
        _lastScannedAt != null &&
        now.difference(_lastScannedAt!) < const Duration(milliseconds: 1500)) {
      return;
    }

    _lastScannedCode = code;
    _lastScannedAt = now;

    final format = barcodes.first.format;
    if (!_guard.accept(code,
        verifyChecksum: [
          BarcodeFormat.ean13,
          BarcodeFormat.ean8,
          BarcodeFormat.upcA
        ].contains(format))) return;
    _controller.stop();
    HapticFeedback.lightImpact();
    Navigator.pop(context);
    widget.onBarcodeScanned(code);
  }

  Future<void> _showManualInputDialog() async {
    _manualOpen = true;
    await _controller.stop();
    if (!mounted) return;
    final textController = TextEditingController();
    final text = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text("Barkodni qo'lda kiritish"),
        content: TextField(
          controller: textController,
          autofocus: true,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(
            hintText: "Masalan: 4780012345671",
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text("Bekor qilish"),
          ),
          ElevatedButton(
            onPressed: () {
              if (textController.text.trim().isNotEmpty) {
                Navigator.pop(ctx, textController.text.trim());
              }
            },
            child: const Text("Qidirish"),
          ),
        ],
      ),
    );
    _manualOpen = false;
    if (!mounted) return;
    if (text != null && _guard.accept(text)) {
      Navigator.pop(context);
      widget.onBarcodeScanned(text);
    } else {
      await _controller.start();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      height: MediaQuery.of(context).size.height * 0.75,
      decoration: const BoxDecoration(
        color: Colors.black,
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      child: Stack(
        children: [
          ClipRRect(
            borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
            child: MobileScanner(
              controller: _controller,
              onDetect: _onDetect,
            ),
          ),

          // Top Header & Controls
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  IconButton(
                    icon:
                        const Icon(Icons.close, color: Colors.white, size: 28),
                    onPressed: () => Navigator.pop(context),
                  ),
                  Row(
                    children: [
                      IconButton(
                        icon: const Icon(Icons.flash_on, color: Colors.white),
                        onPressed: () => _controller.toggleTorch(),
                      ),
                      IconButton(
                        icon: const Icon(Icons.flip_camera_ios,
                            color: Colors.white),
                        onPressed: () => _controller.switchCamera(),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),

          // Aim overlay
          Center(
            child: Container(
              width: 250,
              height: 250,
              decoration: BoxDecoration(
                border:
                    Border.all(color: AppColors.of(context).accent, width: 2),
                borderRadius: BorderRadius.circular(16),
              ),
            ),
          ),

          // Bottom Manual input button
          Positioned(
            bottom: 24,
            left: 24,
            right: 24,
            child: SafeArea(
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.of(context).card,
                  foregroundColor: AppColors.of(context).text,
                ),
                icon: const Icon(Icons.keyboard),
                label: const Text("Barkodni qo'lda kiritish"),
                onPressed: _showManualInputDialog,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
