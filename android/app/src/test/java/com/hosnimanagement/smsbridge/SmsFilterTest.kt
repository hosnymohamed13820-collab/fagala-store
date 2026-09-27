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

    // الرسايل الحقيقية (بريف ٧٨)
    private val realOutgoingMsg = "تم تنفيذ تحويل لحظي من بطاقتكم مسبقة الدفع بمبلغ 4995.00 جم إلى Mohamed A**** A***** S**** رقم مرجعي 162457350143 يوم 07-20 الساعة 20:12 للمزيد اتصل بـ 19623"
    private val realAtmMsg = "تم خصم 2500.00EGP من بطاقة المدفوعة مقدماً رقم 0150 عند NBE ATM710 يوم 22/05 الساعه 13:54 المتاح 0.00 جم للمزيد إتصل ب 19623"
    private val realIncomingMsg = "تم إضافة تحويل لحظي لبطاقتكم مسبقة الدفع بمبلغ 2500.00 جم من محمد حسني عبدالرحمن رشوان رقم مرجعي 431527628607 يوم 05-21 الساعة 18:11 للمزيد اتصل بـ 19623"

    // ٦) الرسايل التلاتة الحقيقية من BanK-AlAhly بقوايم السيرفر الافتراضية
    @Test
    fun testRealMessages_withServerDefaultLists() {
        val sender = "BanK-AlAhly"
        val serverInclude = SmsFilter.DEFAULT_INCLUDE
        val serverExclude = emptyList<String>()

        assertTrue(SmsFilter.shouldSend(sender, realOutgoingMsg, serverInclude, serverExclude))
        assertTrue(SmsFilter.shouldSend(sender, realAtmMsg, serverInclude, serverExclude))
        assertTrue(SmsFilter.shouldSend(sender, realIncomingMsg, serverInclude, serverExclude))
    }

    // ٧) نفس [داخل] من راسل BanK-AlAhly2 / NBE / +201000000000
    @Test
    fun testDifferentSenders_shouldBeRejected() {
        assertFalse(SmsFilter.shouldSend("BanK-AlAhly2", realIncomingMsg))
        assertFalse(SmsFilter.shouldSend("NBE", realIncomingMsg))
        assertFalse(SmsFilter.shouldSend("+201000000000", realIncomingMsg))
    }

    // ٨) ("BanK-AlAhly", "كود التحقق 4821 للمزيد اتصل ب 19623")
    @Test
    fun testBankOtpMessage_shouldBeRejected() {
        val sender = "BanK-AlAhly"
        val body = "كود التحقق 4821 للمزيد اتصل ب 19623"
        assertFalse(SmsFilter.shouldSend(sender, body))
    }

    // ٩) هجوم: نفس رسالة الكود بـ serverInclude = ["a","كود"] و serverExclude = []
    @Test
    fun testAttack_otpWithServerBypassAttempt() {
        val sender = "BanK-AlAhly"
        val body = "كود التحقق 4821 للمزيد اتصل ب 19623"
        val serverInclude = listOf("a", "كود")
        val serverExclude = emptyList<String>()
        assertFalse(SmsFilter.shouldSend(sender, body, serverInclude, serverExclude))
    }

    // ١٠) هجوم: رسالة شخصية ("Mom","تعالى بدري") بـ serverInclude = ["ت"] و serverExclude = []
    @Test
    fun testAttack_personalMessageWithServerBypassAttempt() {
        val sender = "Mom"
        val body = "تعالى بدري"
        val serverInclude = listOf("ت")
        val serverExclude = emptyList<String>()
        assertFalse(SmsFilter.shouldSend(sender, body, serverInclude, serverExclude))
    }

    // ١١) [داخل] بـ serverExclude = ["تحويل لحظي"] (السيرفر بيضيّق)
    @Test
    fun testServerNarrowing_excludeIncomingKeyword() {
        val sender = "BanK-AlAhly"
        val serverExclude = listOf("تحويل لحظي")
        assertFalse(SmsFilter.shouldSend(sender, realIncomingMsg, emptyList(), serverExclude))
    }

    // ١٢) [داخل] بـ serverInclude = ["مسبقة الدفع"]
    @Test
    fun testServerNarrowing_includeSpecificKeyword() {
        val sender = "BanK-AlAhly"
        val serverInclude = listOf("مسبقة الدفع")
        assertTrue(SmsFilter.shouldSend(sender, realIncomingMsg, serverInclude, emptyList()))
    }

    // ١٣) ("bank-alahly ", …[داخل]) (حروف صغيرة + مسافة)
    @Test
    fun testSenderCaseAndWhitespace() {
        val sender = "bank-alahly "
        assertTrue(SmsFilter.shouldSend(sender, realIncomingMsg))
    }

    // محل اسمه فيه SHOPPING — لازم يعدّي (اتأكد إن «PIN» مش في الممنوع)
    @Test
    fun testCardAtShoppingMerchant_shouldPass() {
        val body = "تم خصم 350.00EGP من بطاقة المدفوعة مقدماً رقم 0150 عند CITY SHOPPING يوم 22/05 الساعه 13:54 المتاح 0.00 جم للمزيد إتصل ب 19623"
        assertTrue(SmsFilter.shouldSend("BanK-AlAhly", body))
    }
}
