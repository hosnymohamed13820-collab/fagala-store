package com.hosnimanagement.callsbridge

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

class MainActivity : AppCompatActivity() {

    companion object {
        const val PREFS_NAME = "calls_bridge_prefs"
        const val KEY_SERVER_URL = "server_url"
        const val KEY_TOKEN = "secret_token"
        const val KEY_LAST_SUCCESS = "last_success"
        const val KEY_LAST_RESPONSE = "last_response"
        const val KEY_LAST_ERROR = "last_error"
        const val DEFAULT_URL = "https://hosnimanagement.com/calls_hook.php"

        private const val PERMISSION_REQ_CODE = 2001
    }

    private lateinit var editServerUrl: EditText
    private lateinit var editToken: EditText
    private lateinit var btnTest: Button
    private lateinit var txtTestResult: TextView
    private lateinit var btnPermission: Button
    private lateinit var btnBattery: Button
    private lateinit var txtLastSync: TextView
    private lateinit var txtLastResponse: TextView
    private lateinit var txtLastError: TextView
    private lateinit var btnSyncNow: Button

    private val executor = Executors.newSingleThreadExecutor()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        initViews()
        loadPreferences()
        setupListeners()
        schedulePeriodicSync()
        updateUiStatus()
    }

    override fun onResume() {
        super.onResume()
        updateUiStatus()
    }

    private fun initViews() {
        editServerUrl = findViewById(R.id.editServerUrl)
        editToken = findViewById(R.id.editToken)
        btnTest = findViewById(R.id.btnTest)
        txtTestResult = findViewById(R.id.txtTestResult)
        btnPermission = findViewById(R.id.btnPermission)
        btnBattery = findViewById(R.id.btnBattery)
        txtLastSync = findViewById(R.id.txtLastSync)
        txtLastResponse = findViewById(R.id.txtLastResponse)
        txtLastError = findViewById(R.id.txtLastError)
        btnSyncNow = findViewById(R.id.btnSyncNow)
    }

    private fun loadPreferences() {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        editServerUrl.setText(prefs.getString(KEY_SERVER_URL, DEFAULT_URL))
        editToken.setText(prefs.getString(KEY_TOKEN, ""))
    }

    private fun savePreferences() {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        prefs.edit()
            .putString(KEY_SERVER_URL, editServerUrl.text.toString().trim())
            .putString(KEY_TOKEN, editToken.text.toString().trim())
            .apply()
    }

    private fun setupListeners() {
        btnTest.setOnClickListener {
            savePreferences()
            testConnection()
        }

        btnPermission.setOnClickListener {
            requestRequiredPermissions()
        }

        btnBattery.setOnClickListener {
            requestBatteryExemption()
        }

        btnSyncNow.setOnClickListener {
            savePreferences()
            triggerImmediateSync()
        }
    }

    private fun testConnection() {
        val urlStr = editServerUrl.text.toString().trim()
        val token = editToken.text.toString().trim()

        if (!Net.isAllowedUrl(urlStr)) {
            txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.neg))
            txtTestResult.text = "الرابط غير صحيح، لازم يبدأ بـ https:// وينتمي لـ hosnimanagement.com"
            return
        }

        if (token.isEmpty()) {
            txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.neg))
            txtTestResult.text = "اكتب توكن الفرع الأول"
            return
        }

        val targetUrl = Net.normalizeUrl(urlStr) + "?ping=1"
        txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.ink_500))
        txtTestResult.text = "جاري الاتصال بالسيرفر..."

        executor.execute {
            var conn: HttpURLConnection? = null
            try {
                conn = Net.openSafe(targetUrl, token)
                conn.requestMethod = "GET"

                val code = conn.responseCode
                if (code == 401) {
                    runOnUiThread {
                        txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.neg))
                        txtTestResult.text = getString(R.string.status_token_error)
                    }
                    return@execute
                }

                val stream = if (code in 200..299) conn.inputStream else conn.errorStream
                val body = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() } ?: ""

                if (code in 200..299) {
                    val json = JSONObject(body)
                    if (json.optBoolean("ok", false)) {
                        val branch = json.optString("branch", "معروف")
                        runOnUiThread {
                            txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.pos))
                            txtTestResult.text = getString(R.string.status_connected, branch)
                        }
                    } else {
                        runOnUiThread {
                            txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.neg))
                            txtTestResult.text = "السيرفر رد برفض: $body"
                        }
                    }
                } else {
                    runOnUiThread {
                        txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.neg))
                        txtTestResult.text = "كود الخطأ: $code"
                    }
                }
            } catch (e: Exception) {
                runOnUiThread {
                    txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.neg))
                    txtTestResult.text = "فشل الاتصال: ${e.message}"
                }
            } finally {
                conn?.disconnect()
            }
        }
    }

    private fun requestRequiredPermissions() {
        val permissions = arrayOf(
            Manifest.permission.READ_CALL_LOG,
            Manifest.permission.READ_PHONE_STATE
        )
        ActivityCompat.requestPermissions(this, permissions, PERMISSION_REQ_CODE)
    }

    private fun requestBatteryExemption() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            val powerManager = getSystemService(Context.POWER_SERVICE) as PowerManager
            if (!powerManager.isIgnoringBatteryOptimizations(packageName)) {
                val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                    data = Uri.parse("package:$packageName")
                }
                startActivity(intent)
            } else {
                Toast.makeText(this, "التطبيق مستثنى بالفعل من توفير البطارية", Toast.LENGTH_SHORT).show()
            }
        }
    }

    private fun schedulePeriodicSync() {
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        val periodicSync = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
            .setConstraints(constraints)
            .build()

        WorkManager.getInstance(this).enqueueUniquePeriodicWork(
            BootReceiver.PERIODIC_WORK_NAME,
            ExistingPeriodicWorkPolicy.KEEP,
            periodicSync
        )
    }

    private fun triggerImmediateSync() {
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        val syncRequest = OneTimeWorkRequestBuilder<SyncWorker>()
            .setConstraints(constraints)
            .build()

        WorkManager.getInstance(this).enqueueUniqueWork(
            "CallsImmediateSync",
            ExistingWorkPolicy.REPLACE,
            syncRequest
        )

        Toast.makeText(this, "بدأت المزامنة الآن...", Toast.LENGTH_SHORT).show()
    }

    private fun updateUiStatus() {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val lastSuccess = prefs.getLong(KEY_LAST_SUCCESS, 0L)
        val lastResponse = prefs.getString(KEY_LAST_RESPONSE, null)
        val lastError = prefs.getString(KEY_LAST_ERROR, null)

        if (lastSuccess > 0) {
            val sdf = SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.getDefault())
            txtLastSync.text = getString(R.string.last_sync_label, sdf.format(Date(lastSuccess)))
        } else {
            txtLastSync.text = getString(R.string.last_sync_label, "—")
        }

        txtLastResponse.text = getString(R.string.last_response_label, lastResponse ?: "—")

        if (!lastError.isNullOrEmpty()) {
            txtLastError.visibility = View.VISIBLE
            txtLastError.text = getString(R.string.last_error_label, lastError)
        } else {
            txtLastError.visibility = View.GONE
        }
    }
}

