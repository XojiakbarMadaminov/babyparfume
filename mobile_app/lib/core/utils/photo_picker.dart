import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';

class PhotoPickerException implements Exception {
  final String message;
  const PhotoPickerException(this.message);

  @override
  String toString() => message;
}

class PhotoPicker {
  static Future<Uint8List?> pick(BuildContext context,
      {ImagePicker? picker, int maxBytes = 10 * 1024 * 1024}) async {
    FocusScope.of(context).unfocus();
    final source = await showModalBottomSheet<ImageSource>(
        context: context,
        useSafeArea: true,
        builder: (sheetContext) => SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Padding(
                        padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                        child: Text('Rasm yuklash',
                            style:
                                Theme.of(sheetContext).textTheme.titleLarge)),
                    ListTile(
                        leading: const Icon(Icons.camera_alt_outlined),
                        title: const Text('Kamera'),
                        subtitle: const Text('Yangi rasmga olish'),
                        onTap: () =>
                            Navigator.pop(sheetContext, ImageSource.camera)),
                    ListTile(
                        leading: const Icon(Icons.photo_library_outlined),
                        title: const Text('Galereya'),
                        subtitle: const Text('Qurilmadan rasm tanlash'),
                        onTap: () =>
                            Navigator.pop(sheetContext, ImageSource.gallery)),
                    const SizedBox(height: 8),
                    TextButton(
                        onPressed: () => Navigator.pop(sheetContext),
                        child: const Text('Bekor qilish')),
                  ],
                ),
              ),
            ));
    if (source == null || !context.mounted) return null;
    try {
      final image = await (picker ?? ImagePicker()).pickImage(
          source: source,
          maxWidth: 1600,
          maxHeight: 1600,
          imageQuality: 80,
          requestFullMetadata: false);
      if (image == null) return null;
      final bytes = await image.readAsBytes();
      if (bytes.length > maxBytes) {
        throw PhotoPickerException(
            'Rasm ${maxBytes ~/ (1024 * 1024)} MB dan oshmasin. Kichikroq rasm tanlang.');
      }
      return bytes;
    } on PlatformException catch (error) {
      final message = switch (error.code) {
        'camera_access_denied' ||
        'camera_access_denied_without_prompt' ||
        'camera_access_restricted' =>
          'Kameraga ruxsat berilmagan. Qurilma sozlamalaridan kameraga ruxsat bering.',
        'photo_access_denied' ||
        'photo_access_denied_without_prompt' ||
        'photo_access_restricted' =>
          'Galereyaga ruxsat berilmagan. Qurilma sozlamalaridan rasmlarga ruxsat bering.',
        'no_available_camera' ||
        'camera_unavailable' =>
          'Kamera mavjud emas. Galereyadan rasm tanlang.',
        _ => 'Rasmni ochib bo‘lmadi. Qayta urinib ko‘ring.',
      };
      throw PhotoPickerException(message);
    }
  }
}
