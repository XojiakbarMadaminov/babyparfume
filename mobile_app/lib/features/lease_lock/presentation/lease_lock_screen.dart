import 'package:flutter/material.dart';
import '../../../core/theme/app_colors.dart';

class LeaseLockScreen extends StatelessWidget {
  final String reason;
  final VoidCallback onRetry;

  const LeaseLockScreen({
    super.key,
    required this.reason,
    required this.onRetry,
  });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.lightBg,
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Container(
                  width: 80,
                  height: 80,
                  decoration: const BoxDecoration(
                    color: AppColors.lightDangerBg,
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(
                    Icons.lock_clock_outlined,
                    size: 40,
                    color: AppColors.lightDanger,
                  ),
                ),
                const SizedBox(height: 24),
                const Text(
                  "Oflayn muddat yakunlandi",
                  style: TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.w700,
                    color: AppColors.lightText,
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  reason,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontSize: 14,
                    color: AppColors.lightMuted,
                    height: 1.5,
                  ),
                ),
                const SizedBox(height: 16),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.lightTint,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: const Text(
                    "Maksimal oflayn ishlash muddati: 10 soat. Moliyaviy xavfsizlik uchun internet tarmog'iga ulaning va litsenziyani yangilang.",
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 12,
                      color: AppColors.lightAccent,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ),
                const SizedBox(height: 32),
                ElevatedButton.icon(
                  onPressed: onRetry,
                  icon: const Icon(Icons.sync),
                  label: const Text("Qayta tekshirish va sinxronlash"),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
