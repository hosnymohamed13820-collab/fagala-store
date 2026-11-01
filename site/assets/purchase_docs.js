/** purchase_docs.js — منطق شاشة فاتورة الشراء ومرتجع المورد */
document.addEventListener('DOMContentLoaded', function () {
  const cfg = window.PURCHASE_CONFIG || {};
  const PURCHASE_CSRF = cfg.csrf || '';
  const PURCHASE_INITIAL_ID = Number(cfg.initialId) || 0;
  const PURCHASE_INITIAL_TYPE = cfg.initialType || 'purchase';
  const boot = JSON.parse(document.getElementById('pdBoot')?.textContent || '{}');
  let purchaseDocType = PURCHASE_INITIAL_TYPE;
  let purchaseNavigating = false;
  let purchasePendingPage = 1;
  const PURCHASE_OWNER = Boolean(cfg.owner);
  const PURCHASE_CAN_CREATE = Boolean(cfg.canCreate);
  const PURCHASE_CAN_EDIT_DATE = Boolean(cfg.canEditDate);

  let purchaseOptions = { branches: [], suppliers: [] };
  let purchaseLoading = true;
  let purchaseReadonly = false;
  let purchaseLineSeq = 0;
  let purchaseSaveTimer = null;
  let purchaseSaving = null;
  let purchaseSaveDirty = false;
  let purchaseDiscountType = null;
  let purchaseOriginCosts = {};
  let purchaseRemoved = [];
  let purchaseSearchLine = null;
  let purchaseSearchItems = [];
  let purchaseSearchIndex = 0;
  let purchaseSearchRequest = 0;
  let purchaseBalanceRequest = 0;
  let purchaseSearchTimer = null;
  let purchaseBalanceTimer = null;

  const pe = id => document.getElementById(id);

  function isEnterKey(event) {
    return event.key === 'Enter' || event.keyCode === 13;
  }

  function purchaseEsc(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[char]));
  }

  function purchaseMessage(text, bad = false) {
    const box = pe('purchaseMessage');
    box.textContent = '';
    if (!text) return;
    const div = document.createElement('div');
    div.className = 'falert ' + (bad ? 'falert-neg' : 'falert-pos');
    div.textContent = text;
    box.appendChild(div);
  }

  function purchaseSaveState(text, kind = '') {
    const el = pe('purchaseSaveState');
    if (!el) return;
    el.textContent = text;
    el.className = 'purchase-save-state' + (kind ? ' is-' + kind : '');
  }

  async function purchaseApi(url, options = {}) {
    const response = await window.apiFetch(url, options);
    return response.data;
  }

  function purchaseNumber(value, digits = 3) {
    const number = Number(value) || 0;
    return number.toLocaleString('en-US', { maximumFractionDigits: digits });
  }

  function purchaseSupplierLabel(supplier) {
    return String(supplier.name || '') + ' — ' + String(supplier.erp_supplier_code || supplier.id);
  }

  function purchaseResolveSupplier() {
    const value = pe('purchaseSupplier').value.trim();
    const supplier = purchaseOptions.suppliers.find(row => purchaseSupplierLabel(row) === value);
    pe('purchaseSupplierId').value = supplier ? String(supplier.id) : '';
    return supplier ? Number(supplier.id) : 0;
  }

  function purchaseSetSupplier(id) {
    const supplier = purchaseOptions.suppliers.find(row => Number(row.id) === Number(id));
    pe('purchaseSupplierId').value = supplier ? String(supplier.id) : '';
    const label = supplier ? purchaseSupplierLabel(supplier) : '';
    pe('purchaseSupplier').fcombo?.setValue(label);
    pe('purchaseSupplier').value = label;
    pe('purchaseSupplierCode').value = supplier ? String(supplier.erp_supplier_code || supplier.id) : '';
  }

  async function purchaseLoadOptions() {
    const data = boot.options || await purchaseApi('purchase_docs_api.php?action=options');
    purchaseOptions = data;
    window.ui.combo(pe('purchaseSupplier'), data.suppliers.map(s => ({ id:s.id, name:purchaseSupplierLabel(s) })), { onPick: supplier => {
      purchaseSetSupplier(supplier.id); purchaseLoadOrigins(); purchaseQueueSave(true); purchaseUpdatePrintHeader();
    } });
    pe('purchaseBranch').innerHTML = data.branches.map(b => '<option value="' + Number(b.id) + '">' + purchaseEsc(b.name) + '</option>').join('');
    if (!PURCHASE_OWNER && data.user_branch) pe('purchaseBranch').value = String(data.user_branch);
  }

  function purchaseUnitText(factor) {
    const value = Number(factor) || 1;
    return value === 1 ? 'قطعة' : 'وحدة × ' + purchaseNumber(value);
  }

  function purchaseLineTemplate(row = {}) {
    const id = ++purchaseLineSeq;
    const itemId = Number(row.item_id) || 0;
    const factor = Number(row.unit_factor) || 1;
    return '<tr class="purchase-line' + (itemId ? '' : ' frow-blue') + '" data-line="' + id + '" data-lookup-code="' + purchaseEsc(itemId ? row.code || '' : '') + '">'
      + '<td class="num line-index"></td>'
      + '<td class="purchase-no-print"><button class="btn btn-sm btn-ghost line-scan" type="button" aria-label="فتح السكانر"' + (itemId ? ' disabled' : '') + '><i class="ti ti-camera"></i></button></td>'
      + '<td class="fcell line-code-cell"><div class="purchase-code-wrap"><input id="purchaseCode' + id + '" class="finput finput-cell num line-code" value="' + purchaseEsc(row.code || '') + '" autocomplete="off" inputmode="text"></div><input class="line-item" type="hidden" value="' + itemId + '"><input class="line-factor" type="hidden" value="' + factor + '"></td>'
      + '<td class="fcell line-name-cell">' + (itemId ? '<span class="line-name" title="' + purchaseEsc(row.item_name || '') + '">' + purchaseEsc(row.item_name || '') + '</span>' : '<input class="finput finput-cell line-name-input" autocomplete="off" placeholder="اكتب اسم الصنف…">') + '</td>'
      + purchaseLineDetails(row) + '</tr>';
  }

  function purchaseLineDetails(row = {}) {
    if (!Number(row.item_id)) return '<td></td><td class="num line-balance"></td>' + '<td></td>'.repeat(7);
    const factor = Number(row.unit_factor) || 1;
    const qty = row.qty_units === undefined ? 1 : Number(row.qty_units);
    const price = row.unit_price === undefined ? 0 : Number(row.unit_price);
    const discount = Number(row.discount_pct) || 0;
    return '<td><span class="fchip fchip-mute line-unit">' + purchaseEsc(purchaseUnitText(factor)) + '</span></td>'
      + '<td class="num line-balance">—</td>'
      + '<td class="num fcell line-number"><input class="finput finput-cell num line-qty" type="number" min="0.001" step="0.001" value="' + purchaseEsc(qty) + '"></td>'
      + '<td class="num fcell line-number"><input class="finput finput-cell num line-cost" type="number" min="0" step="0.0001" value="' + purchaseEsc(price) + '"></td>'
      + '<td class="num fcell line-number"><input class="finput finput-cell num line-discount" type="number" min="0" max="100" step="0.001" value="' + purchaseEsc(discount) + '"></td>'
      + '<td class="num fcell line-number"><input class="finput finput-cell num line-discount-amount" type="number" min="0" step="0.0001" value="' + purchaseRound(price * discount / 100, 4) + '"></td>'
      + '<td class="num line-number"><span class="line-net">0.00</span></td>'
      + '<td class="num line-number"><span class="line-total">0.00</span></td>'
      + '<td class="line-remove-cell purchase-no-print"><button class="btn btn-sm btn-danger line-remove" type="button" aria-label="حذف السطر"><i class="ti ti-trash"></i></button></td>';
  }

  function purchaseAddLine(row = {}, focus = false) {
    pe('purchaseLines').insertAdjacentHTML('beforeend', purchaseLineTemplate(row));
    const line = pe('purchaseLines').lastElementChild;
    if (Number(row.item_id)) purchaseLockCode(line);
    purchaseBindLine(line);
    purchaseQueueBalances();
    if (row.line_total !== undefined) { line.dataset.savedTotal = String(row.line_total); line.dataset.savedNet = String(row.net_unit_cost); }
    purchaseCalc();
    if (focus) line.querySelector('.line-code,.line-qty').focus();
    return line;
  }

  function purchaseCode(line) {
    return line.dataset.lookupCode || line.querySelector('.line-code')?.value.trim() || '';
  }

  function purchaseLockCode(line) {
    const input = line.querySelector('.line-code');
    if (!input) return;
    const text = document.createElement('span');
    text.className = 'line-code-text num';
    text.textContent = purchaseCode(line);
    text.title = text.textContent;
    input.closest('.purchase-code-wrap').replaceWith(text);
    line.querySelector('.line-scan').disabled = true;
    line.classList.remove('frow-blue');
  }

  function purchaseBindLine(line) {
    const code = line.querySelector('.line-code');
    if (code) {
      code.addEventListener('keydown', event => {
        if (isEnterKey(event)) {
          event.preventDefault();
          purchaseLookup(line, undefined, !event.isTrusted || code.dataset.scanFast === '1');
        }
      });
      code.addEventListener('input', event => {
        code.classList.remove('is-invalid');
        const now = Number(event.timeStamp) || Date.now(), last = Number(code.dataset.scanLast || 0);
        const count = now - last < 80 ? Number(code.dataset.scanCount || 0) + 1 : 1;
        code.dataset.scanLast = String(now);
        code.dataset.scanCount = String(count);
        code.dataset.scanFast = count >= 3 && code.value.length >= 3 ? '1' : '0';
      });
      line.querySelector('.line-scan').addEventListener('click', () => {
        if (typeof openBarcodeScanner === 'function') openBarcodeScanner(code.id);
      });
      line.querySelector('.line-name-input').addEventListener('keydown', event => {
        if (isEnterKey(event)) { event.preventDefault(); purchaseLookupName(line); }
      });
    }
    line.querySelectorAll('.line-qty,.line-cost,.line-discount,.line-discount-amount').forEach(input => {
      input.addEventListener('input', () => {
        if (input.matches('.line-discount-amount')) {
          const price = Number(line.querySelector('.line-cost').value) || 0;
          line.querySelector('.line-discount').value = String(purchaseRound(price > 0 ? Math.min(100, Math.max(0, Number(input.value) || 0) / price * 100) : 0, 3));
        }
        purchaseCalc(); purchaseQueueSave();
      });
      input.addEventListener('blur', () => purchaseQueueSave(true));
      input.addEventListener('keydown', event => purchaseGridKey(event, input));
    });
    line.querySelector('.line-remove')?.addEventListener('click', () => purchaseRemoveLine(line));
  }

  async function purchaseRemoveLine(line) {
    if (purchaseReadonly || !line?.isConnected) return;
    if (typeof fgConfirm === 'function') {
      if (!await fgConfirm('متأكد إنك عايز تمسح الصف ده؟', { title: 'مسح صف', okText: 'مسح', cancelText: 'رجوع', danger: true })) return;
    } else {
      if (!confirm('متأكد إنك عايز تمسح الصف ده؟')) return;
    }
    if (purchaseReadonly || !line.isConnected) return;
    if (Number(line.querySelector('.line-item').value)) {
      purchaseRemoved.push({ code: purchaseCode(line), qty_units: Number(line.querySelector('.line-qty').value) });
    }
    line.remove();
    purchaseEnsureBlank();
    purchaseCalc();
    purchaseQueueSave(true);
    purchaseFocusBarcode();
  }

  function purchaseMergeLine(line) {
    const rows = purchaseBoundLines(), index = rows.indexOf(line);
    const old = rows.slice(0, index).find(other => purchaseCode(other) === purchaseCode(line));
    if (!old) return false;
    old.querySelector('.line-qty').value = String(purchaseRound(Number(old.querySelector('.line-qty').value) + Number(line.querySelector('.line-qty').value), 3));
    line.remove();
    purchaseEnsureBlank();
    purchaseCalc();
    purchaseQueueSave(true);
    purchaseFocusBarcode();
    return true;
  }

  function purchaseQueueBalances() {
    clearTimeout(purchaseBalanceTimer);
    purchaseBalanceTimer = setTimeout(purchaseBalances, 60);
  }

  async function purchaseBalances() {
    if (location.protocol === 'file:') return;
    const request = ++purchaseBalanceRequest, branch = Number(pe('purchaseBranch').value), codes = [...new Set(purchaseBoundLines().map(purchaseCode))];
    if (!branch || !codes.length) return;
    purchaseBoundLines().forEach(line => line.querySelector('.line-balance').textContent = '…');
    try {
      const query = new URLSearchParams({ action: 'balances', branch_id: branch });
      codes.forEach(code => query.append('codes[]', code));
      const data = await purchaseApi('purchase_docs_api.php?' + query);
      if (request !== purchaseBalanceRequest || branch !== Number(pe('purchaseBranch').value)) return;
      purchaseBoundLines().forEach(line => {
        line.querySelector('.line-balance').textContent = data.balances[purchaseCode(line)] === undefined ? '—' : purchaseNumber(data.balances[purchaseCode(line)]);
      });
    } catch (error) {
      if (request === purchaseBalanceRequest) {
        purchaseBoundLines().forEach(line => line.querySelector('.line-balance').textContent = '—');
        purchaseMessage(error.message, true);
      }
    }
  }

  function purchaseBoundLines() {
    return [...pe('purchaseLines').querySelectorAll('.purchase-line')].filter(line => Number(line.querySelector('.line-item').value) > 0);
  }

  function purchaseEnsureBlank() {
    if (purchaseReadonly) return;
    const lines = [...pe('purchaseLines').querySelectorAll('.purchase-line')];
    const blanks = lines.filter(line => !Number(line.querySelector('.line-item').value));
    blanks.slice(1).forEach(line => line.remove());
    if (!blanks.length) purchaseAddLine();
    purchaseRenumber();
  }

  function purchaseRenumber() {
    [...pe('purchaseLines').children].forEach((line, index) => {
      line.querySelector('.line-index').textContent = String(index + 1);
    });
  }

  function purchaseFocusBarcode() {
    const blank = [...pe('purchaseLines').children].find(line => !Number(line.querySelector('.line-item').value));
    blank?.querySelector('.line-code').focus();
  }

  async function purchaseLookup(line, chosenCode, scanner = false) {
    if (purchaseReadonly || !line.isConnected || Number(line.querySelector('.line-item').value) || line.dataset.busy) return;
    const input = line.querySelector('.line-code'), code = chosenCode ?? input.value.trim();
    if (!code) return;
    line.dataset.busy = '1';
    try {
      const data = await purchaseApi('purchase_docs_api.php?action=code&code=' + encodeURIComponent(code) + '&supplier_id=' + purchaseResolveSupplier());
      if (!line.isConnected || purchaseReadonly) return;
      if (purchaseHasOrigin() && purchaseOriginCosts[Number(data.item.item_id)] === undefined) throw new Error('الصنف مش موجود في فاتورة الأصل');
      const foundCode = String(data.item.code || code);
      const duplicate = purchaseBoundLines().find(other => purchaseCode(other) === foundCode);
      if (scanner && duplicate) {
        duplicate.querySelector('.line-qty').value = String(purchaseRound(Number(duplicate.querySelector('.line-qty').value) + 1, 3));
        line.remove();
      } else {
        line.dataset.lookupCode = foundCode;
        line.querySelector('.line-item').value = String(Number(data.item.item_id));
        line.querySelector('.line-factor').value = String(Number(data.item.unit_factor) || 1);
        line.querySelector('.line-name-cell').innerHTML = '<span class="line-name" title="' + purchaseEsc(data.item.name || foundCode) + '">' + purchaseEsc(data.item.name || foundCode) + '</span>';
        while (line.cells.length > 4) line.deleteCell(4);
        line.insertAdjacentHTML('beforeend', purchaseLineDetails({ item_id: data.item.item_id, unit_factor: data.item.unit_factor }));
        if (Number(line.querySelector('.line-cost').value) === 0 && data.item.last_purchase_price !== null) {
          line.querySelector('.line-cost').value = String(data.item.last_purchase_price);
        }
        purchaseLockCode(line);
        purchaseBindLine(line);
      }
      purchaseEnsureBlank();
      purchaseCalc();
      purchaseQueueBalances();
      purchaseQueueSave();
      purchaseMessage('');
      if (scanner) {
        const blank = [...pe('purchaseLines').children].find(row => !Number(row.querySelector('.line-item').value));
        const next = blank?.querySelector('.line-code');
        if (next) { next.id = input.id; next.focus(); }
      } else {
        const qty = line.querySelector('.line-qty');
        qty.focus();
        qty.select();
      }
    } catch (error) {
      purchaseMessage(error.message, true);
      input.classList.add('is-invalid');
      input.focus();
      input.select();
    } finally {
      delete line.dataset.busy;
    }
  }

  async function purchaseLookupName(line) {
    const name = line.querySelector('.line-name-input')?.value.trim();
    if (!name) return;
    try {
      const data = await purchaseApi('purchase_docs_api.php?action=code&name=' + encodeURIComponent(name));
      if (data.item) await purchaseLookup(line, data.item.code);
      else purchaseOpenSearch(line, name);
    } catch (error) {
      purchaseMessage(error.message, true);
    }
  }

  function purchaseOpenSearch(line, text = '') {
    if (purchaseReadonly) return;
    purchaseSearchLine = line;
    pe('purchaseSearch').classList.add('fg-dlg-bg');
    pe('purchaseSearch').hidden = false;
    document.documentElement.classList.add('fg-dlg-open');
    pe('purchaseSearchTerm').value = text;
    pe('purchaseSearchTerm').focus();
    purchaseRunSearch();
  }

  function purchaseCloseSearch() {
    ++purchaseSearchRequest;
    clearTimeout(purchaseSearchTimer);
    pe('purchaseSearch').hidden = true;
    pe('purchaseSearch').classList.remove('fg-dlg-bg');
    document.documentElement.classList.remove('fg-dlg-open');
    if (purchaseSearchLine?.isConnected) {
      (purchaseSearchLine.querySelector('.line-name-input') || purchaseSearchLine.querySelector('.line-qty')).focus();
    } else {
      purchaseFocusBarcode();
    }
  }

  function purchaseRenderSearch() {
    pe('purchaseSearchResults').innerHTML = purchaseSearchItems.length
      ? purchaseSearchItems.map((item, index) => '<button type="button" class="btn ' + (index === purchaseSearchIndex ? 'btn-primary' : 'btn-ghost') + '" data-result="' + index + '" aria-pressed="' + (index === purchaseSearchIndex) + '">' + purchaseEsc(item.code) + ' — ' + purchaseEsc(item.name) + '</button>').join('')
      : '<p>مفيش نتايج</p>';
    pe('purchaseSearchResults').querySelectorAll('[data-result]').forEach(button => {
      button.addEventListener('click', () => purchaseChooseSearch(Number(button.dataset.result)));
    });
  }

  async function purchaseRunSearch() {
    const request = ++purchaseSearchRequest, term = pe('purchaseSearchTerm').value.trim();
    purchaseSearchItems = [];
    purchaseSearchIndex = 0;
    if (!term) { pe('purchaseSearchResults').textContent = 'اكتب اسم الصنف'; return; }
    pe('purchaseSearchResults').textContent = 'جاري البحث…';
    try {
      const data = await purchaseApi('purchase_docs_api.php?action=code&q=' + encodeURIComponent(term));
      if (request !== purchaseSearchRequest) return;
      purchaseSearchItems = data.items || [];
      purchaseRenderSearch();
    } catch (error) {
      if (request === purchaseSearchRequest) pe('purchaseSearchResults').textContent = error.message;
    }
  }

  async function purchaseChooseSearch(index) {
    const item = purchaseSearchItems[index];
    if (!item) return;
    const line = purchaseSearchLine?.isConnected && !Number(purchaseSearchLine.querySelector('.line-item').value)
      ? purchaseSearchLine
      : [...pe('purchaseLines').children].find(row => !Number(row.querySelector('.line-item').value));
    purchaseCloseSearch();
    if (line) await purchaseLookup(line, item.code);
  }

  pe('purchaseSearchClose')?.addEventListener('click', purchaseCloseSearch);
  pe('purchaseSearchTerm')?.addEventListener('input', () => {
    ++purchaseSearchRequest;
    purchaseSearchItems = [];
    clearTimeout(purchaseSearchTimer);
    purchaseSearchTimer = setTimeout(purchaseRunSearch, 250);
  });

  pe('purchaseSearch')?.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      purchaseCloseSearch();
    } else if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
      event.preventDefault();
      purchaseSearchIndex = Math.max(0, Math.min(purchaseSearchItems.length - 1, purchaseSearchIndex + (event.key === 'ArrowDown' ? 1 : -1)));
      purchaseRenderSearch();
      pe('purchaseSearchResults').children[purchaseSearchIndex]?.scrollIntoView({ block: 'nearest' });
    } else if (isEnterKey(event) && event.target === pe('purchaseSearchTerm')) {
      event.preventDefault();
      purchaseChooseSearch(purchaseSearchIndex);
    } else if (event.key === 'Tab') {
      const controls = [...pe('purchaseSearch').querySelectorAll('input,button')], first = controls[0], last = controls.at(-1);
      if (event.shiftKey && event.target === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && event.target === last) { event.preventDefault(); first.focus(); }
    }
  });

  // اختصارات الصفحة: Alt+1 لشراء و Alt+2 لمرتجع، بالإضافة للاختصارات القديمة
  document.addEventListener('keydown', event => {
    if (event.altKey && (event.key === '1' || event.code === 'Digit1')) {
      event.preventDefault();
      purchaseSetType('purchase');
      return;
    }
    if (event.altKey && (event.key === '2' || event.code === 'Digit2')) {
      event.preventDefault();
      purchaseSetType('purchase_return');
      return;
    }
    if (purchaseReadonly || !pe('purchaseSearch').hidden || document.querySelector('.fg-dlg-bg:not([hidden]):not(.purchase-search-bg)')) return;
    const line = document.activeElement?.closest('.purchase-line');
    if (event.key === 'Insert' || (event.ctrlKey && event.key.toLowerCase() === 'n')) {
      event.preventDefault();
      purchaseEnsureBlank();
      purchaseFocusBarcode();
    } else if (event.ctrlKey && event.key === 'Delete' && line) {
      event.preventDefault();
      purchaseRemoveLine(line);
    } else if (event.key === 'F2') {
      event.preventDefault();
      purchaseOpenSearch(line);
    }
  });

  function purchaseGridKey(event, input) {
    const enter = isEnterKey(event);
    if (!enter && !['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    const line = input.closest('.purchase-line');
    if (enter && input.matches('.line-qty') && purchaseMergeLine(line)) { event.preventDefault(); event.stopPropagation(); return; }
    event.preventDefault();
    event.stopPropagation();
    const rows = [...pe('purchaseLines').querySelectorAll('.purchase-line')].filter(row => !row.hidden && !row.classList.contains('xt-out'));
    const columns = ['.line-code', '.line-qty', '.line-cost', '.line-discount', '.line-discount-amount'];
    const column = columns.findIndex(selector => input.matches(selector));
    const row = rows.indexOf(line);
    let target = null;
    if (enter || event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      const step = event.key === 'ArrowUp' ? -1 : 1;
      for (let i = row + step; i >= 0 && i < rows.length; i += step) {
        const candidate = rows[i].querySelector(columns[column]);
        if (candidate && !candidate.disabled) { target = candidate; break; }
      }
    } else {
      const step = event.key === 'ArrowLeft' ? 1 : -1;
      for (let i = column + step; i >= 0 && i < columns.length; i += step) {
        const candidate = line.querySelector(columns[i]);
        if (candidate && !candidate.disabled) { target = candidate; break; }
      }
    }
    if (target) { target.focus(); target.select(); }
  }

  function purchaseRows() {
    return purchaseBoundLines().map(line => ({
      code: purchaseCode(line),
      item_id: Number(line.querySelector('.line-item').value),
      unit_factor: Number(line.querySelector('.line-factor').value),
      qty_units: Number(line.querySelector('.line-qty').value),
      unit_price: Number(line.querySelector('.line-cost').value),
      discount_pct: Number(line.querySelector('.line-discount').value) || 0
    }));
  }

  function purchaseHasOrigin() {
    return purchaseDocType === 'purchase_return' && Number(pe('purchaseOrigin').value) > 0;
  }

  function purchaseDiscount() {
    return purchaseHasOrigin() || !purchaseDiscountType ? null : {
      type: purchaseDiscountType,
      value: Number(pe(purchaseDiscountType === 'percent' ? 'purchaseDiscountPct' : 'purchaseDiscountAmount').value) || 0
    };
  }

  function purchaseRound(value, digits) {
    const scale = 10 ** digits, scaled = value * scale;
    return Math.round(scaled + Number.EPSILON * Math.max(1, Math.abs(scaled))) / scale;
  }

  function purchaseNetText(value) {
    return Number(value).toFixed(Number.isInteger(Number(value)) ? 2 : 4);
  }

  function purchaseCalc() {
    let grand = 0, pieces = 0, gross = 0, totalGross = 0, largest = 0;
    const bound = purchaseBoundLines(), locked = purchaseHasOrigin();
    const rows = bound.map((line, index) => {
      const qty = purchaseRound(Number(line.querySelector('.line-qty').value) || 0, 3);
      const price = line.querySelector('.line-cost'), discountInput = line.querySelector('.line-discount');
      if (locked && !purchaseReadonly) {
        const cost = purchaseOriginCosts[Number(line.querySelector('.line-item').value)];
        if (cost !== undefined) price.value = String(purchaseRound(cost * Number(line.querySelector('.line-factor').value), 4));
        discountInput.value = '0';
      }
      price.readOnly = locked;
      discountInput.readOnly = locked;
      const cost = purchaseRound(Number(price.value) || 0, 4), discount = purchaseRound(Number(discountInput.value) || 0, 3);
      const after = Math.round(purchaseRound(qty * cost * (1 - discount / 100), 2) * 100);
      if (index === 0 || after > bound[largest]._after) largest = index;
      line._after = after;
      totalGross += purchaseRound(qty * cost, 2);
      const amountInput = line.querySelector('.line-discount-amount');
      amountInput.readOnly = locked;
      if (document.activeElement !== amountInput) amountInput.value = String(purchaseRound(cost * discount / 100, 4));
      gross += after;
      pieces += purchaseRound(qty * Number(line.querySelector('.line-factor').value || 1), 3);
      return { line, qty, after, alloc: 0 };
    });
    pe('purchaseDiscountPct').disabled = purchaseReadonly || locked;
    pe('purchaseDiscountAmount').disabled = purchaseReadonly || locked;
    const discount = purchaseDiscount(), value = purchaseRound(discount?.value || 0, 4);
    const target = purchaseRound(discount?.type === 'percent' ? gross * value / 100 : value * 100, 0);
    let allocated = 0;
    rows.forEach(row => {
      row.alloc = purchaseRound(discount?.type === 'percent' ? row.after * value / 100 : (gross > 0 ? value * 100 * row.after / gross : 0), 0);
      allocated += row.alloc;
    });
    if (rows.length) rows[largest].alloc += target - allocated;
    rows.forEach(row => {
      let total = (row.after - row.alloc) / 100, net = row.qty > 0 ? total / row.qty : 0;
      if (purchaseReadonly && row.line.dataset.savedTotal !== undefined) {
        total = Number(row.line.dataset.savedTotal);
        net = Number(row.line.dataset.savedNet);
      }
      grand += total;
      row.line.querySelector('.line-total').textContent = total.toFixed(2);
      row.line.querySelector('.line-net').textContent = purchaseNetText(net);
    });
    if (purchaseDiscountType !== 'percent') pe('purchaseDiscountPct').value = gross ? String(purchaseRound(value * 10000 / gross, 4)) : '0';
    if (purchaseDiscountType !== 'amount') pe('purchaseDiscountAmount').value = (target / 100).toFixed(2);
    pe('purchaseItemsCount').textContent = String(purchaseBoundLines().length);
    pe('purchasePieces').textContent = purchaseNumber(pieces);
    pe('purchaseGrand').textContent = grand.toFixed(2);
    pe('purchaseGross').textContent = (gross / 100).toFixed(2);
    pe('purchaseTotalGross').textContent = totalGross.toFixed(2);
    pe('purchaseLineDiscount').textContent = (totalGross - gross / 100).toFixed(2);
    purchaseRenumber();
    purchaseUpdatePrintHeader();
  }

  function purchaseUpdatePrintHeader() {
    const isReturn = purchaseDocType === 'purchase_return';
    const label = isReturn ? 'مرتجع مورد' : 'فاتورة شراء';
    const pTitle = pe('printDocTitle');
    if (pTitle) pTitle.textContent = label;
    const pNo = pe('printDocNo');
    if (pNo) pNo.textContent = pe('purchaseDocNo').textContent;
    const pDate = pe('printDocDate');
    if (pDate) pDate.textContent = pe('purchaseDate').value;
    const pSupplier = pe('printSupplier');
    if (pSupplier) pSupplier.textContent = pe('purchaseSupplier').value || '—';
    const pBranch = pe('printBranch');
    const branchSel = pe('purchaseBranch');
    if (pBranch && branchSel) pBranch.textContent = branchSel.options[branchSel.selectedIndex]?.text || '—';
    const ref = pe('purchaseRef').value.trim();
    const pRef = pe('printRef');
    const pRefWrap = pe('printRefWrap');
    if (pRef) pRef.textContent = ref;
    if (pRefWrap) pRefWrap.hidden = !ref;

    // ملخص الطباعة
    const printItemsCount = pe('printItemsCount');
    if (printItemsCount) printItemsCount.textContent = pe('purchaseItemsCount').textContent;
    const printPieces = pe('printPieces');
    if (printPieces) printPieces.textContent = pe('purchasePieces').textContent;
    const printGross = pe('printGross');
    if (printGross) printGross.textContent = pe('purchaseGross').textContent;
    const printGrand = pe('printGrand');
    if (printGrand) printGrand.textContent = pe('purchaseGrand').textContent;
    const printDiscount = pe('printDiscount');
    if (printDiscount) {
      const g = Number(pe('purchaseGross').textContent) || 0;
      const t = Number(pe('purchaseGrand').textContent) || 0;
      printDiscount.textContent = Math.max(0, g - t).toFixed(2);
    }
  }

  function purchasePayload(action) {
    return {
      action,
      doc_id: Number(pe('purchaseDocId').value) || null,
      doc_type: purchaseDocType,
      supplier_id: purchaseResolveSupplier(),
      branch_id: Number(pe('purchaseBranch').value),
      doc_date: pe('purchaseDate').value,
      ref_text: pe('purchaseRef').value.trim(),
      orig_doc_id: Number(pe('purchaseOrigin').value) || null,
      notes: pe('purchaseNotes').value.trim(),
      inv_discount: purchaseDiscount(),
      lines: purchaseRows(),
      removed_lines: purchaseRemoved.slice()
    };
  }

  function purchaseQueueSave(immediate = false) {
    if (purchaseLoading || purchaseReadonly) return;
    purchaseSaveDirty = true;
    clearTimeout(purchaseSaveTimer);
    purchaseSaveState('فيه تغييرات…');
    purchaseSaveTimer = setTimeout(() => purchaseSaveNow(false), immediate ? 0 : 800);
  }

  async function purchaseSaveNow(force = false) {
    clearTimeout(purchaseSaveTimer);
    if (purchaseReadonly) return;
    if (purchaseSaving) {
      purchaseSaveDirty = true;
      await purchaseSaving;
      if (force || purchaseSaveDirty) return purchaseSaveNow(force);
      return;
    }
    if (!purchaseSaveDirty && !force) return;
    const payload = purchasePayload('save');
    if (!payload.supplier_id || !payload.branch_id || (!payload.lines.length && (!payload.doc_id || !payload.removed_lines.length))) {
      if (force) throw new Error('كمّل المورد والفرع والأصناف الأول');
      return;
    }
    purchaseSaveDirty = false;
    purchaseSaveState('بيتحفظ…');
    purchaseSaving = purchaseApi('purchase_docs_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': PURCHASE_CSRF },
      body: JSON.stringify(payload)
    });
    let failed = false;
    try {
      const data = await purchaseSaving;
      purchaseRemoved.splice(0, payload.removed_lines.length);
      pe('purchaseDocId').value = String(data.doc_id);
      pe('purchaseDocNo').textContent = 'مسودة-' + data.doc_id;
      pe('purchaseType').disabled = true;
      purchaseRefreshPending().catch(error => purchaseMessage(error.message, true));
      history.replaceState(null, '', 'purchase_docs.php?id=' + data.doc_id);
      purchaseSaveState('اتحفظ ✓', 'ok');
      purchaseUpdatePrintHeader();
    } catch (error) {
      failed = true;
      purchaseSaveDirty = true;
      purchaseSaveState('الحفظ وقف', 'bad');
      purchaseMessage(error.message, true);
      if (force) throw error;
    } finally {
      purchaseSaving = null;
    }
    if (purchaseSaveDirty && !failed) return purchaseSaveNow(force);
  }

  async function purchaseLoadOrigins(selected = 0) {
    const wrap = pe('purchaseOriginWrap');
    const isReturn = purchaseDocType === 'purchase_return';
    wrap.hidden = !isReturn;
    if (!isReturn) { pe('purchaseOrigin').value = ''; return; }
    const supplierId = purchaseResolveSupplier();
    const branchId = Number(pe('purchaseBranch').value);
    if (!supplierId || !branchId) {
      pe('purchaseOrigin').innerHTML = '<option value="">مرتجع من غير أصل</option>';
      return;
    }
    try {
      const data = await purchaseApi('purchase_docs_api.php?action=origins&branch_id=' + branchId + '&supplier_id=' + supplierId);
      pe('purchaseOrigin').innerHTML = '<option value="">مرتجع من غير أصل</option>' + data.origins.map(origin => '<option value="' + Number(origin.id) + '">' + purchaseEsc(origin.doc_year + '/' + origin.doc_no + ' — ' + (origin.ref_text || 'من غير رقم')) + '</option>').join('');
      if (selected) {
        if (![...pe('purchaseOrigin').options].some(option => Number(option.value) === selected)) {
          pe('purchaseOrigin').insertAdjacentHTML('beforeend', '<option value="' + selected + '">فاتورة الأصل #' + selected + '</option>');
        }
        pe('purchaseOrigin').value = String(selected);
      }
      purchaseCalc();
    } catch (error) {
      purchaseMessage(error.message, true);
    }
  }

  async function purchaseFillOrigin() {
    const id = Number(pe('purchaseOrigin').value);
    if (!id) {
      purchaseOriginCosts = {};
      purchaseCalc();
      return purchaseQueueSave(true);
    }
    try {
      const data = await purchaseApi('purchase_docs_api.php?action=return_available&orig_doc_id=' + id);
      purchaseOriginCosts = Object.fromEntries(data.lines.map(line => [Number(line.item_id), Number(line.unit_cost)]));
      purchaseDiscountType = null;
      pe('purchaseDiscountPct').value = '';
      pe('purchaseDiscountAmount').value = '';
      purchaseLoading = true;
      pe('purchaseLines').textContent = '';
      data.lines.forEach(line => purchaseAddLine({
        code: line.code,
        item_id: line.item_id,
        item_name: line.item_name,
        unit_factor: line.unit_factor,
        qty_units: Number(line.available_pieces) / Number(line.unit_factor || 1),
        unit_price: line.unit_price,
        discount_pct: 0
      }));
      purchaseLoading = false;
      purchaseEnsureBlank();
      purchaseCalc();
      purchaseQueueSave(true);
      purchaseFocusBarcode();
    } catch (error) {
      purchaseLoading = false;
      purchaseMessage(error.message, true);
    }
  }

  function purchaseStatus(status) {
    const chip = pe('purchaseStatus');
    chip.textContent = status === 'posted' ? 'مترحلة' : (status === 'cancelled' ? 'ملغية' : 'مسودة');
    chip.className = 'fchip ' + (status === 'posted' ? 'fchip-pos' : (status === 'cancelled' ? 'fchip-neg' : 'fchip-warn'));
  }

  function purchaseSetReadonly(readonly, status = 'draft') {
    purchaseReadonly = readonly;
    pe('purchaseReadonlyNote').hidden = !readonly;
    document.querySelectorAll('[data-edit],.purchase-line input,.purchase-line button').forEach(control => {
      control.disabled = readonly;
    });
    if (!PURCHASE_OWNER) pe('purchaseBranch').disabled = true;
    if (!PURCHASE_CAN_EDIT_DATE) pe('purchaseDate').readOnly = true;
    pe('purchasePost')?.toggleAttribute('hidden', readonly);
    pe('purchaseCancel')?.toggleAttribute('hidden', status !== 'posted');
  }

  function purchaseRenderEvents(events) {
    const box = pe('purchaseEvents');
    if (!box) return;
    if (!events.length) { box.hidden = true; box.textContent = ''; return; }
    box.hidden = false;
    box.innerHTML = '<strong>سجل المستند</strong>' + events.map(event => '<div>' + purchaseEsc(event.at) + ' — ' + purchaseEsc(event.event) + ' — ' + purchaseEsc(event.user_name || 'النظام') + '</div>').join('');
  }

  async function purchaseBeforeNavigate() {
    if (purchaseLoading) throw new Error('استنى تحميل المستند الأول');
    if ([...pe('purchaseLines').querySelectorAll('.line-code,.line-name-input')].some(input => input.value.trim())) {
      throw new Error('ثبّت الصنف المكتوب بـ Enter الأول علشان شغلك يتحفظ');
    }
    if (purchaseSaving || purchaseSaveDirty) await purchaseSaveNow(true);
    if (purchaseSaveDirty) throw new Error('فيه تغييرات لسه ماتحفظتش');
  }

  async function purchaseNavigate(url, newTab = false) {
    if (purchaseNavigating) return;
    purchaseNavigating = true;
    // بنحجز التاب وقت الضغطة علشان المتصفح ما يمنعوش بعد انتظار الحفظ.
    const tab = newTab ? window.open('about:blank', '_blank') : null;
    if (tab) tab.opener = null;
    try {
      if (newTab && !tab) throw new Error('اسمح بفتح تاب جديد علشان نفتح المستند');
      await purchaseBeforeNavigate();
      if (tab) tab.location.replace(url); else location.href = url;
    } catch (error) {
      tab?.close(); purchaseMessage(error.message, true);
    } finally { purchaseNavigating = false; }
  }

  function purchaseSetType(type) {
    return purchaseNavigate('purchase_docs.php?type=' + type, true);
  }

  function purchaseClosePending() {
    pe('purchaseDraftModal').hidden = true;
    pe('purchaseDraftModal').classList.remove('fg-dlg-bg');
    document.documentElement.classList.remove('fg-dlg-open');
    pe('purchasePending').focus();
  }

  async function purchaseRefreshPending(show = false) {
    const query = new URLSearchParams({ action:'docs', type:purchaseDocType, status:'draft', branch_id:pe('purchaseBranch').value, page:purchasePendingPage });
    const data = await purchaseApi('purchase_docs_api.php?' + query);
    pe('purchasePendingCount').textContent = String(data.total);
    pe('purchaseDraftPrev').disabled = purchasePendingPage <= 1;
    pe('purchaseDraftNext').disabled = purchasePendingPage * 200 >= data.total;
    pe('purchaseDraftRows').innerHTML = data.docs.map(doc => '<tr role="button" tabindex="0" aria-expanded="false" style="cursor:pointer" data-draft="' + Number(doc.id) + '">'
      + '<td>مسودة-' + Number(doc.id) + '</td><td>' + purchaseEsc(doc.supplier_name) + '</td><td>' + purchaseEsc(doc.doc_date) + '</td>'
      + '<td class="num">' + Number(doc.lines_count) + '</td><td class="num">' + purchaseNumber(doc.total_amount, 2) + '</td><td>' + purchaseEsc(doc.last_modified) + '</td></tr>').join('');
    pe('purchaseDraftMessage').textContent = data.docs.length ? 'صفحة ' + purchasePendingPage + ' · الإجمالي ' + data.total + ' مستند، لنفس النوع والفرع المختار' : 'مفيش مستندات معلّقة للنوع والفرع ده';
    if (show) {
      pe('purchaseDraftTitle').textContent = purchaseDocType === 'purchase_return' ? 'مرتجعات المورد المعلّقة' : 'فواتير الشراء المعلّقة';
      pe('purchaseDraftModal').hidden = false;
      pe('purchaseDraftModal').classList.add('fg-dlg-bg');
      document.documentElement.classList.add('fg-dlg-open');
      (pe('purchaseDraftRows').querySelector('tr') || pe('purchaseDraftClose')).focus();
    }
  }

  async function purchaseOpen(id) {
    const data = boot.document && Number(boot.document.doc.id) === Number(id) ? boot.document : await purchaseApi('purchase_docs_api.php?action=doc&id=' + id);
    const doc = data.doc;
    purchaseDiscountType = doc.inv_discount?.type || null;
    if (purchaseDiscountType) pe(purchaseDiscountType === 'percent' ? 'purchaseDiscountPct' : 'purchaseDiscountAmount').value = String(doc.inv_discount.value);
    pe('purchaseDocId').value = String(doc.id);
    purchaseDocType = doc.doc_type;
    pe('purchaseType').value = doc.doc_type;
    const report = pe('purchaseReport');
    if (report) { report.href = 'reports.php?type=purchase_docs&doc_type=' + purchaseDocType; report.textContent = purchaseDocType === 'purchase' ? 'تقرير المشتريات' : 'تقرير المرتجعات'; }
    document.querySelectorAll('#purchaseTypeTabs .ftab').forEach(btn => {
      btn.classList.toggle('is-active', btn.dataset.type === doc.doc_type);

    });
    purchaseSetSupplier(doc.supplier_id);
    pe('purchaseBranch').value = String(doc.branch_id);
    pe('purchaseDate').value = doc.doc_date;
    pe('purchaseRef').value = doc.ref_text || '';
    pe('purchaseNotes').value = doc.notes || '';
    pe('purchaseLines').textContent = '';
    data.lines.forEach(line => purchaseAddLine(line));
    pe('purchaseOriginWrap').hidden = purchaseDocType !== 'purchase_return';
    if (doc.orig_doc_id) {
      pe('purchaseOrigin').insertAdjacentHTML('beforeend', '<option value="' + Number(doc.orig_doc_id) + '">فاتورة الأصل #' + Number(doc.orig_doc_id) + '</option>');
      pe('purchaseOrigin').value = String(doc.orig_doc_id);
      purchaseOriginCosts = Object.fromEntries(data.lines.map(line => [Number(line.item_id), Number(line.unit_price) / Number(line.unit_factor || 1)]));
    }
    if (location.protocol !== 'file:') await purchaseLoadOrigins(Number(doc.orig_doc_id) || 0);
    if (location.protocol !== 'file:' && doc.orig_doc_id && doc.status === 'draft') {
      const origin = await purchaseApi('purchase_docs_api.php?action=return_available&orig_doc_id=' + Number(doc.orig_doc_id));
      purchaseOriginCosts = Object.fromEntries(origin.lines.map(line => [Number(line.item_id), Number(line.unit_cost)]));
    }
    const label = doc.doc_type === 'purchase' ? 'فاتورة شراء' : 'مرتجع مورد';
    pe('purchaseTitle').textContent = label;
    pe('purchaseDocNo').textContent = doc.doc_no ? doc.doc_year + '/' + doc.doc_no : 'مسودة-' + doc.id;
    purchaseStatus(doc.status);
    purchaseRenderEvents(data.events || []);
    const readonly = doc.status !== 'draft' || !PURCHASE_CAN_CREATE;
    purchaseSetReadonly(readonly, doc.status);
    pe('purchaseType').disabled = true;
    if (!readonly) purchaseEnsureBlank();
    purchaseCalc();
  }

  function purchaseNew() {
    purchaseNavigate('purchase_docs.php?type=' + purchaseDocType);
  }

  async function purchasePostDoc() {
    try {
      await purchaseSaveNow(true);
      const id = Number(pe('purchaseDocId').value);
      if (!id) return;
      if (!confirm('متأكد إنك عايز ترحّل المستند؟')) return;
      await purchaseApi('purchase_docs_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': PURCHASE_CSRF },
        body: JSON.stringify({ action: 'post', doc_id: id })
      });
      location.href = 'purchase_docs.php?id=' + id;
    } catch (error) {
      purchaseMessage(error.message, true);
    }
  }

  async function purchaseCancelDoc() {
    const reason = prompt('اكتب سبب الإلغاء');
    if (!reason || !reason.trim()) return;
    try {
      const id = Number(pe('purchaseDocId').value);
      await purchaseApi('purchase_docs_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': PURCHASE_CSRF },
        body: JSON.stringify({ action: 'cancel', doc_id: id, reason: reason.trim() })
      });
      location.href = 'purchase_docs.php?id=' + id;
    } catch (error) {
      purchaseMessage(error.message, true);
    }
  }

  async function purchaseInit() {
    try {
      await purchaseLoadOptions();
      if (PURCHASE_INITIAL_ID) {
        await purchaseOpen(PURCHASE_INITIAL_ID);
      } else {
        pe('purchaseType').value = PURCHASE_INITIAL_TYPE;
        document.querySelectorAll('#purchaseTypeTabs .ftab').forEach(btn => {
          btn.classList.toggle('is-active', btn.dataset.type === PURCHASE_INITIAL_TYPE);
        });
        purchaseStatus('draft');
        if (location.protocol !== 'file:') await purchaseLoadOrigins();
        purchaseEnsureBlank();
        purchaseSetReadonly(!PURCHASE_CAN_CREATE, 'draft');
        purchaseFocusBarcode();
        purchaseUpdatePrintHeader();
      }
    } catch (error) {
      purchaseMessage(error.message, true);
      purchaseSetReadonly(true, 'draft');
    } finally {
      purchaseLoading = false;
      if (location.protocol !== 'file:') purchaseRefreshPending().catch(error => purchaseMessage(error.message, true));
    }
  }

  // زراير النوع
  document.querySelectorAll('#purchaseTypeTabs .ftab').forEach(btn => {
    btn.addEventListener('click', event => {
      event.preventDefault();
      purchaseSetType(btn.dataset.type);
    });
    btn.addEventListener('auxclick', event => {
      if (event.button !== 1) return;
      event.preventDefault(); purchaseSetType(btn.dataset.type);
    });
  });

  ['purchaseRef', 'purchaseNotes', 'purchaseDate'].forEach(id => {
    const el = pe(id);
    if (!el) return;
    el.addEventListener('input', () => { purchaseQueueSave(); purchaseUpdatePrintHeader(); });
    el.addEventListener('blur', () => { purchaseQueueSave(true); purchaseUpdatePrintHeader(); });
  });

  [['purchaseDiscountPct', 'percent'], ['purchaseDiscountAmount', 'amount']].forEach(([id, type]) => {
    const el = pe(id);
    if (!el) return;
    el.addEventListener('input', () => { purchaseDiscountType = type; purchaseCalc(); purchaseQueueSave(); });
    el.addEventListener('blur', () => purchaseQueueSave(true));
  });

  pe('purchaseSupplier')?.addEventListener('input', () => { purchaseResolveSupplier(); purchaseQueueSave(); purchaseUpdatePrintHeader(); });
  pe('purchaseSupplier')?.addEventListener('change', () => { purchaseResolveSupplier(); purchaseLoadOrigins(); purchaseQueueSave(true); purchaseUpdatePrintHeader(); });
  pe('purchaseBranch')?.addEventListener('change', () => { purchasePendingPage = 1; purchaseRefreshPending().catch(error => purchaseMessage(error.message, true)); purchaseLoadOrigins(); purchaseQueueBalances(); purchaseQueueSave(true); purchaseUpdatePrintHeader(); });
  pe('purchaseOrigin')?.addEventListener('change', purchaseFillOrigin);
  pe('purchasePost')?.addEventListener('click', purchasePostDoc);
  pe('purchaseCancel')?.addEventListener('click', purchaseCancelDoc);
  pe('purchasePrint')?.addEventListener('click', () => window.print());
  pe('purchaseNew')?.addEventListener('click', purchaseNew);

  pe('purchaseSupplierCode').addEventListener('keydown', event => {
    if (!isEnterKey(event)) return;
    event.preventDefault();
    const supplier = purchaseOptions.suppliers.find(row => String(row.erp_supplier_code || row.id) === event.target.value.trim());
    if (!supplier) return purchaseMessage('مفيش مورد بالكود ده', true);
    purchaseSetSupplier(supplier.id); purchaseLoadOrigins(); purchaseQueueSave(true); purchaseFocusBarcode();
  });
  pe('purchaseFind').addEventListener('click', () => purchaseOpenSearch(null));
  pe('purchaseClear').addEventListener('click', async () => {
    if (purchaseReadonly || !purchaseBoundLines().length) return;
    if (!await fgConfirm('متأكد إنك عايز تمسح كل أصناف المسودة؟', { title:'مسح الفاتورة', danger:true })) return;
    purchaseBoundLines().forEach(line => {
      purchaseRemoved.push({ code:purchaseCode(line), qty_units:Number(line.querySelector('.line-qty').value) }); line.remove();
    });
    purchaseEnsureBlank(); purchaseCalc(); purchaseQueueSave(true);
  });
  pe('purchasePending').addEventListener('click', async () => {
    try { await purchaseRefreshPending(true); } catch (error) { purchaseMessage(error.message, true); }
  });
  pe('purchaseDraftClose').addEventListener('click', purchaseClosePending);
  [['purchaseDraftPrev', -1], ['purchaseDraftNext', 1]].forEach(([id, step]) => {
    pe(id).addEventListener('click', async () => {
      pe(id).disabled = true;
      purchasePendingPage += step;
      try { await purchaseRefreshPending(); } catch (error) { purchasePendingPage -= step; pe(id).disabled = false; purchaseMessage(error.message, true); }
    });
  });
  pe('purchaseDraftModal').addEventListener('click', event => { if (event.target === event.currentTarget) purchaseClosePending(); });
  pe('purchaseDraftRows').addEventListener('click', event => {
    const row = event.target.closest('[data-draft]');
    if (row) purchaseNavigate('purchase_docs.php?id=' + Number(row.dataset.draft));
  });
  pe('purchaseDraftModal').addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault(); purchaseClosePending(); }
    const row = event.target.closest('[data-draft]');
    if (row && ['Enter',' '].includes(event.key)) { event.preventDefault(); row.click(); }
    if (event.key === 'Tab') {
      const controls = [...event.currentTarget.querySelectorAll('[tabindex="0"],button')].filter(el => el.getClientRects().length);
      const first = controls[0], last = controls[controls.length - 1];
      if (event.shiftKey && event.target === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && event.target === last) { event.preventDefault(); first.focus(); }
    }
  });
  window.addEventListener('beforeunload', event => {
    if (!purchaseSaveDirty && !purchaseSaving) return;
    event.preventDefault(); event.returnValue = '';
  });

  purchaseInit();
});
