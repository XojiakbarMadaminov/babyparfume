import CoreGraphics
import CoreVideo
import Flutter
import Foundation
import Vision

enum ProductPhotoSegmenter {
  private static let queue = DispatchQueue(label: "babyparfume.product-photo", qos: .userInitiated)

  static func handle(_ call: FlutterMethodCall, result: @escaping FlutterResult) {
    guard call.method == "segment" else {
      result(FlutterMethodNotImplemented)
      return
    }
    guard #available(iOS 17.0, *) else {
      result(nil)
      return
    }
    guard let arguments = call.arguments as? [String: Any],
          let rgba = (arguments["rgba"] as? FlutterStandardTypedData)?.data,
          let width = arguments["width"] as? Int,
          let height = arguments["height"] as? Int,
          (1...512).contains(width), (1...512).contains(height),
          rgba.count == width * height * 4 else {
      result(FlutterError(code: "invalid_photo", message: nil, details: nil))
      return
    }

    queue.async {
      let mask = try? segment(rgba: rgba, width: width, height: height)
      DispatchQueue.main.async { result(mask) }
    }
  }

  @available(iOS 17.0, *)
  private static func segment(rgba: Data, width: Int, height: Int) throws -> [String: Any]? {
    guard let provider = CGDataProvider(data: rgba as CFData),
          let image = CGImage(
            width: width, height: height, bitsPerComponent: 8, bitsPerPixel: 32,
            bytesPerRow: width * 4, space: CGColorSpaceCreateDeviceRGB(),
            bitmapInfo: CGBitmapInfo(rawValue: CGImageAlphaInfo.noneSkipLast.rawValue)
              .union(.byteOrder32Big),
            provider: provider, decode: nil, shouldInterpolate: true, intent: .defaultIntent
          ) else { return nil }

    let request = VNGenerateForegroundInstanceMaskRequest()
    let handler = VNImageRequestHandler(cgImage: image, options: [:])
    try handler.perform([request])
    guard let observation = request.results?.first, !observation.allInstances.isEmpty else {
      return nil
    }

    let mask = try observation.generateScaledMaskForImage(
      forInstances: observation.allInstances, from: handler
    )
    guard CVPixelBufferLockBaseAddress(mask, .readOnly) == kCVReturnSuccess else { return nil }
    defer { CVPixelBufferUnlockBaseAddress(mask, .readOnly) }
    guard let base = CVPixelBufferGetBaseAddress(mask) else { return nil }

    let maskWidth = CVPixelBufferGetWidth(mask)
    let maskHeight = CVPixelBufferGetHeight(mask)
    let bytesPerRow = CVPixelBufferGetBytesPerRow(mask)
    let format = CVPixelBufferGetPixelFormatType(mask)
    var alpha = Data(count: maskWidth * maskHeight)
    switch format {
    case kCVPixelFormatType_OneComponent32Float:
      alpha.withUnsafeMutableBytes { (output: UnsafeMutableRawBufferPointer) in
        for y in 0..<maskHeight {
          let row = base.advanced(by: y * bytesPerRow).assumingMemoryBound(to: Float32.self)
          for x in 0..<maskWidth {
            let value = row[x]
            output[y * maskWidth + x] = value.isFinite
              ? UInt8((255 * min(1, max(0, value))).rounded()) : 0
          }
        }
      }
    case kCVPixelFormatType_OneComponent8:
      alpha.withUnsafeMutableBytes { (output: UnsafeMutableRawBufferPointer) in
        guard let destination = output.baseAddress else { return }
        for y in 0..<maskHeight {
          destination.advanced(by: y * maskWidth).copyMemory(
            from: base.advanced(by: y * bytesPerRow), byteCount: maskWidth
          )
        }
      }
    default:
      return nil
    }

    return ["width": maskWidth, "height": maskHeight, "alpha": FlutterStandardTypedData(bytes: alpha)]
  }
}
