import 'package:flutter/material.dart';
import 'core/api/api_client.dart';
import 'core/database/app_database.dart';
import 'core/storage/secure_storage.dart';
import 'core/theme/app_theme.dart';
import 'features/auth/data/auth_repository.dart';
import 'features/auth/presentation/login_screen.dart';
import 'features/shell/presentation/app_shell.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const BabyParfumeApp());
}

class BabyParfumeApp extends StatefulWidget {
  const BabyParfumeApp({super.key});
  @override
  State<BabyParfumeApp> createState() => _BabyParfumeAppState();
}

class _BabyParfumeAppState extends State<BabyParfumeApp> {
  late final AuthRepository _auth;
  bool _initialized = false;
  bool _loggedIn = false;
  @override
  void initState() {
    super.initState();
    final storage = SecureStorageService();
    _auth = AuthRepository(
        storage: storage,
        apiClient: ApiClient(
            storage: storage,
            onUnauthenticated: () {
              if (mounted) setState(() => _loggedIn = false);
            }));
    _initialize();
  }

  Future<void> _initialize() async {
    final loggedIn = await _auth.isLoggedIn();
    if (mounted) {
      setState(() {
        _loggedIn = loggedIn;
        _initialized = true;
      });
    }
  }

  Future<void> _logout() async {
    await _auth.logout();
    await AppDatabase.close();
    if (mounted) setState(() => _loggedIn = false);
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
        key: ValueKey(_loggedIn),
        title: 'Baby Parfume',
        debugShowCheckedModeBanner: false,
        theme: AppTheme.lightTheme(),
        darkTheme: AppTheme.darkTheme(),
        themeMode: ThemeMode.system,
        home: !_initialized
            ? const Scaffold(body: Center(child: CircularProgressIndicator()))
            : !_loggedIn
                ? LoginScreen(
                    authRepository: _auth,
                    onLoginSuccess: () => setState(() => _loggedIn = true))
                : AppShell(authRepository: _auth, onLogout: _logout),
      );
}
