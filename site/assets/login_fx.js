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

    // الوش — عينين + بؤبؤ بلمعة + خدود + ابتسامة. واحد للكل عشان يفضلوا شبه بعض.
    // الـ<g class="lfx-eye-pupil"> هو اللي بيتحرك (البؤبؤ واللمعة مع بعض).
    function face(xL, xR, y, r, ink) {
        const pr = (r * 0.52).toFixed(2);
        const hl = (r * 0.2).toFixed(2);
        const hx = (r * 0.2).toFixed(2);
        const hy = (r * 0.24).toFixed(2);
        const mid = (xL + xR) / 2;
        const sw = r * 0.5;                        // نص عرض الابتسامة (رقم مش نص — toFixed هنا كانت بتلزق الأرقام)
        const sy = y + r + 3.2;                    // مكان الابتسامة
        const eye = (x) => `
                <circle cx="${x}" cy="${y}" r="${r}" fill="#ffffff" stroke="${ink}" stroke-width="1.4"/>
                <g class="lfx-eye-pupil">
                    <circle cx="${x}" cy="${y}" r="${pr}" fill="${ink}"/>
                    <circle cx="${x - hx}" cy="${y - hy}" r="${hl}" fill="#ffffff"/>
                </g>`;
        return eye(xL) + eye(xR) + `
                <ellipse cx="${xL - r * 0.7}" cy="${y + r + 1.6}" rx="${(r * 0.55).toFixed(2)}" ry="${(r * 0.32).toFixed(2)}" fill="#f43f5e" opacity="0.45"/>
                <ellipse cx="${xR + r * 0.7}" cy="${y + r + 1.6}" rx="${(r * 0.55).toFixed(2)}" ry="${(r * 0.32).toFixed(2)}" fill="#f43f5e" opacity="0.45"/>
                <path d="M${(mid - sw).toFixed(2)} ${sy.toFixed(2)}C${(mid - sw * 0.5).toFixed(2)} ${(sy + 3).toFixed(2)} ${(mid + sw * 0.5).toFixed(2)} ${(sy + 3).toFixed(2)} ${(mid + sw).toFixed(2)} ${sy.toFixed(2)}" stroke="${ink}" stroke-width="1.6" stroke-linecap="round"/>`;
    }

    // إيدين ورجلين «ستيكس» — ورا الجسم (أول حاجة في الـSVG). كل طرف <g> بيلف حوالين
    // المفصل بتاعه (data-j). الزاوية صفر = الطرف نازل لتحت؛ الموجب مع عقارب الساعة.
    const LIMB_INK = '#cbd5e1';
    const ARM_REST = 28;   // الإيد نازلة لبرّه شوية
    const LEG_REST = 5;
    function limbsSvg(l) {
        const g = (kind, j, len, rest, tip) =>
            `<g class="lfx-limb" data-k="${kind}" data-j="${j[0]} ${j[1]}" data-r="${rest}" transform="rotate(${rest} ${j[0]} ${j[1]})">` +
            `<path d="M${j[0]} ${j[1]}V${j[1] + len}${tip}"/></g>`;
        return `<g stroke="${LIMB_INK}" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" fill="none">` +
            g('al', l.arms[0], l.armLen, ARM_REST, '') +
            g('ar', l.arms[1], l.armLen, -ARM_REST, '') +
            g('ll', l.legs[0], l.legLen, LEG_REST, 'h-5') +      // الرجل الشمال والرِّجل لبرّه
            g('lr', l.legs[1], l.legLen, -LEG_REST, 'h5') +
            `</g>`;
    }

    // الـ٧ شخصيات (SVG flat كيوت — الوش من face())
    const CHARACTERS = [
        {
            id: 'pencil',
            limbs: { arms: [[24, 52], [56, 52]], armLen: 16, legs: [[33, 84], [47, 84]], legLen: 18 },
            name: 'قلم رصاص',
            width: 76,
            height: 104,
            depth: 22,
            pos: { top: '12%', right: '14%' },
            posMobile: { top: '6%', right: '4%' },
            svg: `
            <svg viewBox="0 0 80 110" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M24 18C24 11.3726 29.3726 6 36 6H44C50.6274 6 56 11.3726 56 18V24H24V18Z" fill="#ff9aa2"/>
                <rect x="23" y="24" width="34" height="7" rx="1.5" fill="#cbd5e1" stroke="#94a3b8" stroke-width="1"/>
                <path d="M24 31H56V76H24V31Z" fill="#fbc02d"/>
                <path d="M35 31H45V76H35V31Z" fill="#f9a825"/>
                <path d="M24 76L40 99L56 76H24Z" fill="#ffecb3"/>
                <path d="M34.8 91.5L40 99L45.2 91.5H34.8Z" fill="#374151"/>
                ${face(33, 47, 48, 6, '#1f2937')}
            </svg>`
        },
        {
            id: 'eraser',
            limbs: { arms: [[14, 47], [76, 41]], armLen: 14, legs: [[36, 77], [56, 75]], legLen: 14 },
            name: 'أستيكة',
            width: 82,
            height: 82,
            depth: 18,
            pos: { top: '38%', right: '8%' },
            // على الموبايل مكانها القديم كان ورا المربع بالظبط — نزلت تحت
            posMobile: { top: '84%', left: '40%' },
            svg: `
            <svg viewBox="0 0 90 90" fill="none" xmlns="http://www.w3.org/2000/svg">
                <g transform="rotate(-8 45 45)">
                    <rect x="12" y="14" width="66" height="62" rx="14" fill="#ffb7b2"/>
                    <rect x="12" y="44" width="66" height="32" rx="10" fill="#3b82f6"/>
                    <rect x="12" y="48" width="66" height="6" fill="#60a5fa"/>
                    ${face(35, 55, 27, 6.2, '#1e293b')}
                </g>
            </svg>`
        },
        {
            id: 'pen',
            limbs: { arms: [[26, 52], [54, 52]], armLen: 16, legs: [[34, 84], [46, 84]], legLen: 18 },
            name: 'قلم جاف',
            width: 74,
            height: 106,
            depth: 26,
            pos: { top: '14%', left: '13%' },
            posMobile: { top: '6%', left: '4%' },
            svg: `
            <svg viewBox="0 0 80 110" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="26" y="8" width="28" height="12" rx="3" fill="#1e40af"/>
                <rect x="54" y="12" width="5" height="32" rx="2" fill="#93c5fd" stroke="#1e40af" stroke-width="1"/>
                <path d="M26 20H54V78H26V20Z" fill="#2563eb"/>
                <path d="M36 20H44V78H36V20Z" fill="#3b82f6"/>
                <path d="M26 78L40 98L54 78H26Z" fill="#94a3b8"/>
                <circle cx="40" cy="99" r="2" fill="#1e293b"/>
                ${face(34, 46, 44, 5.6, '#0f172a')}
            </svg>`
        },
        {
            id: 'ruler',
            limbs: { arms: [[23, 54], [57, 58]], armLen: 15, legs: [[33, 100], [48, 101]], legLen: 12 },
            name: 'مسطرة',
            width: 72,
            height: 110,
            depth: 28,
            pos: { top: '64%', right: '11%' },
            posMobile: { top: '78%', right: '5%' },
            svg: `
            <svg viewBox="0 0 80 110" fill="none" xmlns="http://www.w3.org/2000/svg">
                <g transform="rotate(6 40 55)">
                    <rect x="22" y="8" width="36" height="94" rx="6" fill="#fef08a" stroke="#facc15" stroke-width="1.5"/>
                    <g stroke="#ca8a04" stroke-width="1.2" stroke-linecap="round">
                        <line x1="22" y1="16" x2="31" y2="16"/>
                        <line x1="22" y1="24" x2="27" y2="24"/>
                        <line x1="22" y1="32" x2="31" y2="32"/>
                        <line x1="22" y1="72" x2="31" y2="72"/>
                        <line x1="22" y1="80" x2="27" y2="80"/>
                        <line x1="22" y1="88" x2="31" y2="88"/>
                        <line x1="22" y1="96" x2="27" y2="96"/>
                    </g>
                    ${face(35, 48, 50, 5.6, '#1f2937')}
                </g>
            </svg>`
        },
        {
            // برّاية كلاسيك (شكل صورة محمد) بجسم بلاستيك برتقاني: جوانب مقروصة للمسكة · شفرة طويلة
            // بطرف مدوّر عليها الوش · مسمار نحاس تحت الابتسامة · من غير فتحة القلم (طلب محمد)
            id: 'sharpener',
            limbs: { arms: [[21, 50], [59, 50]], armLen: 14, legs: [[28, 93], [52, 93]], legLen: 11 },
            name: 'برّاية',
            width: 70,
            height: 88,
            depth: 14,
            pos: { top: '38%', left: '9%' },
            // على الموبايل مكانها القديم كان ورا المربع — طلعت فوق بين القلم والكتاب
            posMobile: { top: '12%', left: '27%' },
            svg: `
            <svg viewBox="0 0 80 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M18 12H62Q68 12 68 18V30Q60 40 60 50Q60 60 68 68V80H12V68Q20 60 20 50Q20 40 12 30V18Q12 12 18 12Z" fill="#fb923c" stroke="#c2410c" stroke-width="1.4" stroke-linejoin="round"/>
                <rect x="12" y="78" width="56" height="16" rx="4" fill="#ea580c" stroke="#c2410c" stroke-width="1.4"/>
                <g fill="#fdba74">
                    <circle cx="16" cy="20" r="0.9"/><circle cx="20" cy="24" r="0.9"/><circle cx="16" cy="28" r="0.9"/>
                    <circle cx="64" cy="20" r="0.9"/><circle cx="60" cy="24" r="0.9"/><circle cx="64" cy="28" r="0.9"/>
                    <circle cx="16" cy="72" r="0.9"/><circle cx="20" cy="68" r="0.9"/><circle cx="16" cy="64" r="0.9"/>
                    <circle cx="64" cy="72" r="0.9"/><circle cx="60" cy="68" r="0.9"/><circle cx="64" cy="64" r="0.9"/>
                </g>
                <path d="M25 7H55V60Q55 71 40 71Q25 71 25 60Z" fill="#e2e8f0" stroke="#475569" stroke-width="1.4" stroke-linejoin="round"/>
                <line x1="28" y1="10" x2="28" y2="60" stroke="#f8fafc" stroke-width="2" stroke-linecap="round"/>
                <circle cx="40" cy="52" r="4.6" fill="#d4a24c" stroke="#8a6420" stroke-width="1.1"/>
                <path d="M37.6 52H42.4M40 49.6V54.4" stroke="#8a6420" stroke-width="1.3" stroke-linecap="round"/>
                ${face(34, 46, 25, 5.2, '#1f2937')}
            </svg>`
        },
        {
            id: 'paper',
            limbs: { arms: [[15, 50], [75, 50]], armLen: 14, legs: [[35, 75], [55, 75]], legLen: 13 },
            name: 'ورقة نوت',
            width: 86,
            height: 86,
            depth: 20,
            pos: { top: '65%', left: '12%' },
            posMobile: { top: '78%', left: '5%' },
            svg: `
            <svg viewBox="0 0 90 90" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M15 15H58L75 32V75H15V15Z" fill="#fef9c3" stroke="#fde047" stroke-width="1.5"/>
                <path d="M58 15V32H75L58 15Z" fill="#fef08a" stroke="#fde047" stroke-width="1.2"/>
                <line x1="24" y1="30" x2="50" y2="30" stroke="#fde047" stroke-width="1.5" stroke-linecap="round"/>
                <line x1="24" y1="66" x2="66" y2="66" stroke="#fde047" stroke-width="1.5" stroke-linecap="round"/>
                ${face(37, 53, 45, 6.2, '#1f2937')}
            </svg>`
        },
        {
            // كتاب بجد: جلدة ملوّنة + كعب غامق + الصفحات باينة من الجنب وتحت + شريطة
            id: 'book',
            limbs: { arms: [[11, 52], [72, 52]], armLen: 15, legs: [[30, 92], [52, 92]], legLen: 11 },
            name: 'كتاب',
            width: 80,
            height: 96,
            depth: 16,
            pos: { top: '4%', left: '46%' },
            posMobile: { top: '3%', left: '38%' },
            svg: `
            <svg viewBox="0 0 84 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="17" y="12" width="56" height="81" rx="5" fill="#fef3c7" stroke="#e7d6a6" stroke-width="1"/>
                <g stroke="#e7d6a6" stroke-width="0.9">
                    <line x1="69.5" y1="18" x2="69.5" y2="88"/>
                    <line x1="71.5" y1="18" x2="71.5" y2="88"/>
                    <line x1="22" y1="90" x2="66" y2="90"/>
                </g>
                <path d="M54 86V97L57.5 94L61 97V86Z" fill="#ef4444"/>
                <rect x="11" y="7" width="57" height="81" rx="6" fill="#14b8a6"/>
                <rect x="11" y="7" width="11" height="81" rx="5" fill="#0f766e"/>
                <line x1="14" y1="18" x2="19" y2="18" stroke="#5eead4" stroke-width="1.6" stroke-linecap="round"/>
                <line x1="14" y1="77" x2="19" y2="77" stroke="#5eead4" stroke-width="1.6" stroke-linecap="round"/>
                <rect x="31" y="18" width="28" height="6" rx="3" fill="#99f6e4"/>
                ${face(37, 53, 48, 6.2, '#134e4a')}
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

            // الأطراف أول حاجة جوّه الـ<svg> عشان تبان طالعة من ورا الجسم
            el.innerHTML = charDef.svg.replace('>', '>' + limbsSvg(charDef.limbs));
            containerEl.appendChild(el);

            return {
                def: charDef,
                el: el,
                pupils: el.querySelectorAll('.lfx-eye-pupil'),
                limbs: Array.from(el.querySelectorAll('.lfx-limb')).map(g => ({
                    g: g,
                    k: g.dataset.k,
                    j: g.dataset.j,
                    rest: parseFloat(g.dataset.r),
                    last: parseFloat(g.dataset.r)
                })),
                prevX: 0,
                prevY: 0,
                walkPhase: index * 0.9,
                walkAmp: 0,
                curX: 0,
                curY: 0,
                curPupilX: 0,
                curPupilY: 0,
                curRot: 0,
                baseX: 0,
                baseY: 0,
                // إزاحة زمنية مختلفة لكل شخصية في مشوار الخمول
                idlePhase: index * (1.0 / CHARACTERS.length),
                // سرعة مختلفة طفيفة (بين ٠.٩ و ١.١)
                idleSpeedFactor: 0.9 + (index % 3) * 0.1
            };
        });

        updateCardBounds();
        cacheBasePositions();
        window.addEventListener('resize', function () { updateCardBounds(); cacheBasePositions(); }, { passive: true });

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

    // مكان كل كاركتر من غير الـtransform — بيتحسب مرة واحدة ومع الـresize بس.
    // (getBoundingClientRect جوّه اللوب كان بيعمل layout ٧ مرات في كل فريم — تقيل على الآيفون القديم)
    function cacheBasePositions() {
        charNodes.forEach(node => {
            node.baseX = node.el.offsetLeft;
            node.baseY = node.el.offsetTop;
        });
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

                targetX = targetWorldX - node.baseX - (node.def.width / 2);
                targetY = targetWorldY - node.baseY - (node.def.height / 2);

                // إمالة طفيفة في اتجاه السير
                targetRot = (pos.angle * 180 / Math.PI) * 0.15;

                // العيون تنظر قليلاً للأمام في اتجاه السير
                targetPupilX = Math.cos(pos.angle) * 2.2;
                targetPupilY = Math.sin(pos.angle) * 2.2;

            } else if (hasValidMouse) {
                // وضع الـ Parallax وتتبع الماوس
                const relX = (mouseX - centerX) / centerX; // -1 to 1
                const relY = (mouseY - centerY) / centerY; // -1 to 1

                targetX = relX * node.def.depth;
                targetY = relY * node.def.depth;

                // مركز الكاركتر الفعلي لحساب زاوية نظر العين للماوس
                const charMidX = node.baseX + node.curX + node.def.width / 2;
                const charMidY = node.baseY + node.curY + node.def.height / 2;

                const dx = mouseX - charMidX;
                const dy = mouseY - charMidY;
                const dist = Math.hypot(dx, dy);

                if (dist > 1) {
                    const angle = Math.atan2(dy, dx);
                    // أقصى إزاحة للبؤبؤ داخل العين ٢.٨ (العين نص قطرها ~٦)
                    const pupilDist = Math.min(2.8, dist / 120 * 2.8);
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

            // المشي: الرجلين بتتحرك على قد المسافة اللي الكاركتر قطعها في الفريم —
            // واقف = واقف، ماشي بسرعة = خطوات أسرع. الإيد عكس الرجل اللي في نفس الناحية.
            const speed = Math.min(4, Math.hypot(node.curX - node.prevX, node.curY - node.prevY));
            node.prevX = node.curX;
            node.prevY = node.curY;
            node.walkAmp += (Math.min(1, speed / 0.3) - node.walkAmp) * 0.1;
            node.walkPhase += 0.02 + speed * 0.35;
            const swing = Math.sin(node.walkPhase) * node.walkAmp;
            // وهو واقف الإيدين بتتهز هزة خفيفة (بدل «التنفّس» القديم)
            const sway = Math.sin(timestamp / 650 + node.idlePhase * 6) * 4 * (1 - node.walkAmp);
            node.limbs.forEach(l => {
                let a = l.rest;
                if (l.k === 'll') a += 30 * swing;
                else if (l.k === 'lr') a -= 30 * swing;
                else if (l.k === 'al') a += -26 * swing + sway;
                else a += -26 * swing - sway;
                if (Math.abs(a - l.last) > 0.15) {
                    l.last = a;
                    l.g.setAttribute('transform', `rotate(${a.toFixed(1)} ${l.j})`);
                }
            });

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
