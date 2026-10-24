package com.hosnimanagement.callsbridge

import android.content.Context
import android.provider.CallLog

/**
 * قارئ مكالمات النظام من CallLog.Calls
 */
object CallReader {

    private val PROJECTION = arrayOf(
        CallLog.Calls._ID,
        CallLog.Calls.NUMBER,
        CallLog.Calls.TYPE,
        CallLog.Calls.DATE,
        CallLog.Calls.DURATION
    )

    /**
     * قراءة المكالمات وفق منطق البريف:
     * - أول تشغيل: آخر ٣٠ يوم
     * - التشغيل المعتاد: آخر ٤٨ ساعة أو _ID أكبر من آخر معرف مرسل
     */
    fun readCalls(context: Context, lastSentId: Long, isInitialSync: Boolean): List<CallItem> {
        val now = System.currentTimeMillis()
        val selection: String
        val selectionArgs: Array<String>

        if (isInitialSync) {
            val thirtyDaysAgo = now - (30L * 24 * 60 * 60 * 1000)
            selection = "${CallLog.Calls.DATE} >= ?"
            selectionArgs = arrayOf(thirtyDaysAgo.toString())
        } else {
            val fortyEightHoursAgo = now - (48L * 60 * 60 * 1000)
            selection = "${CallLog.Calls.DATE} >= ? OR ${CallLog.Calls._ID} > ?"
            selectionArgs = arrayOf(fortyEightHoursAgo.toString(), lastSentId.toString())
        }

        val sortOrder = "${CallLog.Calls._ID} ASC"
        val calls = mutableListOf<CallItem>()

        try {
            val cursor = context.contentResolver.query(
                CallLog.Calls.CONTENT_URI,
                PROJECTION,
                selection,
                selectionArgs,
                sortOrder
            )

            cursor?.use { c ->
                val idIdx = c.getColumnIndex(CallLog.Calls._ID)
                val numIdx = c.getColumnIndex(CallLog.Calls.NUMBER)
                val typeIdx = c.getColumnIndex(CallLog.Calls.TYPE)
                val dateIdx = c.getColumnIndex(CallLog.Calls.DATE)
                val durIdx = c.getColumnIndex(CallLog.Calls.DURATION)

                while (c.moveToNext()) {
                    val id = if (idIdx >= 0) c.getLong(idIdx) else 0L
                    val num = if (numIdx >= 0) c.getString(numIdx) ?: "" else ""
                    val type = if (typeIdx >= 0) c.getInt(typeIdx) else 0
                    val dateMs = if (dateIdx >= 0) c.getLong(dateIdx) else 0L
                    val dur = if (durIdx >= 0) c.getInt(durIdx) else 0

                    if (id > 0) {
                        calls.add(CallItem(id, num, type, dateMs, dur))
                    }
                }
            }
        } catch (e: Exception) {
            // في حالة عدم منح الصلاحية أو خطأ في النظام نرجع القائمة فارغة
        }

        return calls
    }
}

