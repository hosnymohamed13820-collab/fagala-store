package com.hosnimanagement.smsbridge

/**
 * فلتر رسايل SMS — دالة واحدة تحدد إذا كانت الرسالة تتبعت للموقع ولا لأ.
 *
 * 🔴 القواعد:
 *  ١) لو فيه أي كلمة من exclude في الراسل أو النص ← false دايماً (أكواد التحقق).
 *  ٢) غير كده: لو فيه أي كلمة من include في الراسل أو النص ← true.
 *  ٣) المقارنة بتشيل التشكيل ([ً-ْ]) وغير حساسة لحالة الحروف (case-insensitive).
 */
object SmsFilter {

    val DEFAULT_INCLUDE = listOf(
        "BanK-AlAhly",
        "تحويل لحظي",
        "مسبقة الدفع",
        "المدفوعة مقدما",
        "19623",
        "InstaPay",
        "انستاباي"
    )

    val DEFAULT_EXCLUDE = listOf(
        "OTP",
        "كود",
        "رمز",
        "كلمة المرور",
        "password",
        "verification",
        "لا تشارك"
    )

    // تشكيل الحركات والتنوين في اللغة العربية (من ً لـ ْ)
    private val TASHKEEL_REGEX = Regex("[\\u064B-\\u0652]")

    fun stripTashkeel(text: String): String {
        return text.replace(TASHKEEL_REGEX, "")
    }

    private fun normalize(text: String): String {
        return stripTashkeel(text).lowercase()
    }

    fun shouldSend(
        sender: String,
        body: String,
        include: List<String> = DEFAULT_INCLUDE,
        exclude: List<String> = DEFAULT_EXCLUDE
    ): Boolean {
        val normSender = normalize(sender)
        val normBody   = normalize(body)
        val fullText   = "$normSender $normBody"

        // ١) فحص كلمات الاستبعاد أولاً — لو ظهرت أي كلمة منهم نرفض فوراً
        for (ex in exclude) {
            val normEx = normalize(ex.trim())
            if (normEx.isNotEmpty() && fullText.contains(normEx)) {
                return false
            }
        }

        // ٢) فحص كلمات التضمين — لو فيه أي كلمة مقبولة نوافق
        for (inc in include) {
            val normInc = normalize(inc.trim())
            if (normInc.isNotEmpty() && fullText.contains(normInc)) {
                return true
            }
        }

        return false
    }
}
