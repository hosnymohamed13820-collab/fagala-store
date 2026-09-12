// =====================================================================
//  فحص الجافاسكربت اللي جوّه صفحات PHP
//
//  ليه الملف ده موجود:
//    `run_all.sh` كان بيفحص `site/assets/*.js` و`sw.js` وبس. الجافاسكربت
//    اللي مكتوب جوّه بلوك <script> في صفحة PHP ماكانش بيتفحص خالص.
//
//    يوم ١٢ سبتمبر ٢٠٢٦ ده كلّف صفحة كاملة: `review.php` كان فيها
//    سطر جديد خام جوّه سترنج بعلامة تنصيص مفردة:
//
//        return confirm('رفض عهدة ' + branch + '؟
//        (سطر فاضي)
//        هترجع للكاشير يظبّطها.');
//
//    ده SyntaxError، والـSyntaxError **بيوقّع بلوك <script> كله** مش
//    السطر بس. يعني `openReview()` عمرها ما اتعرّفت، وزرار «مراجعة»
//    مابيعملش حاجة خالص — من غير ولا رسالة خطأ ظاهرة للمستخدم.
//    عدّى من كل الفحوصات ووصل السيرفر.
//
//  إزاي بيشتغل:
//    ١. بيطلّع البلوكات اللي بين سطر <script> وسطر </script> بالظبط.
//    ٢. بيبدّل `<?= ... ?>` بـ`0` — دي قيمة بتتحقن، وبدلها بصفر
//       بتسيب الجافاسكربت سليم نحوياً سواء كانت رقم أو جوّه سترنج.
//    ٣. بلوك لسه فيه `<?php` (شرط أو حلقة بتولّد **بنية** جافاسكربت)
//       **بيتخطى** — مينفعش يتفحص لوحده من غير ما نكدب.
//    ٤. الباقي بيتفحص نحوياً.
//
//  🔴 الفحص ده نحوي بس. مابيمسكش منطق غلط ولا اسم دالة مش موجود.
// =====================================================================
import fs from 'fs';
import vm from 'vm';

let failed = 0, checked = 0, skipped = 0;

for (const path of process.argv.slice(2)) {
    let src;
    try { src = fs.readFileSync(path, 'utf8'); } catch { continue; }

    const lines = src.split(/\r?\n/);
    let inBlock = false, startLine = 0, buf = [], idx = 0;

    for (let i = 0; i < lines.length; i++) {
        const t = lines[i].trim();
        if (!inBlock) {
            if (t === '<script>') { inBlock = true; startLine = i + 2; buf = []; }
            continue;
        }
        if (t === '</script>') {
            inBlock = false;
            idx++;
            const raw = buf.join('\n');

            // القيم المحقونة بتبقى صفر — السترنج والرقم الاتنين بيفضلوا سليمين
            const code = raw.replace(/<\?=[\s\S]*?\?>/g, '0');

            // بنية جافاسكربت متولّدة من PHP — مينفعش تتفحص لوحدها
            if (code.includes('<?')) {
                skipped++;
                continue;
            }

            checked++;
            try {
                // بنلف الكود في دالة عشان `return` في أعلى مستوى ماترميش
                new vm.Script('(function(){' + code + '\n})', { filename: path });
            } catch (e) {
                failed++;
                console.log(`  ❌ ${path} (بلوك ${idx}, بيبدأ سطر ${startLine})`);
                console.log(`     ${e.message}`);
            }
            continue;
        }
        buf.push(lines[i]);
    }

    if (inBlock) {
        failed++;
        console.log(`  ❌ ${path}: بلوك <script> مش مقفول`);
    }
}

console.log(`  ${failed ? '❌' : '✅'} ${checked} بلوك اتفحص · ${skipped} اتخطى (فيه <?php) · ${failed} فشل`);
process.exit(failed ? 1 : 0);
