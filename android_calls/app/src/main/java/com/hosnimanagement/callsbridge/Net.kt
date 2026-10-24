package com.hosnimanagement.callsbridge

import java.net.HttpURLConnection
import java.net.URI
import java.net.URL

/**
 * أدوات الشبكة المؤمنة لتطبيق مكالمات الفرع.
 * الاتصال مقفول على HTTPS فقط والدومينات المعتمدة.
 */
object Net {

    val ALLOWED_HOSTS = setOf("hosnimanagement.com", "ashraqa.hosnimanagement.com")

    /**
     * فحص أمان الرابط:
     * - لازم يبدأ بـ https://
     * - الـ host لازم يطابق أحد الدومينات المعتمدة تماماً
     * - ممنوع وجود userInfo في الرابط
     */
    fun isAllowedUrl(urlStr: String): Boolean {
        return try {
            val trimmed = urlStr.trim()
            val uri = URI(trimmed)
            val scheme = uri.scheme?.lowercase()
            val host = uri.host?.lowercase()
            val userInfo = uri.userInfo
            scheme == "https" && host in ALLOWED_HOSTS && userInfo == null
        } catch (e: Exception) {
            false
        }
    }

    /**
     * تطبيع الرابط لضمان توجيهه إلى calls_hook.php
     */
    fun normalizeUrl(urlStr: String): String {
        val trimmed = urlStr.trim().trimEnd('/')
        return if (!trimmed.endsWith("calls_hook.php")) {
            "$trimmed/calls_hook.php"
        } else {
            trimmed
        }
    }

    /**
     * فتح اتصال آمن مع ضبط المهلات وهيدر التوكن
     */
    fun openSafe(urlStr: String, token: String? = null): HttpURLConnection {
        if (!isAllowedUrl(urlStr)) {
            throw SecurityException("الرابط غير مسموح به، مسموح فقط بـ hosnimanagement.com أو ashraqa.hosnimanagement.com عبر HTTPS")
        }
        val url = URL(urlStr.trim())
        val conn = url.openConnection() as HttpURLConnection
        conn.instanceFollowRedirects = false
        conn.connectTimeout = 15000
        conn.readTimeout = 20000
        if (!token.isNullOrEmpty()) {
            conn.setRequestProperty("X-Calls-Token", token.trim())
        }
        return conn
    }
}

