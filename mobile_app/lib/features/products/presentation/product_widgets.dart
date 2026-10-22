import 'dart:typed_data';
import 'package:flutter/material.dart';

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

class ProductImage extends StatelessWidget {
  final String? url;
  final Uint8List? bytes;
  final double height;
  const ProductImage({super.key, this.url, this.bytes, this.height = 180});

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    Widget placeholder() => Center(
        child: Icon(Icons.inventory_2_outlined,
            size: 56, color: colors.primary.withOpacity(0.5)));
    return ClipRRect(
        borderRadius: BorderRadius.circular(14),
        child: Container(
            height: height,
            width: double.infinity,
            color: colors.primary.withOpacity(0.05),
            child: bytes != null
                ? Image.memory(bytes!,
                    fit: BoxFit.contain,
                    errorBuilder: (_, __, ___) => placeholder())
                : url == null || url!.isEmpty
                    ? placeholder()
                    : Image.network(url!,
                        fit: BoxFit.contain,
                        errorBuilder: (_, __, ___) => placeholder(),
                        loadingBuilder: (_, child, progress) => progress == null
                            ? child
                            : const Center(
                                child: CircularProgressIndicator()))));
  }
}
