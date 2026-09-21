<?php
/**
 * book_receipt_lib.php — إيصال حجز الكتب وطابور طباعته
 * نظام الفجالة ستور المالي
 *
 * الموقع مابيطبعش بنفسه. بيبني «تخطيط» الإيصال كـJSON ويرميه في
 * print_queue، ووكيل PowerShell على كمبيوتر الفرع بيسحبه ويرسمه على
 * الطابعة الحرارية.
 *
 * 🔴 ليه JSON وسطور مرقّمة مش HTML:
 *    الطابعات الصينية (XP-80C / XP-90) عربيتها بتطلع مقطّعة لو
 *    بعتنالها نص ESC/POS خام. فالوكيل بيرسم بخط الويندوز، والويندوز
 *    هو اللي بيوصّل الحروف ويظبط الاتجاه. والوكيل مابيعرفش حاجة عن
 *    الحجوزات — بيرسم اللي جايله بالترتيب وخلاص.
 *
 * 🔴 يعني: أي تعديل في **شكل** الإيصال بعدين = تعديل في الملف ده بس،
 *    مش تنزيل نسخة جديدة من الوكيل على ٦ أجهزة.
 *
 * 🔴 ممنوع أي HTML هنا. لا ltr_out() ولا e() ولا <bdi>. نص خام بس.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/book_lib.php';

/**
 * تخطيط الإيصال كـJSON جاهز للرسم على الوكيل
 *
 * @param array{res:array,lines:array} $data ناتج book_reservation_get()
 * @return array
 */
function book_receipt_layout(array $data): array
{
    $r     = $data['res'] ?? [];
    $lines = $data['lines'] ?? [];

    $store  = (string) setting('store_name', 'مكتبات الفجالة ستور');
    $branch = trim((string)($r['branch_name'] ?? ''));
    $phone  = trim((string)($r['branch_phone'] ?? ''));
    $by     = trim((string)($r['reserved_by'] ?? '')) ?: trim((string)($r['creator'] ?? ''));
    $ts     = !empty($r['created_at']) ? strtotime((string)$r['created_at']) : time();
    $when   = date('Y-m-d', $ts);
    $time   = date('h:i', $ts) . (date('A', $ts) === 'AM' ? ' ص' : ' م');

    $nKinds = count($lines);
    $nCopies = 0;
    foreach ($lines as $l) {
        $nCopies += (float)($l['qty'] ?? 0);
    }

    // 🔴 العدد في العربي مش بيتصرّف زي الإنجليزي. «1 أصناف» و«2 نسخة»
    //    غلط، والورقة دي بتتسلّم للعميل في إيده. القاعدة:
    //    ١ = مفرد · ٢ = مثنى · ٣–١٠ = جمع قلة · ١١+ = مفرد بعد الرقم.
    $arCount = static function (float $n, string $one, string $two, string $few, string $many): string {
        $i = (int) round($n);
        if (abs($n - $i) > 0.001) {          // كمية كسرية — نسيبها رقم
            return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') . ' ' . $many;
        }
        if ($i === 1) return $one;
        if ($i === 2) return $two;
        if ($i >= 3 && $i <= 10) return $i . ' ' . $few;
        return $i . ' ' . $many;
    };

    $summary = $arCount($nKinds,  'صنف واحد',   'صنفين',   'أصناف', 'صنف')
             . ' · '
             . $arCount($nCopies, 'نسخة واحدة', 'نسختين',  'نسخ',   'نسخة');

    // مساعد داخلي لإضافة kv مع تجاهل الفاضي أو '—'
    $kv = static function (array &$out, string $k, $v, string $dir = 'rtl'): void {
        $v = trim((string) $v);
        if ($v === '' || $v === '—') return;   // سطر فاضي مايتطبعش
        $item = ['t' => 'kv', 'k' => $k, 'v' => $v];
        if ($dir === 'ltr') {
            $item['dir'] = 'ltr';
        }
        $out[] = $item;
    };

    // ختم الحجز إذا لم يكن مفتوحاً (ملغي / اتسلّم)
    $stamp = null;
    if (!empty($r['status']) && $r['status'] !== 'open') {
        $stamp = ['t' => 'stamp', 'v' => $r['status'] === 'cancelled' ? 'ملغي' : 'اتسلّم'];
    }

    // ==========================================
    // النسخة الأولى — العميل
    // ==========================================
    $c1 = [];
    if ($stamp) {
        $c1[] = $stamp;
    }
    $c1[] = ['t' => 'h1', 'v' => $store];
    $c1[] = ['t' => 'h2', 'v' => 'إيصال حجز كتب'];
    $c1[] = ['t' => 'rule', 's' => 'double'];

    $kv($c1, 'حجز رقم', (string)($r['id'] ?? ''), 'ltr');
    $kv($c1, 'التاريخ', $when, 'ltr');
    $kv($c1, 'الوقت', $time, 'ltr');
    $c1[] = ['t' => 'rule', 's' => 'solid'];

    $kv($c1, 'الفرع', $branch);
    $kv($c1, 'تليفون', $phone, 'ltr');
    $kv($c1, 'العميل', $r['customer_name'] ?? '');
    $kv($c1, 'الحاجز', $by);
    $c1[] = ['t' => 'rule', 's' => 'solid'];

    $c1[] = ['t' => 'row', 'r' => 'الكتاب', 'l' => 'الكمية'];
    $c1[] = ['t' => 'rule', 's' => 'dash'];

    foreach ($lines as $l) {
        $qStr = rtrim(rtrim(number_format((float)($l['qty'] ?? 0), 2, '.', ''), '0'), '.');
        $c1[] = ['t' => 'row', 'r' => trim((string)($l['item_name'] ?? '')), 'l' => $qStr];
    }
    $c1[] = ['t' => 'rule', 's' => 'solid'];

    $c1[] = ['t' => 'line', 'v' => $summary];
    $c1[] = ['t' => 'rule', 's' => 'double'];

    $depositFormatted = number_format((float)($r['deposit'] ?? 0), 2);
    $c1[] = ['t' => 'big', 'k' => 'المقدم المدفوع', 'v' => $depositFormatted, 'dir' => 'ltr'];
    $c1[] = ['t' => 'rule', 's' => 'double'];

    $c1[] = ['t' => 'note', 'v' => 'احتفظ بالإيصال لاستلام الكتب'];

    // سطر سياسة المقدم (مخفي إذا كان فارغاً)
    $policy = trim((string) setting('reserve_policy_line', ''));
    if ($policy !== '') {
        $c1[] = ['t' => 'note', 'v' => $policy];
    }

    $c1[] = ['t' => 'gap', 'n' => 2];

    // ==========================================
    // النسخة الثانية — الفرع
    // ==========================================
    $c2 = [];
    if ($stamp) {
        $c2[] = $stamp;
    }
    $c2[] = ['t' => 'h2', 'v' => 'إيصال حجز — نسخة الفرع'];
    $c2[] = ['t' => 'rule', 's' => 'double'];

    $kv($c2, 'حجز رقم', (string)($r['id'] ?? ''), 'ltr');
    $kv($c2, 'التاريخ', $when, 'ltr');
    $kv($c2, 'الوقت', $time, 'ltr');
    $c2[] = ['t' => 'rule', 's' => 'solid'];

    $kv($c2, 'العميل', $r['customer_name'] ?? '');
    $kv($c2, 'موبايل', $r['customer_phone'] ?? '', 'ltr');
    $kv($c2, 'الحاجز', $by);
    $c2[] = ['t' => 'rule', 's' => 'solid'];

    $c2[] = ['t' => 'row', 'r' => 'الكتاب', 'l' => 'الكمية'];
    $c2[] = ['t' => 'rule', 's' => 'dash'];

    foreach ($lines as $l) {
        $qStr = rtrim(rtrim(number_format((float)($l['qty'] ?? 0), 2, '.', ''), '0'), '.');
        $c2[] = ['t' => 'row', 'r' => trim((string)($l['item_name'] ?? '')), 'l' => $qStr];
    }
    $c2[] = ['t' => 'rule', 's' => 'solid'];

    $c2[] = ['t' => 'line', 'v' => $summary];
    $kv($c2, 'المقدم', $depositFormatted, 'ltr');
    $c2[] = ['t' => 'gap', 'n' => 2];

    return [
        'v'        => 1,
        'width_mm' => 80,
        'copies'   => [
            ['blocks' => $c1],
            ['blocks' => $c2],
        ],
    ];
}

/**
 * إدراج مهمة طباعة في الطابور
 *
 * @param int $resId رقم الحجز
 * @param int|null $userId المستخدم المنشئ
 * @return bool
 */
function book_receipt_enqueue(int $resId, ?int $userId = null): bool
{
    $data = book_reservation_get($resId);
    if (!$data) {
        return false;
    }

    $layout = book_receipt_layout($data);
    $branchId = !empty($data['res']['branch_id']) ? (int)$data['res']['branch_id'] : null;
    $payload = json_encode($layout, JSON_UNESCAPED_UNICODE);

    $st = db()->prepare(
        'INSERT INTO print_queue (branch_id, kind, ref_id, payload, created_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $st->execute([$branchId, 'book_reserve', $resId, $payload, $userId]);
    return true;
}

/**
 * جلب حالة آخر مهمة طباعة للحجز
 *
 * @param int $resId رقم الحجز
 * @return array{state:string,at:?string,attempts:int}
 */
function book_receipt_status(int $resId): array
{
    if ($resId <= 0) {
        return ['state' => 'none', 'at' => null, 'attempts' => 0];
    }
    try {
        $st = db()->prepare(
            "SELECT status, attempts, created_at, printed_at
             FROM print_queue
             WHERE kind = 'book_reserve' AND ref_id = ?
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$resId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['state' => 'none', 'at' => null, 'attempts' => 0];
        }

        $rawStatus = (string) $row['status'];
        $attempts  = (int) $row['attempts'];
        $createdTs = !empty($row['created_at']) ? strtotime((string)$row['created_at']) : 0;
        $printedAt = !empty($row['printed_at']) ? (string)$row['printed_at'] : null;

        if (in_array($rawStatus, ['queued', 'claimed'], true) && (time() - $createdTs > 120)) {
            $state = 'stuck';
        } else {
            $state = $rawStatus;
        }

        return [
            'state'    => $state,
            'at'       => $printedAt ?: (!empty($row['created_at']) ? (string)$row['created_at'] : null),
            'attempts' => $attempts,
        ];
    } catch (Throwable $e) {
        return ['state' => 'none', 'at' => null, 'attempts' => 0];
    }
}

