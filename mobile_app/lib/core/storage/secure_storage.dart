import 'dart:convert';
import 'package:cryptography/cryptography.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:uuid/uuid.dart';

class SecureStorageService {
  final FlutterSecureStorage _storage;
  SecureStorageService({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const defaultServerUrl = String.fromEnvironment('API_BASE_URL',
      defaultValue: 'https://elyorpf.uz/api/flutter/v1');
  static const _serverKey = 'flutter_server_url';

  Future<String?> getThemeMode() => _storage.read(key: 'flutter_theme_mode');
  Future<void> setThemeMode(String value) =>
      _storage.write(key: 'flutter_theme_mode', value: value);

  static String normalizeServerUrl(String value) {
    final uri = Uri.parse(value.trim());
    if (uri.scheme != 'https' ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        uri.path.replaceAll(RegExp(r'/+$'), '') != '/api/flutter/v1') {
      throw ArgumentError(
          "HTTPS API manzilini /api/flutter/v1 bilan kiriting.");
    }
    return uri.replace(path: '/api/flutter/v1').toString();
  }

  Future<String> getServerUrl() async => normalizeServerUrl(
      await _storage.read(key: _serverKey) ?? defaultServerUrl);
  Future<void> setServerUrl(String url) =>
      _storage.write(key: _serverKey, value: normalizeServerUrl(url));
  Future<String> _key(String name) async =>
      'flutter_v1_${base64Url.encode(utf8.encode(await getServerUrl()))}_$name';

  Future<String?> getToken() async => _storage.read(key: await _key('token'));
  Future<void> setToken(String value) async =>
      _storage.write(key: await _key('token'), value: value);
  Future<void> clearToken() async => _storage.delete(key: await _key('token'));

  Future<String> getOrCreateDeviceUuid() async {
    final existing = await _storage.read(key: 'flutter_device_uuid');
    if (existing != null) return existing;
    final uuid = const Uuid().v4();
    await _storage.write(key: 'flutter_device_uuid', value: uuid);
    return uuid;
  }

  Future<Map<String, dynamic>?> getSession() async {
    final value = await _storage.read(key: await _key('session'));
    return value == null ? null : jsonDecode(value) as Map<String, dynamic>;
  }

  Future<void> setSession(Map<String, dynamic> value) async =>
      _storage.write(key: await _key('session'), value: jsonEncode(value));
  Future<String?> getUserId() async =>
      (await getSession())?['user']?['id']?.toString();
  Future<String?> getSelectedStoreId() async =>
      _storage.read(key: await _key('store_${await getUserId()}'));
  Future<void> setSelectedStoreId(String storeId) async => _storage.write(
      key: await _key('store_${await getUserId()}'), value: storeId);
  Future<String> _leaseKey() async =>
      _key('lease_${await getUserId()}_${await getSelectedStoreId()}');
  Future<Map<String, dynamic>?> getLease() async {
    final value = await _storage.read(key: await _leaseKey());
    return value == null ? null : jsonDecode(value) as Map<String, dynamic>;
  }

  Future<void> setLease(Map<String, dynamic> value) async =>
      _storage.write(key: await _leaseKey(), value: jsonEncode(value));
  Future<void> clearLease() async => _storage.delete(key: await _leaseKey());
  Future<void> clearSession() async {
    await clearToken();
    await _storage.delete(key: await _key('session'));
  }

  Future<String> databasePassword(String namespace) async {
    final key = 'flutter_database_key_$namespace';
    final existing = await _storage.read(key: key);
    if (existing != null) return existing;
    final secret = await AesGcm.with256bits().newSecretKey();
    final password = base64Url.encode(await secret.extractBytes());
    await _storage.write(key: key, value: password);
    return password;
  }
}
