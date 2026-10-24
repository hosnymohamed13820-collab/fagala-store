package com.hosnimanagement.callsbridge

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.telephony.TelephonyManager
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

/**
 * مستقبل حالة الهاتف لإرسال المكالمة فور انتهائها أو فواتها
 */
class PhoneStateReceiver : BroadcastReceiver() {

    companion object {
        const val WORK_NAME = "CallsPhoneStateSync"
    }

    override fun onReceive(context: Context, intent: Intent?) {
        if (intent?.action != TelephonyManager.ACTION_PHONE_STATE_CHANGED) return

        val state = intent.getStringExtra(TelephonyManager.EXTRA_STATE)
        // عند انتهاء المكالمة أو فواتها تصبح الحالة IDLE
        if (state == TelephonyManager.EXTRA_STATE_IDLE) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            // تأخير ١٠ ثوانٍ لإتاحة وقت لنظام أندرويد لكتابة المكالمة في CallLog
            val syncRequest = OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(constraints)
                .setInitialDelay(10, TimeUnit.SECONDS)
                .build()

            WorkManager.getInstance(context).enqueueUniqueWork(
                WORK_NAME,
                ExistingWorkPolicy.REPLACE,
                syncRequest
            )
        }
    }
}

