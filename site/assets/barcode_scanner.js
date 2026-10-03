/**
 * barcode_scanner.js — سكانر باركود مشترك (زر كاميرا جنب أي خانة كود).
 * الاستخدام: زر onclick="openBarcodeScanner('ID_الخانة')"
 * لما يتقرا الباركود، بيتحط في الخانة ويطلّق حدث input + Enter تلقائياً.
 *
 * تحسينات الكاميرا (2026-07-21):
 *  - فوكس مستمر (focusMode: continuous) — بيمنع الشرود للحاجات البعيدة
 *  - زووم افتراضي ×2 + أزرار ×1/×2/×3 — أكبر تحسينة لثبات الفوكس:
 *    الكادر بيبقى مقرّب على الباركود من غير ما تلزق الموبايل فيه
 *  - زرار كشاف 🔦 (لو الموبايل بيدعمه) — للإضاءة الضعيفة في المخزن
 *  - زرار تبديل الكاميرا 🔄 — موبايلات كتير المتصفح بيمسك فيها العدسة
 *    الغلط (wide)؛ الاختيار بيتحفظ فمرة واحدة وخلاص
 *  - كل الميزات دي بتظهر بس لو الجهاز بيدعمها — مفيش حاجة بتضرب
 */
(function () {
    let scanner = null;
    let scanning = false;
    let targetInputId = null;
    let torchOn = false;
    let keepOpen = false;      // وضع المسح المستمر (صفحة السكانر البعيد)
    let lastCode = '', lastSeen = 0, lastFired = 0;  // آخر كود · آخر مرة اتشاف · آخر مرة اتبعت

    /* ------------------------------------------------------------
     *  منع تكرار الكود في الوضع المستمر — 2026-08-11
     * ------------------------------------------------------------
     *  الكاميرا بتفك نفس الباركود ~15 مرة في الثانية طول ما هو قدامها،
     *  فوقفة واحدة على الكود كانت بتبعت عشرات النسخ للكمبيوتر.
     *
     *  القاعدة دلوقتي: **مسحة واحدة لكل وقفة**.
     *  الكود بيتقفل طول ما هو لسه في الكادر، وبيتفك لوحده بعد ما
     *  يختفي DUP_GONE_MS. يعني عشان تمسحه تاني: بعّد الكاميرا
     *  وارجع — حركة طبيعية وسريعة، مش انتظار عدّاد.
     *
     *  كود مختلف بيفك القفل فورًا، فمسح رف كامل بيفضل سريع زي ما هو.
     *
     *  عايز بدلها قفل زمني صارم (٥ ثواني من ساعة ما الكود دخل، حتى
     *  لو بعّدت الكاميرا)؟ غيّر السطر في onScan للنسخة المعلّقة تحته.
     * ------------------------------------------------------------ */
    const DUP_GONE_MS = 1500;   // اختفى من الكادر المدة دي = القفل يتفك
    const DUP_LOCK_MS = 5000;   // (للوضع الصارم البديل — مش مستخدم افتراضيًا)
    const LOCK_MSG    = 'نفس الكود — بعّد الكاميرا وارجع';
    const HINT_MSG    = 'وجّه الكاميرا على الباركود';
    let lockTimer = null;  // مراقب رسالة القفل في الوضع المستمر
    let camList = [];      // كاميرات الجهاز
    let camIndex = -1;     // المختارة حاليًا

    const LS_CAM  = 'bc_cam_id';   // آخر كاميرا اختارها المستخدم
    const LS_ZOOM = 'bc_zoom';     // آخر زووم

    // إنشاء نافذة السكانر (مرة واحدة)
    function ensureModal() {
        if (document.getElementById('bcScanModal')) return;
        const modal = document.createElement('div');
        modal.id = 'bcScanModal';
        modal.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(30,27,75,0.75);z-index:100000;align-items:center;justify-content:center;padding:16px;';
        modal.innerHTML =
            '<style>' +
              '#bcReader video,#bcReader canvas{width:100%!important;height:auto!important;max-width:100%!important;display:block;}' +
              '#bcReader img{max-width:100%!important;}' +
              '.bc-ctl{border:1px solid #d9d6ee;background:#f6f5fb;color:#1E1B4B;border-radius:10px;padding:8px 12px;font-size:14px;cursor:pointer;min-width:44px;}' +
              '.bc-ctl.on{background:#1E1B4B;color:#fff;border-color:#1E1B4B;}' +
              '.bc-ctl:disabled{opacity:0.4;cursor:default;}' +
            '</style>' +
            '<div style="background:#fff;border-radius:16px;padding:18px;max-width:420px;width:100%;box-shadow:0 18px 50px rgba(0,0,0,0.3);">' +
              '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">' +
                '<span style="font-weight:700;color:#1E1B4B;font-size:16px;"><i class="ti ti-camera"></i> امسح الباركود</span>' +
                '<button type="button" onclick="closeBarcodeScanner()" style="background:none;border:none;font-size:26px;color:#a8a4c0;cursor:pointer;line-height:1;" aria-label="إغلاق">&times;</button>' +
              '</div>' +
              '<div id="bcReader" style="width:100%;border-radius:12px;overflow:hidden;"></div>' +
              // شريط التحكم: زووم + كشاف + تبديل كاميرا (بيظهر منهم المدعوم بس)
              '<div id="bcCtls" style="display:flex;justify-content:center;gap:8px;margin-top:12px;flex-wrap:wrap;">' +
                '<button type="button" class="bc-ctl" id="bcZoom1" onclick="bcSetZoom(1)" aria-label="زووم واحد">×1</button>' +
                '<button type="button" class="bc-ctl" id="bcZoom2" onclick="bcSetZoom(2)" aria-label="زووم اتنين">×2</button>' +
                '<button type="button" class="bc-ctl" id="bcZoom3" onclick="bcSetZoom(3)" aria-label="زووم تلاتة">×3</button>' +
                '<button type="button" class="bc-ctl" id="bcTorch" onclick="bcToggleTorch()" style="display:none;" aria-label="الكشاف">كشاف</button>' +
                '<button type="button" class="bc-ctl" id="bcSwitch" onclick="bcSwitchCamera()" style="display:none;" aria-label="تبديل الكاميرا"><i class="ti ti-refresh"></i></button>' +
                '<button type="button" class="bc-ctl" id="bcSoundBtn" onclick="bcToggleSound()" aria-label="صوت المسح">صوت</button>' +
              '</div>' +
              '<p id="bcHint" style="text-align:center;color:#8884a8;font-size:13px;margin-top:10px;">وجّه الكاميرا على الباركود</p>' +
            '</div>';
        document.body.appendChild(modal);
    }

    // تحميل مكتبة html5-qrcode لو مش محمّلة
    // نعكس حالة الصوت المحفوظة على الزرار أول ما الشاشة تفتح
    function syncSoundBtn() {
        const b = document.getElementById('bcSoundBtn');
        if (b) {
            b.textContent = soundOn ? 'صوت' : 'صامت';
            b.classList.toggle('on', soundOn);
        }
    }

    function ensureLib(cb) {
        if (window.Html5Qrcode) { cb(); return; }
        const s = document.createElement('script');
        s.src = 'assets/vendor/html5-qrcode.min.js';
        s.onload = cb;
        s.onerror = function () { alert('تعذّر تحميل مكتبة الكاميرا.'); };
        document.head.appendChild(s);
    }

    function fixVideoElement() {
        const vid = document.querySelector('#bcReader video');
        if (vid) {
            vid.setAttribute('playsinline', 'true');
            vid.setAttribute('webkit-playsinline', 'true');
            vid.setAttribute('muted', 'true');
            vid.setAttribute('autoplay', 'true');
            vid.muted = true;
            vid.playsInline = true;
        }
    }

    function getCameraErrorMessage(err) {
        if (!navigator.mediaDevices) {
            return 'افتح الموقع من Safari نفسه مش من الأيقونة. اكتب الكود يدوياً.';
        }
        const errName = err ? (err.name || '') : '';
        if (errName === 'NotAllowedError' || errName === 'PermissionDeniedError') {
            return 'تم حظر الكاميرا: الإعدادات ← Safari ← الكاميرا ← سماح. اكتب الكود يدوياً.';
        }
        if (errName === 'NotReadableError' || errName === 'TrackStartError') {
            return 'الكاميرا مفتوحة في برنامج تاني. اكتب الكود يدوياً.';
        }
        return 'تعذّر تشغيل الكاميرا. اكتب الكود يدوياً.';
    }

    /* ============================================================
     *  ضبط الكاميرا بعد التشغيل: فوكس مستمر + زووم + إظهار المدعوم
     * ============================================================ */
    function tuneCamera() {
        if (!scanner || !scanning) return;
        fixVideoElement();
        let caps = {};
        try { caps = scanner.getRunningTrackCapabilities() || {}; } catch (e) { caps = {}; }

        // 1) فوكس مستمر — بيمنع الكاميرا تفكّس على الخلفية البعيدة
        if (Array.isArray(caps.focusMode) && caps.focusMode.indexOf('continuous') > -1) {
            scanner.applyVideoConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {});
        }

        // 2) الزووم: افتراضي ×2 (أو آخر اختيار) لو الجهاز بيدعم
        const ctls = document.getElementById('bcCtls');
        if (caps.zoom && typeof caps.zoom.max === 'number' && caps.zoom.max > 1) {
            const saved = parseFloat(localStorage.getItem(LS_ZOOM) || '2') || 2;
            bcSetZoom(saved, true);
            // نعطّل أزرار الزووم الأعلى من قدرة الكاميرا
            [1, 2, 3].forEach(function (z) {
                const b = document.getElementById('bcZoom' + z);
                if (b) b.disabled = z > caps.zoom.max;
            });
            if (ctls) ctls.style.display = 'flex';
        } else {
            // مفيش زووم: نخفي أزراره بس نسيب الشريط لو فيه كشاف/تبديل
            [1, 2, 3].forEach(function (z) {
                const b = document.getElementById('bcZoom' + z);
                if (b) b.style.display = 'none';
            });
        }

        // 3) الكشاف
        if (caps.torch === true) {
            const t = document.getElementById('bcTorch');
            if (t) { t.style.display = 'inline-block'; }
            if (ctls) ctls.style.display = 'flex';
        }

        // 4) تبديل الكاميرا (لو فيه أكتر من كاميرا خلفية أو على iOS)
        const isIos = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        if (camList.length > 1 || isIos) {
            const s = document.getElementById('bcSwitch');
            if (s) s.style.display = 'inline-block';
            if (ctls) ctls.style.display = 'flex';
        }
    }

    window.bcSetZoom = function (z, silent) {
        if (!scanner || !scanning) return;
        let caps = {};
        try { caps = scanner.getRunningTrackCapabilities() || {}; } catch (e) { return; }
        if (!caps.zoom) return;
        const val = Math.min(Math.max(z, caps.zoom.min || 1), caps.zoom.max);
        scanner.applyVideoConstraints({ advanced: [{ zoom: val }] })
            .then(function () {
                if (!silent) localStorage.setItem(LS_ZOOM, String(z));
                [1, 2, 3].forEach(function (n) {
                    const b = document.getElementById('bcZoom' + n);
                    if (b) b.classList.toggle('on', n === z);
                });
            }).catch(function () {});
    };

    window.bcToggleTorch = function () {
        if (!scanner || !scanning) return;
        torchOn = !torchOn;
        scanner.applyVideoConstraints({ advanced: [{ torch: torchOn }] })
            .then(function () {
                const t = document.getElementById('bcTorch');
                if (t) t.classList.toggle('on', torchOn);
            })
            .catch(function () { torchOn = !torchOn; });
    };

    window.bcSwitchCamera = function () {
        if (!camList.length) {
            Html5Qrcode.getCameras().then(function (cams) {
                camList = cams || [];
                if (camList.length > 1) {
                    const s = document.getElementById('bcSwitch');
                    if (s) s.style.display = 'inline-block';
                    camIndex = (camIndex + 1) % camList.length;
                    const cam = camList[camIndex];
                    localStorage.setItem(LS_CAM, cam.id);
                    restartWith(cam.id);
                } else if (camList.length === 1) {
                    const h = document.getElementById('bcHint');
                    if (h) h.textContent = 'مفيش كاميرا تانية متاحة للتبديل.';
                }
            }).catch(function (err) {
                const h = document.getElementById('bcHint');
                if (h) h.textContent = getCameraErrorMessage(err);
            });
            return;
        }
        camIndex = (camIndex + 1) % camList.length;
        const cam = camList[camIndex];
        localStorage.setItem(LS_CAM, cam.id);
        restartWith(cam.id);
    };

    /* ============================================================
     *  اختيار الكاميرا الأنسب:
     *  المتصفح ساعات بيمسك العدسة الواسعة (wide/ultra) وفوكسها وحش
     *  على القريب. بنفلتر الخلفية وبنستبعد wide/ultra/tele لو فيه بديل.
     * ============================================================ */
    function pickCamera(cams) {
        // آخر اختيار للمستخدم بيكسب دايمًا
        const savedId = localStorage.getItem(LS_CAM);
        if (savedId) {
            const idx = cams.findIndex(function (c) { return c.id === savedId; });
            if (idx > -1) return idx;
        }
        const back = [];
        cams.forEach(function (c, i) {
            const L = (c.label || '').toLowerCase();
            if (L.indexOf('front') === -1 && L.indexOf('أمامية') === -1) back.push(i);
        });
        const pool = back.length ? back : cams.map(function (_, i) { return i; });
        // نفضّل اللي مش wide/ultra/tele
        const main = pool.filter(function (i) {
            const L = (cams[i].label || '').toLowerCase();
            return L.indexOf('wide') === -1 && L.indexOf('ultra') === -1 && L.indexOf('tele') === -1;
        });
        return (main.length ? main : pool)[0];
    }

    function startConfig() {
        return {
            fps: 10,
            qrbox: { width: 250, height: 150 },
            // نطلب فوكس مستمر من أول لحظة (الأجهزة اللي مش بتدعمه بتتجاهله)
            videoConstraints: {
                facingMode: 'environment',
                focusMode: 'continuous',
                width: { ideal: 1280 }   // دقة أعلى = قراءة أسرع للباركود الصغير
            }
        };
    }

    // ---------------------------------------------------------------
    //  صفّارة المسح
    //  Web Audio بدل ملف صوت: مفيش ملف يتحمّل، بيشتغل من غير نت،
    //  ومفيش تأخير أول مرة. الصوت بيتولّد في اللحظة.
    // ---------------------------------------------------------------
    let audioCtx = null;
    let soundOn = true;
    try {
        soundOn = localStorage.getItem('bcSound') !== '0';
    } catch (e) { /* بعض المتصفحات بتمنع localStorage */ }

    function beep(ok) {
        if (!soundOn) return;
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            if (!audioCtx) audioCtx = new AC();
            // iOS بيوقف السياق لحد أول تفاعل — بنفكّه هنا
            if (audioCtx.state === 'suspended') audioCtx.resume();

            const t = audioCtx.currentTime;
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'square';
            // نغمة عالية قصيرة للنجاح، ونغمة واطية للخطأ
            osc.frequency.setValueAtTime(ok ? 2000 : 380, t);
            // ارتفاع وهبوط سريع عشان مايبقاش فيه طقطقة
            gain.gain.setValueAtTime(0.0001, t);
            gain.gain.exponentialRampToValueAtTime(0.22, t + 0.008);
            gain.gain.exponentialRampToValueAtTime(0.0001, t + (ok ? 0.10 : 0.22));
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start(t);
            osc.stop(t + (ok ? 0.12 : 0.24));
        } catch (e) { /* الصوت مش ضروري — منوقفش المسح عشانه */ }
    }

    // تشغيل/إيقاف الصوت (زرار السماعة في شاشة الكاميرا)
    window.bcToggleSound = function () {
        soundOn = !soundOn;
        try { localStorage.setItem('bcSound', soundOn ? '1' : '0'); } catch (e) {}
        const b = document.getElementById('bcSoundBtn');
        if (b) {
            b.textContent = soundOn ? 'صوت' : 'صامت';
            b.classList.toggle('on', soundOn);
        }
        if (soundOn) beep(true);   // عيّنة عشان يسمع إنه اشتغل
    };

    function onScan(decodedText) {
        // في الوضع المستمر: مسحة واحدة لكل وقفة — شوف الشرح فوق عند الثوابت
        if (keepOpen) {
            const now = Date.now();
            //  النسخة الصارمة (٥ ثواني بالعدّاد، والبعد عن الكود مايفكّش):
            //  شيل السطر اللي تحت وحط بدله:
            //      if (decodedText === lastCode && now - lastFired < DUP_LOCK_MS) {
            //  وامسح سطر «lastSeen = now» اللي جواه.
            if (decodedText === lastCode && now - lastSeen < DUP_GONE_MS) {
                lastSeen = now;                 // لسه في الكادر — القفل مستمر
                // الرسالة بتتكتب مرة واحدة بس (الدالة دي بتتنده ~15 مرة/ثانية)
                const h = document.getElementById('bcHint');
                if (h && h.textContent !== LOCK_MSG) h.textContent = LOCK_MSG;
                return;                          // من غير صوت ولا إرسال
            }
            lastCode = decodedText; lastSeen = now; lastFired = now;
        }
        beep(true);
        const inp = document.getElementById(targetInputId);
        if (inp) {
            inp.value = decodedText;
            // نطلّق input (عشان lookup الاسم) ثم Enter (عشان الإضافة)
            inp.dispatchEvent(new Event('input', { bubbles: true }));
            if (!keepOpen) { closeBarcodeScanner(); inp.focus(); }
            const ev = new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', keyCode: 13, bubbles: true });
            inp.dispatchEvent(ev);
            // إشارة بصرية سريعة في الوضع المستمر
            if (keepOpen) {
                const h = document.getElementById('bcHint');
                if (h) { h.textContent = '✓ ' + decodedText; setTimeout(function(){ if (h && h.textContent !== LOCK_MSG) h.textContent = HINT_MSG; }, 1200); }
            }
        } else if (!keepOpen) {
            closeBarcodeScanner();
        }
    }

    function restartWith(camId) {
        if (!scanner) return;
        const go = function () {
            scanning = false; torchOn = false;
            const cfg = startConfig();
            delete cfg.videoConstraints.facingMode; // بنحدد كاميرا بعينها
            cfg.videoConstraints.deviceId = { exact: camId };
            scanner.start({ deviceId: { exact: camId } }, cfg, onScan, function () {})
                .then(function () {
                    scanning = true;
                    fixVideoElement();
                    setTimeout(function() {
                        fixVideoElement();
                        tuneCamera();
                    }, 400);
                })
                .catch(function (err) {
                    const h = document.getElementById('bcHint');
                    if (h) h.textContent = getCameraErrorMessage(err);
                });
        };
        // 🔴 `go` بتنده `scanner.start()` واللي بترمي **synchronous** لو
        //    المكتبة لسه جوّه انتقال حالة. جوه `.then()` الرمية دي بتبقى
        //    promise مرفوضة من غير ما حد يمسكها، فكانت بتطلع في السجل
        //    «Unhandled promise: Cannot transition to a new state».
        const safeGo = function () {
            try { go(); } catch (e) {
                const h = document.getElementById('bcHint');
                if (h) h.textContent = 'الكاميرا مشغولة، جرّب تاني.';
            }
        };
        if (scanning) scanner.stop().then(safeGo).catch(safeGo); else safeGo();
    }

    window.openBarcodeScanner = function (inputId, opts) {
        targetInputId = inputId;
        keepOpen = !!(opts && opts.keepOpen);
        lastCode = ''; lastSeen = 0; lastFired = 0;
        ensureModal();
        syncSoundBtn();
        const modal = document.getElementById('bcScanModal');
        modal.style.display = 'flex';
        const hint = document.getElementById('bcHint');
        hint.textContent = HINT_MSG;

        if (!navigator.mediaDevices) {
            hint.textContent = getCameraErrorMessage();
            return;
        }

        // مراقب بسيط: أول ما الكود يبعد عن الكادر، رسالة القفل تتشال
        // (محلي بالكامل — مفيش أي نداء للسيرفر)
        clearInterval(lockTimer);
        if (keepOpen) {
            lockTimer = setInterval(function () {
                const h = document.getElementById('bcHint');
                if (h && h.textContent === LOCK_MSG && Date.now() - lastSeen >= DUP_GONE_MS) {
                    h.textContent = HINT_MSG;
                }
            }, 500);
        }

        const isIos = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

        ensureLib(function () {
            try {
                let formatsToSupport = undefined;
                if (window.Html5QrcodeSupportedFormats) {
                    formatsToSupport = [
                        Html5QrcodeSupportedFormats.EAN_13,
                        Html5QrcodeSupportedFormats.EAN_8,
                        Html5QrcodeSupportedFormats.UPC_A,
                        Html5QrcodeSupportedFormats.UPC_E,
                        Html5QrcodeSupportedFormats.CODE_128,
                        Html5QrcodeSupportedFormats.CODE_39
                    ];
                }
                const qrcodeConfig = {
                    verbose: false,
                    experimentalFeatures: { useBarCodeDetectorIfSupported: true }
                };
                if (formatsToSupport) {
                    qrcodeConfig.formatsToSupport = formatsToSupport;
                }
                scanner = scanner || new Html5Qrcode('bcReader', qrcodeConfig);

                function onStartSuccess() {
                    scanning = true;
                    fixVideoElement();
                    setTimeout(function() {
                        fixVideoElement();
                        tuneCamera();
                    }, 400);
                }

                function onStartFail(err) {
                    hint.textContent = getCameraErrorMessage(err);
                }

                if (isIos) {
                    // على iOS: نبدأ بـ facingMode على طول من غير getCameras()
                    scanner.start({ facingMode: 'environment' }, startConfig(), onScan, function () {})
                        .then(onStartSuccess)
                        .catch(onStartFail);
                } else {
                    // نجيب قايمة الكاميرات الأول (عشان الاختيار الذكي والتبديل)
                    Html5Qrcode.getCameras().then(function (cams) {
                        camList = cams || [];
                        if (camList.length) {
                            camIndex = pickCamera(camList);
                            restartWith(camList[camIndex].id);
                        } else {
                            scanner.start({ facingMode: 'environment' }, startConfig(), onScan, function () {})
                                .then(onStartSuccess)
                                .catch(onStartFail);
                        }
                    }).catch(function () {
                        scanner.start({ facingMode: 'environment' }, startConfig(), onScan, function () {})
                            .then(onStartSuccess)
                            .catch(onStartFail);
                    });
                }
            } catch (e) {
                hint.textContent = getCameraErrorMessage(e);
            }
        });
    };

    window.closeBarcodeScanner = function () {
        const modal = document.getElementById('bcScanModal');
        torchOn = false;
        clearInterval(lockTimer); lockTimer = null;
        if (scanner && scanning) {
            scanner.stop().then(function () {
                // `clear()` كمان بترمي synchronous جوه الـ`.then` — لو سابناها
                //  تطلع، تبقى promise مرفوضة من غير ماسك.
                try { scanner.clear(); } catch (e) {}
                scanning = false;
                if (modal) modal.style.display = 'none';
            }).catch(function () {
                scanning = false;
                if (modal) modal.style.display = 'none';
            });
        } else if (modal) {
            modal.style.display = 'none';
        }
    };
})();
