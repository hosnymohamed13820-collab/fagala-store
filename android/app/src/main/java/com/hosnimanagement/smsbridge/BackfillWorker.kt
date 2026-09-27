package com.hosnimanagement.smsbridge

import android.content.Context
import android.net.Uri
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters

class BackfillWorker(
    private val context: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(context, workerParams) {

    override suspend fun doWork(): Result {
        val prefs = context.getSharedPreferences(MainActivity.PREFS_NAME, Context.MODE_PRIVATE)

        val incSet = prefs.getStringSet(MainActivity.KEY_INCLUDE_KW, null)
        val excSet = prefs.getStringSet(MainActivity.KEY_EXCLUDE_KW, null)

        val include = incSet?.toList() ?: SmsFilter.DEFAULT_INCLUDE
        val exclude = excSet?.toList() ?: SmsFilter.DEFAULT_EXCLUDE

        val db = SmsDatabase.getInstance(context)
        val uri = Uri.parse("content://sms/inbox")
        val projection = arrayOf("_id", "address", "body", "date")

        var totalRead = 0
        var totalPassed = 0

        try {
            val cursor = context.contentResolver.query(
                uri,
                projection,
                null,
                null,
                "date ASC"
            )

            cursor?.use { c ->
                val idIdx   = c.getColumnIndexOrThrow("_id")
                val addrIdx = c.getColumnIndexOrThrow("address")
                val bodyIdx = c.getColumnIndexOrThrow("body")
                val dateIdx = c.getColumnIndexOrThrow("date")

                while (c.moveToNext()) {
                    totalRead++
                    val androidId = c.getLong(idIdx)
                    val sender    = c.getString(addrIdx) ?: ""
                    val body      = c.getString(bodyIdx) ?: ""
                    val smsAtMs   = c.getLong(dateIdx)

                    if (sender.isBlank() || body.isBlank() || smsAtMs <= 0) continue

                    if (SmsFilter.shouldSend(sender, body, include, exclude)) {
                        val inserted = db.insert(
                            sender = sender,
                            body = body,
                            smsAtMs = smsAtMs,
                            androidId = androidId,
                            source = "backfill"
                        )
                        if (inserted) {
                            totalPassed++
                        }
                    }
                }
            }

            prefs.edit()
                .putString(MainActivity.KEY_BACKFILL_STATUS, "تم فحص $totalRead رسالة، وتمت إضافة $totalPassed رسالة مطابقة للطابور.")
                .apply()

            // تشغيل SendWorker فوراً لإرسال الرسايل المسترجعة
            val sendRequest = OneTimeWorkRequestBuilder<SendWorker>()
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .build()

            WorkManager.getInstance(context).enqueue(sendRequest)

            return Result.success()
        } catch (e: Exception) {
            prefs.edit()
                .putString(MainActivity.KEY_BACKFILL_STATUS, "فشل سحب الرسايل: ${e.message}")
                .apply()
            return Result.failure()
        }
    }
}
