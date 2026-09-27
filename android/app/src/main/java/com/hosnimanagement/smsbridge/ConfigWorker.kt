package com.hosnimanagement.smsbridge

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.HttpURLConnection

class ConfigWorker(
    private val context: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(context, workerParams) {

    override suspend fun doWork(): Result {
        val prefs = context.getSharedPreferences(MainActivity.PREFS_NAME, Context.MODE_PRIVATE)
        val serverUrl = prefs.getString(MainActivity.KEY_SERVER_URL, MainActivity.DEFAULT_URL) ?: MainActivity.DEFAULT_URL
        val token = prefs.getString(MainActivity.KEY_TOKEN, "") ?: ""

        if (token.isBlank()) return Result.failure()

        var conn: HttpURLConnection? = null
        try {
            val sep = if (serverUrl.contains("?")) "&" else "?"
            val configUrl = "$serverUrl${sep}config=1"

            // 🔴 الرابط بيتفحص (https + hosnimanagement.com) ومفيش redirects — Net.openSafe
            conn = Net.openSafe(configUrl)
            conn.requestMethod = "GET"
            conn.setRequestProperty("X-Sms-Token", token)

            if (conn.responseCode == 200) {
                val respStr = BufferedReader(InputStreamReader(conn.inputStream, Charsets.UTF_8)).use { it.readText() }
                // 🔴 الرد بيتفحص كله قبل ما يتخزّن — أي حاجة غريبة = نفضل على القديم
                val parsed = Net.parseAndValidateConfig(respStr)
                if (parsed != null) {
                    val (incSet, excSet) = parsed
                    prefs.edit()
                        .putStringSet(MainActivity.KEY_INCLUDE_KW, incSet)
                        .putStringSet(MainActivity.KEY_EXCLUDE_KW, excSet)
                        .apply()
                    return Result.success()
                }
                prefs.edit().putString(MainActivity.KEY_LAST_ERROR, "رد السيرفر مش سليم").apply()
            }
            return Result.retry()
        } catch (e: Exception) {
            return Result.retry()
        } finally {
            conn?.disconnect()
        }
    }
}
