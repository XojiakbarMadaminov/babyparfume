package com.babyparfume.babyparfume_mobile

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import android.os.SystemClock
import android.provider.Settings

class MainActivity: FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "babyparfume/device_clock")
            .setMethodCallHandler { call, result ->
                if (call.method == "read") {
                    val bootId = Settings.Global.getString(contentResolver, "boot_count")
                    if (bootId == null) result.error("clock_unavailable", "Qurilmani tekshirish uchun internetga ulaning.", null)
                    else result.success(mapOf("uptime_ms" to SystemClock.elapsedRealtime(), "boot_id" to bootId))
                } else result.notImplemented()
            }
    }
}
