package com.babyparfume.babyparfume_mobile

import android.content.res.AssetManager
import android.graphics.Bitmap
import android.graphics.Color
import org.tensorflow.lite.Interpreter
import java.nio.ByteBuffer
import java.nio.ByteOrder
import java.nio.channels.FileChannel
import kotlin.math.roundToInt

internal class ProductPhotoSegmenter(private val assets: AssetManager) {
    private companion object {
        const val MODEL_SIZE = 320
        const val MODEL_ASSET = "product_photo/u2netp_d0_320_fp16.tflite"
        val MEAN = floatArrayOf(0.485f, 0.456f, 0.406f)
        val STD = floatArrayOf(0.229f, 0.224f, 0.225f)
    }

    fun segment(rgba: ByteArray, width: Int, height: Int): Map<String, Any>? {
        require(width in 1..512 && height in 1..512 && rgba.size == width * height * 4)
        val pixels = scaledPixels(rgba, width, height)
        val brightest = pixels.maxOf { maxOf(Color.red(it), Color.green(it), Color.blue(it)) }
        if (brightest == 0) return null

        val input = buffer(MODEL_SIZE * MODEL_SIZE * 3)
        for (pixel in pixels) {
            input.putFloat((Color.red(pixel).toFloat() / brightest - MEAN[0]) / STD[0])
            input.putFloat((Color.green(pixel).toFloat() / brightest - MEAN[1]) / STD[1])
            input.putFloat((Color.blue(pixel).toFloat() / brightest - MEAN[2]) / STD[2])
        }
        input.rewind()
        val output = buffer(MODEL_SIZE * MODEL_SIZE)
        Interpreter(openModel(), Interpreter.Options().setNumThreads(2)).use {
            it.run(input, output)
        }
        output.rewind()
        val probabilities = FloatArray(MODEL_SIZE * MODEL_SIZE) { output.float }
        if (probabilities.any { !it.isFinite() } || probabilities.max() < 0.5f) {
            return null
        }
        val alpha = ByteArray(probabilities.size) { index ->
            val edge = ((probabilities[index] - 0.2f) / 0.65f).coerceIn(0f, 1f)
            (255 * edge * edge * (3 - 2 * edge)).roundToInt().toByte()
        }
        return mapOf("width" to MODEL_SIZE, "height" to MODEL_SIZE, "alpha" to alpha)
    }

    private fun scaledPixels(rgba: ByteArray, width: Int, height: Int): IntArray {
        val pixels = IntArray(width * height) { index ->
            val offset = index * 4
            Color.rgb(
                rgba[offset].toInt() and 0xff,
                rgba[offset + 1].toInt() and 0xff,
                rgba[offset + 2].toInt() and 0xff
            )
        }
        val source = Bitmap.createBitmap(pixels, width, height, Bitmap.Config.ARGB_8888)
        val scaled = Bitmap.createScaledBitmap(source, MODEL_SIZE, MODEL_SIZE, true)
        try {
            return IntArray(MODEL_SIZE * MODEL_SIZE).also {
                scaled.getPixels(it, 0, MODEL_SIZE, 0, 0, MODEL_SIZE, MODEL_SIZE)
            }
        } finally {
            if (scaled !== source) scaled.recycle()
            source.recycle()
        }
    }

    private fun openModel(): ByteBuffer = assets.openFd(MODEL_ASSET).use { asset ->
        asset.createInputStream().use { stream ->
            stream.channel.map(FileChannel.MapMode.READ_ONLY, asset.startOffset, asset.length)
        }
    }

    private fun buffer(floatCount: Int): ByteBuffer =
        ByteBuffer.allocateDirect(floatCount * Float.SIZE_BYTES).order(ByteOrder.nativeOrder())
}
