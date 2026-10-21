import 'package:flutter/material.dart';
import 'core/api/api_client.dart';
import 'core/lease/lease_manager.dart';
import 'core/storage/secure_storage.dart';
import 'core/theme/app_theme.dart';
import 'features/auth/data/auth_repository.dart';
import 'features/auth/presentation/login_screen.dart';
import 'features/lease_lock/presentation/lease_lock_screen.dart';
import 'features/shell/presentation/app_shell.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const BabyParfumeApp());
}

class BabyParfumeApp extends StatefulWidget {
  const BabyParfumeApp({super.key});

  @override
  State<BabyParfumeApp> createState() => _BabyParfumeAppState();
}

class _BabyParfumeAppState extends State<BabyParfumeApp> {
  late final SecureStorageService _storage;
  late final ApiClient _apiClient;
  late final LeaseManager _leaseManager;
  late final AuthRepository _authRepository;

  bool _isInitialized = false;
  bool _isLoggedIn = false;
  LeaseState? _leaseState;

  @override
  void initState() {
    super.initState();
    _initializeApp();
  }

  Future<void> _initializeApp() async {
    _storage = SecureStorageService();
    _apiClient = ApiClient(
      storage: _storage,
      onUnauthenticated: () {
        setState(() {
          _isLoggedIn = false;
        });
      },
    );
    _leaseManager = LeaseManager(storage: _storage);
    _authRepository = AuthRepository(apiClient: _apiClient, storage: _storage);

    final loggedIn = await _authRepository.isLoggedIn();
    if (loggedIn) {
      await _leaseManager.initialize();
      _leaseState = await _leaseManager.checkLease();
      _leaseManager.stateStream.listen((state) {
        if (mounted) setState(() => _leaseState = state);
      });
    }

    setState(() {
      _isLoggedIn = loggedIn;
      _isInitialized = true;
    });
  }

  Future<void> _handleLoginSuccess() async {
    // 10-hour lease defaults upon fresh login
    final defaultLeaseExpires = DateTime.now().add(const Duration(hours: 10)).toIso8601String();
    await _leaseManager.updateLease(defaultLeaseExpires);
    await _leaseManager.initialize();
    final lease = await _leaseManager.checkLease();

    setState(() {
      _isLoggedIn = true;
      _leaseState = lease;
    });
  }

  Future<void> _handleLogout() async {
    await _authRepository.logout();
    setState(() {
      _isLoggedIn = false;
      _leaseState = null;
    });
  }

  @override
  void dispose() {
    _leaseManager.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Baby Parfume',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme(),
      darkTheme: AppTheme.darkTheme(),
      themeMode: ThemeMode.system,
      home: !_isInitialized
          ? const Scaffold(
              body: Center(
                child: CircularProgressIndicator(),
              ),
            )
          : !_isLoggedIn
              ? LoginScreen(
                  authRepository: _authRepository,
                  onLoginSuccess: _handleLoginSuccess,
                )
              : (_leaseState != null && _leaseState!.isLocked)
                  ? LeaseLockScreen(
                      reason: _leaseState!.reason ?? "10 soatlik oflayn muddat yakunlandi",
                      onRetry: () async {
                        final updated = await _leaseManager.checkLease();
                        setState(() => _leaseState = updated);
                      },
                    )
                  : AppShell(
                      authRepository: _authRepository,
                      onLogout: _handleLogout,
                    ),
    );
  }
}
