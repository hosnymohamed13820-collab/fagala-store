# v3 — Heartless / Until I Bleed Out — ريلمي GT ماستر إديشن

| الملف | فيه إيه |
|---|---|
| `cover_print.png` | **ملف الطباعة** — ٧٩٫٥×١٦٥٫٢ مم (٣ مم نزيف) ~٦٠٠ dpi |
| `mockup.png` | موكاب على الموبايل |
| `cover_guide.png` | دليل المطبعة: خط القص + فتحة الكاميرا (٦٫٢ مم شمال · ٥٫٩ مم فوق · ٢٣×٤٠ مم) |
| `cover.html` / `mockup.html` | التصميم والموكاب |
| `dust.png` | تراب وخدوش فيلم (`prep_dust.py`) |
| `portrait.png` | البورتريه بباليتة الدم + شفافية الأطراف (`prep_portrait.py` من `src/portrait.jpg`) |

الباليتة: فحمي `#080304` · نبيتي `#4a0a0f` · دم `#b0121b` · أحمر فاتح `#e2281f`

ارندر: `python3 prep_portrait.py && NODE_PATH=$(npm root -g) node render.js`
