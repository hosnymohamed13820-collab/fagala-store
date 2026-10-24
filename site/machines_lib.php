<?php
/**
 * منطق عدادات المكن: القراية الصباحية بداية اليوم، والقراية اللي بعدها نهايته.
 */
declare(strict_types=1);

function mc_fields(string $kind): array {
    return $kind === 'color'
        ? ['black_large'=>'أسود كبير 112', 'black_small'=>'أسود صغير 113',
           'color_large'=>'ألوان كبير 122', 'color_small'=>'ألوان صغير 123']
        : ['bw_total'=>'الإجمالي'];
}

function mc_branches(PDO $pdo, ?int $only = null): array {
    $sql = 'SELECT id, name, sto_id FROM branches WHERE is_active = 1';
    $params = [];
    if ($only !== null) { $sql .= ' AND id = ?'; $params[] = $only; }
    $sql .= ' ORDER BY name';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function mc_machines(PDO $pdo, array $branchIds, bool $activeOnly = false): array {
    if (!$branchIds) return [];
    $marks = implode(',', array_fill(0, count($branchIds), '?'));
    $sql = "SELECT m.id, m.branch_id, m.name, m.kind, m.is_active, m.sort_order,
                   b.name AS branch_name
            FROM machines m JOIN branches b ON b.id = m.branch_id
            WHERE m.branch_id IN ($marks) AND m.kind IN ('bw','color')";
    if ($activeOnly) $sql .= ' AND m.is_active = 1';
    $sql .= ' ORDER BY b.name, m.sort_order, m.id';
    $st = $pdo->prepare($sql);
    $st->execute($branchIds);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** كل القراءات باستعلام واحد؛ التواريخ الأقدم مهمة لو الفترة بدأت وسط فجوة. */
function mc_readings(PDO $pdo, array $machineIds, ?string $until = null): array {
    if (!$machineIds) return [];
    $marks = implode(',', array_fill(0, count($machineIds), '?'));
    $sql = "SELECT machine_id, reading_date, bw_total, black_small, black_large,
                   color_small, color_large, notes, updated_at
            FROM machine_readings WHERE machine_id IN ($marks)";
    $params = $machineIds;
    if ($until !== null) { $sql .= ' AND reading_date <= ?'; $params[] = $until; }
    $sql .= ' ORDER BY reading_date, machine_id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st as $r) $out[(int)$r['machine_id']][$r['reading_date']] = $r;
    return $out;
}

function mc_save_reading(PDO $pdo, int $machineId, int $branchId, string $day,
                         string $field, ?int $value, int $userId): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)
        || DateTime::createFromFormat('!Y-m-d', $day)?->format('Y-m-d') !== $day
        || $day > date('Y-m-d')) throw new RuntimeException('تاريخ القراية غير صحيح.');
    $st = $pdo->prepare('SELECT kind FROM machines WHERE id = ? AND branch_id = ? AND is_active = 1');
    $st->execute([$machineId, $branchId]);
    $kind = $st->fetchColumn();
    if ($kind === false || !array_key_exists($field, mc_fields((string)$kind))) {
        throw new RuntimeException('المكنة أو الخانة مش متاحة لفرعك.');
    }
    if ($value !== null && $value < 0) throw new RuntimeException('القراية لازم تكون صفر أو أكبر.');
    // اسم العمود من القائمة الثابتة فوق، ومش من نص الطلب مباشرة.
    $st = $pdo->prepare("INSERT INTO machine_readings (machine_id, reading_date, $field, created_by)
        VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE $field = VALUES($field)");
    $st->execute([$machineId, $day, $value, $userId]);
    $st = $pdo->prepare("SELECT $field FROM machine_readings
        WHERE machine_id = ? AND reading_date < ? AND $field IS NOT NULL
        ORDER BY reading_date DESC LIMIT 1");
    $st->execute([$machineId, $day]);
    $previous = $st->fetchColumn();
    $backwards = $value !== null && $previous !== false && $value < (int)$previous;
    return ['value'=>$value, 'previous'=>$previous === false ? null : (int)$previous,
            'backwards'=>$backwards];
}

function mc_add_machine(PDO $pdo, int $branchId, string $name, string $kind): int {
    $name = trim($name);
    if ($branchId <= 0 || $name === '' || mb_strlen($name) > 120
        || !in_array($kind, ['bw','color'], true)) throw new RuntimeException('راجع اسم المكنة ونوعها وفرعها.');
    $st = $pdo->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1');
    $st->execute([$branchId]);
    if (!$st->fetchColumn()) throw new RuntimeException('الفرع مش متاح.');
    $st = $pdo->prepare('INSERT INTO machines (branch_id, name, kind) VALUES (?, ?, ?)');
    $st->execute([$branchId, $name, $kind]);
    return (int)$pdo->lastInsertId();
}

function mc_toggle_machine(PDO $pdo, int $machineId, int $branchId, bool $active): void {
    $st = $pdo->prepare('UPDATE machines SET is_active = ? WHERE id = ? AND branch_id = ?');
    $st->execute([$active ? 1 : 0, $machineId, $branchId]);
    if ($st->rowCount() < 1) {
        $check = $pdo->prepare('SELECT id FROM machines WHERE id = ? AND branch_id = ?');
        $check->execute([$machineId, $branchId]);
        if (!$check->fetchColumn()) throw new RuntimeException('المكنة مش في الفرع ده.');
    }
}

/** منطق مردود التحليل نفسه في analysis_lib.php؛ بقية أنواع المستندات تدخل مبيعات. */
function mc_return_types(): array {
    $types = array_values(array_filter(array_map('intval',
        explode(',', (string)setting('analysis_sales_return_types', '7,17'))
    ), static fn($x) => $x > 0));
    return $types;
}

/** خريطة الوكيل للمخزن من نفس المصدر المستخدم في analysis_lib.php. */
function mc_agent_store_map(PDO $pdo): array {
    $agentStore = [];
    $map = $pdo->query('SELECT agent_key, sto_id FROM hq_branch_balances GROUP BY agent_key, sto_id');
    foreach ($map as $r) $agentStore[trim((string)$r['agent_key'])] = (int)$r['sto_id'];
    return $agentStore;
}

/** استعلام مبيعات مجمّع واحد؛ الأكواد المعروفة تتجمع باللون والمجهولة بالكود. */
function mc_sales(PDO $pdo, array $branches, string $from, string $to): array {
    $byStore = array_column(array_filter($branches, static fn($b) => $b['sto_id'] !== null), 'id', 'sto_id');
    $agentStore = mc_agent_store_map($pdo);
    $salesReady = count($agentStore)>0 && (bool)$pdo->query('SELECT 1 FROM br_invoices LIMIT 1')->fetchColumn();
    $returns = implode(',', mc_return_types()) ?: '-1';
    $st = $pdo->prepare("SELECT h.agent_key, DATE(h.invoice_date) AS sale_day, sc.color,
            CASE WHEN sc.item_code IS NULL THEN l.item_code ELSE NULL END AS unknown_code,
            MAX(l.item_name) AS item_name,
            SUM(CASE WHEN h.doc_type IN ($returns) THEN -l.quantity ELSE l.quantity END) AS net_qty,
            SUM(CASE WHEN h.doc_type IN ($returns) THEN -l.quantity ELSE l.quantity END
                * COALESCE(sc.clicks_per_qty, 0)) AS clicks
        FROM br_invoice_lines l
        JOIN br_invoices h ON h.agent_key = l.agent_key AND h.sth_id = l.sth_id AND h.suffix = l.suffix
        LEFT JOIN machine_service_codes sc ON sc.item_code = l.item_code COLLATE utf8mb4_unicode_ci
        WHERE h.invoice_date >= ? AND h.invoice_date < DATE_ADD(?, INTERVAL 1 DAY)
          AND (sc.item_code IS NOT NULL OR l.item_id IN (1010437,1010703)
               OR l.item_name LIKE '%تصوير%' OR l.item_name LIKE '%طباعة%')
        GROUP BY h.agent_key, DATE(h.invoice_date), sc.color,
                 CASE WHEN sc.item_code IS NULL THEN l.item_code ELSE NULL END");
    $st->execute([$from, $to]);
    $sales = []; $unknown = []; $unmapped = [];
    foreach ($st as $r) {
        $store = $agentStore[trim((string)$r['agent_key'])] ?? null;
        $branchId = $store !== null ? ($byStore[$store] ?? null) : null;
        if ($branchId === null) {
            $unmapped[(string)$r['agent_key']] = true;
            continue;
        }
        $day = $r['sale_day'];
        if ($r['unknown_code'] !== null) {
            $unknown[] = ['branch_id'=>$branchId, 'day'=>$day, 'code'=>$r['unknown_code'],
                'name'=>$r['item_name'], 'qty'=>(float)$r['net_qty']];
        } else {
            $color = (int)$r['color'] === 1 ? 'color' : 'bw';
            $sales[$branchId][$day][$color] = round(
                ($sales[$branchId][$day][$color] ?? 0) + (float)$r['clicks'], 3);
        }
    }
    return ['sales'=>$sales, 'unknown'=>$unknown, 'unmapped'=>array_keys($unmapped),
            'sales_ready'=>$salesReady];
}

function mc_day_after(string $day, int $days = 1): string {
    return date('Y-m-d', strtotime($day . " +$days day"));
}

function mc_days_between(string $from, string $to): int {
    return (new DateTime($from))->diff(new DateTime($to))->days;
}

function mc_sales_span(array $sales, int $branchId, string $start, string $end): array {
    $bw = 0.0; $color = 0.0;
    for ($d = $start; $d <= $end; $d = mc_day_after($d)) {
        $bw += $sales[$branchId][$d]['bw'] ?? 0;
        $color += $sales[$branchId][$d]['color'] ?? 0;
    }
    return [round($bw,3), round($color,3)];
}

function mc_status(?float $bwDiff, ?float $colorDiff, bool $missing = false): string {
    if ($missing || $bwDiff === null || $colorDiff === null) return 'ناقص قراية';
    $deficit = $bwDiff > 0.0005 || $colorDiff > 0.0005;
    $surplus = $bwDiff < -0.0005 || $colorDiff < -0.0005;
    if ($deficit && $surplus) return 'عجز وزيادة';
    if ($deficit) return 'عجز';
    if ($surplus) return 'زيادة';
    return 'مطابق';
}

/** الفترات بتتقفل عند أول تاريخ فيه قراية لكل مكنة؛ مفيش توزيع تقديري للفجوات. */
function mc_daily_rows(array $branches, array $machines, array $readings, array $sales,
                       string $from, string $today): array {
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    $byBranch = [];
    foreach ($machines as $m) $byBranch[(int)$m['branch_id']][] = $m;
    $out = [];
    foreach ($branches as $branch) {
        $bid = (int)$branch['id'];
        $owned = $byBranch[$bid] ?? [];
        $common = null;
        foreach ($owned as $m) {
            $dates = array_fill_keys(array_keys($readings[(int)$m['id']] ?? []), true);
            $common = $common === null ? $dates : array_intersect_key($common, $dates);
        }
        $bounds = array_keys($common ?? []);
        sort($bounds);
        $covered = [];
        for ($i = 0; $i + 1 < count($bounds); $i++) {
            $start = $bounds[$i]; $endExclusive = $bounds[$i+1];
            $end = date('Y-m-d', strtotime($endExclusive . ' -1 day'));
            if ($end < $from || $start < $from || $start > $yesterday) continue;
            $bwCounter = 0; $colorCounter = 0; $valid = true;
            foreach ($owned as $m) {
                $a = $readings[(int)$m['id']][$start];
                $b = $readings[(int)$m['id']][$endExclusive];
                foreach (mc_fields($m['kind']) as $field => $_label) {
                    if ($a[$field] === null || $b[$field] === null || (int)$b[$field] < (int)$a[$field]) {
                        $valid = false; break;
                    }
                }
                if (!$valid) break;
                if ($m['kind'] === 'bw') $bwCounter += (int)$b['bw_total'] - (int)$a['bw_total'];
                else {
                    $bwCounter += (int)$b['black_small'] - (int)$a['black_small']
                        + 2 * ((int)$b['black_large'] - (int)$a['black_large']);
                    $colorCounter += (int)$b['color_small'] - (int)$a['color_small']
                        + 2 * ((int)$b['color_large'] - (int)$a['color_large']);
                }
            }
            [$bwSold,$colorSold] = mc_sales_span($sales,$bid,$start,$end);
            $bwDiff = $valid ? $bwCounter - $bwSold : null;
            $colorDiff = $valid ? $colorCounter - $colorSold : null;
            $out[] = ['branch_id'=>$bid,'branch'=>$branch['name'],'start'=>$start,'end'=>$end,
                'label'=>$start === $end ? $start : "من $start لـ$end",
                'bw_sold'=>$bwSold,'bw_counter'=>$valid ? $bwCounter : null,'bw_diff'=>$bwDiff,
                'color_sold'=>$colorSold,'color_counter'=>$valid ? $colorCounter : null,
                'color_diff'=>$colorDiff,'status'=>mc_status($bwDiff,$colorDiff,!$valid),'complete'=>$valid];
            for ($d=$start; $d<=$end; $d=mc_day_after($d)) $covered[$d]=true;
        }
        // الأيام اللي مالهاش نهايتان كاملتان تفضل معلّمة ناقصة، من غير ضغطات متخيلة.
        $gapStart = null;
        for ($d=$from; $d<=mc_day_after($yesterday); $d=mc_day_after($d)) {
            $missing = $d <= $yesterday && !isset($covered[$d]);
            if ($missing && $gapStart === null) $gapStart = $d;
            if (!$missing && $gapStart !== null) {
                $gapEnd = date('Y-m-d',strtotime($d . ' -1 day'));
                [$bwSold,$colorSold] = mc_sales_span($sales,$bid,$gapStart,$gapEnd);
                $out[] = ['branch_id'=>$bid,'branch'=>$branch['name'],'start'=>$gapStart,'end'=>$gapEnd,
                    'label'=>$gapStart===$gapEnd ? $gapStart : "من $gapStart لـ$gapEnd",
                    'bw_sold'=>$bwSold,'bw_counter'=>null,'bw_diff'=>null,
                    'color_sold'=>$colorSold,'color_counter'=>null,'color_diff'=>null,
                    'status'=>'ناقص قراية','complete'=>false];
                $gapStart = null;
            }
        }
        if ($today >= $from) {
            [$bwSold,$colorSold] = mc_sales_span($sales,$bid,$today,$today);
            $out[] = ['branch_id'=>$bid,'branch'=>$branch['name'],'start'=>$today,'end'=>$today,
                'label'=>$today,'bw_sold'=>$bwSold,'bw_counter'=>null,'bw_diff'=>null,
                'color_sold'=>$colorSold,'color_counter'=>null,'color_diff'=>null,
                'status'=>'مستني قراية بكرة الصبح','complete'=>false];
        }
    }
    usort($out, static fn($a,$b) => strcmp($b['end'],$a['end']) ?: strcmp($a['branch'],$b['branch']));
    return $out;
}

function mc_monthly_rows(array $branches, array $daily, string $from, string $today): array {
    $yesterday = date('Y-m-d',strtotime($today . ' -1 day'));
    $months = []; $first = substr($from,0,7); $last = substr($today,0,7);
    for ($m=$first; $m<=$last; $m=date('Y-m',strtotime($m . '-01 +1 month'))) $months[]=$m;
    $out=[];
    foreach (array_reverse($months) as $month) {
        $monthStart=$month . '-01';
        $monthEnd=min(date('Y-m-t',strtotime($monthStart)),$yesterday);
        $tot=['bw_sold'=>0.0,'bw_counter'=>0.0,'color_sold'=>0.0,'color_counter'=>0.0,'covered'=>0,'missing'=>false];
        foreach ($branches as $branch) {
            $bid=(int)$branch['id'];
            $sum=['bw_sold'=>0.0,'bw_counter'=>0.0,'color_sold'=>0.0,'color_counter'=>0.0,'covered'=>0];
            foreach ($daily as $r) {
                if ($r['branch_id']!==$bid || !$r['complete']
                    || $r['start']<$monthStart || $r['end']>$monthEnd) continue;
                foreach (['bw_sold','bw_counter','color_sold','color_counter'] as $k) $sum[$k]+=$r[$k];
                $sum['covered']+=mc_days_between($r['start'],mc_day_after($r['end']));
            }
            $days=$monthEnd >= $monthStart ? mc_days_between($monthStart,mc_day_after($monthEnd)) : 0;
            $missing=$sum['covered']<$days;
            $bwDiff=$sum['bw_counter']-$sum['bw_sold'];
            $colorDiff=$sum['color_counter']-$sum['color_sold'];
            $out[]=['month'=>$month,'branch'=>$branch['name'],'branch_id'=>$bid,
                'bw_sold'=>round($sum['bw_sold'],3),'bw_counter'=>$sum['bw_counter'],'bw_diff'=>round($bwDiff,3),
                'color_sold'=>round($sum['color_sold'],3),'color_counter'=>$sum['color_counter'],
                'color_diff'=>round($colorDiff,3),'status'=>mc_status($bwDiff,$colorDiff,$missing)];
            foreach (['bw_sold','bw_counter','color_sold','color_counter','covered'] as $k) $tot[$k]+=$sum[$k];
            if ($missing) $tot['missing']=true;
        }
        $bd=$tot['bw_counter']-$tot['bw_sold']; $cd=$tot['color_counter']-$tot['color_sold'];
        $out[]=['month'=>$month,'branch'=>'كل الفروع','branch_id'=>0,
            'bw_sold'=>round($tot['bw_sold'],3),'bw_counter'=>$tot['bw_counter'],'bw_diff'=>round($bd,3),
            'color_sold'=>round($tot['color_sold'],3),'color_counter'=>$tot['color_counter'],
            'color_diff'=>round($cd,3),'status'=>mc_status($bd,$cd,$tot['missing'])];
    }
    return $out;
}
