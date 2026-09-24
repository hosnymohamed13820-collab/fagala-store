/**
 * login_fx.js — شخصيات الأدوات المكتبية المتحركة في صفحة الدخول (بريف ٦١)
 * نظام الفجالة ستور المالي
 *
 * ٧ كاركترز لطيفة بتتحرك بـ parallax مع الماوس، وبتبص عليه.
 * بعد ٩٠ ثانية خمول بيبدأوا مشوار دائري ناعم في الخلفية.
 * أداء خفيف ومصمم يشتغل بسلاسة حتى على الأجهزة القديمة.
 */
(function () {
    'use strict';

    // التحقق من دعم Reduced Motion
    const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // مهلة الخمول: ٩٠ ثانية افتراضياً، أو من خلال باراميتر ?lfx_idle=5 للتجربة السريعة
    let idleTimeoutMs = 90000;
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const customIdle = parseInt(urlParams.get('lfx_idle'), 10);
        if (customIdle && customIdle > 0) {
            idleTimeoutMs = customIdle * 1000;
        }
    } catch (e) {}

    // بيانات وتصاميم الـ ٧ شخصيات (SVG متكامل بنمط Flat كيوت مع عيون قابلة للتحريك)
    const CHARACTERS = [
        {
            id: 'pencil',
            name: 'قلم رصاص',
            width: 76,
            height: 104,
            depth: 22,
            pos: { top: '12%', right: '14%' },
            posMobile: { top: '6%', right: '4%' },
            svg: `
            <svg viewBox="0 0 80 110" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- الممحاة والحزام الفضي -->
                <path d="M26 18C26 11.3726 31.3726 6 38 6H42C48.6274 6 54 11.3726 54 18V24H26V18Z" fill="#ff9aa2"/>
                <rect x="25" y="24" width="30" height="7" rx="1.5" fill="#cbd5e1" stroke="#94a3b8" stroke-width="1"/>
                <!-- جسم القلم الخشبي -->
                <path d="M25 31H55V76H25V31Z" fill="#fbc02d"/>
                <path d="M35 31H45V76H35V31Z" fill="#f9a825"/>
                <!-- سن القلم الخشبي والرصاص -->
                <path d="M25 76L40 98L55 76H25Z" fill="#ffecb3"/>
                <path d="M35 90.7L40 98L45 90.7H35Z" fill="#374151"/>
                <!-- ملامح الوجه -->
                <!-- العين اليمنى -->
                <circle cx="34" cy="50" r="4.5" fill="#ffffff" stroke="#1f2937" stroke-width="1.2"/>
                <circle class="lfx-eye-pupil" cx="34" cy="50" r="2.2" fill="#1f2937"/>
                <!-- العين اليسرى -->
                <circle cx="46" cy="50" r="4.5" fill="#ffffff" stroke="#1f2937" stroke-width="1.2"/>
                <circle class="lfx-eye-pupil" cx="46" cy="50" r="2.2" fill="#1f2937"/>
                <!-- الخدود -->
                <ellipse cx="29" cy="54" rx="2.5" ry="1.5" fill="#f87171" opacity="0.6"/>
                <ellipse cx="51" cy="54" rx="2.5" ry="1.5" fill="#f87171" opacity="0.6"/>
                <!-- ابتسامة -->
                <path d="M37 57C38.5 59.5 41.5 59.5 43 57" stroke="#1f2937" stroke-width="1.5" stroke-linecap="round"/>
            </svg>`
        },
        {
            id: 'eraser',
            name: 'أستيكة',
            width: 82,
            height: 82,
            depth: 18,
            pos: { top: '38%', right: '8%' },
            posMobile: { top: '24%', right: '3%' },
            svg: `
            <svg viewBox="0 0 90 90" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- جزء الممحاة الوردي المشطوف -->
                <rect x="12" y="14" width="66" height="62" rx="14" transform="rotate(-8 45 45)" fill="#ffb7b2"/>
                <!-- غلاف الأستيكة الكرتوني الأزرق -->
                <rect x="12" y="38" width="66" height="38" rx="10" transform="rotate(-8 45 45)" fill="#3b82f6"/>
                <rect x="12" y="44" width="66" height="7" transform="rotate(-8 45 45)" fill="#60a5fa"/>
                <!-- ملامح الوجه -->
                <g transform="rotate(-8 45 45)">
                    <circle cx="36" cy="30" r="4.5" fill="#ffffff" stroke="#1e293b" stroke-width="1.2"/>
                    <circle class="lfx-eye-pupil" cx="36" cy="30" r="2.2" fill="#1e293b"/>
                    <circle cx="52" cy="30" r="4.5" fill="#ffffff" stroke="#1e293b" stroke-width="1.2"/>
                    <circle class="lfx-eye-pupil" cx="52" cy="30" r="2.2" fill="#1e293b"/>
                    <ellipse cx="30" cy="33" rx="2.5" ry="1.5" fill="#f43f5e" opacity="0.6"/>
                    <ellipse cx="58" cy="33" rx="2.5" ry="1.5" fill="#f43f5e" opacity="0.6"/>
                    <path d="M41 34C42.5 36.5 45.5 36.5 47 34" stroke="#1e293b" stroke-width="1.5" stroke-linecap="round"/>
                </g>
            </svg>`
        },
        {
            id: 'pen',
            name: 'قلم جاف',
            width: 74,
            height: 106,
            depth: 26,
            pos: { top: '14%', left: '13%' },
            posMobile: { top: '6%', left: '4%' },
            svg: `
            <svg viewBox="0 0 80 110" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- غطاء وكليبسة القلم الجاف -->
                <rect x="29" y="8" width="22" height="12" rx="3" fill="#1e40af"/>
                <path d="M51 14H56V44H51V14Z" rx="2" fill="#93c5fd" stroke="#1e40af" stroke-width="1"/>
                <!-- جسم القلم -->
                <path d="M29 20H51V78H29V20Z" fill="#2563eb"/>
                <path d="M37 20H43V78H37V20Z" fill="#3b82f6"/>
                <!-- سن القلم المعدني والكروي -->
                <path d="M29 78L40 98L51 78H29Z" fill="#94a3b8"/>
                <circle cx="40" cy="99" r="2" fill="#1e293b"/>
                <!-- ملامح الوجه -->
                <circle cx="35" cy="48" r="4.5" fill="#ffffff" stroke="#0f172a" stroke-width="1.2"/>
                <circle class="lfx-eye-pupil" cx="35" cy="48" r="2.2" fill="#0f172a"/>
                <circle cx="45" cy="48" r="4.5" fill="#ffffff" stroke="#0f172a" stroke-width="1.2"/>
                <circle class="lfx-eye-pupil" cx="45" cy="48" r="2.2" fill="#0f172a"/>
                <ellipse cx="31" cy="51" rx="2" ry="1.2" fill="#f43f5e" opacity="0.6"/>
                <ellipse cx="49" cy="51" rx="2" ry="1.2" fill="#f43f5e" opacity="0.6"/>
                <path d="M38 53C39.2 55.2 41 55.2 42 53" stroke="#0f172a" stroke-width="1.4" stroke-linecap="round"/>
            </svg>`
        },
        {
            id: 'ruler',
            name: 'مسطرة',
            width: 72,
            height: 110,
            depth: 28,
            pos: { top: '64%', right: '11%' },
            posMobile: { top: '78%', right: '5%' },
            svg: `
            <svg viewBox="0 0 80 110" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- جسم المسطرة بزوايا مائلة خفيفة -->
                <rect x="24" y="8" width="32" height="94" rx="6" transform="rotate(6 40 55)" fill="#fef08a" stroke="#facc15" stroke-width="1.5"/>
                <!-- علامات التدريج -->
                <g transform="rotate(6 40 55)" stroke="#ca8a04" stroke-width="1.2" stroke-linecap="round">
                    <line x1="24" y1="16" x2="33" y2="16"/>
                    <line x1="24" y1="24" x2="29" y2="24"/>
                    <line x1="24" y1="32" x2="33" y2="32"/>
                    <line x1="24" y1="40" x2="29" y2="40"/>
                    <line x1="24" y1="72" x2="33" y2="72"/>
                    <line x1="24" y1="80" x2="29" y2="80"/>
                    <line x1="24" y1="88" x2="33" y2="88"/>
                    <line x1="24" y1="96" x2="29" y2="96"/>
                </g>
                <!-- ملامح الوجه في منتصف المسطرة -->
                <g transform="rotate(6 40 55)">
                    <circle cx="35" cy="54" r="4.2" fill="#ffffff" stroke="#1f2937" stroke-width="1.2"/>
                    <circle class="lfx-eye-pupil" cx="35" cy="54" r="2.1" fill="#1f2937"/>
                    <circle cx="46" cy="54" r="4.2" fill="#ffffff" stroke="#1f2937" stroke-width="1.2"/>
                    <circle class="lfx-eye-pupil" cx="46" cy="54" r="2.1" fill="#1f2937"/>
                    <ellipse cx="31" cy="57" rx="2" ry="1.2" fill="#fb7185" opacity="0.6"/>
                    <ellipse cx="50" cy="57" rx="2" ry="1.2" fill="#fb7185" opacity="0.6"/>
                    <path d="M38 59C39.5 61 41.5 61 43 59" stroke="#1f2937" stroke-width="1.4" stroke-linecap="round"/>
                </g>
            </svg>`
        },
        {
            id: 'sharpener',
            name: 'برّاية',
            width: 84,
            height: 78,
            depth: 14,
            pos: { top: '40%', left: '8%' },
            posMobile: { top: '25%', left: '3%' },
            svg: `
            <svg viewBox="0 0 90 85" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- جسم البرّاية الانسيابي المائل -->
                <path d="M16 20C16 14.4772 20.4772 10 26 10H64C69.5228 10 74 14.4772 74 20V65C74 70.5228 69.5228 75 64 75H26C20.4772 75 16 70.5228 16 65V20Z" fill="#38bdf8"/>
                <!-- انحناءة وسط البرّاية لتسهيل المسك -->
                <path d="M16 35C20 38 20 47 16 50V35Z" fill="#0284c7"/>
                <path d="M74 35C70 38 70 47 74 50V35Z" fill="#0284c7"/>
                <!-- الشفرة المعدنية والمسمار -->
                <rect x="34" y="16" width="22" height="42" rx="2" fill="#e2e8f0" stroke="#94a3b8" stroke-width="1.2"/>
                <circle cx="45" cy="24" r="3" fill="#64748b"/>
                <line x1="43" y1="24" x2="47" y2="24" stroke="#e2e8f0" stroke-width="1"/>
                <!-- فتحة سن القلم البيضاوية -->
                <ellipse cx="45" cy="67" rx="10" ry="4" fill="#0369a1"/>
                <!-- ملامح الوجه على الشفرة اللطيفة -->
                <circle cx="40" cy="40" r="3.8" fill="#ffffff" stroke="#0f172a" stroke-width="1.1"/>
                <circle class="lfx-eye-pupil" cx="40" cy="40" r="1.9" fill="#0f172a"/>
                <circle cx="50" cy="40" r="3.8" fill="#ffffff" stroke="#0f172a" stroke-width="1.1"/>
                <circle class="lfx-eye-pupil" cx="50" cy="40" r="1.9" fill="#0f172a"/>
                <ellipse cx="37" cy="43" rx="1.8" ry="1" fill="#f43f5e" opacity="0.6"/>
                <ellipse cx="53" cy="43" rx="1.8" ry="1" fill="#f43f5e" opacity="0.6"/>
                <path d="M43 45C44 46.5 46 46.5 47 45" stroke="#0f172a" stroke-width="1.3" stroke-linecap="round"/>
            </svg>`
        },
        {
            id: 'paper',
            name: 'ورقة نوت',
            width: 86,
            height: 86,
            depth: 20,
            pos: { top: '65%', left: '12%' },
            posMobile: { top: '78%', left: '5%' },
            svg: `
            <svg viewBox="0 0 90 90" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- الورقة مع ثنية صغيرة في الزاوية العلوية -->
                <path d="M15 15H58L75 32V75H15V15Z" fill="#fef9c3" stroke="#fde047" stroke-width="1.5"/>
                <!-- الثنية المطوية -->
                <path d="M58 15V32H75L58 15Z" fill="#fef08a" stroke="#fde047" stroke-width="1.2"/>
                <!-- خطوط أسطر خفيفة -->
                <line x1="24" y1="36" x2="52" y2="36" stroke="#fde047" stroke-width="1.5" stroke-linecap="round"/>
                <line x1="24" y1="65" x2="66" y2="65" stroke="#fde047" stroke-width="1.5" stroke-linecap="round"/>
                <!-- ملامح الوجه الكيوت -->
                <circle cx="38" cy="48" r="4.5" fill="#ffffff" stroke="#1f2937" stroke-width="1.2"/>
                <circle class="lfx-eye-pupil" cx="38" cy="48" r="2.2" fill="#1f2937"/>
                <circle cx="52" cy="48" r="4.5" fill="#ffffff" stroke="#1f2937" stroke-width="1.2"/>
                <circle class="lfx-eye-pupil" cx="52" cy="48" r="2.2" fill="#1f2937"/>
                <ellipse cx="32" cy="51" rx="2.5" ry="1.5" fill="#fb7185" opacity="0.6"/>
                <ellipse cx="58" cy="51" rx="2.5" ry="1.5" fill="#fb7185" opacity="0.6"/>
                <path d="M42 53C43.5 55.5 46.5 55.5 48 53" stroke="#1f2937" stroke-width="1.5" stroke-linecap="round"/>
            </svg>`
        },
        {
            id: 'book',
            name: 'كتاب',
            width: 92,
            height: 84,
            depth: 16,
            pos: { top: '8%', left: '46%' },
            posMobile: { top: '3%', left: '38%' },
            svg: `
            <svg viewBox="0 0 95 90" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- غلاف الكتاب وصفحاته المنحنية -->
                <path d="M12 20C12 16 16 14 20 14H75C79 14 83 16 83 20V68C83 72 79 74 75 74H20C16 74 12 72 12 68V20Z" fill="#334155"/>
                <!-- كعب وصفحات الكتاب البيضاء -->
                <rect x="18" y="20" width="62" height="48" rx="4" fill="#f8fafc" stroke="#cbd5e1" stroke-width="1.2"/>
                <!-- شريطة فاصل الكتاب الحمراء -->
                <path d="M50 14V50L46 46L42 50V14H50Z" fill="#ef4444"/>
                <!-- ملامح الوجه على صفحات الكتاب -->
                <circle cx="38" cy="40" r="4.5" fill="#ffffff" stroke="#0f172a" stroke-width="1.2"/>
                <circle class="lfx-eye-pupil" cx="38" cy="40" r="2.2" fill="#0f172a"/>
                <circle cx="58" cy="40" r="4.5" fill="#ffffff" stroke="#0f172a" stroke-width="1.2"/>
                <circle class="lfx-eye-pupil" cx="58" cy="40" r="2.2" fill="#0f172a"/>
                <ellipse cx="32" cy="43" rx="2.5" ry="1.5" fill="#f43f5e" opacity="0.6"/>
                <ellipse cx="64" cy="43" rx="2.5" ry="1.5" fill="#f43f5e" opacity="0.6"/>
                <path d="M45 44C46.5 47 49.5 47 51 44" stroke="#0f172a" stroke-width="1.5" stroke-linecap="round"/>
            </svg>`
        }
    ];

    // مسارات الخمول (Idle Waypoints): نقاط نسبية (0 to 1) تطوف حول الشاشة دون الاصطدام بالكارد في الوسط
    const IDLE_WAYPOINTS = [
        { x: 0.12, y: 0.15 },
        { x: 0.28, y: 0.08 },
        { x: 0.50, y: 0.06 },
        { x: 0.72, y: 0.08 },
        { x: 0.86, y: 0.18 },
        { x: 0.90, y: 0.42 },
        { x: 0.88, y: 0.68 },
        { x: 0.76, y: 0.88 },
        { x: 0.50, y: 0.92 },
        { x: 0.24, y: 0.88 },
        { x: 0.10, y: 0.70 },
        { x: 0.08, y: 0.40 }
    ];

    // حالة التطبيق والمتغيرات العامة
    let containerEl = null;
    let loginCardEl = null;
    let cardRect = null;
    let charNodes = [];

    let mouseX = -9999;
    let mouseY = -9999;
    let isMouseInsideCard = false;
    let isIdle = false;
    let idleTimer = null;
    let lastActivityTime = Date.now();
    let idleStartTime = 0;
    let animFrameId = null;

    // تهيئة وبناء الشخصيات داخل الحاوية
    function init() {
        loginCardEl = document.querySelector('.login-card');
        if (!loginCardEl) return;

        // إنشاء أو العثور على الحاوية
        containerEl = document.querySelector('.lfx');
        if (!containerEl) {
            containerEl = document.createElement('div');
            containerEl.className = 'lfx';
            containerEl.setAttribute('aria-hidden', 'true');
            loginCardEl.parentNode.insertBefore(containerEl, loginCardEl);
        }

        const isMobile = window.innerWidth <= 768;

        // حقن الشخصيات
        charNodes = CHARACTERS.map((charDef, index) => {
            const el = document.createElement('div');
            el.className = 'lfx-char lfx-char-' + charDef.id;
            el.id = 'lfxChar_' + charDef.id;

            const pos = (isMobile && charDef.posMobile) ? charDef.posMobile : charDef.pos;
            if (pos.top)    el.style.top = pos.top;
            if (pos.bottom) el.style.bottom = pos.bottom;
            if (pos.left)   el.style.left = pos.left;
            if (pos.right)  el.style.right = pos.right;

            el.style.width = charDef.width + 'px';
            el.style.height = charDef.height + 'px';

            el.innerHTML = charDef.svg;
            containerEl.appendChild(el);

            return {
                def: charDef,
                el: el,
                pupils: el.querySelectorAll('.lfx-eye-pupil'),
                curX: 0,
                curY: 0,
                curPupilX: 0,
                curPupilY: 0,
                curRot: 0,
                // إزاحة زمنية مختلفة لكل شخصية في مشوار الخمول
                idlePhase: index * (1.0 / CHARACTERS.length),
                // سرعة مختلفة طفيفة (بين ٠.٩ و ١.١)
                idleSpeedFactor: 0.9 + (index % 3) * 0.1
            };
        });

        updateCardBounds();
        window.addEventListener('resize', updateCardBounds, { passive: true });

        // الاستماع لأي تفاعل للمستخدم لتصفير مؤقت الخمول
        const resetEvents = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'];
        resetEvents.forEach(evt => {
            window.addEventListener(evt, onUserActivity, { passive: true });
        });

        // حركة الماوس للـ Parallax
        window.addEventListener('mousemove', onMouseMove, { passive: true });
        window.addEventListener('mouseleave', onMouseLeave, { passive: true });

        // إيقاف واستئناف الحركة لما التاب يختفي
        document.addEventListener('visibilitychange', onVisibilityChange);

        resetIdleTimer();

        // بدء حلقة الحركة إذا لم يكن المستخدم يفضل تقليل الحركة
        if (!prefersReducedMotion) {
            animFrameId = requestAnimationFrame(renderLoop);
        }
    }

    function updateCardBounds() {
        if (loginCardEl) {
            cardRect = loginCardEl.getBoundingClientRect();
        }
    }

    function onMouseMove(e) {
        mouseX = e.clientX;
        mouseY = e.clientY;

        if (cardRect) {
            isMouseInsideCard = (
                mouseX >= cardRect.left &&
                mouseX <= cardRect.right &&
                mouseY >= cardRect.top &&
                mouseY <= cardRect.bottom
            );
        } else {
            isMouseInsideCard = false;
        }
    }

    function onMouseLeave() {
        mouseX = -9999;
        mouseY = -9999;
        isMouseInsideCard = false;
    }

    function onUserActivity() {
        lastActivityTime = Date.now();
        if (isIdle) {
            stopIdleMode();
        }
        resetIdleTimer();
    }

    function resetIdleTimer() {
        if (idleTimer) clearTimeout(idleTimer);
        idleTimer = setTimeout(startIdleMode, idleTimeoutMs);
    }

    function startIdleMode() {
        isIdle = true;
        idleStartTime = performance.now();
        if (containerEl) {
            containerEl.classList.add('lfx-is-idle');
        }
    }

    function stopIdleMode() {
        isIdle = false;
        if (containerEl) {
            containerEl.classList.remove('lfx-is-idle');
        }
    }

    function onVisibilityChange() {
        if (document.hidden) {
            if (animFrameId) {
                cancelAnimationFrame(animFrameId);
                animFrameId = null;
            }
        } else {
            updateCardBounds();
            lastActivityTime = Date.now();
            if (!animFrameId && !prefersReducedMotion) {
                animFrameId = requestAnimationFrame(renderLoop);
            }
        }
    }

    // استيفاء دائري بين نقاط المسار (Catmull-Rom أو Linear Closed Spline)
    function getIdlePosition(t) {
        const total = IDLE_WAYPOINTS.length;
        const normT = ((t % 1) + 1) % 1; // 0 to 1
        const floatIdx = normT * total;
        const i0 = Math.floor(floatIdx) % total;
        const i1 = (i0 + 1) % total;
        const fraction = floatIdx - Math.floor(floatIdx);

        // تنعيم الحركة (Smoothstep)
        const smooth = fraction * fraction * (3 - 2 * fraction);

        const p0 = IDLE_WAYPOINTS[i0];
        const p1 = IDLE_WAYPOINTS[i1];

        const x = p0.x + (p1.x - p0.x) * smooth;
        const y = p0.y + (p1.y - p0.y) * smooth;

        // حساب زاوية الاتجاه
        const dx = p1.x - p0.x;
        const dy = p1.y - p0.y;
        const angle = Math.atan2(dy, dx);

        return { x, y, angle };
    }

    // حلقة الحركة الرئيسية (Single requestAnimationFrame loop)
    function renderLoop(timestamp) {
        if (document.hidden || prefersReducedMotion) return;

        const winW = window.innerWidth || document.documentElement.clientWidth;
        const winH = window.innerHeight || document.documentElement.clientHeight;
        const centerX = winW / 2;
        const centerY = winH / 2;

        const hasValidMouse = (mouseX > 0 && mouseY > 0 && !isMouseInsideCard);

        charNodes.forEach(node => {
            let targetX = 0;
            let targetY = 0;
            let targetPupilX = 0;
            let targetPupilY = 0;
            let targetRot = 0;

            if (isIdle) {
                // وضع الخمول: مسار محسوب مسبقاً يطوف حول الكارد لمدة ٥ دقائق (٣٠٠ ثانية)
                const elapsedSec = (timestamp - idleStartTime) / 1000;
                const loopDuration = 300; // ٥ دقايق للدورة الكاملة
                const progress = (elapsedSec * node.idleSpeedFactor / loopDuration) + node.idlePhase;

                const pos = getIdlePosition(progress);
                const targetWorldX = pos.x * winW;
                const targetWorldY = pos.y * winH;

                // الموضع الابتدائي للكاركتر في DOM
                const baseRect = node.el.getBoundingClientRect();
                const defaultX = baseRect.left - node.curX;
                const defaultY = baseRect.top - node.curY;

                targetX = targetWorldX - defaultX - (node.def.width / 2);
                targetY = targetWorldY - defaultY - (node.def.height / 2);

                // إمالة طفيفة في اتجاه السير
                targetRot = (pos.angle * 180 / Math.PI) * 0.15;

                // العيون تنظر قليلاً للأمام في اتجاه السير
                targetPupilX = Math.cos(pos.angle) * 1.8;
                targetPupilY = Math.sin(pos.angle) * 1.8;

            } else if (hasValidMouse) {
                // وضع الـ Parallax وتتبع الماوس
                const relX = (mouseX - centerX) / centerX; // -1 to 1
                const relY = (mouseY - centerY) / centerY; // -1 to 1

                targetX = relX * node.def.depth;
                targetY = relY * node.def.depth;

                // مركز الكاركتر الفعلي لحساب زاوية نظر العين للماوس
                const rect = node.el.getBoundingClientRect();
                const charMidX = rect.left + rect.width / 2;
                const charMidY = rect.top + rect.height / 2;

                const dx = mouseX - charMidX;
                const dy = mouseY - charMidY;
                const dist = Math.hypot(dx, dy);

                if (dist > 1) {
                    const angle = Math.atan2(dy, dx);
                    // أقصى إزاحة للبؤبؤ داخل العين ٢.٣ بكسل
                    const pupilDist = Math.min(2.3, dist / 120 * 2.3);
                    targetPupilX = Math.cos(angle) * pupilDist;
                    targetPupilY = Math.sin(angle) * pupilDist;
                }
            }

            // حركة ناعمة بـ lerp
            const easeFactor = isIdle ? 0.04 : 0.08;
            node.curX += (targetX - node.curX) * easeFactor;
            node.curY += (targetY - node.curY) * easeFactor;
            node.curRot += (targetRot - node.curRot) * easeFactor;

            node.curPupilX += (targetPupilX - node.curPupilX) * 0.12;
            node.curPupilY += (targetPupilY - node.curPupilY) * 0.12;

            // تطبيق الـ transform على الكاركتر
            if (isIdle) {
                node.el.style.transform = `translate3d(${node.curX.toFixed(2)}px, ${node.curY.toFixed(2)}px, 0) rotate(${node.curRot.toFixed(2)}deg)`;
            } else {
                node.el.style.transform = `translate3d(${node.curX.toFixed(2)}px, ${node.curY.toFixed(2)}px, 0)`;
            }

            // تطبيق حركة البؤبؤ داخل العين
            if (node.pupils.length > 0) {
                const px = node.curPupilX.toFixed(2);
                const py = node.curPupilY.toFixed(2);
                node.pupils.forEach(p => {
                    p.style.transform = `translate(${px}px, ${py}px)`;
                });
            }
        });

        animFrameId = requestAnimationFrame(renderLoop);
    }

    // بدء التشغيل عند اكتمال تحميل DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
