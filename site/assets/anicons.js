/**
 * site/assets/anicons.js — حكايات أيقونات الموقع (بريف ٣٦)
 *
 * بيحوّل أيقونات الموقع المحددة من خط Tabler إلى SVG تفاعلي داخلي.
 * - الأيقونة <i> بتفضل مكانها عشان ستايل الـCSS يفضل شغال.
 * - كلاس has-aicon بيتضاف للـ<i> وبيخفي حرف الخط عبر .ti.has-aicon::before { content: none; }.
 * - MutationObserver مجمّع بـ RAF بيعالج أي أيقونة جديدة (جداول، مودال، AJAX).
 * - تجاهل تام لأيقونات السايدبار (.sidebar-nav) والأيقونات الدوارة (ti-spin, ti-loader).
 * - إدارة كلاس ic-finish لإنهاء دورة الحركة بنعومة عند مغادرة الماوس.
 */
(function (root) {
    'use strict';

    var ICONS = {
        'bell': '<svg class="aicon aicon-bell" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-bell" d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6"/><path class="aicon-part-clapper" d="M9 17v1a3 3 0 0 0 6 0v-1"/></svg>',
        'trash': '<svg class="aicon aicon-trash" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g class="aicon-part-lid"><path d="M4 7h16"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/></g><g class="aicon-part-can"><path d="M10 11v6"/><path d="M14 11v6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/></g></svg>',
        'check': '<svg class="aicon aicon-check" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-check" stroke-dasharray="24" stroke-dashoffset="0" d="M5 12l5 5l10 -10"/></svg>',
        'circle-check': '<svg class="aicon aicon-circle-check" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-circle" d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path class="aicon-part-check" stroke-dasharray="16" stroke-dashoffset="0" d="M9 12l2 2l4 -4"/></svg>',
        'plus': '<svg class="aicon aicon-plus" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-cross" d="M12 5v14M5 12h14"/></svg>',
        'x': '<svg class="aicon aicon-x" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-x" d="M18 6l-12 12M6 6l12 12"/></svg>',
        'download': '<svg class="aicon aicon-download" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-tray" d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path class="aicon-part-arrow" d="M7 11l5 5l5 -5M12 4v12"/></svg>',
        'upload': '<svg class="aicon aicon-upload" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-tray" d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path class="aicon-part-arrow" d="M7 9l5 -5l5 5M12 4v12"/></svg>',
        'refresh': '<svg class="aicon aicon-refresh" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-arrows" d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -5v5h5M4 13a8.1 8.1 0 0 0 15.5 2m.5 5v-5h-5"/></svg>',
        'rotate': '<svg class="aicon aicon-rotate" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-arrows" d="M19.95 11a8 8 0 1 0 -.5 4m.5 5v-5h-5"/></svg>',
        'search': '<svg class="aicon aicon-search" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g class="aicon-part-lens"><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0"/><path d="M21 21l-6 -6"/></g></svg>',
        'filter': '<svg class="aicon aicon-filter" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-funnel" d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.344v-2.228z"/><circle class="aicon-part-drop" cx="12" cy="19" r="1" fill="currentColor"/></svg>',
        'edit': '<svg class="aicon aicon-edit" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-paper" d="M9 7h-3a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-3"/><path class="aicon-part-pencil" d="M9 15h3l8.5 -8.5a1.5 1.5 0 0 0 -3 -3l-8.5 8.5v3"/><line class="aicon-part-pencil-line" x1="16" y1="5" x2="19" y2="8"/></svg>',
        'pencil': '<svg class="aicon aicon-pencil" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-pencil" d="M4 20h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4"/><path class="aicon-part-pencil-cap" d="M13.5 6.5l4 4"/></svg>',
        'lock': '<svg class="aicon aicon-lock" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect class="aicon-part-body" x="5" y="11" width="14" height="10" rx="2"/><circle class="aicon-part-keyhole" cx="12" cy="16" r="1"/><path class="aicon-part-shackle" d="M8 11v-4a4 4 0 0 1 8 0v4"/></svg>',
        'camera': '<svg class="aicon aicon-camera" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-body" d="M5 7h1a2 2 0 0 0 2 -2a1 1 0 0 1 1 -1h6a1 1 0 0 1 1 1a2 2 0 0 0 2 2h1a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2"/><circle class="aicon-part-lens" cx="12" cy="13" r="3"/><circle class="aicon-part-flash" cx="17" cy="8.5" r="1" fill="currentColor"/></svg>',
        'calendar': '<svg class="aicon aicon-calendar" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-frame" d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12zM16 3v4M8 3v4M4 11h16"/><g class="aicon-part-page"><path d="M7 14h.013M10.01 14h.005M13.01 14h.005M16.015 14h.005M13.015 17h.005M7.01 17h.005M10.01 17h.005"/></g></svg>',
        'alert-triangle': '<svg class="aicon aicon-alert-triangle" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-frame" d="M10.24 3.957l-8.422 14.06a1.989 1.989 0 0 0 1.7 2.983h16.845a1.989 1.989 0 0 0 1.7 -2.983l-8.423 -14.06a1.989 1.989 0 0 0 -3.4 0z"/><g class="aicon-part-exclamation"><path d="M12 9v4"/><path d="M12 17h.01"/></g></svg>',
        'send': '<svg class="aicon aicon-send" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g class="aicon-part-plane"><path d="M10 14l11 -11"/><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5"/></g></svg>',
        'file-spreadsheet': '<svg class="aicon aicon-file-spreadsheet" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-frame" d="M14 3v4a1 1 0 0 0 1 1h4"/><path class="aicon-part-frame" d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path class="aicon-part-line1" stroke-dasharray="8" stroke-dashoffset="0" d="M8 11h8"/><path class="aicon-part-line2" stroke-dasharray="8" stroke-dashoffset="0" d="M8 15h8"/><path class="aicon-part-line3" stroke-dasharray="6" stroke-dashoffset="0" d="M11 11v6"/></svg>',
        'file-invoice': '<svg class="aicon aicon-file-invoice" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-frame" d="M14 3v4a1 1 0 0 0 1 1h4"/><path class="aicon-part-frame" d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path class="aicon-part-line1" stroke-dasharray="4" stroke-dashoffset="0" d="M9 7h1"/><path class="aicon-part-line2" stroke-dasharray="8" stroke-dashoffset="0" d="M9 13h6"/><path class="aicon-part-line3" stroke-dasharray="4" stroke-dashoffset="0" d="M13 17h2"/></svg>',
        'link': '<svg class="aicon aicon-link" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-link1" d="M9 15l6 -6"/><path class="aicon-part-link2" d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"/><path class="aicon-part-link3" d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463"/></svg>',
        'chevron-down': '<svg class="aicon aicon-chevron-down" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="aicon-part-chevron" d="M6 9l6 6l6 -6"/></svg>'
    };

    /**
     * فحص وتحديث عنصر أيقونة منفرد.
     * @param {Element} el
     * @returns {boolean} true لو اتحطت الأيقونة
     */
    function upgradeIcon(el) {
        if (!el || !el.classList) return false;
        if (el.classList.contains('has-aicon')) return false;
        if (el.classList.contains('ti-spin') || el.classList.contains('ti-loader')) return false;
        if (el.closest && el.closest('.sidebar-nav')) return false;

        var classes = el.className || '';
        var match = classes.match(/\bti-([a-z0-9-]+)\b/g);
        if (!match) return false;

        for (var i = 0; i < match.length; i++) {
            var rawName = match[i].replace(/^ti-/, '');
            if (rawName === 'spin' || rawName === 'loader') continue;
            if (Object.prototype.hasOwnProperty.call(ICONS, rawName)) {
                el.classList.add('has-aicon');
                el.innerHTML = ICONS[rawName];
                return true;
            }
        }
        return false;
    }

    /**
     * مسح شجرة الـ DOM وتحديث الأيقونات المطابقة.
     * @param {Element|Document} rootNode
     */
    function processAll(rootNode) {
        var base = rootNode || (typeof document !== 'undefined' ? document : null);
        if (!base || typeof base.querySelectorAll !== 'function') return;

        var elements = base.querySelectorAll('i[class*="ti-"]:not(.has-aicon)');
        for (var i = 0; i < elements.length; i++) {
            upgradeIcon(elements[i]);
        }
    }

    // إعداد الـ DOM ومستمعات الأحداث في بيئة المتصفح فقط
    if (typeof window !== 'undefined' && typeof document !== 'undefined') {
        var rafPending = false;
        function scheduleProcess() {
            if (rafPending) return;
            rafPending = true;
            window.requestAnimationFrame(function () {
                rafPending = false;
                processAll(document);
            });
        }

        // تشغيل أولي فور جاهزية الـ DOM
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                processAll(document);
                initBellOnce();
            });
        } else {
            processAll(document);
            initBellOnce();
        }

        // مراقب التغييرات: يلتقط الأيقونات اللي بتتبني لاحقاً بالجافاسكربت
        if (typeof MutationObserver !== 'undefined' && document.body) {
            var observer = new MutationObserver(function () {
                scheduleProcess();
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }

        // ===== إدارة حالة ic-finish (كمّل اللفة) =====
        function getInteractiveContainer(node) {
            if (!node || !node.closest) return null;
            return node.closest('.nav-item, a, button, .btn, label, th, [role="button"]');
        }

        function hasAnimatedIcon(container) {
            if (!container) return false;
            if (container.classList.contains('nav-item')) return true;
            return !!container.querySelector('.aicon');
        }

        function setIcFinish(item) {
            if (!item) return;
            item.classList.add('ic-finish');
            if (item._icTimer) clearTimeout(item._icTimer);
            item._icTimer = setTimeout(function () {
                item.classList.remove('ic-finish');
                item._icTimer = null;
            }, 2000);
        }

        function clearIcFinish(item) {
            if (!item) return;
            item.classList.remove('ic-finish');
            if (item._icTimer) {
                clearTimeout(item._icTimer);
                item._icTimer = null;
            }
        }

        // خروج الماوس / فقدان التركيز: نبدأ إنهاء اللفة
        document.addEventListener('mouseleave', function (e) {
            var item = getInteractiveContainer(e.target);
            if (item && hasAnimatedIcon(item)) {
                setIcFinish(item);
            }
        }, true);

        document.addEventListener('blur', function (e) {
            var item = getInteractiveContainer(e.target);
            if (item && hasAnimatedIcon(item)) {
                setIcFinish(item);
            }
        }, true);

        // عودة الماوس أو التركيز أثناء ic-finish: استمرار سلس
        document.addEventListener('mouseenter', function (e) {
            var item = getInteractiveContainer(e.target);
            if (item && item.classList.contains('ic-finish')) {
                clearIcFinish(item);
            }
        }, true);

        document.addEventListener('focusin', function (e) {
            var item = getInteractiveContainer(e.target);
            if (item && item.classList.contains('ic-finish')) {
                clearIcFinish(item);
            }
        }, true);

        // انتهاء دورة الحركة: تنظيف ic-finish
        document.addEventListener('animationiteration', function (e) {
            var item = e.target && e.target.closest ? e.target.closest('.ic-finish') : null;
            if (item) {
                clearIcFinish(item);
            }
        }, true);

        document.addEventListener('animationend', function (e) {
            var item = e.target && e.target.closest ? e.target.closest('.ic-finish') : null;
            if (item) {
                clearIcFinish(item);
            }
        }, true);

        // ===== استثناء جرس الإشعارات (خطوة ٣٦٠٢) =====
        function initBellOnce() {
            setTimeout(function () {
                var bellBtn = document.querySelector('.notif-bell');
                if (!bellBtn) return;
                var badge = bellBtn.querySelector('.notif-badge');
                if (badge) {
                    var bellSvg = bellBtn.querySelector('.aicon-bell');
                    if (bellSvg) {
                        bellSvg.classList.add('aicon-ring-once');
                        setTimeout(function () {
                            bellSvg.classList.remove('aicon-ring-once');
                        }, 1000);
                    }
                }
            }, 1000);
        }
    }

    // تصدير للاختبار البرمجي
    var fgAnicons = {
        ICONS: ICONS,
        process: processAll,
        upgradeIcon: upgradeIcon
    };

    if (typeof window !== 'undefined') {
        window.fgAnicons = fgAnicons;
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = fgAnicons;
    }
})(typeof window !== 'undefined' ? window : this);

