<?php
/**
 * sync_tokens.php — توكن سري لكل وكيل مزامنة (إشراقة)
 * =====================================================================
 *  لحد ٢٢ سبتمبر ٢٠٢٦ كان فيه توكن واحد لكل الوكلاء، و`sync_branch.php`
 *  كان بياخد اسم الفرع من الطلب ويصدّقه — جهاز أي فرع كان يقدر يبعت
 *  أرصدة وفواتير باسم فرع تاني. دلوقتي التوكن لازم يطابق اسم الوكيل.
 *
 *  كل وكيل = توكن واحد، هو هو للداتا وللنبضة. ونفس القيمة بالظبط
 *  في ملف الـ.ps1 بتاعه ($SyncToken).
 *
 *  🔴 الملف ده **مختلف** بين site/ وashraqa/ عن قصد — ممنوع cp.
 * =====================================================================
 */
declare(strict_types=1);

// الملف ده بيتعمله require بس — مالوش لازمة يتفتح من المتصفح
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

const SYNC_AGENT_TOKENS = [
    // agent_ashraqa_web.ps1 — بيلعب دور «الإدارة» على موقع إشراقة.
    // 🔴 نفس HQ_SYNC_TOKEN في ashraqa/sync_hq.php — لو اتغيّر هناك غيّره هنا.
    'hq' => 'ashweb_15c488c25181d22bec038b314ba6a679',
];

// مفيش فرع بيبعت على موقع إشراقة — sync_branch.php هناك بيرفض أي طلب
const SYNC_BRANCH_AGENTS = [];

// 🔴 انتقالي — التوكن القديم المشترك. بيتقبل لأي وكيل مسموح له بالملف،
//    لحد ما كل الأجهزة تتحدّث. المرحلة التانية = امسح السطر ده وبس.
const SYNC_LEGACY_TOKEN = 'hq_7Kp9mXq2Rv5Nw8Lt3Bz6Yd4Hs1Fj0Gc';

/**
 * التوكن ده مسموح له يبعت باسم الوكيل ده على النقطة دي؟
 * $allowed = الوكلاء المسموحين على الملف اللي بينده (مش كل الخريطة).
 */
function synctok_ok(string $agent, string $token, array $allowed): bool
{
    if ($token === '' || !in_array($agent, $allowed, true)) return false;
    $want = SYNC_AGENT_TOKENS[$agent] ?? '';
    if ($want !== '' && hash_equals($want, $token)) return true;
    return defined('SYNC_LEGACY_TOKEN') && SYNC_LEGACY_TOKEN !== ''
        && hash_equals(SYNC_LEGACY_TOKEN, $token);
}

