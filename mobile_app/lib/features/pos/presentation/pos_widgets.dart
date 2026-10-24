import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_theme.dart';

class PosStyle {
  PosStyle._();

  static const background = Color(0xFF121316);
  static const card = Color(0xFF1C1E23);
  static const inset = Color(0xFF181A20);
  static const line = Color(0x14FFFFFF);
  static const muted = Color(0xFFA5A7B0);
  static const accent = Color(0xFFF59E0B);
  static const accentText = Color(0xFF201607);
  static const blue = Color(0xFF3B82F6);
  static const green = Color(0xFF10B981);
  static const danger = Color(0xFFEF4444);

  static String money(num value) =>
      NumberFormat('#,##0.##', 'en_US').format(value).replaceAll(',', ' ');

  static Color surface(BuildContext context) => Theme.of(context).cardColor;
  static Color border(BuildContext context) =>
      Theme.of(context).colorScheme.outlineVariant;
  static Color secondaryText(BuildContext context) =>
      Theme.of(context).colorScheme.onSurfaceVariant;

  static PosPalette colors(BuildContext context) =>
      PosPalette(Theme.of(context).brightness == Brightness.dark);

  static ThemeData theme(BuildContext context, {Brightness? brightness}) {
    final mode = brightness ?? Theme.of(context).brightness;
    final palette = PosPalette(mode == Brightness.dark);
    final base = palette.isDark ? AppTheme.darkTheme() : AppTheme.lightTheme();
    return base.copyWith(
        scaffoldBackgroundColor: palette.background,
        cardColor: palette.card,
        colorScheme: base.colorScheme.copyWith(
            primary: palette.accent,
            onPrimary: palette.accentText,
            surface: palette.card,
            onSurface: palette.text,
            onSurfaceVariant: palette.muted,
            outlineVariant: palette.line),
        textTheme: base.textTheme
            .apply(bodyColor: palette.text, displayColor: palette.text),
        dividerColor: palette.line,
        iconTheme: IconThemeData(color: palette.muted, size: 22),
        bottomSheetTheme: base.bottomSheetTheme.copyWith(
            backgroundColor: palette.background,
            modalBackgroundColor: palette.background));
  }
}

class PosPalette extends AppPalette {
  const PosPalette(super.isDark);
  @override
  Color get background => isDark ? PosStyle.background : super.background;
  @override
  Color get card => isDark ? PosStyle.card : super.card;
  Color get inset => isDark ? PosStyle.inset : super.soft;
  @override
  Color get muted => isDark ? PosStyle.muted : super.muted;
  @override
  Color get line => isDark ? PosStyle.line : super.line;
  @override
  Color get accent => isDark ? PosStyle.accent : super.accent;
  Color get accentText => AppColors.onColor(accent);
  Color get blue => info;
  Color get green => success;
}

class PosPanel extends StatelessWidget {
  final Widget child;
  final Color? borderColor;
  final Color? color;
  final EdgeInsetsGeometry padding;

  const PosPanel(
      {super.key,
      required this.child,
      this.borderColor,
      this.color,
      this.padding = const EdgeInsets.all(14)});

  @override
  Widget build(BuildContext context) => Container(
      padding: padding,
      decoration: BoxDecoration(
          color: color ?? PosStyle.surface(context),
          border: Border.all(color: borderColor ?? PosStyle.border(context)),
          borderRadius: BorderRadius.circular(14)),
      child: child);
}

class PosButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final Color? color;
  final Color? foreground;
  final bool outlined;
  final bool loading;
  final double height;

  const PosButton(
      {super.key,
      required this.label,
      required this.onPressed,
      this.icon,
      this.color,
      this.foreground,
      this.outlined = false,
      this.loading = false,
      this.height = 50});

  @override
  Widget build(BuildContext context) {
    final background = color ?? PosStyle.colors(context).accent;
    return FilledButton(
        style: FilledButton.styleFrom(
            minimumSize: Size(0, height),
            padding: EdgeInsets.symmetric(
                horizontal: 14, vertical: height <= 44 ? 8 : 12),
            backgroundColor: outlined ? PosStyle.surface(context) : background,
            foregroundColor: foreground ??
                (outlined
                    ? Theme.of(context).colorScheme.onSurface
                    : AppColors.onColor(background)),
            disabledBackgroundColor: background.withOpacity(.25),
            disabledForegroundColor: PosStyle.secondaryText(context),
            textStyle: const TextStyle(
                fontFamily: 'Roboto',
                fontSize: 14,
                fontWeight: FontWeight.w700),
            shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(14),
                side: outlined
                    ? BorderSide(color: PosStyle.border(context))
                    : BorderSide.none)),
        onPressed: loading ? null : onPressed,
        child: Row(
            mainAxisSize: MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Flexible(child: Text(label, textAlign: TextAlign.center)),
              if (icon != null || loading) ...[
                const SizedBox(width: 8),
                if (loading)
                  const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2))
                else
                  Icon(icon, size: 19),
              ]
            ]));
  }
}

class PosIconButton extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback? onPressed;
  final Color? color;

  const PosIconButton(
      {super.key,
      required this.icon,
      required this.label,
      required this.onPressed,
      this.color});

  @override
  Widget build(BuildContext context) => IconButton(
      tooltip: label,
      onPressed: onPressed,
      icon: Icon(icon, size: 21),
      style: IconButton.styleFrom(
          foregroundColor: color ?? Theme.of(context).colorScheme.onSurface,
          backgroundColor: PosStyle.surface(context),
          minimumSize: const Size(44, 44),
          shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(14),
              side: BorderSide(color: PosStyle.border(context)))));
}

class PosSheetHeader extends StatelessWidget {
  final String title;
  final VoidCallback? onClose;
  final Widget? leading;
  final Widget? action;

  const PosSheetHeader(
      {super.key,
      required this.title,
      required this.onClose,
      this.leading,
      this.action});

  @override
  Widget build(BuildContext context) => Container(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
      decoration: BoxDecoration(
          border:
              Border(bottom: BorderSide(color: PosStyle.colors(context).line))),
      child: Row(children: [
        if (leading != null) ...[leading!, const SizedBox(width: 8)],
        Expanded(
            child: Text(title,
                style: const TextStyle(
                    fontSize: 18, fontWeight: FontWeight.w700))),
        if (action != null) ...[action!, const SizedBox(width: 8)],
        PosIconButton(
            icon: Icons.close,
            label: '$title oynasini yopish',
            onPressed: onClose)
      ]));
}

class PosChip extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback? onPressed;

  const PosChip(
      {super.key,
      required this.label,
      this.selected = false,
      required this.onPressed});

  @override
  Widget build(BuildContext context) => Semantics(
      selected: selected,
      child: TextButton(
          onPressed: onPressed,
          style: TextButton.styleFrom(
              minimumSize: const Size(0, 40),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
              backgroundColor: selected
                  ? PosStyle.colors(context).accent
                  : PosStyle.surface(context),
              foregroundColor: selected
                  ? PosStyle.colors(context).accentText
                  : PosStyle.secondaryText(context),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(20),
                  side: BorderSide(
                      color: selected
                          ? PosStyle.colors(context).accent
                          : PosStyle.border(context))),
              textStyle: TextStyle(
                  fontFamily: 'Roboto',
                  fontSize: 13,
                  fontWeight: selected ? FontWeight.w600 : FontWeight.w500)),
          child: Text(label)));
}

class PosKeypad extends StatelessWidget {
  final ValueChanged<String>? onKey;
  final double keyHeight;
  final double spacing;

  const PosKeypad(
      {super.key, required this.onKey, this.keyHeight = 52, this.spacing = 8});

  @override
  Widget build(BuildContext context) => LayoutBuilder(
      builder: (_, constraints) => GridView.count(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              crossAxisCount: 3,
              mainAxisSpacing: spacing,
              crossAxisSpacing: spacing,
              childAspectRatio:
                  (constraints.maxWidth - spacing * 2) / 3 / keyHeight,
              children: [
                for (final key in [
                  '7',
                  '8',
                  '9',
                  '4',
                  '5',
                  '6',
                  '1',
                  '2',
                  '3',
                  ',',
                  '0',
                  'backspace'
                ])
                  FilledButton(
                      style: FilledButton.styleFrom(
                          backgroundColor: PosStyle.colors(context).inset,
                          foregroundColor:
                              Theme.of(context).colorScheme.onSurface,
                          padding: EdgeInsets.zero,
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12),
                              side: BorderSide(
                                  color: PosStyle.colors(context).line))),
                      onPressed: onKey == null ? null : () => onKey!(key),
                      child: key == 'backspace'
                          ? const Icon(Icons.backspace_outlined,
                              semanticLabel: 'Oxirgi raqamni o‘chirish',
                              size: 22)
                          : Text(key,
                              style: const TextStyle(
                                  fontSize: 21, fontWeight: FontWeight.w700)))
              ]));
}

String posNumericKey(String value, String key) {
  if (key == 'backspace') {
    return value.isEmpty ? '' : value.substring(0, value.length - 1);
  }
  if (key == ',') {
    return value.contains('.') ? value : '${value.isEmpty ? '0' : value}.';
  }
  if (value.contains('.') && value.split('.').last.length >= 2) return value;
  return value == '0' ? key : '$value$key';
}
