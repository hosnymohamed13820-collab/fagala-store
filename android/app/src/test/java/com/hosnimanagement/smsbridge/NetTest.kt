package com.hosnimanagement.smsbridge

import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * اختبارات وحدة لفحص أمان الشبكة والروابط والتحقق من صحة ردود السيرفر.
 */
class NetTest {

    // ١) الرابط الصحيح المعتمد: https://hosnimanagement.com/sms_hook.php -> true
    @Test
    fun testAllowedUrl_validHttps() {
        assertTrue(Net.isAllowedUrl("https://hosnimanagement.com/sms_hook.php"))
    }

    // ٢) رابط غير مشفر HTTP: http://hosnimanagement.com/... -> false
    @Test
    fun testAllowedUrl_rejectHttp() {
        assertFalse(Net.isAllowedUrl("http://hosnimanagement.com/sms_hook.php"))
    }

    // ٣) هجوم Subdomain مزيف: https://hosnimanagement.com.evil.com/... -> false
    @Test
    fun testAllowedUrl_rejectFakeSubdomain() {
        assertFalse(Net.isAllowedUrl("https://hosnimanagement.com.evil.com/sms_hook.php"))
    }

    // ٤) هجوم Query parameter: https://evil.com/?h=hosnimanagement.com -> false
    @Test
    fun testAllowedUrl_rejectQueryParamHost() {
        assertFalse(Net.isAllowedUrl("https://evil.com/?h=hosnimanagement.com"))
    }

    // ٥) هجوم UserInfo: https://user@evil.com/ -> false
    @Test
    fun testAllowedUrl_rejectUserInfo() {
        assertFalse(Net.isAllowedUrl("https://user@evil.com/"))
        assertFalse(Net.isAllowedUrl("https://user@hosnimanagement.com/"))
    }

    // ٦) روابط غير صالحة أو فارغة -> false
    @Test
    fun testAllowedUrl_invalidSyntax() {
        assertFalse(Net.isAllowedUrl(""))
        assertFalse(Net.isAllowedUrl("not-a-url"))
        assertFalse(Net.isAllowedUrl("ftp://hosnimanagement.com"))
    }

    // ٧) التحقق من صحة رد السيرفر لإعدادات الكلمات: رد سليم
    @Test
    fun testConfigValidation_valid() {
        val validJson = """
            {
                "ok": true,
                "include": ["تحويل لحظي", "مسبقة الدفع"],
                "exclude": ["OTP", "كود"]
            }
        """.trimIndent()
        val res = Net.parseAndValidateConfig(validJson)
        assertNotNull(res)
        assertTrue(res!!.first.contains("تحويل لحظي"))
        assertTrue(res.second.contains("OTP"))
    }

    // ٨) التحقق من صحة رد السيرفر: رفض أكثر من ٥٠ كلمة
    @Test
    fun testConfigValidation_rejectMoreThan50Words() {
        val bigList = (1..51).joinToString(",") { "\"word$it\"" }
        val json = """
            {
                "ok": true,
                "include": [$bigList],
                "exclude": []
            }
        """.trimIndent()
        assertNull(Net.parseAndValidateConfig(json))
    }

    // ٩) التحقق من صحة رد السيرفر: رفض كلمة أطول من ٤٠ حرف
    @Test
    fun testConfigValidation_rejectWordLongerThan40Chars() {
        val longWord = "a".repeat(41)
        val json = """
            {
                "ok": true,
                "include": ["$longWord"],
                "exclude": []
            }
        """.trimIndent()
        assertNull(Net.parseAndValidateConfig(json))
    }

    // ١٠) التحقق من صحة رد السيرفر: رفض ok=false أو مصفوفات ناقصة
    @Test
    fun testConfigValidation_rejectMalformed() {
        assertNull(Net.parseAndValidateConfig("""{"ok": false, "include": [], "exclude": []}"""))
        assertNull(Net.parseAndValidateConfig("""{"ok": true, "include": []}"""))
        assertNull(Net.parseAndValidateConfig("""{"ok": true, "exclude": []}"""))
        assertNull(Net.parseAndValidateConfig("not json"))
    }
}
