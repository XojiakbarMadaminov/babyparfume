import 'dart:typed_data';
import 'package:flutter/material.dart';
import '../../../core/storage/secure_storage.dart';

class ProductSection extends StatelessWidget {
  final String title;
  final IconData icon;
  final List<Widget> children;
  const ProductSection(
      {super.key,
      required this.title,
      required this.icon,
      required this.children});

  @override
  Widget build(BuildContext context) => Card(
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(children: [
                Icon(icon,
                    size: 20, color: Theme.of(context).colorScheme.primary),
                const SizedBox(width: 10),
                Expanded(
                    child: Text(title,
                        style: Theme.of(context).textTheme.titleMedium)),
              ]),
              for (final child in children) ...[
                const SizedBox(height: 16),
                child,
              ],
            ],
          ),
        ),
      );
}

class ProductImage extends StatefulWidget {
  final String? url;
  final Uint8List? bytes;
  final double height;
  const ProductImage({super.key, this.url, this.bytes, this.height = 180});

  @override
  State<ProductImage> createState() => _ProductImageState();
}

class _ProductImageState extends State<ProductImage> {
  late Future<String?> _resolvedUrl;

  @override
  void initState() {
    super.initState();
    _resolvedUrl = _resolveUrl();
  }

  @override
  void didUpdateWidget(covariant ProductImage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.url != widget.url || oldWidget.bytes != widget.bytes) {
      _resolvedUrl = _resolveUrl();
    }
  }

  Future<String?> _resolveUrl() async {
    final value = widget.url?.trim();
    if (widget.bytes != null || value == null || value.isEmpty) {
      return null;
    }
    final uri = Uri.parse(value);
    if (uri.hasScheme) {
      return value;
    }
    final serverUrl = Uri.parse(await SecureStorageService().getServerUrl());
    return serverUrl.resolve('/').resolveUri(uri).toString();
  }

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    Widget placeholder() => Center(
        child: Icon(Icons.inventory_2_outlined,
            size: 56, color: colors.primary.withOpacity(0.5)));
    return ClipRRect(
        borderRadius: BorderRadius.circular(14),
        child: Container(
            height: widget.height,
            width: double.infinity,
            color: colors.primary.withOpacity(0.05),
            child: widget.bytes != null
                ? Image.memory(widget.bytes!,
                    fit: BoxFit.contain,
                    errorBuilder: (_, __, ___) => placeholder())
                : FutureBuilder<String?>(
                    future: _resolvedUrl,
                    builder: (context, snapshot) {
                      if (snapshot.connectionState != ConnectionState.done ||
                          snapshot.hasError ||
                          snapshot.data == null) {
                        return placeholder();
                      }
                      return Image.network(snapshot.data!,
                          fit: BoxFit.contain,
                          errorBuilder: (_, __, ___) => placeholder(),
                          loadingBuilder: (_, child, progress) =>
                              progress == null
                                  ? child
                                  : const Center(
                                      child: CircularProgressIndicator()));
                    })));
  }
}
