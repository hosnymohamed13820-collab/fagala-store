package com.hosnimanagement.smsbridge

/**
 * فلتر رسايل SMS — دالة واحدة تحدد إذا كانت الرسالة تتبعت للموقع ولا لأ.
 *
 * 🔴 المبدأ: الموقع يقدر يضيّق بس — عمره ما يوسّع.
 * القواعد الأمنية الصارمة محفورة في كود التطبيق، والسيرفر لا يستطيع تجاوزها أبداً.
 */
object SmsFilter {

    // 🔴 ثابتة في الكود — السيرفر مايقدرش يغيّرها
    val ALLOWED_SENDERS = setOf("bank-alahly")

    val HARD_EXCLUDE = listOf(
        "OTP",
        "كود",
        "رمز",
        "كلمة المرور",
        "كلمه المرور",
        "كلمة السر",
        "password",
        "passcode",
        "verification",
        "verify",
        "لا تشارك",
        "لاتشارك",
        "تفعيل",
        "الرقم السري"
        // «PIN» اتشالت عن قصد: المقارنة substring، فكانت هتمنع خصم كارت عند محل اسمه فيه SHOPPING
    )

    val DEFAULT_INCLUDE = listOf(
        "تحويل لحظي",
        "مسبقة الدفع",
        "المدفوعة مقدما",
        "19623",
        "InstaPay",
        "انستاباي"
    )

    val DEFAULT_EXCLUDE = HARD_EXCLUDE

    // تشكيل الحركات والتنوين في اللغة العربية (من ً لـ ْ)
    private val TASHKEEL_REGEX = Regex("[\\u064B-\\u0652]")

    fun stripTashkeel(text: String): String {
        return text.replace(TASHKEEL_REGEX, "")
    }

    private fun normalize(text: String): String {
        return stripTashkeel(text).lowercase()
    }

    /**
     * فحص الرسالة بالترتيب الصارم:
     * ١) الراسل بعد trim().lowercase() لازم يطابق ALLOWED_SENDERS بالظبط.
     * ٢) النص لا يحتوي على أي كلمة من HARD_EXCLUDE أو serverExclude (السيرفر بيزوّد على الممنوع).
     * ٣) النص يحتوي على أي كلمة من DEFAULT_INCLUDE و (لو serverInclude مش فاضية وفيها كلمات طولها >= ٣) يحتوي على كلمة منها أيضاً.
     * ٤) غير كده نرفض الرسالة.
     */
    fun shouldSend(
        sender: String,
        body: String,
        serverInclude: List<String> = emptyList(),
        serverExclude: List<String> = emptyList()
    ): Boolean {
        // ١) فحص الراسل بالظبط
        val normSender = sender.trim().lowercase()
        if (normSender !in ALLOWED_SENDERS) {
            return false
        }

        val normBody = normalize(body)

        // ٢) فحص الاستبعاد الثابت واستبعاد السيرفر الإضافي
        for (ex in HARD_EXCLUDE) {
            val normEx = normalize(ex.trim())
            if (normEx.isNotEmpty() && normBody.contains(normEx)) {
                return false
            }
        }
        for (ex in serverExclude) {
            val normEx = normalize(ex.trim())
            if (normEx.isNotEmpty() && normBody.contains(normEx)) {
                return false
            }
        }

        // ٣) فحص التضمين الافتراضي والتضييق بكلمات السيرفر
        var hasDefaultInclude = false
        for (inc in DEFAULT_INCLUDE) {
            val normInc = normalize(inc.trim())
            if (normInc.isNotEmpty() && normBody.contains(normInc)) {
                hasDefaultInclude = true
                break
            }
        }
        if (!hasDefaultInclude) {
            return false
        }

        // كلمات السيرفر بتضيّق فقط: تُهمل الكلمات التي يقل طولها عن ٣ أحرف
        val validServerInclude = serverInclude.map { it.trim() }.filter { it.length >= 3 }
        if (validServerInclude.isNotEmpty()) {
            var hasServerInclude = false
            for (inc in validServerInclude) {
                val normInc = normalize(inc)
                if (normInc.isNotEmpty() && normBody.contains(normInc)) {
                    hasServerInclude = true
                    break
                }
            }
            if (!hasServerInclude) {
                return false
            }
        }

        return true
    }
}
