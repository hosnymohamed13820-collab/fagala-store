package com.hosnimanagement.smsbridge

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * اختبارات وحدة لفحص فلتر الرسائل حسب الحالات المطلوبة في بريف ٧٦.
 */
class SmsFilterTest {

    // ١) رسالة حقيقية ١: تحويل لحظي من بطاقة مسبقة الدفع
    @Test
    fun testRealMessage1_outgoingTransfer() {
        val sender = "BanK-AlAhly"
        val body = "تم تنفيذ تحويل لحظي من بطاقتكم مسبقة الدفع بمبلغ 4995.00 جم إلى Mohamed A**** A***** S**** رقم مرجعي 162457350143 يوم 07-20 الساعة 20:12 للمزيد اتصل بـ 19623"
        assertTrue(SmsFilter.shouldSend(sender, body))
    }

    // ٢) رسالة حقيقية ٢: خصم من بطاقة مدفوعة مقدماً (مع تنوين الفتح على مقدماً)
    @Test
    fun testRealMessage2_atmWithdrawalWithTashkeel() {
        val sender = "BanK-AlAhly"
        val body = "تم خصم 2500.00EGP من بطاقة المدفوعة مقدماً رقم 0150 عند NBE ATM710 يوم 22/05 الساعه 13:54 المتاح 0.00 جم للمزيد إتصل ب 19623"
        assertTrue(SmsFilter.shouldSend(sender, body))
    }

    // ٣) رسالة حقيقية ٣: إضافة تحويل لحظي للبطاقة
    @Test
    fun testRealMessage3_incomingTransfer() {
        val sender = "BanK-AlAhly"
        val body = "تم إضافة تحويل لحظي لبطاقتكم مسبقة الدفع بمبلغ 2500.00 جم من محمد حسني عبدالرحمن رشوان رقم مرجعي 431527628607 يوم 05-21 الساعة 18:11 للمزيد اتصل بـ 19623"
        assertTrue(SmsFilter.shouldSend(sender, body))
    }

    // ٤) رسالة استبعاد: كود تحقق يحتوي على كلمة كود و19623 والراسل من البنك
    @Test
    fun testOtpMessage_shouldBeExcluded() {
        val sender = "BanK-AlAhly"
        val body = "كود التحقق 1234 للمزيد اتصل ب 19623"
        assertFalse(SmsFilter.shouldSend(sender, body))
    }

    // ٥) رسالة غير مطابقة: رسالة من شركة اتصالات عادية لا تحتوي على أي كلمة تخص إنستاباي
    @Test
    fun testUnrelatedMessage_shouldBeIgnored() {
        val sender = "Vodafone"
        val body = "رصيدك 900"
        assertFalse(SmsFilter.shouldSend(sender, body))
    }
}
