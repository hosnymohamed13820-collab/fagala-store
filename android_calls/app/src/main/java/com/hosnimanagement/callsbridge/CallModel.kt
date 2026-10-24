package com.hosnimanagement.callsbridge

import org.json.JSONArray
import org.json.JSONObject

/**
 * نموذج بيانات المكالمة المقروءة من CallLog
 */
data class CallItem(
    val id: Long,
    val number: String,
    val type: Int,
    val dateMs: Long,
    val duration: Int
) {
    /**
     * تحويل المكالمة إلى JSONObject متوافق مع عقد السيرفر
     * إذا كان الرقم فارغاً يخرج كـ "" وليس null
     */
    fun toJson(): JSONObject {
        val obj = JSONObject()
        obj.put("call_id", id)
        obj.put("number", number)
        obj.put("type", type)
        obj.put("date_ms", dateMs)
        obj.put("duration", duration)
        return obj
    }

    companion object {
        /**
         * بناء كائن الدفعة كاملاً لإرساله للسيرفر
         */
        fun buildBatchPayload(deviceName: String, calls: List<CallItem>): String {
            val root = JSONObject()
            root.put("device", deviceName.take(64))

            val arr = JSONArray()
            for (c in calls) {
                arr.put(c.toJson())
            }
            root.put("calls", arr)
            return root.toString()
        }
    }
}

