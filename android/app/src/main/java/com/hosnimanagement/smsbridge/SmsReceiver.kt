package com.hosnimanagement.smsbridge

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import androidx.work.Constraints
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager

class SmsReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) return

        val msgs = Telephony.Sms.Intents.getMessagesFromIntent(intent)
        if (msgs.isNullOrEmpty()) return

        // تجميع أجزاء الرسالة الواحدة لو كانت multipart
        val grouped = msgs.groupBy { it.originatingAddress ?: "Unknown" }

        val prefs = context.getSharedPreferences(MainActivity.PREFS_NAME, Context.MODE_PRIVATE)
        val incSet = prefs.getStringSet(MainActivity.KEY_INCLUDE_KW, null)
        val excSet = prefs.getStringSet(MainActivity.KEY_EXCLUDE_KW, null)

        // 🔴 قوايم السيرفر بتضيّق بس — القواعد الثابتة جوّه SmsFilter
        val serverInclude = incSet?.toList() ?: emptyList()
        val serverExclude = excSet?.toList() ?: emptyList()

        val db = SmsDatabase.getInstance(context)
        var hasNewToQueue = false

        for ((sender, parts) in grouped) {
            val sb = java.lang.StringBuilder()
            var smsAtMs = 0L

            for (part in parts) {
                sb.append(part.messageBody ?: "")
                if (smsAtMs == 0L) {
                    smsAtMs = part.timestampMillis
                }
            }

            if (smsAtMs == 0L) {
                smsAtMs = System.currentTimeMillis()
            }

            val body = sb.toString()
            if (sender.isBlank() || body.isBlank()) continue

            // تطبيق الفلتر: الرسالة اللي مابتعدّيش الفلتر عمرها ما تتخزّن ولا تتبعت
            if (SmsFilter.shouldSend(sender, body, serverInclude, serverExclude)) {
                val inserted = db.insert(
                    sender = sender,
                    body = body,
                    smsAtMs = smsAtMs,
                    androidId = null,
                    source = "live"
                )
                if (inserted) {
                    hasNewToQueue = true
                }
            }
        }

        // تشغيل SendWorker لو انضافت رسايل جديدة للطابور
        if (hasNewToQueue) {
            val sendRequest = OneTimeWorkRequestBuilder<SendWorker>()
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .build()

            WorkManager.getInstance(context).enqueue(sendRequest)
        }
    }
}
