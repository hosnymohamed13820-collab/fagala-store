package com.hosnimanagement.smsbridge

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

data class SmsItem(
    val id: Long,
    val sender: String,
    val body: String,
    val smsAtMs: Long,
    val androidId: Long?,
    val source: String,
    val sent: Int
)

data class QueueStats(
    val pendingCount: Int,
    val sentCount: Int
)

class SmsDatabase private constructor(context: Context) :
    SQLiteOpenHelper(context.applicationContext, DATABASE_NAME, null, DATABASE_VERSION) {

    companion object {
        private const val DATABASE_NAME = "sms_queue.db"
        private const val DATABASE_VERSION = 1
        private const val TABLE_NAME = "sms_queue"

        @Volatile
        private var instance: SmsDatabase? = null

        fun getInstance(context: Context): SmsDatabase {
            return instance ?: synchronized(this) {
                instance ?: SmsDatabase(context).also { instance = it }
            }
        }
    }

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            """
            CREATE TABLE $TABLE_NAME (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender TEXT NOT NULL,
                body TEXT NOT NULL,
                sms_at_ms INTEGER NOT NULL,
                android_id INTEGER,
                source TEXT NOT NULL,
                sent INTEGER NOT NULL DEFAULT 0,
                UNIQUE(sender, sms_at_ms, body)
            )
            """.trimIndent()
        )
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        // أي تحديث قادم للجدول
    }

    /**
     * إدخال رسالة في الطابور المحلي مع تجاهل التكرار (INSERT OR IGNORE).
     */
    fun insert(sender: String, body: String, smsAtMs: Long, androidId: Long?, source: String): Boolean {
        val cv = ContentValues().apply {
            put("sender", sender)
            put("body", body)
            put("sms_at_ms", smsAtMs)
            if (androidId != null) put("android_id", androidId) else putNull("android_id")
            put("source", source)
            put("sent", 0)
        }
        val rowId = writableDatabase.insertWithOnConflict(
            TABLE_NAME,
            null,
            cv,
            SQLiteDatabase.CONFLICT_IGNORE
        )
        return rowId != -1L
    }

    /**
     * جلب الرسايل التي لم يتم إرسالها بعد (بحد أقصى limit).
     */
    fun getPending(limit: Int = 200): List<SmsItem> {
        val list = mutableListOf<SmsItem>()
        val cursor = readableDatabase.query(
            TABLE_NAME,
            arrayOf("id", "sender", "body", "sms_at_ms", "android_id", "source", "sent"),
            "sent = 0",
            null,
            null,
            null,
            "id ASC",
            limit.toString()
        )
        cursor.use { c ->
            val idIdx     = c.getColumnIndexOrThrow("id")
            val senderIdx = c.getColumnIndexOrThrow("sender")
            val bodyIdx   = c.getColumnIndexOrThrow("body")
            val msIdx     = c.getColumnIndexOrThrow("sms_at_ms")
            val andIdIdx  = c.getColumnIndexOrThrow("android_id")
            val srcIdx    = c.getColumnIndexOrThrow("source")
            val sentIdx   = c.getColumnIndexOrThrow("sent")

            while (c.moveToNext()) {
                val andId = if (c.isNull(andIdIdx)) null else c.getLong(andIdIdx)
                list.add(
                    SmsItem(
                        id = c.getLong(idIdx),
                        sender = c.getString(senderIdx),
                        body = c.getString(bodyIdx),
                        smsAtMs = c.getLong(msIdx),
                        androidId = andId,
                        source = c.getString(srcIdx),
                        sent = c.getInt(sentIdx)
                    )
                )
            }
        }
        return list
    }

    /**
     * تعليم مجموعة رسايل تم إرسالها بنجاح للموقع (sent = 1).
     */
    fun markSent(ids: List<Long>) {
        if (ids.isEmpty()) return
        val db = writableDatabase
        db.beginTransaction()
        try {
            val cv = ContentValues().apply { put("sent", 1) }
            val chunked = ids.chunked(500)
            for (batch in chunked) {
                val placeholders = batch.joinToString(",") { "?" }
                val args = batch.map { it.toString() }.toTypedArray()
                db.update(TABLE_NAME, cv, "id IN ($placeholders)", args)
            }
            db.setTransactionSuccessful()
        } finally {
            db.endTransaction()
        }
    }

    /**
     * إحصائيات الطابور: قيد الانتظار والمرسل.
     */
    fun getStats(): QueueStats {
        var pending = 0
        var sent = 0
        val cursor = readableDatabase.rawQuery(
            "SELECT sent, COUNT(*) FROM $TABLE_NAME GROUP BY sent",
            null
        )
        cursor.use { c ->
            while (c.moveToNext()) {
                val isSent = c.getInt(0)
                val count  = c.getInt(1)
                if (isSent == 1) sent = count else pending = count
            }
        }
        return QueueStats(pending, sent)
    }
}
