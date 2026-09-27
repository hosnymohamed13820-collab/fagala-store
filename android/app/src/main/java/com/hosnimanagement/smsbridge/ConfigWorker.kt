package com.hosnimanagement.smsbridge

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.net.URL

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

            val url = URL(configUrl)
            conn = url.openConnection() as HttpURLConnection
            conn.requestMethod = "GET"
            conn.connectTimeout = 15000
            conn.readTimeout = 15000
            conn.setRequestProperty("X-Sms-Token", token)

            if (conn.responseCode == 200) {
                val respStr = BufferedReader(InputStreamReader(conn.inputStream, Charsets.UTF_8)).use { it.readText() }
                val json = JSONObject(respStr)
                if (json.optBoolean("ok", false)) {
                    val incArr = json.optJSONArray("include")
                    val excArr = json.optJSONArray("exclude")

                    val incSet = mutableSetOf<String>()
                    if (incArr != null) {
                        for (i in 0 until incArr.length()) incSet.add(incArr.getString(i))
                    }

                    val excSet = mutableSetOf<String>()
                    if (excArr != null) {
                        for (i in 0 until excArr.length()) excSet.add(excArr.getString(i))
                    }

                    prefs.edit()
                        .putStringSet(MainActivity.KEY_INCLUDE_KW, incSet)
                        .putStringSet(MainActivity.KEY_EXCLUDE_KW, excSet)
                        .apply()

                    return Result.success()
                }
            }
            return Result.retry()
        } catch (e: Exception) {
            return Result.retry()
        } finally {
            conn?.disconnect()
        }
    }
}
