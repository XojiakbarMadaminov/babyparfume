import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import '../../../core/storage/secure_storage.dart';
import '../../../core/theme/app_colors.dart';
import '../data/auth_repository.dart';

class LoginScreen extends StatefulWidget {
  final AuthRepository authRepository;
  final VoidCallback onLoginSuccess;
  final SecureStorageService? storage;

  const LoginScreen({
    super.key,
    required this.authRepository,
    required this.onLoginSuccess,
    this.storage,
  });

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  late final SecureStorageService _storage;
  bool _isLoading = false;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _storage = widget.storage ?? SecureStorageService();
  }

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  void _openServerSettings() async {
    final currentUrl = await _storage.getServerUrl();
    final urlController = TextEditingController(text: currentUrl);

    if (!mounted) return;

    await showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Row(
          children: [
            Icon(Icons.dns_outlined, color: AppColors.lightAccent),
            SizedBox(width: 8),
            Text("Server manzili"),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              "Mobil ilova ulanadigan API manzili:",
              style: TextStyle(fontSize: 13, color: AppColors.lightMuted),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: urlController,
              decoration: const InputDecoration(
                hintText: "https://elyorpf.uz/api/flutter/v1",
                labelText: "API URL",
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () async {
              await _storage
                  .setServerUrl(SecureStorageService.defaultServerUrl);
              if (ctx.mounted) Navigator.pop(ctx);
            },
            child: const Text("Standart holatga"),
          ),
          ElevatedButton(
            onPressed: () async {
              final newUrl = urlController.text.trim();
              if (newUrl.isNotEmpty) {
                try {
                  await _storage.setServerUrl(newUrl);
                  if (ctx.mounted) Navigator.pop(ctx);
                } catch (_) {
                  if (ctx.mounted) {
                    ScaffoldMessenger.of(ctx).showSnackBar(const SnackBar(
                        content: Text(
                            'HTTPS API manzilini /api/flutter/v1 bilan kiriting.')));
                  }
                }
              }
            },
            child: const Text("Saqlash"),
          ),
        ],
      ),
    );
    urlController.dispose();
  }

  Future<void> _submit() async {
    final email = _emailController.text.trim();
    final password = _passwordController.text;

    if (email.isEmpty || password.isEmpty) {
      setState(() => _errorMessage = "Email va parolni kiriting.");
      return;
    }
    if (_isLoading) return;

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final success = await widget.authRepository.login(
        email: email,
        password: password,
      );
      if (success) {
        widget.onLoginSuccess();
      } else {
        setState(() =>
            _errorMessage = "Kirish amalga oshmadi. Email yoki parol xato.");
      }
    } on DioException catch (e) {
      final msg = e.response?.data is Map
          ? (e.response?.data['error']?['message'] ??
              e.response?.data['message'])
          : null;
      setState(() => _errorMessage = msg?.toString() ??
          "Serverga ulanishda xatolik (${e.response?.statusCode ?? 'aloqa yo\'q'}).");
    } catch (e) {
      setState(() => _errorMessage = "Xatolik: ${e.toString()}");
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        actions: [
          IconButton(
            icon: const Icon(Icons.settings_outlined,
                color: AppColors.lightMuted),
            tooltip: "Server sozlamalari",
            onPressed: _openServerSettings,
          ),
        ],
      ),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // Brand Mark
                  Center(
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(18),
                      child: Image.asset(
                        'assets/images/logo.png',
                        width: 80,
                        height: 80,
                        fit: BoxFit.cover,
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  const Text(
                    'OSON POS',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 1.5,
                      color: AppColors.lightText,
                    ),
                  ),
                  const SizedBox(height: 4),
                  const Text(
                    'Savdo va ombor boshqaruvi',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 13,
                      color: AppColors.lightMuted,
                    ),
                  ),
                  const SizedBox(height: 32),

                  // Heading
                  const Text(
                    'Tizimga kirish',
                    style: TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.w700,
                      color: AppColors.lightText,
                    ),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Do‘kon hisobingizga kirish uchun ma’lumotlarni kiriting.',
                    style: TextStyle(
                      fontSize: 13,
                      color: AppColors.lightMuted,
                    ),
                  ),
                  const SizedBox(height: 24),

                  if (_errorMessage != null) ...[
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: AppColors.lightDangerBg,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        _errorMessage!,
                        style: const TextStyle(
                          color: AppColors.lightDanger,
                          fontSize: 13,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),
                  ],

                  // Email Input
                  TextField(
                    controller: _emailController,
                    keyboardType: TextInputType.emailAddress,
                    autocorrect: false,
                    decoration: const InputDecoration(
                      labelText: 'Email',
                      hintText: 'admin@example.com',
                      prefixIcon: Icon(Icons.email_outlined),
                    ),
                  ),
                  const SizedBox(height: 16),

                  // Password Input
                  TextField(
                    controller: _passwordController,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Parol',
                      prefixIcon: Icon(Icons.lock_outline),
                    ),
                    onSubmitted: (_) => _submit(),
                  ),
                  const SizedBox(height: 28),

                  // Submit Button
                  ElevatedButton(
                    onPressed: _isLoading ? null : _submit,
                    child: _isLoading
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Text('Kirish'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
