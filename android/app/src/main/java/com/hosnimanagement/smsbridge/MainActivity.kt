package com.hosnimanagement.smsbridge

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
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

class MainActivity : AppCompatActivity() {

    companion object {
        const val PREFS_NAME = "sms_bridge_prefs"
        const val KEY_SERVER_URL = "server_url"
        const val KEY_TOKEN = "secret_token"
        const val KEY_LAST_SUCCESS = "last_success"
        const val KEY_LAST_ERROR = "last_error"
        const val KEY_INCLUDE_KW = "include_keywords"
        const val KEY_EXCLUDE_KW = "exclude_keywords"
        const val KEY_BACKFILL_STATUS = "backfill_status"
        const val KEY_INITIAL_BACKFILL_DONE = "initial_backfill_done"

        const val DEFAULT_URL = "https://hosnimanagement.com/sms_hook.php"
        private const val PERMISSIONS_REQUEST_CODE = 1001
    }

    private lateinit var editServerUrl: EditText
    private lateinit var editToken: EditText
    private lateinit var btnTestConnection: Button
    private lateinit var txtTestResult: TextView
    private lateinit var btnBackfill: Button
    private lateinit var txtBackfillProgress: TextView
    private lateinit var txtQueueCount: TextView
    private lateinit var txtSentCount: TextView
    private lateinit var txtLastSuccess: TextView
    private lateinit var txtLastError: TextView
    private lateinit var layoutBatteryWarn: LinearLayout
    private lateinit var btnBatteryOpt: Button

    private val executor = Executors.newSingleThreadExecutor()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        initViews()
        loadSavedConfig()
        checkPermissions()
        checkBatteryOptimization()
        schedulePeriodicWorkers()
    }

    override fun onResume() {
        super.onResume()
        updateStatus()
        checkBatteryOptimization()
    }

    private fun initViews() {
        editServerUrl       = findViewById(R.id.editServerUrl)
        editToken           = findViewById(R.id.editToken)
        btnTestConnection   = findViewById(R.id.btnTestConnection)
        txtTestResult       = findViewById(R.id.txtTestResult)
        btnBackfill         = findViewById(R.id.btnBackfill)
        txtBackfillProgress = findViewById(R.id.txtBackfillProgress)
        txtQueueCount       = findViewById(R.id.txtQueueCount)
        txtSentCount        = findViewById(R.id.txtSentCount)
        txtLastSuccess      = findViewById(R.id.txtLastSuccess)
        txtLastError        = findViewById(R.id.txtLastError)
        layoutBatteryWarn   = findViewById(R.id.layoutBatteryWarn)
        btnBatteryOpt       = findViewById(R.id.btnBatteryOpt)

        btnTestConnection.setOnClickListener {
            saveConfig()
            testConnection()
        }

        btnBackfill.setOnClickListener {
            saveConfig()
            startBackfill()
        }

        btnBatteryOpt.setOnClickListener {
            requestIgnoreBatteryOptimization()
        }
    }

    private fun loadSavedConfig() {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val url   = prefs.getString(KEY_SERVER_URL, DEFAULT_URL) ?: DEFAULT_URL
        val token = prefs.getString(KEY_TOKEN, "") ?: ""

        editServerUrl.setText(url)
        editToken.setText(token)
    }

    private fun saveConfig() {
        val url   = editServerUrl.text.toString().trim()
        val token = editToken.text.toString().trim()

        getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit()
            .putString(KEY_SERVER_URL, if (url.isNotEmpty()) url else DEFAULT_URL)
            .putString(KEY_TOKEN, token)
            .apply()
    }

    private fun updateStatus() {
        val db = SmsDatabase.getInstance(this)
        val stats = db.getStats()
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

        txtQueueCount.text = "في الطابور المحلي: ${stats.pendingCount}"
        txtSentCount.text  = "تم الإرسال للموقع: ${stats.sentCount}"

        val lastSuccess = prefs.getString(KEY_LAST_SUCCESS, "لم يتم بعد") ?: "لم يتم بعد"
        txtLastSuccess.text = "آخر إرسال ناجح: $lastSuccess"

        val lastError = prefs.getString(KEY_LAST_ERROR, "لا يوجد") ?: "لا يوجد"
        txtLastError.text = "آخر خطأ: $lastError"

        val backfillStatus = prefs.getString(KEY_BACKFILL_STATUS, null)
        if (!backfillStatus.isNullOrEmpty()) {
            txtBackfillProgress.text = backfillStatus
            txtBackfillProgress.visibility = View.VISIBLE
        }
    }

    private fun testConnection() {
        val urlStr = editServerUrl.text.toString().trim()
        val token  = editToken.text.toString().trim()

        if (token.isEmpty()) {
            txtTestResult.visibility = View.VISIBLE
            txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.neg))
            txtTestResult.text = "❌ أدخل التوكن أولاً."
            return
        }

        txtTestResult.visibility = View.VISIBLE
        txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.text_secondary))
        txtTestResult.text = "جاري الاتصال بالسيرفر..."
        btnTestConnection.isEnabled = false

        executor.execute {
            var conn: HttpURLConnection? = null
            try {
                val sep = if (urlStr.contains("?")) "&" else "?"
                val fullUrl = "$urlStr${sep}config=1"
                val u = URL(fullUrl)
                conn = u.openConnection() as HttpURLConnection
                conn.requestMethod = "GET"
                conn.connectTimeout = 10000
                conn.readTimeout = 10000
                conn.setRequestProperty("X-Sms-Token", token)

                val code = conn.responseCode
                if (code == 200) {
                    val respStr = BufferedReader(InputStreamReader(conn.inputStream, Charsets.UTF_8)).use { it.readText() }
                    val json = JSONObject(respStr)
                    if (json.optBoolean("ok", false)) {
                        val incArr = json.optJSONArray("include")
                        val excArr = json.optJSONArray("exclude")
                        val incCount = incArr?.length() ?: 0
                        val excCount = excArr?.length() ?: 0

                        // حفظ الكلمات في SharedPreferences
                        val incSet = mutableSetOf<String>()
                        if (incArr != null) {
                            for (i in 0 until incArr.length()) incSet.add(incArr.getString(i))
                        }
                        val excSet = mutableSetOf<String>()
                        if (excArr != null) {
                            for (i in 0 until excArr.length()) excSet.add(excArr.getString(i))
                        }
                        getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit()
                            .putStringSet(KEY_INCLUDE_KW, incSet)
                            .putStringSet(KEY_EXCLUDE_KW, excSet)
                            .apply()

                        runOnUiThread {
                            btnTestConnection.isEnabled = true
                            txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.pos))
                            txtTestResult.text = "✅ الاتصال ناجح! تم استلام $incCount كلمة تضمين و $excCount كلمة استبعاد."
                        }
                    } else {
                        val err = json.optString("error", "رد غير متوقع")
                        runOnUiThread {
                            btnTestConnection.isEnabled = true
                            txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.neg))
                            txtTestResult.text = "❌ فشل: $err"
                        }
                    }
                } else if (code == 401) {
                    runOnUiThread {
                        btnTestConnection.isEnabled = true
                        txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.neg))
                        txtTestResult.text = "❌ التوكن غير صحيح (401 Unauthorized)."
                    }
                } else {
                    runOnUiThread {
                        btnTestConnection.isEnabled = true
                        txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.neg))
                        txtTestResult.text = "❌ خطأ HTTP $code من السيرفر."
                    }
                }
            } catch (e: Exception) {
                runOnUiThread {
                    btnTestConnection.isEnabled = true
                    txtTestResult.setTextColor(ContextCompat.getColor(this, R.color.neg))
                    txtTestResult.text = "❌ تعذر الاتصال: ${e.message}"
                }
            } finally {
                conn?.disconnect()
            }
        }
    }

    private fun startBackfill() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_SMS) != PackageManager.PERMISSION_GRANTED) {
            ActivityCompat.requestPermissions(this, arrayOf(Manifest.permission.READ_SMS), PERMISSIONS_REQUEST_CODE)
            return
        }

        txtBackfillProgress.visibility = View.VISIBLE
        txtBackfillProgress.text = "جاري سحب وفحص الرسايل القديمة في الخلفية..."
        Toast.makeText(this, "بدأ سحب الرسايل القديمة...", Toast.LENGTH_SHORT).show()

        val req = OneTimeWorkRequestBuilder<BackfillWorker>().build()
        WorkManager.getInstance(this).enqueue(req)
    }

    private fun checkPermissions() {
        val perms = mutableListOf(
            Manifest.permission.RECEIVE_SMS,
            Manifest.permission.READ_SMS
        )
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            perms.add(Manifest.permission.POST_NOTIFICATIONS)
        }

        val needed = perms.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }

        if (needed.isNotEmpty()) {
            ActivityCompat.requestPermissions(this, needed.toTypedArray(), PERMISSIONS_REQUEST_CODE)
        } else {
            onAllPermissionsGranted()
        }
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == PERMISSIONS_REQUEST_CODE) {
            val allGranted = grantResults.isNotEmpty() && grantResults.all { it == PackageManager.PERMISSION_GRANTED }
            if (allGranted) {
                onAllPermissionsGranted()
            } else {
                Toast.makeText(this, R.string.permission_required, Toast.LENGTH_LONG).show()
            }
        }
    }

    private fun onAllPermissionsGranted() {
        val prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val initialDone = prefs.getBoolean(KEY_INITIAL_BACKFILL_DONE, false)
        if (!initialDone) {
            prefs.edit().putBoolean(KEY_INITIAL_BACKFILL_DONE, true).apply()
            startBackfill()
        }
    }

    private fun checkBatteryOptimization() {
        val powerManager = getSystemService(Context.POWER_SERVICE) as PowerManager
        val isIgnoring = powerManager.isIgnoringBatteryOptimizations(packageName)
        layoutBatteryWarn.visibility = if (isIgnoring) View.GONE else View.VISIBLE
    }

    private fun requestIgnoreBatteryOptimization() {
        try {
            val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                data = Uri.parse("package:$packageName")
            }
            startActivity(intent)
        } catch (e: Exception) {
            Toast.makeText(this, "افتح إعدادات التطبيق واستثنه من توفير البطارية يدوياً.", Toast.LENGTH_LONG).show()
        }
    }

    private fun schedulePeriodicWorkers() {
        // تحديث كلمات الفلتر كل ٦ ساعات
        val configReq = PeriodicWorkRequestBuilder<ConfigWorker>(6, TimeUnit.HOURS)
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build()
            )
            .build()

        WorkManager.getInstance(this).enqueueUniquePeriodicWork(
            "ConfigUpdateWork",
            ExistingPeriodicWorkPolicy.KEEP,
            configReq
        )

        // إرسال احتياطي كل ١٥ دقيقة
        val sendReq = PeriodicWorkRequestBuilder<SendWorker>(15, TimeUnit.MINUTES)
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build()
            )
            .build()

        WorkManager.getInstance(this).enqueueUniquePeriodicWork(
            "SendQueueWork",
            ExistingPeriodicWorkPolicy.KEEP,
            sendReq
        )
    }
}
