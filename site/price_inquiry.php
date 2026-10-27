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


$page_title    = 'استعلام الأسعار';
$active_module = 'price_inquiry';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .price-wrap { max-width:560px; margin:0 auto; width:100%; }
    .barcode-row { display:flex; gap:10px; margin-bottom:14px; }
    .barcode-row .finput { font-size:18px; }
    .res-name { font-size:20px; font-weight:700; color:var(--ink-900); margin-bottom:8px; }
    .res-code { font-size:13px; color:var(--ink-500); margin-bottom:18px; }
    .res-price { font-size:42px; font-weight:800; color:var(--pos); }
    .res-price .cur { font-size:18px; color:var(--ink-500); font-weight:500; }
    .res-error { font-size:18px; color:var(--neg); font-weight:600; }
    .hint { text-align:center; color:var(--ink-400); font-size:13px; }
    #reader { width:100%; max-width:100%; }
    #reader video, #reader canvas { width:100% !important; height:auto !important; max-width:100% !important; display:block; border-radius:12px; }
    #reader img { max-width:100% !important; }
    .name-search { margin-top:8px; }
    .search-results { margin-top:10px; }
    .sr-item { display:flex; justify-content:space-between; padding:11px 14px; border-bottom:1px solid var(--line);
        cursor:pointer; font-size:14px; }
    .sr-item:hover { background:var(--bg); }
    .sr-price { font-weight:700; color:var(--pos); }
    @media (max-width: 640px) {
        .price-wrap  { max-width:100%; }
        .barcode-row { gap:8px; }
    }
</style>

<div class="price-wrap">
    <div class="fcard" style="margin-bottom:16px;">
        <div class="barcode-row">
            <input type="text" id="barcode" class="finput num" placeholder="امسح أو اكتب الباركود..."
                   aria-label="باركود الصنف" autocomplete="off"
                   inputmode="numeric" autofocus
                   enterkeyhint="search"
                   onkeydown="if(isEnterKey(event)){event.preventDefault();doLookup();}">
            <button class="btn btn-primary" onclick="openBarcodeScanner('barcode')" title="مسح بالكاميرا" style="font-size:20px;padding:0 16px;"><i class="ti ti-camera"></i></button>
        </div>
        <p class="hint">امسح الباركود بالكاميرا أو اكتبه واضغط Enter.</p>

        <div class="name-search">
            <input type="text" id="nameSearch" class="finput" placeholder="أو ابحث باسم الصنف..."
                   aria-label="بحث باسم الصنف" autocomplete="off"
                   oninput="debouncedSearch()">
            <div class="search-results" id="searchResults"></div>
        </div>
    </div>

    <div class="fcard" id="resultCard" style="text-align:center;margin-bottom:18px;display:none;">
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
        apiFetch('price_inquiry_api.php?part=barcode&code=' + encodeURIComponent(code))
            .then(j => {
                if (j.data && j.data.row) {
                    showResult(j.data.row);
                } else {
                    showResult({ error: 'الصنف غير موجود' });
                }
            })
            .catch(() => showError('تعذّر الاتصال.'));
    }

    function showResult(data) {
        resultCard.style.display = 'block';
        if (data.error) {
            resultCard.style.background = 'var(--neg-soft)';
            resultCard.style.borderColor = 'var(--neg)';
            resultContent.innerHTML = '<div class="res-error"><i class="ti ti-x"></i> ' + escapeHtml(data.error) + '</div>';
            return;
        }
        resultCard.style.background = 'var(--surface)';
        resultCard.style.borderColor = 'var(--line)';
        const price = data.price !== null && data.price !== undefined
            ? Number(data.price).toFixed(2) + ' <span class="cur">ج.م</span>'
            : '<span class="cur">السعر غير محدد</span>';
        resultContent.innerHTML =
            '<div class="res-name">' + escapeHtml(data.name) + '</div>' +
            '<div class="res-code">باركود: ' + escapeHtml(data.code) + '</div>' +
            '<div class="res-price">' + price + '</div>';
    }

    function showError(msg) {
        resultCard.style.display = 'block';
        resultCard.style.background = 'var(--neg-soft)';
        resultCard.style.borderColor = 'var(--neg)';
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
        apiFetch('price_inquiry_api.php?part=search&term=' + encodeURIComponent(term))
            .then(j => {
                const list = (j.data && j.data.rows) ? j.data.rows : [];
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
