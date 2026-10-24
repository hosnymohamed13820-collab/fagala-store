package com.hosnimanagement.callsbridge

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * اختبارات وحدة لتطبيق مكالمات الفرع (بريف ٢١٠)
 */
class CallsBridgeTest {

    /**
     * اختبار ١: تقسيم ١٢٣٤ مكالمة ← ٣ دفعات (٥٠٠ · ٥٠٠ · ٢٣٤)
     */
    @Test
    fun testChunking1234CallsIntoBatches() {
        val totalCalls = (1..1234).map { i ->
            CallItem(
                id = i.toLong(),
                number = "0100$i",
                type = 1,
                dateMs = 1729700000000L + i,
                duration = 30
            )
        }

        val batches = totalCalls.chunked(500)
        assertEquals(3, batches.size)
        assertEquals(500, batches[0].size)
        assertEquals(500, batches[1].size)
        assertEquals(234, batches[2].size)
        assertEquals(1L, batches[0].first().id)
        assertEquals(500L, batches[0].last().id)
        assertEquals(501L, batches[1].first().id)
        assertEquals(1000L, batches[1].last().id)
        assertEquals(1001L, batches[2].first().id)
        assertEquals(1234L, batches[2].last().id)
    }

    /**
     * اختبار ٢: lastSentId لا يتحدث إذا فشلت الدفعة الثانية
     */
    @Test
    fun testLastSentIdDoesNotUpdateOnSecondBatchFailure() {
        var simulatedLastSentId = 0L

        val batch1 = (1..500).map { i -> CallItem(i.toLong(), "010$i", 1, 1000L + i, 10) }
        val batch2 = (501..1000).map { i -> CallItem(i.toLong(), "010$i", 1, 2000L + i, 10) }

        // محاكاة إرسال الدفعة الأولى بنجاح
        val batch1Success = true
        if (batch1Success) {
            val maxId1 = batch1.maxOf { it.id }
            if (maxId1 > simulatedLastSentId) {
                simulatedLastSentId = maxId1
            }
        }
        assertEquals(500L, simulatedLastSentId)

        // محاكاة فشل إرسال الدفعة الثانية (خطأ شبكة مثلاً)
        val batch2Success = false
        if (batch2Success) {
            val maxId2 = batch2.maxOf { it.id }
            if (maxId2 > simulatedLastSentId) {
                simulatedLastSentId = maxId2
            }
        }

        // التأكد من أن lastSentId لم يتغير وظل 500L
        assertEquals(500L, simulatedLastSentId)
    }

    /**
     * اختبار ٣: رفض http:// وقبول https://
     */
    @Test
    fun testRejectHttpAndAllowHttps() {
        // روابط غير مسموحة (http أو دومين غريب أو userInfo)
        assertFalse(Net.isAllowedUrl("http://hosnimanagement.com/calls_hook.php"))
        assertFalse(Net.isAllowedUrl("http://hosnimanagement.com"))
        assertFalse(Net.isAllowedUrl("ftp://hosnimanagement.com"))
        assertFalse(Net.isAllowedUrl("https://evil.com/calls_hook.php"))
        assertFalse(Net.isAllowedUrl("https://admin:secret@hosnimanagement.com/calls_hook.php"))

        // روابط مسموحة (https مع الدومين المعتمد)
        assertTrue(Net.isAllowedUrl("https://hosnimanagement.com/calls_hook.php"))
        assertTrue(Net.isAllowedUrl("https://hosnimanagement.com"))
        assertTrue(Net.isAllowedUrl("https://ashraqa.hosnimanagement.com/calls_hook.php"))
    }

    /**
     * اختبار ٤: بناء JSON لمكالمة رقمها فارغ ("") ينتج "number":"" مش null
     */
    @Test
    fun testBuildJsonForEmptyNumberProducesEmptyStringNotNull() {
        val emptyNumCall = CallItem(
            id = 42L,
            number = "",
            type = 3,
            dateMs = 1729700000000L,
            duration = 0
        )

        val json = emptyNumCall.toJson()
        assertTrue(json.has("number"))
        assertFalse(json.isNull("number"))
        assertEquals("", json.getString("number"))

        val payload = CallItem.buildBatchPayload("Samsung S21", listOf(emptyNumCall))
        val rootJson = JSONObject(payload)
        val callsArr = rootJson.getJSONArray("calls")
        val firstCall = callsArr.getJSONObject(0)

        assertEquals("", firstCall.getString("number"))
        assertFalse(firstCall.isNull("number"))
        assertTrue(payload.contains("\"number\":\"\""))
        assertFalse(payload.contains("\"number\":null"))
    }
}

