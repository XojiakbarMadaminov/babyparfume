import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'app_colors.dart';

class AppTheme {
  AppTheme._();

  static ThemeData lightTheme() => _theme(Brightness.light);
  static ThemeData darkTheme() => _theme(Brightness.dark);

  static ThemeData _theme(Brightness brightness) {
    final colors = AppColors.forBrightness(brightness);
    final scheme = ColorScheme.fromSeed(
        seedColor: AppColors.primaryButton,
        brightness: brightness,
        primary: colors.accent,
        onPrimary: colors.onButton,
        primaryContainer: colors.tint,
        onPrimaryContainer: colors.accent,
        surface: colors.card,
        onSurface: colors.text,
        onSurfaceVariant: colors.muted,
        surfaceContainerHighest: colors.soft,
        outline: colors.muted,
        outlineVariant: colors.line,
        error: colors.danger,
        onError: AppColors.onColor(colors.danger),
        errorContainer: colors.dangerBackground,
        onErrorContainer: colors.danger);
    final base = ThemeData(useMaterial3: true, colorScheme: scheme);
    final shape = RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: colors.line));
    final inputBorder = OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: colors.line));
    return base.copyWith(
        scaffoldBackgroundColor: colors.background,
        cardColor: colors.card,
        dividerColor: colors.line,
        hintColor: colors.muted,
        disabledColor: colors.muted.withOpacity(.5),
        textTheme: base.textTheme
            .apply(bodyColor: colors.text, displayColor: colors.text),
        iconTheme: IconThemeData(color: colors.muted),
        appBarTheme: AppBarTheme(
            backgroundColor: colors.card,
            foregroundColor: colors.text,
            surfaceTintColor: Colors.transparent,
            elevation: 0,
            scrolledUnderElevation: 0,
            systemOverlayStyle: colors.isDark
                ? SystemUiOverlayStyle.light
                : SystemUiOverlayStyle.dark),
        cardTheme: CardTheme(
            color: colors.card,
            surfaceTintColor: Colors.transparent,
            elevation: 0,
            shape: shape),
        dialogTheme: DialogTheme(
            backgroundColor: colors.card,
            surfaceTintColor: Colors.transparent,
            shape: shape),
        bottomSheetTheme: BottomSheetThemeData(
            backgroundColor: colors.card,
            surfaceTintColor: Colors.transparent,
            modalBackgroundColor: colors.card,
            dragHandleColor: colors.muted),
        popupMenuTheme: PopupMenuThemeData(
            color: colors.card,
            surfaceTintColor: Colors.transparent,
            textStyle: TextStyle(color: colors.text)),
        snackBarTheme: SnackBarThemeData(
            backgroundColor: colors.text,
            contentTextStyle: TextStyle(color: colors.card),
            actionTextColor:
                colors.isDark ? AppColors.lightAccent : AppColors.darkAccent),
        elevatedButtonTheme: ElevatedButtonThemeData(
            style: ElevatedButton.styleFrom(
                backgroundColor: colors.button,
                foregroundColor: colors.onButton,
                minimumSize: const Size.fromHeight(49),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14)),
                textStyle: const TextStyle(
                    fontSize: 14, fontWeight: FontWeight.w600))),
        filledButtonTheme: FilledButtonThemeData(
            style: FilledButton.styleFrom(
                backgroundColor: colors.button,
                foregroundColor: colors.onButton)),
        inputDecorationTheme: InputDecorationTheme(
            filled: true,
            fillColor: colors.card,
            contentPadding:
                const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            border: inputBorder,
            enabledBorder: inputBorder,
            focusedBorder: inputBorder.copyWith(
                borderSide: BorderSide(color: colors.accent, width: 2)),
            labelStyle: TextStyle(color: colors.muted, fontSize: 13),
            hintStyle: TextStyle(color: colors.muted, fontSize: 13),
            prefixIconColor: colors.muted,
            suffixIconColor: colors.muted),
        bottomNavigationBarTheme: BottomNavigationBarThemeData(
            backgroundColor: colors.card,
            selectedItemColor: colors.accent,
            unselectedItemColor: colors.muted,
            selectedLabelStyle:
                const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
            unselectedLabelStyle: const TextStyle(fontSize: 12),
            type: BottomNavigationBarType.fixed,
            elevation: 0));
  }
}
