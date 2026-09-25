/**
 * login_fx.js — شخصيات الأدوات المكتبية المتحركة في صفحة الدخول (بريف ٦٣)
 * نظام الفجالة ستور المالي
 *
 * محرّك حركة فيزيائي بـ dt حقيقي، مسارات Catmull-Rom ناعمة،
 * منع تصادم وترتيب عمق، ومشاهد قصيرة متبادلة وتسكّع حيوي.
 */
(function () {
    'use strict';

    // التحقق من تفضيل تقليل الحركة
    const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ثوابت الحركة والسرعات بالبكسل/ثانية (بريف ٦٣)
    const IDLE_MS = 15000;       // مهلة الخمول ١٥ ثانية
    const WALK = 70;             // سرعة المشي ٧٠ بكسل/ث
    const SLOW = 38;             // سرعة الكتاب ٣٨ بكسل/ث
    const RUN = 190;             // سرعة الجري ١٩٠ بكسل/ث
    const ACCEL_T = 35 / 100;    // زمن التسارع والتباطؤ ٠٫٣٥ ثانية
    const STRIDE_WALK = 22;      // خطوة المشي ٢٢ بكسل
    const STRIDE_RUN = 34;       // خطوة الجري ٣٤ بكسل

    // قراءة باراميترات التجربة من الرابط
    let idleTimeoutMs = IDLE_MS;
    let initialSceneName = null;
    let warpSeconds = 0;
    let debugMode = false;

    try {
        const urlParams = new URLSearchParams(window.location.search);
        const customIdle = parseInt(urlParams.get('lfx_idle'), 10);
        if (!isNaN(customIdle) && customIdle >= 0) {
            idleTimeoutMs = customIdle * 1000;
        }
        initialSceneName = urlParams.get('lfx_scene');
        const customWarp = parseFloat(urlParams.get('lfx_warp'));
        if (!isNaN(customWarp) && customWarp > 0) {
            warpSeconds = customWarp;
        }
        if (urlParams.get('lfx_debug') === '1') {
            debugMode = true;
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
        const sw = r / 2;                          // نص عرض الابتسامة (رقم مش نص — toFixed هنا كانت بتلزق الأرقام)
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

    // =========================================================================
    // بريف ٦٣: محرّك الحركة الجديد والمشاهد القصيرة
    // =========================================================================

    // البيوت للشاشات الكبيرة والموبايل (إحداثيات نسبية [0, 1])
    const HOMES_DESKTOP = {
        pencil:    { x: 12 / 100, y: 12 / 100 },
        eraser:    { x: 22 / 100, y: 14 / 100 },
        pen:       { x: 7 / 100,  y: 50 / 100 },
        ruler:     { x: 36 / 100, y: 90 / 100 },
        sharpener: { x: 92 / 100, y: 90 / 100 },
        book:      { x: 12 / 100, y: 88 / 100 },
        paper:     { x: 50 / 100, y: 14 / 100 }
    };

    const HOMES_MOBILE = {
        pencil:    { x: 14 / 100, y: 8 / 100 },
        eraser:    { x: 32 / 100, y: 8 / 100 },
        pen:       { x: 82 / 100, y: 8 / 100 },
        paper:     { x: 55 / 100, y: 8 / 100 },
        ruler:     { x: 20 / 100, y: 90 / 100 },
        book:      { x: 50 / 100, y: 90 / 100 },
        sharpener: { x: 84 / 100, y: 90 / 100 }
    };

    // المشاهد الستة — مصفوفة داتا واضحة (ممنوع if متفرقة لكل شخصية)
    const SCENES = [
        {
            name: 'write',
            dur: 26,
            steps: [
                { at: 0, who: 'pencil', do: 'walk', to: [55 / 100, 12 / 100], speed: WALK, trail: 'pencil' },
                { at: 0, who: 'eraser', do: 'follow', target: 'pencil', gap: 110, speed: WALK, eraseTrail: true },
                { at: 8, who: 'pencil', do: 'lookAt', target: 'eraser', dur: 2 },
                { at: 10, who: 'pencil', do: 'walk', to: [85 / 100, 12 / 100], speed: WALK, trail: 'pencil' },
                { at: 18, who: 'pencil', do: 'walk', to: 'home', speed: WALK },
                { at: 18, who: 'eraser', do: 'walk', to: 'home', speed: WALK }
            ]
        },
        {
            name: 'chase',
            dur: 27,
            steps: [
                { at: 0, who: 'pencil', do: 'walk', to: [80 / 100, 75 / 100], speed: WALK },
                { at: 4, who: 'sharpener', do: 'lookAt', target: 'pencil', dur: 1 },
                { at: 5, who: 'pencil', do: 'run', to: [26 / 100, 88 / 100], speed: RUN },
                { at: 5, who: 'sharpener', do: 'follow', target: 'pencil', gap: 150, speed: RUN * 95 / 100 },
                { at: 5, who: 'all_others', do: 'lookAt', target: 'pencil', dur: 8 },
                { at: 13, who: 'sharpener', do: 'hop', dur: 3 },
                { at: 13, who: 'pencil', do: 'walk', to: 'home', speed: WALK },
                { at: 17, who: 'sharpener', do: 'walk', to: 'home', speed: WALK * 70 / 100 }
            ]
        },
        {
            name: 'sign',
            dur: 25,
            steps: [
                { at: 0, who: 'pen', do: 'walk', to: [12 / 100, 55 / 100], speed: WALK },
                { at: 0, who: 'book', do: 'walk', to: [24 / 100, 60 / 100], speed: SLOW },
                { at: 4, who: 'book', do: 'lookAt', target: 'pen', dur: 14 },
                { at: 4, who: 'pen', do: 'sign', dur: 12 },
                { at: 18, who: 'pen', do: 'walk', to: 'home', speed: WALK },
                { at: 18, who: 'book', do: 'walk', to: 'home', speed: SLOW }
            ]
        },
        {
            name: 'measure',
            dur: 24,
            steps: [
                { at: 0, who: 'ruler', do: 'walk', to: [22 / 100, 88 / 100], speed: WALK, ortho: true },
                { at: 5, who: 'book', do: 'lookAt', target: 'ruler', dur: 6 },
                { at: 5, who: 'ruler', do: 'measure', dur: 3 },
                { at: 10, who: 'ruler', do: 'walk', to: 'home', speed: WALK, ortho: true }
            ]
        },
        {
            name: 'read',
            dur: 25,
            steps: [
                { at: 0, who: 'book', do: 'sit', dur: 18 },
                { at: 1, who: 'pencil', do: 'walk', to: [6 / 100, 88 / 100], speed: WALK },
                { at: 1, who: 'eraser', do: 'walk', to: [18 / 100, 88 / 100], speed: WALK },
                { at: 6, who: 'pencil', do: 'lookAt', target: 'book', dur: 6 },
                { at: 6, who: 'eraser', do: 'lookAt', target: 'book', dur: 6 },
                { at: 14, who: 'pencil', do: 'walk', to: 'home', speed: WALK },
                { at: 14, who: 'eraser', do: 'walk', to: 'home', speed: WALK }
            ]
        }
    ];

    // متغيرات النظام وحالة المحاكاة
    let containerEl = null;
    let loginCardEl = null;
    let cardRect = null;
    let charNodes = [];

    let trailsSvg = null;
    let pencilPolylineEl = null;
    let penPolylineEl = null;
    let pencilTrail = [];
    let penTrail = [];

    let mouseX = -9999;
    let mouseY = -9999;
    let isMouseInsideCard = false;
    let isIdle = false;
    let idleTimer = null;
    let lastActivityTime = Date.now();
    let animFrameId = null;

    // حالة المشاهد والتسكّع
    let currentScene = null;
    let sceneTime = 0;
    let sceneStage = 'idle_wait'; // 'idle_wait' | 'scene' | 'loiter'
    let stageTimer = 0;
    let lastSceneIndex = -1;

    // باراميترات التجربة والتتبع
    let debugMinDist = 9999;
    let debugFlipCount = 0;

    // فحص تقاطع خط مع مستطيل المربع (مع هامش ٢٤ بكسل)
    function segmentIntersectsBox(x1, y1, x2, y2, box) {
        let t0 = 0;
        let t1 = 1;
        const dx = x2 - x1;
        const dy = y2 - y1;
        const p = [-dx, dx, -dy, dy];
        const q = [x1 - box.minX, box.maxX - x1, y1 - box.minY, box.maxY - y1];

        for (let i = 0; i < 4; i++) {
            if (p[i] === 0) {
                if (q[i] < 0) return false;
            } else {
                const t = q[i] / p[i];
                if (p[i] < 0) {
                    if (t > t1) return false;
                    if (t > t0) t0 = t;
                } else {
                    if (t < t0) return false;
                    if (t < t1) t1 = t;
                }
            }
        }
        return t0 <= t1 && t1 >= 0 && t0 <= 1;
    }

    // بناء مسار آمن يلتف حول المربع بدلاً من الانزلاق على حوافه
    function planSafeWaypoints(x1, y1, x2, y2, winW, winH) {
        if (!cardRect) return [{ x: x1, y: y1 }, { x: x2, y: y2 }];

        const box = {
            minX: cardRect.left - 24,
            maxX: cardRect.right + 24,
            minY: cardRect.top - 24,
            maxY: cardRect.bottom + 24
        };

        if (!segmentIntersectsBox(x1, y1, x2, y2, box)) {
            return [{ x: x1, y: y1 }, { x: x2, y: y2 }];
        }

        // الأركان الأربعة مع مسافة أمان إضافية
        const corners = [
            { x: box.minX - 20, y: box.minY - 20 }, // TL
            { x: box.maxX + 20, y: box.minY - 20 }, // TR
            { x: box.minX - 20, y: box.maxY + 20 }, // BL
            { x: box.maxX + 20, y: box.maxY + 20 }  // BR
        ];

        // اختيار أفضل ركن لا يتقاطع مع المربع
        let bestCorner = null;
        let bestDist = Infinity;

        for (let i = 0; i < corners.length; i++) {
            const c = corners[i];
            const d = Math.hypot(x1 - c.x, y1 - c.y) + Math.hypot(x2 - c.x, y2 - c.y);
            if (!segmentIntersectsBox(x1, y1, c.x, c.y, box) && !segmentIntersectsBox(c.x, c.y, x2, y2, box)) {
                if (d < bestDist) {
                    bestDist = d;
                    bestCorner = c;
                }
            }
        }

        if (bestCorner) {
            return [{ x: x1, y: y1 }, bestCorner, { x: x2, y: y2 }];
        }

        // لو تعذر ركن واحد (قطع قطري)، نستخدم ركنين
        return [{ x: x1, y: y1 }, corners[0], corners[1], { x: x2, y: y2 }];
    }

    // منحنى Catmull-Rom مقسّم بطول القوس (Arc-Length Parameterization)
    function buildCatmullRomPath(pts) {
        if (pts.length < 2) {
            const p = pts[0] || { x: 0, y: 0 };
            return {
                totalLength: 0,
                getAt: () => ({ x: p.x, y: p.y, angle: 0 })
            };
        }

        const full = [];
        const p0 = pts[0];
        const p1 = pts[1];
        const pn = pts[pts.length - 1];
        const pn1 = pts[pts.length - 2];

        full.push({ x: 2 * p0.x - p1.x, y: 2 * p0.y - p1.y });
        for (let i = 0; i < pts.length; i++) full.push(pts[i]);
        full.push({ x: 2 * pn.x - pn1.x, y: 2 * pn.y - pn1.y });

        const dense = [];
        for (let i = 1; i < full.length - 2; i++) {
            const pA = full[i - 1];
            const pB = full[i];
            const pC = full[i + 1];
            const pD = full[i + 2];
            const segSteps = 24;
            for (let j = 0; j < segSteps; j++) {
                const u = j / segSteps;
                const u2 = u * u;
                const u3 = u2 * u;
                const x = 0.5 * (
                    (2 * pB.x) +
                    (-pA.x + pC.x) * u +
                    (2 * pA.x - 5 * pB.x + 4 * pC.x - pD.x) * u2 +
                    (-pA.x + 3 * pB.x - 3 * pC.x + pD.x) * u3
                );
                const y = 0.5 * (
                    (2 * pB.y) +
                    (-pA.y + pC.y) * u +
                    (2 * pA.y - 5 * pB.y + 4 * pC.y - pD.y) * u2 +
                    (-pA.y + 3 * pB.y - 3 * pC.y + pD.y) * u3
                );
                dense.push({ x, y });
            }
        }
        dense.push(pts[pts.length - 1]);

        // حساب طول القوس التراكمي
        const cumDist = [0];
        for (let i = 1; i < dense.length; i++) {
            cumDist.push(cumDist[i - 1] + Math.hypot(dense[i].x - dense[i - 1].x, dense[i].y - dense[i - 1].y));
        }
        const totalLength = cumDist[cumDist.length - 1];

        if (totalLength <= 0.001) {
            const p = pts[0];
            return {
                totalLength: 0,
                getAt: () => ({ x: p.x, y: p.y, angle: 0 })
            };
        }

        // تقسيم المسار إلى نقاط متساوية كل ~٦ بكسل
        const uniform = [];
        const stepSize = 6;
        const count = Math.max(2, Math.ceil(totalLength / stepSize));
        let curIdx = 0;

        for (let k = 0; k <= count; k++) {
            const targetD = Math.min(totalLength, (k / count) * totalLength);
            while (curIdx < cumDist.length - 2 && cumDist[curIdx + 1] < targetD) {
                curIdx++;
            }
            const d0 = cumDist[curIdx];
            const d1 = cumDist[curIdx + 1];
            const frac = d1 > d0 ? (targetD - d0) / (d1 - d0) : 0;
            const x = dense[curIdx].x + (dense[curIdx + 1].x - dense[curIdx].x) * frac;
            const y = dense[curIdx].y + (dense[curIdx + 1].y - dense[curIdx].y) * frac;
            uniform.push({ x, y });
        }

        for (let i = 0; i < uniform.length; i++) {
            if (i < uniform.length - 1) {
                uniform[i].angle = Math.atan2(uniform[i + 1].y - uniform[i].y, uniform[i + 1].x - uniform[i].x);
            } else {
                uniform[i].angle = uniform[i - 1].angle;
            }
        }

        return {
            totalLength,
            getAt(d) {
                const clampedD = Math.max(0, Math.min(totalLength, d));
                const progress = totalLength > 0 ? clampedD / totalLength : 0;
                const floatIndex = progress * (uniform.length - 1);
                const idx0 = Math.floor(floatIndex);
                const idx1 = Math.min(uniform.length - 1, idx0 + 1);
                const frac = floatIndex - idx0;
                const pA = uniform[idx0];
                const pB = uniform[idx1];
                return {
                    x: pA.x + (pB.x - pA.x) * frac,
                    y: pA.y + (pB.y - pA.y) * frac,
                    angle: pA.angle
                };
            }
        };
    }

    // بناء مسار متعامد (أفقي ورأسي فقط) للمسطرة
    function buildOrthoPath(x1, y1, x2, y2) {
        const corner = { x: x2, y: y1 };
        const pts = [{ x: x1, y: y1 }, corner, { x: x2, y: y2 }];
        const leg1 = Math.hypot(corner.x - x1, corner.y - y1);
        const leg2 = Math.hypot(x2 - corner.x, y2 - corner.y);
        const totalLength = leg1 + leg2;

        return {
            totalLength,
            isOrtho: true,
            leg1,
            getAt(d) {
                if (d <= leg1) {
                    const u = leg1 > 0 ? d / leg1 : 0;
                    return {
                        x: x1 + (corner.x - x1) * u,
                        y: y1 + (corner.y - y1) * u,
                        angle: Math.atan2(corner.y - y1, corner.x - x1),
                        rot: 0
                    };
                } else {
                    const u = leg2 > 0 ? (d - leg1) / leg2 : 0;
                    return {
                        x: corner.x + (x2 - corner.x) * u,
                        y: corner.y + (y2 - corner.y) * u,
                        angle: Math.atan2(y2 - corner.y, x2 - corner.x),
                        rot: 90
                    };
                }
            }
        };
    }

    // إعداد وحقن العناصر في الصفحة
    function init() {
        loginCardEl = document.querySelector('.login-card');
        if (!loginCardEl) return;

        containerEl = document.querySelector('.lfx');
        if (!containerEl) {
            containerEl = document.createElement('div');
            containerEl.className = 'lfx';
            containerEl.setAttribute('aria-hidden', 'true');
            loginCardEl.parentNode.insertBefore(containerEl, loginCardEl);
        }

        trailsSvg = containerEl.querySelector('.lfx-trails');
        if (!trailsSvg) {
            trailsSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            trailsSvg.setAttribute('class', 'lfx-trails');
            trailsSvg.setAttribute('aria-hidden', 'true');
            trailsSvg.innerHTML = 
                '<polyline id="lfxPencilTrail" fill="none" stroke="#94a3b8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" opacity="0.85" />' +
                '<polyline id="lfxPenTrail" fill="none" stroke="#2563eb" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" opacity="0.9" />';
            containerEl.insertBefore(trailsSvg, containerEl.firstChild);
        }
        pencilPolylineEl = trailsSvg.querySelector('#lfxPencilTrail');
        penPolylineEl = trailsSvg.querySelector('#lfxPenTrail');

        const isMobile = window.innerWidth < 700;
        const homes = isMobile ? HOMES_MOBILE : HOMES_DESKTOP;
        const winW = window.innerWidth || document.documentElement.clientWidth;
        const winH = window.innerHeight || document.documentElement.clientHeight;

        charNodes = CHARACTERS.map((charDef, index) => {
            const el = document.createElement('div');
            el.className = 'lfx-char lfx-char-' + charDef.id;
            el.id = 'lfxChar_' + charDef.id;

            const homeNorm = homes[charDef.id] || { x: 0.5, y: 0.5 };
            const startX = homeNorm.x * winW;
            const startY = homeNorm.y * winH;

            el.style.left = '0px';
            el.style.top = '0px';
            el.style.width = charDef.width + 'px';
            el.style.height = charDef.height + 'px';

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
                x: startX,
                y: startY,
                vx: 0,
                vy: 0,
                currentSpeed: 0,
                targetSpeed: WALK,
                facing: 1,
                pendingFacing: 0,
                pendingFacingTimer: 0,
                walkPhase: index * 0.9,
                walkAmp: 0,
                bounce: 0,
                tilt: 0,
                customTilt: null,
                customScaleX: null,
                lookAtTarget: null,
                pupilOffset: { x: 0, y: 0 },
                currentZ: 0,
                // مسار الحركة النشط
                activePath: null,
                distOnPath: 0,
                isOrtho: false,
                rulerRot: 0,
                // تاريخ الأثر للمتابعة (Follow)
                trailHistory: [{ x: startX, y: startY, cumDist: 0 }],
                trailCumDist: 0,
                // التسكع الفردي
                loiterTarget: null,
                loiterTimer: 1 + Math.random() * 2,
                // مؤقتات الحركات الخاصة
                actionTimer: 0,
                specialAction: null
            };
        });

        updateCardBounds();
        window.addEventListener('resize', updateCardBounds, { passive: true });

        const resetEvents = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'];
        resetEvents.forEach(evt => window.addEventListener(evt, onUserActivity, { passive: true }));
        window.addEventListener('mousemove', onMouseMove, { passive: true });
        window.addEventListener('mouseleave', onMouseLeave, { passive: true });
        document.addEventListener('visibilitychange', onVisibilityChange);

        resetIdleTimer();

        // محاكاة سريعة ثابتة إذا طُلب ?lfx_warp=N
        if (warpSeconds > 0) {
            startIdleMode();
            const fixedDt = 1 / 60;
            const steps = Math.round(warpSeconds * 60);
            for (let s = 0; s < steps; s++) {
                simulatePhysics(fixedDt);
            }
            applyDOM();
        }

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
        sceneStage = 'idle_wait';
        stageTimer = 2.0; // يبدأ أول مشهد خلال ٢ إلى ٣ ثواني
        if (containerEl) {
            containerEl.classList.add('lfx-is-idle');
        }
    }

    function stopIdleMode() {
        isIdle = false;
        currentScene = null;
        sceneStage = 'idle_wait';
        pencilTrail = [];
        penTrail = [];
        if (pencilPolylineEl) pencilPolylineEl.setAttribute('points', '');
        if (penPolylineEl) penPolylineEl.setAttribute('points', '');
        if (containerEl) {
            containerEl.classList.remove('lfx-is-idle');
        }

        // إلغاء كل المسارات وتصفير السرعات
        charNodes.forEach(n => {
            n.activePath = null;
            n.specialAction = null;
            n.lookAtTarget = null;
            n.targetSpeed = WALK;
        });
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

    // إطلاق مشهد جديد
    function triggerNextScene() {
        let nextIdx = 0;
        if (initialSceneName) {
            const found = SCENES.findIndex(s => s.name === initialSceneName);
            if (found >= 0) nextIdx = found;
            initialSceneName = null; // أول مشهد فقط
        } else {
            // اختيار عشوائي لا يكرر المشهد السابق
            do {
                nextIdx = Math.floor(Math.random() * SCENES.length);
            } while (nextIdx === lastSceneIndex && SCENES.length > 1);
        }

        lastSceneIndex = nextIdx;
        currentScene = SCENES[nextIdx];
        sceneTime = 0;
        sceneStage = 'scene';

        // تفريغ المسارات القديمة للشخصيات
        charNodes.forEach(n => {
            n.activePath = null;
            n.specialAction = null;
            n.lookAtTarget = null;
        });
    }

    // أمر مشي لشخصية معينة
    function startCharWalk(node, targetX, targetY, speed, isRun, isOrtho, trailType, eraseTrail) {
        const winW = window.innerWidth || document.documentElement.clientWidth;
        const winH = window.innerHeight || document.documentElement.clientHeight;

        node.targetSpeed = speed || (isRun ? RUN : WALK);
        node.trailType = trailType || null;
        node.eraseTrail = eraseTrail || false;
        node.isFollower = false;
        node.followLeader = null;
        node.lookAtTarget = null;
        node.specialAction = null;

        if (isOrtho) {
            node.activePath = buildOrthoPath(node.x, node.y, targetX, targetY);
            node.distOnPath = 0;
            node.isOrtho = true;
        } else {
            const waypoints = planSafeWaypoints(node.x, node.y, targetX, targetY, winW, winH);
            node.activePath = buildCatmullRomPath(waypoints);
            node.distOnPath = 0;
            node.isOrtho = false;
        }
    }

    // أمر متابعة قائد بمسافة gap
    function startCharFollow(node, leaderNode, gap, speed, eraseTrail) {
        node.isFollower = true;
        node.followLeader = leaderNode;
        node.followGap = gap;
        node.targetSpeed = speed || WALK;
        node.eraseTrail = eraseTrail || false;
        node.activePath = null;
        node.specialAction = null;
        node.lookAtTarget = null;
    }

    // محاكاة فيزيائية لفريم واحد بـ dt حقيقي
    function simulatePhysics(dt) {
        const winW = window.innerWidth || document.documentElement.clientWidth;
        const winH = window.innerHeight || document.documentElement.clientHeight;
        const isMobile = winW < 700;
        const homes = isMobile ? HOMES_MOBILE : HOMES_DESKTOP;
        const PERSONAL = isMobile ? 64 : 84;
        const MAX_SEP_SPEED = 60;

        // إدارة مراحل الخمول والمشاهد
        if (isIdle) {
            if (sceneStage === 'idle_wait') {
                stageTimer -= dt;
                if (stageTimer <= 0) {
                    triggerNextScene();
                }
            } else if (sceneStage === 'loiter') {
                stageTimer -= dt;
                if (stageTimer <= 0) {
                    triggerNextScene();
                }
            } else if (sceneStage === 'scene' && currentScene) {
                const prevSceneTime = sceneTime;
                sceneTime += dt;

                // تنفيذ خطوات المشهد عند حلول توقيتها
                for (let i = 0; i < currentScene.steps.length; i++) {
                    const step = currentScene.steps[i];
                    if (step.at >= prevSceneTime && step.at < sceneTime) {
                        applySceneStep(step, winW, winH, homes);
                    }
                }

                if (sceneTime >= currentScene.dur) {
                    sceneStage = 'loiter';
                    stageTimer = 4 + Math.random() * 4; // تسكع بين المشاهد من ٤ إلى ٨ ثواني
                    currentScene = null;
                    // الجميع يعود لبيته بعد المشهد
                    charNodes.forEach(n => {
                        if (n.def.id !== 'paper') {
                            const h = homes[n.def.id];
                            if (h) {
                                startCharWalk(n, h.x * winW, h.y * winH, WALK, false, n.def.id === 'ruler');
                            }
                        }
                    });
                }
            }
        }

        // تحديث كل شخصية
        charNodes.forEach(node => {
            const charId = node.def.id;

            // ١) الورقة تعوم دائماً ولا تشارك في المشاهد
            if (charId === 'paper') {
                node.actionTimer += dt;
                const t = node.actionTimer;
                const home = homes.paper;
                node.x = (20 / 100 + (60 / 100 * (1 / 2 + (1 / 2) * Math.sin(t * (2 * Math.PI / 60))))) * winW;
                node.y = (home.y * winH) + Math.sin(t * 4 / 10) * 20;
                node.customTilt = Math.cos(t * 4 / 10) * 10;
                const cycle = t % 20;
                node.customScaleX = cycle < 1 ? Math.cos(cycle * 2 * Math.PI) : 1;
                node.vx = (Math.cos(t * (2 * Math.PI / 60)) * (30 / 100 * winW)) * (2 * Math.PI / 60);
                node.vy = Math.cos(t * 4 / 10) * 8;
                node.currentSpeed = Math.hypot(node.vx, node.vy);
                node.walkAmp = 0;
                return;
            }

            // ٢) التسكع الطبيعي للشخصيات التي ليست في مشهد نشط
            if (isIdle && !node.activePath && !node.isFollower && !node.specialAction && !node.lookAtTarget) {
                node.loiterTimer -= dt;
                if (node.loiterTimer <= 0) {
                    node.loiterTimer = 3 + Math.random() * 4;
                    const home = homes[charId] || { x: 0.5, y: 0.5 };
                    const homeX = home.x * winW;
                    const homeY = home.y * winH;
                    // تسكع خفيف في دائرة قطرها ٢٥ بكسل حول البيت
                    const angle = Math.random() * 2 * Math.PI;
                    const rad = Math.random() * 25;
                    const tx = homeX + Math.cos(angle) * rad;
                    const ty = homeY + Math.sin(angle) * rad;
                    startCharWalk(node, tx, ty, 24, false, false);
                }
            }

            // ٣) الحركة على المسار (Catmull-Rom أو Ortho)
            if (node.activePath) {
                const path = node.activePath;
                const a = node.targetSpeed / ACCEL_T;
                const remDist = path.totalLength - node.distOnPath;
                const stopDist = (node.currentSpeed * node.currentSpeed) / (2 * a);

                if (remDist > stopDist) {
                    node.currentSpeed = Math.min(node.targetSpeed, node.currentSpeed + a * dt);
                } else {
                    node.currentSpeed = Math.max(14, node.currentSpeed - a * dt);
                }

                node.distOnPath += node.currentSpeed * dt;

                if (node.distOnPath >= path.totalLength) {
                    node.distOnPath = path.totalLength;
                    const endPt = path.getAt(path.totalLength);
                    node.x = endPt.x;
                    node.y = endPt.y;
                    node.currentSpeed = 0;
                    node.vx = 0;
                    node.vy = 0;
                    node.activePath = null;
                } else {
                    const pt = path.getAt(node.distOnPath);
                    node.vx = Math.cos(pt.angle) * node.currentSpeed;
                    node.vy = Math.sin(pt.angle) * node.currentSpeed;
                    node.x = pt.x;
                    node.y = pt.y;
                    if (path.isOrtho) {
                        node.rulerRot = pt.rot || 0;
                        node.customTilt = node.rulerRot;
                    }
                }
            }

            // ٤) المتابعة بالمسافة (Follow) على أثر القائد
            else if (node.isFollower && node.followLeader) {
                const leader = node.followLeader;
                const targetDist = leader.trailCumDist - node.followGap;

                if (targetDist <= 0 || leader.trailHistory.length < 2) {
                    node.currentSpeed = 0;
                    node.vx = 0;
                    node.vy = 0;
                } else {
                    const hist = leader.trailHistory;
                    let idx = 0;
                    while (idx < hist.length - 2 && hist[idx + 1].cumDist < targetDist) {
                        idx++;
                    }
                    const p0 = hist[idx];
                    const p1 = hist[idx + 1];
                    const segSpan = p1.cumDist - p0.cumDist;
                    const u = segSpan > 0 ? (targetDist - p0.cumDist) / segSpan : 0;
                    const targetX = p0.x + (p1.x - p0.x) * u;
                    const targetY = p0.y + (p1.y - p0.y) * u;

                    const dx = targetX - node.x;
                    const dy = targetY - node.y;
                    const distToTarget = Math.hypot(dx, dy);

                    if (distToTarget > 1) {
                        node.currentSpeed = Math.min(node.targetSpeed, distToTarget / Math.max(dt, 16 / 1000));
                        node.vx = (dx / distToTarget) * node.currentSpeed;
                        node.vy = (dy / distToTarget) * node.currentSpeed;
                        node.x += node.vx * dt;
                        node.y += node.vy * dt;
                    } else {
                        node.currentSpeed = 0;
                        node.vx = 0;
                        node.vy = 0;
                    }
                }
            }

            // ٥) الحركات الخاصة (إمضاء، قياس، نطّة، قعدة كتاب)
            else if (node.specialAction) {
                const act = node.specialAction;
                act.timer += dt;
                node.currentSpeed = 0;
                node.vx = 0;
                node.vy = 0;

                if (act.type === 'sign') {
                    // حركة إمضاء: لفتين بقطر ٢٢ بكسل + شرطة تحتها
                    const st = act.timer;
                    const cx = act.startX;
                    const cy = act.startY;
                    if (st < 8) {
                        const ang = (st / 4) * 2 * Math.PI;
                        const r = 22;
                        node.x = cx + Math.cos(ang) * r + (st / 8) * 25;
                        node.y = cy + Math.sin(ang) * (r * 70 / 100);
                        node.currentSpeed = 30;
                        node.vx = -Math.sin(ang) * 30;
                        // تسجيل أثر الحبر الأزرق
                        penTrail.push({ x: node.x, y: node.y + node.def.height / 2 - 4, life: 6 });
                    } else if (st < 11) {
                        const u = (st - 8) / 3;
                        node.x = cx - 10 + u * 65;
                        node.y = cy + 22;
                        node.currentSpeed = 40;
                        node.vx = 40;
                        penTrail.push({ x: node.x, y: node.y + node.def.height / 2 - 4, life: 6 });
                    } else if (st >= act.dur) {
                        node.specialAction = null;
                    }
                } else if (act.type === 'measure') {
                    // دوران ٩٠ درجة لقياس الكتاب
                    const st = act.timer;
                    if (st < 3 / 10) {
                        node.customTilt = (st / (3 / 10)) * 90;
                    } else if (st < act.dur - (3 / 10)) {
                        node.customTilt = 90;
                    } else if (st < act.dur) {
                        node.customTilt = 90 - ((st - (act.dur - (3 / 10))) / (3 / 10)) * 90;
                    } else {
                        node.customTilt = 0;
                        node.specialAction = null;
                    }
                } else if (act.type === 'hop') {
                    // ٣ نطات للمتعب/اللاهث
                    const st = act.timer;
                    if (st < 3) {
                        const hopPhase = (st / 1) * Math.PI;
                        node.hopBounce = Math.max(0, Math.sin(hopPhase * 3)) * 6;
                    } else {
                        node.hopBounce = 0;
                        node.specialAction = null;
                    }
                } else if (act.type === 'sit') {
                    // كتاب يقرأ: ينبض scaleX 1 إلى 1.12
                    const st = act.timer;
                    node.customScaleX = 1 + (12 / 200) * (1 + Math.sin(st * (2 * Math.PI / 4)));
                    if (st >= act.dur) {
                        node.customScaleX = 1;
                        node.specialAction = null;
                    }
                }
            } else {
                node.currentSpeed = 0;
                node.vx = 0;
                node.vy = 0;
            }

            // تسجيل تاريخ مسار القائد للمتتبعين
            const lastHist = node.trailHistory[node.trailHistory.length - 1];
            const distSinceLast = Math.hypot(node.x - lastHist.x, node.y - lastHist.y);
            if (distSinceLast >= 6) {
                node.trailCumDist += distSinceLast;
                node.trailHistory.push({ x: node.x, y: node.y, cumDist: node.trailCumDist });
                if (node.trailHistory.length > 200) {
                    node.trailHistory.splice(0, node.trailHistory.length - 200);
                }
            }

            // رسم أثر القلم الرصاص عند الكتابة
            if (node.trailType === 'pencil' && node.currentSpeed > 5) {
                const waveY = Math.sin(sceneTime * 12) * 2;
                const tipX = node.x;
                const tipY = node.y + node.def.height / 2 - 6 + waveY;
                const lastPt = pencilTrail[pencilTrail.length - 1];
                if (!lastPt || Math.hypot(tipX - lastPt.x, tipY - lastPt.y) >= 6) {
                    pencilTrail.push({ x: tipX, y: tipY, life: 8 });
                    if (pencilTrail.length > 80) pencilTrail.shift();
                }
            }

            // مسح أثر القلم عند اقتراب الأستيكة (< ٣٠ بكسل)
            if (node.eraseTrail && charId === 'eraser') {
                pencilTrail = pencilTrail.filter(pt => Math.hypot(pt.x - node.x, pt.y - node.y) >= 30);
            }

            // ٦) اتجاه الوجه مع خاصية Hysteresis (تغيير فقط إذا استمر أكثر من ٢٥٠ms)
            if (node.lookAtTarget) {
                const ldx = node.lookAtTarget.x - node.x;
                if (Math.abs(ldx) > 15) {
                    const desired = ldx > 0 ? 1 : -1;
                    if (desired !== node.facing) {
                        node.facing = desired;
                        debugFlipCount++;
                    }
                }
                node.pendingFacing = 0;
                node.pendingFacingTimer = 0;
            } else if (Math.abs(node.vx) > 25) {
                const dir = node.vx > 0 ? 1 : -1;
                if (dir !== node.facing) {
                    if (dir === node.pendingFacing) {
                        node.pendingFacingTimer += dt;
                        if (node.pendingFacingTimer >= 25 / 100) {
                            node.facing = dir;
                            node.pendingFacing = 0;
                            node.pendingFacingTimer = 0;
                            debugFlipCount++;
                        }
                    } else {
                        node.pendingFacing = dir;
                        node.pendingFacingTimer = 0;
                    }
                } else {
                    node.pendingFacing = 0;
                    node.pendingFacingTimer = 0;
                }
            } else {
                node.pendingFacing = 0;
                node.pendingFacingTimer = 0;
            }

            // ٧) حركة الأرجل والأذرع (الرجلين قد الأرض تماماً بدون أي تزحلق)
            const distanceMoved = Math.hypot(node.vx, node.vy) * dt;
            const isRun = node.currentSpeed > WALK * 12 / 10;
            const stride = isRun ? STRIDE_RUN : STRIDE_WALK;

            node.walkPhase += distanceMoved / stride;
            const targetAmp = Math.min(1, node.currentSpeed / WALK);
            node.walkAmp += (targetAmp - node.walkAmp) * (1 - Math.exp(-dt * 15));

            // نطّة مع الخطوة وميل للأمام
            node.bounce = (node.walkAmp > 0.05) ? Math.abs(Math.sin(node.walkPhase)) * 2.5 * node.walkAmp : 0;
            if (node.hopBounce) node.bounce = node.hopBounce;

            if (node.customTilt === null && charId !== 'ruler') {
                node.tilt = node.facing * Math.min(6, (node.currentSpeed / RUN) * 6);
            } else {
                node.tilt = node.customTilt || 0;
            }

            // اتجاه البؤبؤ
            if (node.lookAtTarget) {
                const ldx = node.lookAtTarget.x - node.x;
                const ldy = node.lookAtTarget.y - node.y;
                const lang = Math.atan2(ldy, ldx);
                node.pupilOffset.x = Math.cos(lang) * 2.2 * node.facing;
                node.pupilOffset.y = Math.sin(lang) * 2.2;
            } else if (node.currentSpeed > 10) {
                const moveAng = Math.atan2(node.vy, node.vx);
                node.pupilOffset.x = Math.cos(moveAng) * 2.2 * node.facing;
                node.pupilOffset.y = Math.sin(moveAng) * 2.2;
            } else {
                node.pupilOffset.x = 1.6;
                node.pupilOffset.y = 0;
            }
        });

        // ٨) المسافة الشخصية ومنع التداخل بين الشخصيات (Personal Space)
        for (let i = 0; i < charNodes.length; i++) {
            for (let j = i + 1; j < charNodes.length; j++) {
                const c1 = charNodes[i];
                const c2 = charNodes[j];
                if (c1.def.id === 'paper' || c2.def.id === 'paper') continue;

                let dx = c2.x - c1.x;
                let dy = c2.y - c1.y;
                let dist = Math.hypot(dx, dy);

                if (dist > 0 && dist < PERSONAL) {
                    const ux = dx / dist;
                    const uy = dy / dist;
                    if (dist < 74) {
                        const pushHard = (74 - dist) / 2;
                        c1.x -= ux * pushHard;
                        c1.y -= uy * pushHard;
                        c2.x += ux * pushHard;
                        c2.y += uy * pushHard;
                        dist = 74;
                    } else {
                        const overlap = PERSONAL - dist;
                        const pushSpeed = Math.min(MAX_SEP_SPEED, (overlap / PERSONAL) * MAX_SEP_SPEED);
                        const pushDist = (pushSpeed * dt) / 2;
                        c1.x -= ux * pushDist;
                        c1.y -= uy * pushDist;
                        c2.x += ux * pushDist;
                        c2.y += uy * pushDist;
                    }
                }

                if (debugMode) {
                    debugMinDist = Math.min(debugMinDist, dist);
                }
            }
        }

        // حدود الشاشة (هامش ٥٠ بكسل) وشروط الموبايل
        charNodes.forEach(node => {
            node.x = Math.max(50, Math.min(winW - 50, node.x));
            if (isMobile) {
                if (node.y > winH * 16 / 100 && node.y < winH * 86 / 100) {
                    node.y = node.y < winH / 2 ? winH * 14 / 100 : winH * 88 / 100;
                }
            } else {
                node.y = Math.max(50, Math.min(winH - 50, node.y));
            }
        });

        // بهتان الأثر مع الوقت
        pencilTrail.forEach(pt => { pt.life -= dt; });
        pencilTrail = pencilTrail.filter(pt => pt.life > 0);
        penTrail.forEach(pt => { pt.life -= dt; });
        penTrail = penTrail.filter(pt => pt.life > 0);

        // تسجيل المتغيرات في body للتجربة
        if (debugMode) {
            document.body.dataset.lfxMindist = String(Math.round(debugMinDist));
            document.body.dataset.lfxFlips = String(debugFlipCount);
        }
    }

    // تطبيق خطوة من خطوات المشهد
    function applySceneStep(step, winW, winH, homes) {
        if (step.who === 'all_others') {
            const targetNode = charNodes.find(n => n.def.id === step.target);
            if (targetNode) {
                charNodes.forEach(n => {
                    if (n.def.id !== step.target && n.def.id !== 'sharpener' && n.def.id !== 'paper') {
                        n.lookAtTarget = targetNode;
                        n.currentSpeed = 0;
                        n.vx = 0;
                        n.vy = 0;
                        n.activePath = null;
                    }
                });
            }
            return;
        }

        const node = charNodes.find(n => n.def.id === step.who);
        if (!node) return;

        if (step.do === 'walk' || step.do === 'run') {
            let tx, ty;
            if (step.to === 'home') {
                const h = homes[node.def.id] || { x: 0.5, y: 0.5 };
                tx = h.x * winW;
                ty = h.y * winH;
            } else {
                tx = step.to[0] * winW;
                ty = step.to[1] * winH;
                if (winW < 700) {
                    ty = ty < winH / 2 ? winH * 14 / 100 : winH * 88 / 100;
                }
            }
            startCharWalk(node, tx, ty, step.speed, step.do === 'run', step.ortho, step.trail, step.eraseTrail);
        } else if (step.do === 'follow') {
            const leader = charNodes.find(n => n.def.id === step.target);
            if (leader) {
                startCharFollow(node, leader, step.gap, step.speed, step.eraseTrail);
            }
        } else if (step.do === 'lookAt') {
            const targetNode = charNodes.find(n => n.def.id === step.target);
            if (targetNode) {
                node.lookAtTarget = targetNode;
                node.currentSpeed = 0;
                node.vx = 0;
                node.vy = 0;
                node.activePath = null;
            }
        } else if (step.do === 'sign') {
            node.specialAction = { type: 'sign', timer: 0, dur: step.dur || 12, startX: node.x, startY: node.y };
        } else if (step.do === 'measure') {
            node.specialAction = { type: 'measure', timer: 0, dur: step.dur || 3 };
        } else if (step.do === 'hop') {
            node.specialAction = { type: 'hop', timer: 0, dur: step.dur || 3 };
        } else if (step.do === 'sit') {
            node.specialAction = { type: 'sit', timer: 0, dur: step.dur || 18 };
        }
    }

    // تطبيق قيم الـ DOM (ترتيب عمق، transform، تحريك أطراف وبؤبؤ، أثر)
    function applyDOM() {
        // ترتيب العمق z-index حسب موضع القدم
        const sorted = [...charNodes].sort((a, b) => (a.y + a.def.height / 2) - (b.y + b.def.height / 2));
        sorted.forEach((node, rank) => {
            const z = 10 + rank;
            if (node.currentZ !== z) {
                node.currentZ = z;
                node.el.style.zIndex = String(z);
            }
        });

        // رسم كل شخصية
        charNodes.forEach(node => {
            const scaleX = node.customScaleX !== null ? node.customScaleX : node.facing;
            const posX = node.x - (node.def.width / 2);
            const posY = (node.y - (node.def.height / 2)) - node.bounce;

            node.el.style.transform = `translate3d(${posX.toFixed(1)}px, ${posY.toFixed(1)}px, 0) scaleX(${scaleX.toFixed(2)}) rotate(${node.tilt.toFixed(1)}deg)`;

            // حركة الأطراف
            const swing = Math.sin(node.walkPhase) * node.walkAmp;
            node.limbs.forEach(l => {
                let a = l.rest;
                if (node.def.id !== 'paper' && node.walkAmp > 0.01) {
                    if (l.k === 'll') a += 30 * swing;
                    else if (l.k === 'lr') a -= 30 * swing;
                    else if (l.k === 'al') a -= 26 * swing;
                    else a += 26 * swing;
                }
                if (Math.abs(a - l.last) > 0.15) {
                    l.last = a;
                    l.g.setAttribute('transform', `rotate(${a.toFixed(1)} ${l.j})`);
                }
            });

            // حركة البؤبؤ
            if (node.pupils.length > 0) {
                const px = node.pupilOffset.x.toFixed(2);
                const py = node.pupilOffset.y.toFixed(2);
                node.pupils.forEach(p => {
                    p.style.transform = `translate(${px}px, ${py}px)`;
                });
            }
        });

        // تحديث أثر القلم الرصاص
        if (pencilPolylineEl) {
            if (pencilTrail.length > 1) {
                const pts = pencilTrail.map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
                pencilPolylineEl.setAttribute('points', pts);
            } else {
                pencilPolylineEl.setAttribute('points', '');
            }
        }

        // تحديث أثر القلم الجاف
        if (penPolylineEl) {
            if (penTrail.length > 1) {
                const pts = penTrail.map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
                penPolylineEl.setAttribute('points', pts);
            } else {
                penPolylineEl.setAttribute('points', '');
            }
        }
    }

    // حركة Parallax وتتبع الماوس أثناء التفاعل النشط
    function applyActiveParallax(dt) {
        const winW = window.innerWidth || document.documentElement.clientWidth;
        const winH = window.innerHeight || document.documentElement.clientHeight;
        const centerX = winW / 2;
        const centerY = winH / 2;
        const isMobile = winW < 700;
        const homes = isMobile ? HOMES_MOBILE : HOMES_DESKTOP;
        const hasValidMouse = (mouseX > 0 && mouseY > 0 && !isMouseInsideCard);

        charNodes.forEach(node => {
            const home = homes[node.def.id] || { x: 0.5, y: 0.5 };
            let targetX = home.x * winW;
            let targetY = home.y * winH;
            let targetPupilX = 1.6;
            let targetPupilY = 0;

            if (hasValidMouse) {
                const relX = (mouseX - centerX) / centerX;
                const relY = (mouseY - centerY) / centerY;
                targetX += relX * node.def.depth;
                targetY += relY * node.def.depth;

                const dx = mouseX - node.x;
                const dy = mouseY - node.y;
                const dist = Math.hypot(dx, dy);
                if (dist > 1) {
                    const angle = Math.atan2(dy, dx);
                    const pupilDist = Math.min(2.8, (dist / 120) * 2.8);
                    targetPupilX = Math.cos(angle) * pupilDist;
                    targetPupilY = Math.sin(angle) * pupilDist;
                }
            }

            // حركة ناعمة بالعودة للمكان الأصلي في ~٨٠٠ms
            const smoothK = 1 - Math.exp(-dt * 5);
            const prevX = node.x;
            const prevY = node.y;
            node.x += (targetX - node.x) * smoothK;
            node.y += (targetY - node.y) * smoothK;
            node.vx = (node.x - prevX) / Math.max(dt, 1 / 1000);
            node.vy = (node.y - prevY) / Math.max(dt, 1 / 1000);
            node.currentSpeed = Math.hypot(node.vx, node.vy);

            node.facing = 1;
            node.tilt = 0;
            node.bounce = 0;
            node.walkAmp += (0 - node.walkAmp) * (1 - Math.exp(-dt * 15));

            node.pupilOffset.x += (targetPupilX - node.pupilOffset.x) * (1 - Math.exp(-dt * 8));
            node.pupilOffset.y += (targetPupilY - node.pupilOffset.y) * (1 - Math.exp(-dt * 8));
        });

        applyDOM();
    }

    // حلقة الحركة الرئيسية (Single requestAnimationFrame loop)
    let lastFrameTime = performance.now();
    function renderLoop(timestamp) {
        if (document.hidden || prefersReducedMotion) return;

        const now = timestamp || performance.now();
        const dt = Math.min((now - lastFrameTime) / 1000, 0.05);
        lastFrameTime = now;

        if (isIdle) {
            simulatePhysics(dt);
            applyDOM();
        } else {
            applyActiveParallax(dt);
        }

        animFrameId = requestAnimationFrame(renderLoop);
    }

    // بدء التشغيل عند اكتمال تحميل DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
