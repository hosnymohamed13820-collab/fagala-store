package com.hosnimanagement.smsbridge

import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URI
import java.net.URL

/**
 * أدوات الشبكة المؤمنة لتطبيق ربط الرسايل.
 * الاتصال مقفول بالكامل على دومين الموقع المعتمد وببروتوكول HTTPS فقط.
 */
object Net {

    const val ALLOWED_HOST = "hosnimanagement.com"

    /**
     * فحص أمان الرابط:
     * - لازم يبدأ بـ https://
     * - الـ host لازم يطابق hosnimanagement.com تماماً بدون أي تلاعب
     * - ممنوع وجود userInfo في الرابط
     */
    fun isAllowedUrl(urlStr: String): Boolean {
        return try {
            val trimmed = urlStr.trim()
            val uri = URI(trimmed)
            val scheme = uri.scheme?.lowercase()
            val host = uri.host?.lowercase()
            val userInfo = uri.userInfo
            scheme == "https" && host == ALLOWED_HOST && userInfo == null
        } catch (e: Exception) {
            false
        }
    }

    /**
     * فتح اتصال آمن مع فحص الرابط ومنع تتبع الـ redirects تلقائياً وضبط المهلات.
     */
    fun openSafe(urlStr: String): HttpURLConnection {
        if (!isAllowedUrl(urlStr)) {
            throw SecurityException("الرابط غير مسموح به، مسموح فقط بـ https://$ALLOWED_HOST")
        }
        val url = URL(urlStr.trim())
        val conn = url.openConnection() as HttpURLConnection
        conn.instanceFollowRedirects = false
        conn.connectTimeout = 15000
        conn.readTimeout = 20000
        return conn
    }

    /**
     * فحص والتحقق من صحة رد السيرفر لإعدادات الكلمات (?config=1):
     * - الرد لازم يبقى JSON فيه ok == true ومصفوفتين include و exclude.
     * - كل مصفوفة لا تتعدى ٥٠ كلمة.
     * - كل كلمة لا تتعدى ٤٠ حرف ومش فاضية بعد الـ trim.
     * - أي خلل يرجع null ليتم رفض الرد بالكامل والبقاء على القديم.
     */
    fun parseAndValidateConfig(respStr: String): Pair<Set<String>, Set<String>>? {
        return try {
            val json = JSONObject(respStr)
            if (!json.optBoolean("ok", false)) return null

            val incArr = json.optJSONArray("include") ?: return null
            val excArr = json.optJSONArray("exclude") ?: return null

            if (incArr.length() > 50 || excArr.length() > 50) return null

            val incSet = mutableSetOf<String>()
            for (i in 0 until incArr.length()) {
                val item = incArr.opt(i)
                if (item !is String) return null
                val trimmed = item.trim()
                if (trimmed.isEmpty() || trimmed.length > 40) return null
                incSet.add(trimmed)
            }

            val excSet = mutableSetOf<String>()
            for (i in 0 until excArr.length()) {
                val item = excArr.opt(i)
                if (item !is String) return null
                val trimmed = item.trim()
                if (trimmed.isEmpty() || trimmed.length > 40) return null
                excSet.add(trimmed)
            }

            Pair(incSet, excSet)
        } catch (e: Exception) {
            null
        }
    }
}
