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
    // وباراميتر ?lfx_t=150 لبدء التايملاين مباشرة من ثانية محددة (بريف ٦٢)
    let idleTimeoutMs = 90000;
    let initialTimelineSec = 0;
    let hasCustomT = false;
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const customIdle = parseInt(urlParams.get('lfx_idle'), 10);
        if (customIdle && customIdle > 0) {
            idleTimeoutMs = customIdle * 1000;
        }
        const tParam = urlParams.get('lfx_t');
        if (tParam !== null) {
            const parsedT = parseFloat(tParam);
            if (!isNaN(parsedT)) {
                initialTimelineSec = parsedT;
                hasCustomT = true;
                if (!customIdle) {
                    idleTimeoutMs = 300; // ادخل الخمول سريعاً لتسهيل تجربة التايملاين
                }
            }
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

    // =========================================================================
    // سكريبتات الخمول (٣٠٠ ثانية) — لكل شخصية سيناريو وحركة خاصة (بريف ٦٢)
    // الإحداثيات نسبية للشاشة [0, 1] وتمثل مركز الشخصية.
    // المنطقة الممنوعة (.login-card + 24px) بتُحسب ديناميكياً وتُدفع النقط للخارج.
    // =========================================================================
    const TIMELINE_DURATION = 300;

    const SCRIPTS = {
        // ١) قلم رصاص — يكتب على الشريط العلوي، يفكر، يجري في المطاردة، ويلف ويرجع
        pencil: [
            { t: 0,   to: [0.08, 0.10], dur: 0,  act: 'start' },
            { t: 0,   to: [0.30, 0.07], dur: 40, act: 'write' },
            { t: 40,  to: [0.30, 0.07], dur: 8,  act: 'think' },
            { t: 48,  to: [0.70, 0.08], dur: 30, act: 'write' },
            { t: 78,  to: [0.92, 0.14], dur: 32, act: 'write' },
            { t: 110, to: [0.93, 0.45], dur: 40, act: 'walk' },
            { t: 150, to: [0.93, 0.80], dur: 35, act: 'run' },
            { t: 185, to: [0.55, 0.93], dur: 55, act: 'walk' },
            { t: 240, to: [0.12, 0.88], dur: 20, act: 'walk' },
            { t: 260, to: [0.06, 0.50], dur: 20, act: 'walk' },
            { t: 280, to: [0.08, 0.10], dur: 20, act: 'walk' }
        ],

        // ٢) أستيكة — تمسح وراء القلم بتأخير ٤ ثواني، وتقف تتفرج على المطاردة ١٥٠–١٨٥ ثانية
        eraser: [
            { t: 0,   to: null,         dur: 150, act: 'follow_pencil', delay: 8 },
            { t: 150, to: [0.90, 0.30], dur: 35,  act: 'watch_chase' },
            { t: 185, to: null,         dur: 115, act: 'follow_pencil', delay: 8 }
        ],

        // ٣) قلم جاف — يمشي على الجنب الشمال، يمضي حلزونة وخط أزرق، يزور الأعلى ويرجع
        pen: [
            { t: 0,   to: [0.06, 0.35], dur: 0,  act: 'start' },
            { t: 0,   to: [0.07, 0.55], dur: 30, act: 'walk' },
            { t: 30,  to: [0.07, 0.55], dur: 40, act: 'sign' },
            { t: 70,  to: [0.10, 0.25], dur: 25, act: 'walk' },
            { t: 95,  to: [0.10, 0.25], dur: 25, act: 'pause' },
            { t: 120, to: [0.45, 0.05], dur: 40, act: 'walk' },
            { t: 160, to: [0.10, 0.25], dur: 40, act: 'walk' },
            { t: 200, to: [0.08, 0.72], dur: 15, act: 'walk' },
            { t: 215, to: [0.08, 0.72], dur: 45, act: 'sign' },
            { t: 260, to: [0.06, 0.35], dur: 40, act: 'walk' }
        ],

        // ٤) مسطرة — خطوط مستقيمة متعامدة فقط، تلف ٩٠ درجة وتقيس
        ruler: [
            { t: 0,   to: [0.10, 0.90], dur: 0,  act: 'start',      rot: 0 },
            { t: 0,   to: [0.40, 0.90], dur: 60, act: 'ortho_move', rot: 0 },
            { t: 60,  to: [0.40, 0.90], dur: 5,  act: 'measure',    rot: 0 },
            { t: 65,  to: [0.60, 0.90], dur: 65, act: 'ortho_move', rot: 0 },
            { t: 130, to: [0.60, 0.90], dur: 5,  act: 'measure',    rot: 0 },
            { t: 135, to: [0.90, 0.90], dur: 55, act: 'ortho_move', rot: 0 },
            { t: 190, to: [0.90, 0.62], dur: 50, act: 'ortho_move', rot: 90 },
            { t: 240, to: [0.90, 0.90], dur: 30, act: 'ortho_move', rot: 90 },
            { t: 270, to: [0.10, 0.90], dur: 30, act: 'ortho_move', rot: 0 }
        ],

        // ٥) برّاية — تتسكع في الركن، تبص لفوق، تطارد القلم بسرعة ×٢، وتنهج
        sharpener: [
            { t: 0,   to: [0.94, 0.92], dur: 140, act: 'pace', offset: 0.03 },
            { t: 140, to: [0.94, 0.92], dur: 10,  act: 'look_pencil' },
            { t: 150, to: null,         dur: 35,  act: 'chase_pencil', delay: 6 },
            { t: 185, to: [0.93, 0.80], dur: 15,  act: 'pant' },
            { t: 200, to: [0.94, 0.92], dur: 100, act: 'walk' }
        ],

        // ٦) ورقة — طفو جيبي ناعم زي ورقة في الهواء، بدون حركة أرجل، تقلب scaleX كل ٤٥ ثانية
        paper: [
            { t: 0, to: null, dur: 300, act: 'float' }
        ],

        // ٧) كتاب — يقرأ بحركة نبض، يمشي ببطء بنصف السرعة، ويتابع المطاردة
        book: [
            { t: 0,   to: [0.14, 0.88], dur: 90,  act: 'read' },
            { t: 90,  to: [0.30, 0.88], dur: 60,  act: 'walk' },
            { t: 150, to: [0.30, 0.88], dur: 35,  act: 'watch_chase' },
            { t: 185, to: [0.08, 0.62], dur: 35,  act: 'walk' },
            { t: 220, to: [0.08, 0.62], dur: 40,  act: 'read' },
            { t: 260, to: [0.14, 0.88], dur: 40,  act: 'walk' }
        ]
    };

    // استيفاء ناعم بين النقاط المتتالية
    function evalWaypointSegs(segs, sec) {
        let curSeg = segs[0];
        let prevTo = segs[0].to;
        for (let i = 0; i < segs.length; i++) {
            const s = segs[i];
            if (sec >= s.t) {
                curSeg = s;
                prevTo = (i > 0 && segs[i - 1].to) ? segs[i - 1].to : s.to;
            }
        }
        const dur = curSeg.dur || 1;
        const u = Math.max(0, Math.min(1, (sec - curSeg.t) / dur));
        // Smoothstep interpolation (3u^2 - 2u^3)
        const s = u * u * (3 - 2 * u);
        const p0 = prevTo || curSeg.to;
        const p1 = curSeg.to || prevTo;
        const nx = p0[0] + (p1[0] - p0[0]) * s;
        const ny = p0[1] + (p1[1] - p0[1]) * s;
        const dx = (p1[0] - p0[0]) * 6 * u * (1 - u);
        const dy = (p1[1] - p0[1]) * 6 * u * (1 - u);
        return { nx, ny, dx, dy, act: curSeg.act, seg: curSeg };
    }

    // موقع القلم الرصاص عند أي ثانية في التايملاين
    function getPencilNormPos(sec) {
        const s = ((sec % TIMELINE_DURATION) + TIMELINE_DURATION) % TIMELINE_DURATION;
        return evalWaypointSegs(SCRIPTS.pencil, s);
    }

    // حساب المنطقة الممنوعة لكارت تسجيل الدخول (+ 24px هامش)
    function getCardNormalizedBounds(winW, winH) {
        if (!cardRect || winW <= 0 || winH <= 0) return null;
        const margin = 24;
        return {
            minX: Math.max(0, (cardRect.left - margin) / winW),
            maxX: Math.min(1, (cardRect.right + margin) / winW),
            minY: Math.max(0, (cardRect.top - margin) / winH),
            maxY: Math.min(1, (cardRect.bottom + margin) / winH)
        };
    }

    // دفع أي نقطة تقع داخل كارت الدخول لأقرب حافة خارجية
    function avoidForbiddenCard(nx, ny, cardBox) {
        if (!cardBox) return { x: nx, y: ny };
        if (nx >= cardBox.minX && nx <= cardBox.maxX && ny >= cardBox.minY && ny <= cardBox.maxY) {
            const dLeft = Math.abs(nx - cardBox.minX);
            const dRight = Math.abs(cardBox.maxX - nx);
            const dTop = Math.abs(ny - cardBox.minY);
            const dBottom = Math.abs(cardBox.maxY - ny);
            const minD = Math.min(dLeft, dRight, dTop, dBottom);
            if (minD === dLeft) return { x: cardBox.minX, y: ny };
            if (minD === dRight) return { x: cardBox.maxX, y: ny };
            if (minD === dTop) return { x: nx, y: cardBox.minY };
            return { x: nx, y: cardBox.maxY };
        }
        return { x: nx, y: ny };
    }

    // على الموبايل: حصر الحركة في الشريط العلوي (y <= 0.16) أو السفلي (y >= 0.86)
    function clampMobileY(ny, winW) {
        if (winW >= 700) return ny;
        if (ny <= 0.16 || ny >= 0.86) return ny;
        return ny < 0.51 ? 0.14 : 0.88;
    }

    // تقييم حالة وموقع كل شخصية عند ثانية معينة
    function getCharacterIdleState(charId, sec, winW, winH, cardBox, pencilState) {
        let nx = 0.5;
        let ny = 0.5;
        let dx = 0;
        let dy = 0;
        let rot = 0;
        let customScaleX = null;
        let walkAmpTarget = 1;
        let isRunning = false;
        let targetLook = null;
        let act = 'walk';
        let emitTrail = null;
        let hopBounce = 0;

        const s = ((sec % TIMELINE_DURATION) + TIMELINE_DURATION) % TIMELINE_DURATION;

        if (charId === 'pencil') {
            const p = evalWaypointSegs(SCRIPTS.pencil, s);
            nx = p.nx;
            ny = p.ny;
            dx = p.dx;
            dy = p.dy;
            act = p.act;

            if (act === 'write') {
                emitTrail = 'pencil';
                walkAmpTarget = 0.8;
            } else if (act === 'think') {
                walkAmpTarget = 0;
                const st = s - p.seg.t;
                rot = Math.sin(st * 2.0) * 8; // يميل ويرجع وهو بيفكر
            } else if (act === 'run') {
                isRunning = true;
                walkAmpTarget = 1.0;
            } else {
                walkAmpTarget = 0.8;
            }

        } else if (charId === 'eraser') {
            if (s >= 150 && s < 185) {
                // تقف في (0.90, 0.30) وتبص تتابع المطاردة
                nx = 0.90;
                ny = 0.30;
                walkAmpTarget = 0;
                act = 'watch_chase';
                if (pencilState) {
                    targetLook = { x: pencilState.worldX, y: pencilState.worldY };
                }
            } else {
                // متأخرة ٤ ثواني وراء القلم
                const delayT = (s - 4 + TIMELINE_DURATION) % TIMELINE_DURATION;
                const p = getPencilNormPos(delayT);
                nx = p.nx;
                ny = p.ny;
                dx = p.dx;
                dy = p.dy;
                walkAmpTarget = 0.8;
                act = 'follow';
            }

        } else if (charId === 'pen') {
            const p = evalWaypointSegs(SCRIPTS.pen, s);
            act = p.act;

            if (act === 'sign') {
                emitTrail = 'pen';
                const st = s - p.seg.t;
                if (st < 28) {
                    // دورتين حلزونية
                    const loopAngle = (st / 14) * 2 * Math.PI;
                    nx = p.seg.to[0] + (st / 28) * 0.035 + 0.025 * Math.cos(loopAngle);
                    ny = p.seg.to[1] + 0.025 * Math.sin(loopAngle);
                    dx = -0.025 * Math.sin(loopAngle);
                    dy = 0.025 * Math.cos(loopAngle);
                    walkAmpTarget = 0.5;
                } else if (st < 34) {
                    // شرطة تحت الإمضاء
                    const u = (st - 28) / 6;
                    nx = (p.seg.to[0] - 0.01) + u * 0.07;
                    ny = p.seg.to[1] + 0.045;
                    dx = 0.07;
                    dy = 0;
                    walkAmpTarget = 0.7;
                } else {
                    // وقفة بعد الإمضاء
                    nx = p.seg.to[0] + 0.06;
                    ny = p.seg.to[1] + 0.045;
                    dx = 0;
                    dy = 0;
                    walkAmpTarget = 0;
                }
            } else if (act === 'pause') {
                nx = p.nx;
                ny = p.ny;
                walkAmpTarget = 0;
            } else {
                nx = p.nx;
                ny = p.ny;
                dx = p.dx;
                dy = p.dy;
                walkAmpTarget = 0.8;
            }

        } else if (charId === 'ruler') {
            const segs = SCRIPTS.ruler;
            let curSeg = segs[0];
            let prevTo = segs[0].to;
            for (let i = 0; i < segs.length; i++) {
                const sg = segs[i];
                if (s >= sg.t) {
                    curSeg = sg;
                    prevTo = (i > 0 && segs[i - 1].to) ? segs[i - 1].to : sg.to;
                }
            }
            act = curSeg.act;
            if (act === 'measure') {
                nx = curSeg.to[0];
                ny = curSeg.to[1];
                walkAmpTarget = 0;
                const st = s - curSeg.t;
                // تلف أفقي وترجع (تقيس)
                rot = Math.sin((st / (curSeg.dur || 5)) * Math.PI) * 90;
            } else if (act === 'ortho_move') {
                const dur = curSeg.dur || 1;
                const u = Math.max(0, Math.min(1, (s - curSeg.t) / dur));
                // حركة مستقيمة فقط (Linear) دون منحنيات
                nx = prevTo[0] + (curSeg.to[0] - prevTo[0]) * u;
                ny = prevTo[1] + (curSeg.to[1] - prevTo[1]) * u;
                dx = curSeg.to[0] - prevTo[0];
                dy = curSeg.to[1] - prevTo[1];
                rot = curSeg.rot || 0;
                walkAmpTarget = 0.8;
            } else {
                nx = curSeg.to[0];
                ny = curSeg.to[1];
                rot = curSeg.rot || 0;
                walkAmpTarget = 0;
            }

        } else if (charId === 'sharpener') {
            if (s < 140) {
                // تتمشى رايح جاي خطوتين في الركن
                nx = 0.94 + 0.03 * Math.sin(s * 0.25);
                ny = 0.92;
                dx = 0.03 * 0.25 * Math.cos(s * 0.25);
                dy = 0;
                walkAmpTarget = 0.5;
                act = 'pace';
            } else if (s >= 140 && s < 150) {
                // تبص فوق ناحية القلم
                nx = 0.94;
                ny = 0.92;
                walkAmpTarget = 0;
                act = 'look_pencil';
                if (pencilState) {
                    targetLook = { x: pencilState.worldX, y: pencilState.worldY };
                }
            } else if (s >= 150 && s < 185) {
                // تجري ورا القلم متأخرة ثانيتين بسرعة مضاعفة
                const delayT = (s - 2 + TIMELINE_DURATION) % TIMELINE_DURATION;
                const p = getPencilNormPos(delayT);
                nx = p.nx;
                ny = p.ny;
                dx = p.dx;
                dy = p.dy;
                isRunning = true;
                walkAmpTarget = 1.0;
                act = 'chase';
            } else if (s >= 185 && s < 200) {
                // تقف لاهثة وتنط ٣ مرات
                nx = 0.93;
                ny = 0.80;
                walkAmpTarget = 0;
                act = 'pant';
                const st = s - 185;
                if (st < 7.5) {
                    // ٣ نطات صغيرة
                    const hop = Math.max(0, Math.sin(st * Math.PI / 2.5));
                    hopBounce = hop * 5.0;
                }
            } else {
                // ترجع ببطء للركن
                const u = (s - 200) / 100;
                const sm = u * u * (3 - 2 * u);
                nx = 0.93 + (0.94 - 0.93) * sm;
                ny = 0.80 + (0.92 - 0.80) * sm;
                dx = 0.01;
                dy = 0.12;
                walkAmpTarget = 0.4;
                act = 'walk_back';
            }

        } else if (charId === 'paper') {
            // ورقة طايرة — مسار جيبي هادي، مفيش حركة رجلين
            nx = 0.50 + 0.35 * Math.sin((s / 300) * 2 * Math.PI - Math.PI / 2);
            ny = (winW < 700) ? (0.08 + 0.04 * Math.sin(s * 0.4)) : (0.20 + 0.05 * Math.sin(s * 0.4));
            rot = 12 * Math.cos(s * 0.4);
            const cycle = s % 45;
            customScaleX = cycle < 1 ? Math.cos(cycle * 2 * Math.PI) : 1;
            walkAmpTarget = 0;
            act = 'float';

        } else if (charId === 'book') {
            const p = evalWaypointSegs(SCRIPTS.book, s);
            act = p.act;
            if (act === 'read') {
                nx = p.seg.to[0];
                ny = p.seg.to[1];
                walkAmpTarget = 0;
                // يتفتح ويتقفل ببطء
                customScaleX = 1 + 0.075 * (1 + Math.sin((s / 4) * 2 * Math.PI));
            } else if (act === 'watch_chase') {
                nx = p.seg.to[0];
                ny = p.seg.to[1];
                walkAmpTarget = 0;
                if (pencilState) {
                    targetLook = { x: pencilState.worldX, y: pencilState.worldY };
                }
            } else {
                nx = p.nx;
                ny = p.ny;
                dx = p.dx;
                dy = p.dy;
                // نصف سرعة الباقي
                walkAmpTarget = 0.4;
            }
        }

        // تطبيق شروط الموبايل والمنطقة الممنوعة
        ny = clampMobileY(ny, winW);
        const avoided = avoidForbiddenCard(nx, ny, cardBox);
        nx = avoided.x;
        ny = avoided.y;

        return {
            nx,
            ny,
            dx,
            dy,
            rot,
            customScaleX,
            walkAmpTarget,
            isRunning,
            targetLook,
            act,
            emitTrail,
            hopBounce
        };
    }

    // حالة التطبيق والمتغيرات العامة
    let containerEl = null;
    let loginCardEl = null;
    let cardRect = null;
    let charNodes = [];

    // عناصر SVG الخاصة بالأثر
    let trailsSvg = null;
    let pencilPolylineEl = null;
    let penPolylineEl = null;
    let pencilTrail = [];
    let penTrail = [];

    let mouseX = -9999;
    let mouseY = -9999;
    let isMouseInsideCard = false;
    let isIdle = false;
    let isFirstIdleFrame = true;
    let initTimestamp = Date.now();
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

        // إنشاء طبقة SVG للأثر المشترك (قلم رصاص وقلم جاف)
        trailsSvg = containerEl.querySelector('.lfx-trails');
        if (!trailsSvg) {
            trailsSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            trailsSvg.setAttribute('class', 'lfx-trails');
            trailsSvg.setAttribute('aria-hidden', 'true');
            trailsSvg.innerHTML = `
                <polyline id="lfxPencilTrail" fill="none" stroke="#64748b" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" opacity="0.75" />
                <polyline id="lfxPenTrail" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" opacity="0.85" />
            `;
            containerEl.insertBefore(trailsSvg, containerEl.firstChild);
        }
        pencilPolylineEl = trailsSvg.querySelector('#lfxPencilTrail');
        penPolylineEl = trailsSvg.querySelector('#lfxPenTrail');

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
                facing: 1,
                curX: 0,
                curY: 0,
                curPupilX: 0,
                curPupilY: 0,
                curRot: 0,
                baseX: 0,
                baseY: 0
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

        // لو تم تمرير ?lfx_t= ابدأ وضع الخمول فوراً بالتوقيت المحدد
        if (hasCustomT) {
            startIdleMode();
        }

        // بدء حلقة الحركة إذا لم يكن المستخدم يفضل تقليل الحركة
        if (!prefersReducedMotion) {
            animFrameId = requestAnimationFrame(renderLoop);
        }
    }

    // مكان كل كاركتر من غير الـtransform — بيتحسب مرة واحدة ومع الـresize بس
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
        if (hasCustomT && Date.now() - initTimestamp < 1500) return;
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
        isFirstIdleFrame = true;
        idleStartTime = performance.now() - (initialTimelineSec * 1000);
        if (containerEl) {
            containerEl.classList.add('lfx-is-idle');
        }
    }

    function stopIdleMode() {
        isIdle = false;
        pencilTrail = [];
        penTrail = [];
        if (pencilPolylineEl) pencilPolylineEl.setAttribute('points', '');
        if (penPolylineEl) penPolylineEl.setAttribute('points', '');
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

    // حلقة الحركة الرئيسية (Single requestAnimationFrame loop)
    function renderLoop(timestamp) {
        if (document.hidden || prefersReducedMotion) return;

        const winW = window.innerWidth || document.documentElement.clientWidth;
        const winH = window.innerHeight || document.documentElement.clientHeight;
        const centerX = winW / 2;
        const centerY = winH / 2;

        const cardBox = getCardNormalizedBounds(winW, winH);
        const hasValidMouse = (mouseX > 0 && mouseY > 0 && !isMouseInsideCard);

        let elapsedSec = 0;
        let pencilState = null;
        let eraserWorldX = -9999;
        let eraserWorldY = -9999;

        if (isIdle) {
            elapsedSec = ((timestamp - idleStartTime) / 1000) % TIMELINE_DURATION;
            // حساب موقع القلم المرجعي لحركة الأستيكة والبرّاية وتتبع العيون
            const pIdeal = getCharacterIdleState('pencil', elapsedSec, winW, winH, cardBox, null);
            pencilState = {
                worldX: pIdeal.nx * winW,
                worldY: pIdeal.ny * winH
            };
        }

        // إيجاد مركز الأستيكة لمسح أثر القلم عند اللمس
        const eraserNode = charNodes.find(n => n.def.id === 'eraser');
        if (eraserNode) {
            eraserWorldX = eraserNode.baseX + eraserNode.curX + (eraserNode.def.width / 2);
            eraserWorldY = eraserNode.baseY + eraserNode.curY + (eraserNode.def.height / 2);
        }

        charNodes.forEach(node => {
            let targetX = 0;
            let targetY = 0;
            let targetPupilX = 0;
            let targetPupilY = 0;
            let targetRot = 0;
            let scaleX = 1;
            let bounce = 0;
            let charWalkAmpTarget = 0;
            let isRunning = false;

            if (isIdle) {
                // وضع الخمول: سكريبت الحركة الخاص بالشخصية
                const state = getCharacterIdleState(node.def.id, elapsedSec, winW, winH, cardBox, pencilState);

                const targetWorldX = state.nx * winW;
                const targetWorldY = state.ny * winH;

                targetX = targetWorldX - node.baseX - (node.def.width / 2);
                targetY = targetWorldY - node.baseY - (node.def.height / 2);

                if (isFirstIdleFrame) {
                    node.curX = targetX;
                    node.curY = targetY;
                    node.prevX = targetX;
                    node.prevY = targetY;
                }

                targetRot = state.rot;
                charWalkAmpTarget = state.walkAmpTarget;
                isRunning = state.isRunning;
                bounce = state.hopBounce || 0;

                // اتجاه الوجه (scaleX(-1) عند المشي يساراً)
                const vx = targetX - node.curX;
                if (node.def.id !== 'paper' && node.def.id !== 'ruler') {
                    if (vx < -0.2) {
                        node.facing = -1;
                    } else if (vx > 0.2) {
                        node.facing = 1;
                    }
                }

                if (node.def.id === 'paper') {
                    scaleX = state.customScaleX !== null ? state.customScaleX : 1;
                } else if (node.def.id === 'book' && state.customScaleX !== null) {
                    scaleX = state.customScaleX;
                } else if (node.def.id === 'ruler') {
                    scaleX = 1; // المسطرة تلف ٩٠ درجة ولا تُقلب
                } else {
                    scaleX = node.facing;
                }

                // اتجاه نظر العينين أثناء الخمول
                if (state.targetLook) {
                    const charMidX = node.baseX + node.curX + (node.def.width / 2);
                    const charMidY = node.baseY + node.curY + (node.def.height / 2);
                    const ldx = state.targetLook.x - charMidX;
                    const ldy = state.targetLook.y - charMidY;
                    const angle = Math.atan2(ldy, ldx);
                    targetPupilX = Math.cos(angle) * 2.2 * node.facing;
                    targetPupilY = Math.sin(angle) * 2.2;
                } else {
                    const vy = targetY - node.curY;
                    const moveSpeed = Math.hypot(vx, vy);
                    if (moveSpeed > 0.25) {
                        const angle = Math.atan2(vy, vx);
                        targetPupilX = Math.cos(angle) * 2.2 * node.facing;
                        targetPupilY = Math.sin(angle) * 2.2;
                    } else {
                        targetPupilX = 1.6;
                        targetPupilY = 0;
                    }
                }

                // تتبع الأثر (Trail) للقلم الرصاص والقلم الجاف
                const curWorldX = node.baseX + node.curX + (node.def.width / 2);
                const curWorldY = node.baseY + node.curY + (node.def.height / 2);

                // نقطة كل ١٠٠ms لكل قلم لوحده — كل فريم كان بيخلّي الـ٦٠ نقطة = ثانية واحدة (~٨px تحت رجله)
                const trailTick = Math.abs(elapsedSec - (node.lastTrailSec ?? -1)) >= 0.1;
                if (trailTick && state.emitTrail) node.lastTrailSec = elapsedSec;
                if (!trailTick) {
                    // لسه بدري على نقطة جديدة
                } else if (state.emitTrail === 'pencil') {
                    const tipX = curWorldX;
                    const tipY = curWorldY + (node.def.height * 0.44) + Math.sin(elapsedSec * 14) * 2;
                    pencilTrail.push({ x: tipX, y: tipY, t: elapsedSec });
                } else if (state.emitTrail === 'pen') {
                    const penTipX = curWorldX;
                    const penTipY = curWorldY + (node.def.height * 0.42);
                    penTrail.push({ x: penTipX, y: penTipY, t: elapsedSec });
                }

            } else if (hasValidMouse) {
                // وضع الـ Parallax وتتبع الماوس
                const relX = (mouseX - centerX) / centerX;
                const relY = (mouseY - centerY) / centerY;

                targetX = relX * node.def.depth;
                targetY = relY * node.def.depth;
                node.facing = 1;
                scaleX = 1;

                const charMidX = node.baseX + node.curX + (node.def.width / 2);
                const charMidY = node.baseY + node.curY + (node.def.height / 2);

                const dx = mouseX - charMidX;
                const dy = mouseY - charMidY;
                const dist = Math.hypot(dx, dy);

                if (dist > 1) {
                    const angle = Math.atan2(dy, dx);
                    const pupilDist = Math.min(2.8, dist / 120 * 2.8);
                    targetPupilX = Math.cos(angle) * pupilDist;
                    targetPupilY = Math.sin(angle) * pupilDist;
                }
            } else {
                // عودة للمركز
                targetX = 0;
                targetY = 0;
                node.facing = 1;
                scaleX = 1;
            }

            // حركة ناعمة بـ lerp (العودة السريعة عند التفاعل ~800ms)
            const easeFactor = isIdle ? 0.05 : 0.08;
            node.curX += (targetX - node.curX) * easeFactor;
            node.curY += (targetY - node.curY) * easeFactor;
            node.curRot += (targetRot - node.curRot) * easeFactor;

            node.curPupilX += (targetPupilX - node.curPupilX) * 0.12;
            node.curPupilY += (targetPupilY - node.curPupilY) * 0.12;

            // حساب سرعة الحركة الفعلية في الفريم
            const speed = Math.hypot(node.curX - node.prevX, node.curY - node.prevY);
            node.prevX = node.curX;
            node.prevY = node.curY;

            // حركة المشي والأرجل: الرجلين بتتحرك بس وهو ماشي وسرعتها قد سرعته
            if (node.def.id === 'paper') {
                node.walkAmp = 0;
            } else {
                const targetAmp = (charWalkAmpTarget > 0 && speed > 0.05)
                    ? Math.min(1, speed / (isRunning ? 0.7 : 0.3)) * charWalkAmpTarget
                    : 0;
                node.walkAmp += (targetAmp - node.walkAmp) * 0.15;
                if (node.walkAmp < 0.01) node.walkAmp = 0;

                const stepSpeed = isRunning ? (0.04 + speed * 0.55) : (0.02 + speed * 0.35);
                node.walkPhase += stepSpeed;
            }

            // نطّة صغيرة مع كل خطوة (2–3px)
            if (node.def.id !== 'paper' && node.walkAmp > 0.05 && bounce === 0) {
                bounce = Math.abs(Math.sin(node.walkPhase)) * 2.5 * node.walkAmp;
            }

            // ميل خفيف لقدّام وقت السرعة
            let finalRot = node.curRot;
            if (isIdle && node.def.id !== 'paper' && node.def.id !== 'ruler') {
                finalRot += node.facing * Math.min(speed * 1.6, 7);
            }

            // تطبيق الـ transform على الكاركتر
            node.el.style.transform = `translate3d(${node.curX.toFixed(2)}px, ${(node.curY - bounce).toFixed(2)}px, 0) scaleX(${scaleX.toFixed(2)}) rotate(${finalRot.toFixed(1)}deg)`;

            // حركة الأطراف: أرجل متأرجحة أثناء السير وثابتة تماماً عند الوقوف
            const swing = Math.sin(node.walkPhase) * node.walkAmp;
            node.limbs.forEach(l => {
                let a = l.rest;
                if (node.def.id !== 'paper' && node.walkAmp > 0.01) {
                    if (l.k === 'll') a += 30 * swing;
                    else if (l.k === 'lr') a -= 30 * swing;
                    else if (l.k === 'al') a += -26 * swing;
                    else a += -26 * swing;
                }
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

        if (isIdle && isFirstIdleFrame) {
            isFirstIdleFrame = false;
        }

        // تحديث أثر القلم الرصاص (يبهت على ٦ ثواني ويمسح عند ملامسة الأستيكة)
        if (isIdle && pencilPolylineEl) {
            pencilTrail = pencilTrail.filter(pt => {
                // مسح الأثر إذا اقتربت منه الأستيكة (أقل من 48px)
                const distToEraser = Math.hypot(pt.x - eraserWorldX, pt.y - eraserWorldY);
                if (distToEraser < 48) return false;
                // ١٠ ثواني مش ٦ — الأستيكة ورا القلم بـ٨ ثواني، فهي اللي بتمسحه مش بيبهت لوحده
                const age = ((elapsedSec - pt.t + TIMELINE_DURATION) % TIMELINE_DURATION);
                return age <= 10;
            });
            if (pencilTrail.length > 100) {
                pencilTrail.splice(0, pencilTrail.length - 100);
            }
            const pts = pencilTrail.map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
            pencilPolylineEl.setAttribute('points', pts);
        }

        // تحديث أثر القلم الجاف (حبر أزرق بيبهت على ٦ ثواني)
        if (isIdle && penPolylineEl) {
            penTrail = penTrail.filter(pt => {
                const age = ((elapsedSec - pt.t + TIMELINE_DURATION) % TIMELINE_DURATION);
                return age <= 6;
            });
            if (penTrail.length > 60) {
                penTrail.splice(0, penTrail.length - 60);
            }
            const pts = penTrail.map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
            penPolylineEl.setAttribute('points', pts);
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
