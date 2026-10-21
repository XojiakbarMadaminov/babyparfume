import 'package:babyparfume_mobile/core/api/api_client.dart';
import 'package:babyparfume_mobile/core/storage/secure_storage.dart';

class AuthUser {
  final int id;
  final String name;
  final String email;

  AuthUser({required this.id, required this.name, required this.email});

  factory AuthUser.fromJson(Map<String, dynamic> json) {
    return AuthUser(
      id: json['id'] as int,
      name: json['name'] as String? ?? '',
      email: json['email'] as String? ?? '',
    );
  }
}

class StoreItem {
  final int id;
  final String name;

  StoreItem({required this.id, required this.name});

  factory StoreItem.fromJson(Map<String, dynamic> json) {
    return StoreItem(
      id: json['id'] as int,
      name: json['name'] as String? ?? '',
    );
  }
}

class AuthRepository {
  final ApiClient apiClient;
  final SecureStorageService storage;

  AuthRepository({required this.apiClient, required this.storage});

  Future<bool> login({required String email, required String password}) async {
    final deviceUuid = await storage.getOrCreateDeviceUuid();
    final response = await apiClient.post(
      '/login',
      data: {
        'email': email,
        'password': password,
        'device_uuid': deviceUuid,
        'device_name': 'Mobile Device',
        'platform': 'flutter',
      },
    );

    if (response.statusCode == 200 || response.statusCode == 201) {
      final token = response.data['data']['token'] as String;
      await storage.setToken(token);
      return true;
    }
    return false;
  }

  Future<List<StoreItem>> getStores() async {
    final response = await apiClient.get('/stores');
    if (response.statusCode == 200) {
      final list = response.data['data'] as List;
      return list.map((item) => StoreItem.fromJson(item as Map<String, dynamic>)).toList();
    }
    return [];
  }

  Future<void> logout() async {
    try {
      await apiClient.post('/logout');
    } catch (_) {}
    await storage.clearSession();
  }

  Future<bool> isLoggedIn() async {
    final token = await storage.getToken();
    return token != null && token.isNotEmpty;
  }
}
