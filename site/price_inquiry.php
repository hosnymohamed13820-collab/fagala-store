<?php
/**
 * price_inquiry.php — استعلام الأسعار
 * نظام الفجالة ستور المالي
 *
 * - بحث بالباركود (كتابة أو سكانر كاميرا) → اسم الصنف + سعر الكاشير.
 * - بحث جزئي بالاسم.
 * - يقرا من SQL Server عبر price_lib.php.
 */
require_once __DIR__ . '/auth.php';
require_permission('price_inquiry');
require_once __DIR__ . '/price_lib.php';

// طلب AJAX للبحث (يرجّع JSON)
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_GET['ajax'] === 'barcode') {
            $res = lookup_price_by_barcode($_GET['code'] ?? '');
            echo json_encode($res ?: ['error' => 'الصنف غير موجود'], JSON_UNESCAPED_UNICODE);
        } elseif ($_GET['ajax'] === 'search') {
            echo json_encode(search_items($_GET['term'] ?? ''), JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['error' => 'طلب غير معروف']);
        }
    } catch (Throwable $ex) {
        echo json_encode(['error' => 'خطأ في الاتصال: ' . $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$page_title    = 'استعلام الأسعار';
$active_module = 'price_inquiry';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .price-wrap { max-width:560px; margin:0 auto; width:100%; }
    .search-card { background:var(--surface); border-radius:var(--r-lg); padding:20px; border:1px solid var(--line);
        box-shadow:var(--shadow-sm); margin-bottom:16px; }
    .barcode-row { display:flex; gap:10px; margin-bottom:14px; }
    .barcode-row input { flex:1 1 auto; min-width:0; padding:14px 16px; border:1.5px solid var(--line); border-radius:12px;
        font-size:17px; font-family:inherit; background:var(--line-soft); text-align:center; letter-spacing:1px; }
    .barcode-row input:focus { outline:none; border-color:var(--accent-text); background:var(--surface); }
    .btn-scan { flex:0 0 auto; background:var(--accent); color:#fff; border:none; border-radius:var(--r); padding:0 16px;
        font-size:22px; cursor:pointer; display:flex; align-items:center; }
    .btn-scan:hover { background:var(--accent-hover); }
    .result-card { background:var(--surface); border-radius:var(--r-lg); padding:24px; border:1px solid var(--line); box-shadow:var(--shadow-sm);
        text-align:center; margin-bottom:18px; display:none; }
    .result-card.show { display:block; }
    .result-card.notfound { background:var(--neg-soft); }
    .res-name { font-size:20px; font-weight:700; color:var(--ink-900); margin-bottom:8px; }
    .res-code { font-size:13px; color:var(--ink-500); margin-bottom:18px; }
    .res-price { font-size:42px; font-weight:800; color:var(--pos); }
    .res-price .cur { font-size:18px; color:var(--ink-500); font-weight:500; }
    .res-error { font-size:18px; color:var(--neg); font-weight:600; }
    .hint { text-align:center; color:var(--ink-400); font-size:13px; }
    #reader { width:100%; max-width:100%; }
    #reader video, #reader canvas { width:100% !important; height:auto !important; max-width:100% !important; display:block; border-radius:12px; }
    #reader img { max-width:100% !important; }
    /* بحث بالاسم */
    @media (max-width: 640px) {
        .price-wrap  { max-width:100%; }
        .search-card { padding:16px; border-radius:var(--r); }
        .result-card { padding:18px; border-radius:var(--r); }
        .barcode-row { gap:8px; }
        .barcode-row input { font-size:16px; padding:12px 10px; letter-spacing:0; }
        .btn-scan { padding:0 13px; font-size:20px; }
    }
    .name-search { margin-top:8px; }
    .name-search input { width:100%; padding:11px 14px; border:1.5px solid var(--line); border-radius:10px;
        font-size:14px; font-family:inherit; background:var(--line-soft); }
    .search-results { margin-top:10px; }
    .sr-item { display:flex; justify-content:space-between; padding:11px 14px; border-bottom:1px solid var(--line);
        cursor:pointer; font-size:14px; }
    .sr-item:hover { background:var(--bg); }
    .sr-price { font-weight:700; color:var(--pos); }
</style>

<div class="price-wrap">
    <div class="search-card">
        <div class="barcode-row">
            <input type="text" id="barcode" placeholder="امسح أو اكتب الباركود..."
                   aria-label="باركود الصنف" autocomplete="off"
                   inputmode="numeric" autocomplete="off" autofocus
                   enterkeyhint="search"
                   onkeydown="if(isEnterKey(event)){event.preventDefault();doLookup();}">
            <button class="btn-scan" onclick="openBarcodeScanner('barcode')" title="مسح بالكاميرا"><i class="ti ti-camera"></i></button>
        </div>
        <p class="hint">امسح الباركود بالكاميرا أو اكتبه واضغط Enter.</p>

        <div class="name-search">
            <input type="text" id="nameSearch" placeholder="أو ابحث باسم الصنف..."
                   aria-label="بحث باسم الصنف" autocomplete="off"
                   oninput="debouncedSearch()" autocomplete="off">
            <div class="search-results" id="searchResults"></div>
        </div>
    </div>

    <div class="result-card" id="resultCard">
        <div id="resultContent"></div>
    </div>
</div>

<script src="assets/barcode_scanner.js?v=9"></script>
<script>
    const resultCard = document.getElementById('resultCard');
    const resultContent = document.getElementById('resultContent');

    // ===== بحث بالباركود =====
    function doLookup() {
        const code = document.getElementById('barcode').value.trim();
        if (!code) return;
        fetch('price_inquiry.php?ajax=barcode&code=' + encodeURIComponent(code))
            .then(r => r.json())
            .then(showResult)
            .catch(() => showError('تعذّر الاتصال.'));
    }

    function showResult(data) {
        resultCard.classList.add('show');
        if (data.error) {
            resultCard.classList.add('notfound');
            resultContent.innerHTML = '<div class="res-error"><i class="ti ti-x"></i> ' + escapeHtml(data.error) + '</div>';
            return;
        }
        resultCard.classList.remove('notfound');
        const price = data.price !== null && data.price !== undefined
            ? Number(data.price).toFixed(2) + ' <span class="cur">ج.م</span>'
            : '<span class="cur">السعر غير محدد</span>';
        resultContent.innerHTML =
            '<div class="res-name">' + escapeHtml(data.name) + '</div>' +
            '<div class="res-code">باركود: ' + escapeHtml(data.code) + '</div>' +
            '<div class="res-price">' + price + '</div>';
    }

    function showError(msg) {
        resultCard.classList.add('show','notfound');
        resultContent.innerHTML = '<div class="res-error">' + msg + '</div>';
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    // ===== بحث بالاسم (مع تأخير بسيط) =====
    let searchTimer = null;
    function debouncedSearch() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 350);
    }
    function runSearch() {
        const term = document.getElementById('nameSearch').value.trim();
        const box = document.getElementById('searchResults');
        if (term.length < 2) { box.innerHTML = ''; return; }
        fetch('price_inquiry.php?ajax=search&term=' + encodeURIComponent(term))
            .then(r => r.json())
            .then(list => {
                if (!Array.isArray(list) || list.length === 0) { box.innerHTML = '<div class="hint">لا نتائج</div>'; return; }
                box.innerHTML = list.map(it =>
                    '<div class="sr-item" onclick="pickItem(\'' + encodeURIComponent(it.code) + '\')">' +
                    '<span>' + escapeHtml(it.name) + '</span>' +
                    '<span class="sr-price">' + (it.price!=null ? Number(it.price).toFixed(2) : '—') + '</span></div>'
                ).join('');
            })
            .catch(() => box.innerHTML = '<div class="hint">خطأ في البحث</div>');
    }
    function pickItem(code) {
        document.getElementById('barcode').value = decodeURIComponent(code);
        document.getElementById('searchResults').innerHTML = '';
        document.getElementById('nameSearch').value = '';
        doLookup();
    }

    // سكانر الكاميرا: المكون المشترك assets/barcode_scanner.js
    // (فوكس مستمر + زووم + كشاف + تبديل كاميرا — نفس تجربة باقي الشاشات)
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
