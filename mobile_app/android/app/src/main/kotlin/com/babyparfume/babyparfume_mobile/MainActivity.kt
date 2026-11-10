package com.babyparfume.babyparfume_mobile

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import io.flutter.plugin.common.StandardMethodCodec
import android.os.SystemClock
import android.provider.Settings

class MainActivity: FlutterActivity() {
    override fun onPostResume() {
        super.onPostResume()
        window.decorView.post {
            if (!isFinishing && !isDestroyed && window.decorView.isShown) {
                AppPushNotifications.onResume(this)
            }
        }
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == AppPushNotifications.PERMISSION_REQUEST) {
            AppPushNotifications.subscribe(this)
        }
    }

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

        val messenger = flutterEngine.dartExecutor.binaryMessenger
        val photoSegmenter = ProductPhotoSegmenter(assets)
        MethodChannel(
            messenger,
            "babyparfume/product_photo",
            StandardMethodCodec.INSTANCE,
            messenger.makeBackgroundTaskQueue()
        ).setMethodCallHandler { call, result ->
            if (call.method != "segment") {
                result.notImplemented()
                return@setMethodCallHandler
            }
            val rgba = call.argument<ByteArray>("rgba")
            val width = call.argument<Int>("width")
            val height = call.argument<Int>("height")
            if (rgba == null || width == null || height == null) {
                result.error("invalid_photo", null, null)
                return@setMethodCallHandler
            }
            try {
                result.success(photoSegmenter.segment(rgba, width, height))
            } catch (error: Exception) {
                result.error("segmentation_unavailable", null, null)
            } catch (error: LinkageError) {
                result.error("segmentation_unavailable", null, null)
            }
        }
    }
}
