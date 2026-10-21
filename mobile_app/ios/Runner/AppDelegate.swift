import Flutter
import UIKit
import Darwin

@main
@objc class AppDelegate: FlutterAppDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    GeneratedPluginRegistrant.register(with: self)
    if let controller = window?.rootViewController as? FlutterViewController {
      FlutterMethodChannel(name: "babyparfume/device_clock", binaryMessenger: controller.binaryMessenger)
        .setMethodCallHandler { call, result in
          guard call.method == "read" else { result(FlutterMethodNotImplemented); return }
          var boot = timeval()
          var size = MemoryLayout<timeval>.size
          guard sysctlbyname("kern.boottime", &boot, &size, nil, 0) == 0 else {
            result(FlutterError(code: "clock_unavailable", message: "Serverga ulaning.", details: nil)); return
          }
          var timebase = mach_timebase_info_data_t()
          mach_timebase_info(&timebase)
          let milliseconds = Double(mach_continuous_time()) * Double(timebase.numer) / Double(timebase.denom) / 1_000_000
          result(["uptime_ms": Int64(milliseconds), "boot_id": String(boot.tv_sec)])
        }
    }
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }
}
