import 'package:flutter/material.dart';

class AppColors {
  AppColors._();

  static AppPalette of(BuildContext context) =>
      forBrightness(Theme.of(context).brightness);

  static AppPalette forBrightness(Brightness brightness) =>
      AppPalette(brightness == Brightness.dark);

  static Color onColor(Color background) =>
      background.computeLuminance() > 0.179 ? Colors.black : Colors.white;

  // Light Theme
  static const Color lightBg = Color(0xFFF7F8FA);
  static const Color lightCard = Color(0xFFFFFFFF);
  static const Color lightText = Color(0xFF192332);
  static const Color lightMuted = Color(0xFF5C687A);
  static const Color lightLine = Color(0xFFE5E9F0);
  static const Color lightSoft = Color(0xFFEEF1F5);
  static const Color lightAccent = Color(0xFF995207);
  static const Color lightTint = Color(0xFFFFF1DC);
  static const Color lightDanger = Color(0xFFA63C29);
  static const Color lightDangerBg = Color(0xFFFFF0EB);

  // Dark Theme
  static const Color darkBg = Color(0xFF15191F);
  static const Color darkCard = Color(0xFF20262F);
  static const Color darkText = Color(0xFFF1F4F8);
  static const Color darkMuted = Color(0xFFB0BAC9);
  static const Color darkLine = Color(0xFF343E4B);
  static const Color darkSoft = Color(0xFF2A3340);
  static const Color darkAccent = Color(0xFFEFB66E);
  static const Color darkTint = Color(0xFF3A2B1B);
  static const Color darkDanger = Color(0xFFFFB29D);
  static const Color darkDangerBg = Color(0xFF3D2926);

  // Accent button color
  static const Color primaryButton = Color(0xFFA75C09);
  static const Color warning = Color(0xFFD97706);
  static const Color warningBg = Color(0xFFFEF3C7);
  static const Color success = Color(0xFF059669);
  static const Color successBg = Color(0xFFD1FAE5);
}

class AppPalette {
  final bool isDark;
  const AppPalette(this.isDark);

  Color get background => isDark ? AppColors.darkBg : AppColors.lightBg;
  Color get card => isDark ? AppColors.darkCard : AppColors.lightCard;
  Color get text => isDark ? AppColors.darkText : AppColors.lightText;
  Color get muted => isDark ? AppColors.darkMuted : AppColors.lightMuted;
  Color get line => isDark ? AppColors.darkLine : AppColors.lightLine;
  Color get soft => isDark ? AppColors.darkSoft : AppColors.lightSoft;
  Color get accent => isDark ? AppColors.darkAccent : AppColors.lightAccent;
  Color get tint => isDark ? AppColors.darkTint : AppColors.lightTint;
  Color get danger => isDark ? AppColors.darkDanger : AppColors.lightDanger;
  Color get dangerBackground =>
      isDark ? AppColors.darkDangerBg : AppColors.lightDangerBg;
  Color get success =>
      isDark ? const Color(0xFF7BD4B1) : const Color(0xFF006B45);
  Color get successBackground =>
      isDark ? const Color(0xFF19392E) : AppColors.successBg;
  Color get warning =>
      isDark ? const Color(0xFFF6C177) : const Color(0xFF824600);
  Color get warningBackground =>
      isDark ? const Color(0xFF392D1A) : AppColors.warningBg;
  Color get info => isDark ? const Color(0xFF93C5FD) : const Color(0xFF1D4ED8);
  Color get button => accent;
  Color get onButton => AppColors.onColor(button);
}
