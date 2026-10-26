import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/theme/app_colors.dart';

class StatisticsStyle {
  StatisticsStyle._();
  static const background = Color(0xFF12171E);
  static const card = Color(0xFF181F28);
  static const text = Color(0xFFF1F4F8);
  static const muted = Color(0xFFA1ACBB);
  static const line = Color(0xFF303B49);
  static const soft = Color(0xFF222C38);
  static const accent = Color(0xFFEFB66E);
  static const tint = Color(0xFF29251F);
  static const danger = Color(0xFFFFB29D);
  static const green = Color(0xFF7BD4B1);

  static String number(num value, {int decimals = 2}) =>
      NumberFormat(decimals == 1 ? '#,##0.#' : '#,##0.##', 'en_US')
          .format(value)
          .replaceAll(',', ' ');
  static String money(num? value) =>
      value == null ? '—' : '${number(value)} so‘m';

  static StatisticsPalette colors(BuildContext context) =>
      StatisticsPalette(Theme.of(context).brightness == Brightness.dark);

  static ThemeData theme({Brightness brightness = Brightness.dark}) {
    final palette = StatisticsPalette(brightness == Brightness.dark);
    final base = palette.isDark ? AppTheme.darkTheme() : AppTheme.lightTheme();
    return base.copyWith(
        scaffoldBackgroundColor: palette.background,
        cardColor: palette.card,
        colorScheme: base.colorScheme.copyWith(
            primary: palette.accent,
            onPrimary: AppColors.onColor(palette.accent),
            surface: palette.card,
            onSurface: palette.text,
            onSurfaceVariant: palette.muted,
            outlineVariant: palette.line),
        dividerColor: palette.line,
        textButtonTheme: TextButtonThemeData(
            style: TextButton.styleFrom(
                foregroundColor: palette.accent,
                textStyle:
                    const TextStyle(fontFamily: 'Roboto', fontSize: 12))));
  }
}

class StatisticsPalette extends AppPalette {
  const StatisticsPalette(super.isDark);
  @override
  Color get background =>
      isDark ? StatisticsStyle.background : super.background;
  @override
  Color get card => isDark ? StatisticsStyle.card : super.card;
  @override
  Color get text => isDark ? StatisticsStyle.text : super.text;
  @override
  Color get muted => isDark ? StatisticsStyle.muted : super.muted;
  @override
  Color get line => isDark ? StatisticsStyle.line : super.line;
  @override
  Color get soft => isDark ? StatisticsStyle.soft : super.soft;
  @override
  Color get accent => isDark ? StatisticsStyle.accent : super.accent;
  @override
  Color get tint => isDark ? StatisticsStyle.tint : super.tint;
  @override
  Color get danger => isDark ? StatisticsStyle.danger : super.danger;
  Color get green => success;
}

class StatisticsPanel extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;
  final bool hero;
  const StatisticsPanel(
      {super.key,
      required this.child,
      this.padding = const EdgeInsets.all(18),
      this.hero = false});

  @override
  Widget build(BuildContext context) => Container(
      decoration: BoxDecoration(
          color: StatisticsStyle.colors(context).card,
          border: Border.all(color: StatisticsStyle.colors(context).line),
          borderRadius: BorderRadius.circular(17)),
      clipBehavior: Clip.antiAlias,
      child: Container(
          decoration: hero
              ? BoxDecoration(
                  border: Border(
                      top: BorderSide(
                          color: StatisticsStyle.colors(context).accent,
                          width: 2)))
              : null,
          padding: padding,
          child: child));
}

class StatisticsSectionHeading extends StatelessWidget {
  final String title;
  final String? caption;
  final IconData? icon;
  const StatisticsSectionHeading(
      {super.key, required this.title, this.caption, this.icon});
  @override
  Widget build(BuildContext context) => Row(children: [
        Expanded(
            child: Text(title,
                style: const TextStyle(
                    fontSize: 16, fontWeight: FontWeight.w600))),
        if (caption != null)
          Expanded(
              child: Text(caption!,
                  textAlign: TextAlign.right,
                  style: TextStyle(
                      fontSize: 11,
                      color: StatisticsStyle.colors(context).muted))),
        if (icon != null)
          Icon(icon, size: 18, color: StatisticsStyle.colors(context).muted),
      ]);
}

class StatisticsNotice extends StatelessWidget {
  final String title;
  final String? description;
  final VoidCallback? onRetry;
  final bool error;
  const StatisticsNotice(
      {super.key,
      required this.title,
      this.description,
      this.onRetry,
      this.error = false});
  @override
  Widget build(BuildContext context) => Semantics(
      liveRegion: true,
      child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
              color: error
                  ? AppColors.of(context).dangerBackground
                  : StatisticsStyle.colors(context).tint,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: StatisticsStyle.colors(context).line)),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(error ? Icons.error_outline : Icons.info_outline,
                size: 18,
                color: error
                    ? StatisticsStyle.colors(context).danger
                    : StatisticsStyle.colors(context).accent),
            const SizedBox(width: 10),
            Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  Text(title,
                      style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          color: error
                              ? StatisticsStyle.colors(context).danger
                              : StatisticsStyle.colors(context).text)),
                  if (description != null) ...[
                    const SizedBox(height: 5),
                    Text(description!,
                        style: TextStyle(
                            fontSize: 12,
                            color: StatisticsStyle.colors(context).muted,
                            height: 1.5))
                  ],
                  if (onRetry != null)
                    TextButton(
                        onPressed: onRetry, child: const Text('Qayta urinish')),
                ]))
          ])));
}

class StatisticsSkeleton extends StatelessWidget {
  const StatisticsSkeleton({super.key});
  Widget _bar(BuildContext context, double width, double height) => Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
          color: StatisticsStyle.colors(context).soft,
          borderRadius: BorderRadius.circular(6)));
  @override
  Widget build(BuildContext context) => Semantics(
      label: 'Sotuv statistikasi yuklanmoqda…',
      liveRegion: true,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        StatisticsPanel(
            hero: true,
            padding: const EdgeInsets.all(20),
            child: SizedBox(
                height: 138,
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      _bar(context, 130, 16),
                      _bar(context, double.infinity, 36),
                      _bar(context, 200, 16)
                    ]))),
        const SizedBox(height: 16),
        Row(children: [
          for (var i = 0; i < 2; i++) ...[
            if (i > 0) const SizedBox(width: 10),
            Expanded(
                child: StatisticsPanel(
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                  _bar(context, 90, 16),
                  const SizedBox(height: 20),
                  _bar(context, 100, 28)
                ]))),
          ]
        ]),
        const SizedBox(height: 16),
        StatisticsPanel(
            child: SizedBox(
                height: 100,
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _bar(context, 100, 16),
                      const SizedBox(height: 20),
                      _bar(context, 180, 28)
                    ]))),
        const SizedBox(height: 16),
        StatisticsPanel(
            child: SizedBox(
                height: 250,
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _bar(context, 150, 16),
                      const SizedBox(height: 20),
                      Expanded(child: _bar(context, double.infinity, 200))
                    ]))),
      ]));
}
