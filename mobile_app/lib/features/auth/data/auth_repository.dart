import 'dart:io';
import 'package:babyparfume_mobile/core/api/api_client.dart';
import 'package:babyparfume_mobile/core/storage/secure_storage.dart';

class AuthUser {
  final int id;
  final String name;
  final String email;

  AuthUser({required this.id, required this.name, required this.email});

  factory AuthUser.fromJson(Map<String, dynamic> json) {
    return AuthUser(
      id: int.tryParse(json['id'].toString()) ?? 0,
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
      id: int.tryParse(json['id'].toString()) ?? 0,
      name: json['name'] as String? ?? '',
    );
  }
}

class AuthRepository {
  final ApiClient apiClient;
  final SecureStorageService storage;

  AuthRepository({required this.apiClient, required this.storage});

  Future<bool> isLoggedIn() async {
    final token = await storage.getToken();
    return token != null && token.isNotEmpty;
  }

  Future<bool> login({required String email, required String password}) async {
    final deviceUuid = await storage.getOrCreateDeviceUuid();
    final platform = Platform.isIOS ? 'ios' : 'android';
    final response = await apiClient.post(
      '/login',
      data: {
        'email': email,
        'password': password,
        'device_uuid': deviceUuid,
        'device_name': Platform.isIOS ? 'iPhone' : 'Android Device',
        'platform': platform,
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
    try {
      final response = await apiClient.get('/stores');
      if (response.statusCode == 200 && response.data['data'] is List) {
        final list = response.data['data'] as List;
        return list.map((item) => StoreItem.fromJson(item as Map<String, dynamic>)).toList();
      }
    } catch (_) {}

    try {
      final sessionRes = await apiClient.get('/session');
      if (sessionRes.statusCode == 200 && sessionRes.data['data']?['stores'] is List) {
        final list = sessionRes.data['data']['stores'] as List;
        return list.map((item) => StoreItem.fromJson(item as Map<String, dynamic>)).toList();
      }
    } catch (_) {}

    return [];
  }

  Future<void> logout() async {
    try {
      await apiClient.post('/logout');
    } catch (_) {}
    await storage.clearSession();
  }
}
