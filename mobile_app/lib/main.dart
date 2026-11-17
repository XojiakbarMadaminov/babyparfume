import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'dart:async';
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

class _BabyParfumeAppState extends State<BabyParfumeApp>
    with WidgetsBindingObserver {
  late final AuthRepository _auth;
  bool _initialized = false;
  bool _loggedIn = false;
  Brightness _brightness = Brightness.dark;
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final storage = SecureStorageService();
    _auth = AuthRepository(
        storage: storage,
        apiClient: ApiClient(
            storage: storage,
            onUnauthenticated: () {
              if (mounted) setState(() => _loggedIn = false);
            }));
    _initialize();
    const MethodChannel('babyparfume/push_notifications')
        .setMethodCallHandler((call) async {
      if (call.method == 'tokenChanged' && _loggedIn) {
        await _auth.syncPushToken();
      }
    });
  }

  @override
  void dispose() {
    const MethodChannel('babyparfume/push_notifications')
        .setMethodCallHandler(null);
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _loggedIn) {
      unawaited(_auth.syncPushToken());
    }
  }

  void _onLoginSuccess() {
    setState(() => _loggedIn = true);
    unawaited(_auth.syncPushToken());
  }

  Future<void> _initialize() async {
    final loggedIn = await _auth.isLoggedIn();
    if (loggedIn) unawaited(_auth.syncPushToken());
    final themeMode = await _auth.storage.getThemeMode();
    if (mounted) {
      setState(() {
        _loggedIn = loggedIn;
        _brightness = themeMode == 'light' ? Brightness.light : Brightness.dark;
        _initialized = true;
      });
    }
  }

  Future<void> _setBrightness(Brightness value) async {
    setState(() => _brightness = value);
    await _auth.storage.setThemeMode(value.name);
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
        themeMode:
            _brightness == Brightness.dark ? ThemeMode.dark : ThemeMode.light,
        home: !_initialized
            ? const Scaffold(body: Center(child: CircularProgressIndicator()))
            : !_loggedIn
                ? LoginScreen(
                    authRepository: _auth, onLoginSuccess: _onLoginSuccess)
                : AppShell(
                    authRepository: _auth,
                    onLogout: _logout,
                    brightness: _brightness,
                    onBrightnessChanged: _setBrightness),
      );
}
