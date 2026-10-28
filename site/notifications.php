<?php
/** notifications.php — صفحة إشعارات المستخدم الحالي (عرض فقط). */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

$page_title = 'الإشعارات';
$active_module = '';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    /* المكتبة محتاجة مكوّن .faccordion؛ الموجود هنا تخطيط القايمة والصفحات بس. */
    .notifs-toolbar { display:flex; align-items:center; justify-content:space-between; gap:var(--sp-3); flex-wrap:wrap; }
    .notifs-list { margin-top:var(--sp-4); }
    .notifs-list .notif-item { border:1px solid var(--line-soft); border-radius:var(--r-md); margin-bottom:var(--sp-2); }
    .notifs-pager { display:flex; align-items:center; justify-content:center; gap:var(--sp-3); margin-top:var(--sp-4); }
</style>

<?= ui_page_head('الإشعارات', 'كل إشعاراتك متقسمة حسب نوع الشغل') ?>

<section class="fcard">
    <div class="notifs-toolbar">
        <nav class="ftabs" id="notifTabs" aria-label="مجموعات الإشعارات"></nav>
        <?= ui_btn('علّم الكل كمقروء', [
            'variant' => 'ghost',
            'icon' => 'check',
            'id' => 'notifMarkGroup',
            'attrs' => ['style' => 'display:none;'],
        ]) ?>
    </div>
    <div class="notifs-list" id="notifPageList"><?= ui_alert('info', 'جاري تحميل الإشعارات...') ?></div>
    <div class="notifs-pager" id="notifPager" style="display:none;">
        <?= ui_btn('السابق', ['variant' => 'ghost', 'icon' => 'chevron-left', 'id' => 'notifPrev']) ?>
        <span id="notifPageNo"></span>
        <?= ui_btn('التالي', ['variant' => 'ghost', 'icon' => 'arrow-right', 'id' => 'notifNext']) ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var state = {group:'', page:1, hasMore:false, groups:[]};
    var tabs = document.getElementById('notifTabs');
    var list = document.getElementById('notifPageList');
    var pager = document.getElementById('notifPager');
    var markBtn = document.getElementById('notifMarkGroup');

    function renderItem(item) {
        var esc = window.ui.esc;
        return '<a href="' + esc(item.href) + '" class="notif-item ' + (item.is_read ? '' : 'unread') + '">' +
            '<div class="ni-title">' + esc(item.title) + '</div>' +
            (item.body ? '<div class="ni-body">' + esc(item.body) + '</div>' : '') +
            '<div class="ni-time">' + esc(item.time) + '</div></a>';
    }

    function renderTabs() {
        tabs.innerHTML = state.groups.map(function (group) {
            return '<button type="button" class="ftab ' + (group.key === state.group ? 'is-active' : '') +
                '" data-group="' + window.ui.esc(group.key) + '">' + window.ui.icon(group.icon) + ' ' +
                window.ui.esc(group.name) + ' ' + window.ui.chip(group.unread, group.unread ? 'neg' : 'mute') + '</button>';
        }).join('');
        var current = state.groups.find(function (group) { return group.key === state.group; });
        markBtn.style.display = current && current.unread > 0 ? '' : 'none';
    }

    function applySummary(data) {
        state.groups = data.groups || [];
        var badge = document.getElementById('notifBadge');
        var markAll = document.getElementById('notifMarkAll');
        var total = Number(data.total_unread) || 0;
        if (badge) {
            badge.textContent = total > 9 ? '9+' : String(total);
            badge.style.display = total > 0 ? 'flex' : 'none';
        }
        if (markAll) markAll.style.display = total > 0 ? '' : 'none';
        var requested = new URLSearchParams(location.search).get('group') || '';
        if (!state.group) {
            state.group = state.groups.some(function (g) { return g.key === requested; })
                ? requested : (state.groups[0] ? state.groups[0].key : 'sales');
        }
        renderTabs();
    }

    function applyList(data) {
        state.hasMore = !!data.has_more;
        list.innerHTML = data.items.length
            ? data.items.map(renderItem).join('')
            : window.ui.empty('مفيش إشعارات في المجموعة دي');
        document.getElementById('notifPageNo').textContent = 'صفحة ' + data.page;
        document.getElementById('notifPrev').disabled = data.page <= 1;
        document.getElementById('notifNext').disabled = !data.has_more;
        pager.style.display = (data.page > 1 || data.has_more) ? 'flex' : 'none';
    }

    function loadSummary() {
        return window.apiFetch('notifications_api.php?part=summary').then(function (res) {
            applySummary(res.data || {});
        });
    }

    function loadList() {
        list.innerHTML = window.ui.alert('info', 'جاري تحميل الإشعارات...');
        return window.apiFetch('notifications_api.php?part=list&group=' + encodeURIComponent(state.group) + '&page=' + state.page)
            .then(function (res) {
                applyList(res.data || {});
            });
    }

    function loadInit() {
        var requested = new URLSearchParams(location.search).get('group') || '';
        var url = 'notifications_api.php?part=init' + (requested ? '&group=' + encodeURIComponent(requested) : '');
        return window.apiFetch(url).then(function (res) {
            var data = res.data || {};
            state.group = data.group || requested || 'sales';
            applySummary(data.summary || {});
            applyList(data.list || {});
        });
    }

    function showError(err) {
        list.innerHTML = window.ui.alert('neg', (err && err.error) || 'حصل خطأ في تحميل الإشعارات');
    }

    tabs.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-group]');
        if (!btn) return;
        state.group = btn.dataset.group;
        state.page = 1;
        history.replaceState(null, '', 'notifications.php?group=' + encodeURIComponent(state.group));
        renderTabs();
        loadList().catch(showError);
    });

    markBtn.addEventListener('click', function () {
        markBtn.disabled = true;
        window.apiFetch('notifications_api.php?part=mark_read', {
            method: 'POST', body: {group: state.group}
        }).then(function () {
            return loadInit();
        }).catch(showError).finally(function () { markBtn.disabled = false; });
    });

    document.getElementById('notifPrev').addEventListener('click', function () {
        if (state.page <= 1) return;
        state.page--;
        loadList().catch(showError);
    });
    document.getElementById('notifNext').addEventListener('click', function () {
        if (!state.hasMore) return;
        state.page++;
        loadList().catch(showError);
    });

    loadInit().catch(showError);
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
