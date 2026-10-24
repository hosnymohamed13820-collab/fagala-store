package com.hosnimanagement.callsbridge

import android.content.Context
import android.os.Build
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection

/**
 * Worker إرسال مكالمات الفرع للسيرفر في الخلفية
 */
class SyncWorker(
    private val context: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(context, workerParams) {

    companion object {
        const val TAG = "CallsSyncWorker"
        const val PREFS_NAME = "calls_bridge_prefs"
        const val KEY_SERVER_URL = "server_url"
        const val KEY_TOKEN = "secret_token"
        const val KEY_LAST_SENT_ID = "last_sent_id"
        const val KEY_INITIAL_SYNC_DONE = "initial_sync_done"
        const val KEY_LAST_SUCCESS = "last_success"
        const val KEY_LAST_RESPONSE = "last_response"
        const val KEY_LAST_ERROR = "last_error"
        const val DEFAULT_URL = "https://hosnimanagement.com/calls_hook.php"
    }

    override suspend fun doWork(): Result {
        val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val rawUrl = prefs.getString(KEY_SERVER_URL, DEFAULT_URL)?.trim() ?: DEFAULT_URL
        val token = prefs.getString(KEY_TOKEN, "")?.trim() ?: ""

        if (token.isEmpty()) {
            prefs.edit().putString(KEY_LAST_ERROR, "التوكن مش متسجل").apply()
            return Result.failure()
        }

        val targetUrl = Net.normalizeUrl(rawUrl)
        if (!Net.isAllowedUrl(targetUrl)) {
            prefs.edit().putString(KEY_LAST_ERROR, "رابط السيرفر غير مسموح به").apply()
            return Result.failure()
        }

        var lastSentId = prefs.getLong(KEY_LAST_SENT_ID, 0L)
        val isInitialDone = prefs.getBoolean(KEY_INITIAL_SYNC_DONE, false)

        val calls = CallReader.readCalls(context, lastSentId, !isInitialDone)
        if (calls.isEmpty()) {
            prefs.edit()
                .putLong(KEY_LAST_SUCCESS, System.currentTimeMillis())
                .putString(KEY_LAST_RESPONSE, "مفيش مكالمات جديدة")
                .remove(KEY_LAST_ERROR)
                .apply()
            return Result.success()
        }

        val deviceName = "${Build.MANUFACTURER} ${Build.MODEL}".trim()
        val batches = calls.chunked(500)

        var totalInserted = 0
        var totalUpdated = 0
        var totalRejected = 0
        var totalReceived = 0

        for (batch in batches) {
            val payload = CallItem.buildBatchPayload(deviceName, batch)
            val result = sendBatch(targetUrl, token, payload)

            if (result.isTokenError) {
                prefs.edit().putString(KEY_LAST_ERROR, "التوكن غلط").apply()
                return Result.failure()
            }

            if (!result.isSuccess) {
                prefs.edit().putString(KEY_LAST_ERROR, result.errorMsg ?: "خطأ في الشبكة").apply()
                return Result.retry()
            }

            // تحديث lastSentId بعد نجاح الدفعة الحالية فقط
            val maxBatchId = batch.maxOfOrNull { it.id } ?: lastSentId
            if (maxBatchId > lastSentId) {
                lastSentId = maxBatchId
                prefs.edit().putLong(KEY_LAST_SENT_ID, lastSentId).apply()
            }

            totalReceived += result.received
            totalInserted += result.inserted
            totalUpdated += result.updated
            totalRejected += result.rejected
        }

        val summary = "مستلم: $totalReceived · جديد: $totalInserted · محدث: $totalUpdated · مرفوض: $totalRejected"
        prefs.edit()
            .putLong(KEY_LAST_SUCCESS, System.currentTimeMillis())
            .putString(KEY_LAST_RESPONSE, summary)
            .putBoolean(KEY_INITIAL_SYNC_DONE, true)
            .remove(KEY_LAST_ERROR)
            .apply()

        return Result.success()
    }

    private data class BatchResult(
        val isSuccess: Boolean,
        val isTokenError: Boolean = false,
        val received: Int = 0,
        val inserted: Int = 0,
        val updated: Int = 0,
        val rejected: Int = 0,
        val errorMsg: String? = null
    )

    private fun sendBatch(targetUrl: String, token: String, payloadJson: String): BatchResult {
        var conn: HttpURLConnection? = null
        return try {
            conn = Net.openSafe(targetUrl, token)
            conn.requestMethod = "POST"
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")

            OutputStreamWriter(conn.outputStream, Charsets.UTF_8).use { writer ->
                writer.write(payloadJson)
                writer.flush()
            }

            val code = conn.responseCode
            if (code == 401) {
                return BatchResult(isSuccess = false, isTokenError = true, errorMsg = "التوكن غلط (401)")
            }

            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val respBody = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() } ?: ""

            if (code in 200..299) {
                val json = JSONObject(respBody)
                if (json.optBoolean("ok", false)) {
                    BatchResult(
                        isSuccess = true,
                        received = json.optInt("received", 0),
                        inserted = json.optInt("inserted", 0),
                        updated = json.optInt("updated", 0),
                        rejected = json.optInt("rejected", 0)
                    )
                } else {
                    BatchResult(isSuccess = false, errorMsg = json.optString("error", "فشل الرد من السيرفر"))
                }
            } else {
                BatchResult(isSuccess = false, errorMsg = "كود استجابة السيرفر: $code")
            }
        } catch (e: Exception) {
            BatchResult(isSuccess = false, errorMsg = "خطأ في الاتصال: ${e.message}")
        } finally {
            conn?.disconnect()
        }
    }
}
