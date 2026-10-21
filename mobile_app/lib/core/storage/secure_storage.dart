import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:uuid/uuid.dart';

class SecureStorageService {
  final FlutterSecureStorage _storage;

  SecureStorageService({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const String defaultServerUrl = 'https://elyorpf.uz/api/flutter/v1';

  static const String _keyServerUrl = 'flutter_server_url';
  static const String _keyToken = 'flutter_bearer_token';
  static const String _keyDeviceUuid = 'flutter_device_uuid';
  static const String _keyStoreId = 'flutter_selected_store_id';
  static const String _keyLeaseExpiresAt = 'flutter_lease_expires_at';

  Future<String> getServerUrl() async =>
      await _storage.read(key: _keyServerUrl) ?? defaultServerUrl;

  Future<void> setServerUrl(String url) async =>
      _storage.write(key: _keyServerUrl, value: url.trim());

  Future<String?> getToken() async => _storage.read(key: _keyToken);
  Future<void> setToken(String token) async =>
      _storage.write(key: _keyToken, value: token);
  Future<void> clearToken() async => _storage.delete(key: _keyToken);

  Future<String> getOrCreateDeviceUuid() async =>
      await _storage.read(key: _keyDeviceUuid) ??
      await () async {
        final newUuid = const Uuid().v4();
        await _storage.write(key: _keyDeviceUuid, value: newUuid);
        return newUuid;
      }();

  Future<String?> getSelectedStoreId() async =>
      _storage.read(key: _keyStoreId);
  Future<void> setSelectedStoreId(String storeId) async =>
      _storage.write(key: _keyStoreId, value: storeId);

  Future<String?> getLeaseExpiresAt() async =>
      _storage.read(key: _keyLeaseExpiresAt);
  Future<void> setLeaseExpiresAt(String isoString) async =>
      _storage.write(key: _keyLeaseExpiresAt, value: isoString);

  Future<void> clearSession() async {
    await _storage.delete(key: _keyToken);
    await _storage.delete(key: _keyStoreId);
    await _storage.delete(key: _keyLeaseExpiresAt);
  }
}
