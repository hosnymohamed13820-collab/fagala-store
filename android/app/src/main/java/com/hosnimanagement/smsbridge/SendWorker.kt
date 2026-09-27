package com.hosnimanagement.smsbridge

import android.content.Context
import android.os.Build
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class SendWorker(
    private val context: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(context, workerParams) {

    override suspend fun doWork(): Result {
        val prefs = context.getSharedPreferences(MainActivity.PREFS_NAME, Context.MODE_PRIVATE)
        val serverUrl = prefs.getString(MainActivity.KEY_SERVER_URL, MainActivity.DEFAULT_URL) ?: MainActivity.DEFAULT_URL
        val token = prefs.getString(MainActivity.KEY_TOKEN, "") ?: ""

        if (token.isBlank()) {
            recordError(prefs, "التوكن غير محدد في إعدادات التطبيق.")
            return Result.failure()
        }

        val db = SmsDatabase.getInstance(context)

        while (true) {
            val pending = db.getPending(200)
            if (pending.isEmpty()) {
                break
            }

            val payload = JSONObject().apply {
                put("device", "${Build.MANUFACTURER} ${Build.MODEL}".trim())
                val msgsArray = JSONArray()
                for (item in pending) {
                    val m = JSONObject().apply {
                        put("sender", item.sender)
                        put("body", item.body)
                        put("sms_at_ms", item.smsAtMs)
                        if (item.androidId != null) put("android_id", item.androidId)
                        put("source", item.source)
                    }
                    msgsArray.put(m)
                }
                put("messages", msgsArray)
            }

            var conn: HttpURLConnection? = null
            try {
                val url = URL(serverUrl)
                conn = url.openConnection() as HttpURLConnection
                conn.requestMethod = "POST"
                conn.connectTimeout = 15000
                conn.readTimeout = 30000
                conn.doOutput = true
                conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")
                conn.setRequestProperty("X-Sms-Token", token)

                OutputStreamWriter(conn.outputStream, Charsets.UTF_8).use { writer ->
                    writer.write(payload.toString())
                    writer.flush()
                }

                val responseCode = conn.responseCode
                if (responseCode == 200) {
                    val respStr = BufferedReader(InputStreamReader(conn.inputStream, Charsets.UTF_8)).use { it.readText() }
                    val jsonResp = JSONObject(respStr)
                    if (jsonResp.optBoolean("ok", false)) {
                        db.markSent(pending.map { it.id })
                        recordSuccess(prefs)
                    } else {
                        val err = jsonResp.optString("error", "فشل الرد من السيرفر")
                        recordError(prefs, "خطأ من السيرفر: $err")
                        return Result.retry()
                    }
                } else {
                    val errBody = try {
                        BufferedReader(InputStreamReader(conn.errorStream ?: conn.inputStream, Charsets.UTF_8)).use { it.readText() }
                    } catch (e: Exception) {
                        ""
                    }
                    recordError(prefs, "خطأ HTTP $responseCode: $errBody")
                    return Result.retry()
                }
            } catch (ex: Exception) {
                recordError(prefs, "فشل الاتصال: ${ex.message}")
                return Result.retry()
            } finally {
                conn?.disconnect()
            }
        }

        return Result.success()
    }

    private fun recordSuccess(prefs: android.content.SharedPreferences) {
        val sdf = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault())
        prefs.edit()
            .putString(MainActivity.KEY_LAST_SUCCESS, sdf.format(Date()))
            .putString(MainActivity.KEY_LAST_ERROR, "لا يوجد")
            .apply()
    }

    private fun recordError(prefs: android.content.SharedPreferences, error: String) {
        prefs.edit()
            .putString(MainActivity.KEY_LAST_ERROR, error)
            .apply()
    }
}
