# «سين سيتي»: الصورة أبيض وأسود والأحمر بس (البدلة والدم) يفضل — وخلفية التيل تتشال
from PIL import Image, ImageFilter
import numpy as np

W = 2034                                   # ٨٦٠ وحدة × ٢٫٣٦٥ = ٦٠٠ dpi (تكبير ~١٫٥× بس)
im = Image.open('src/portrait.jpg').convert('RGB')
im = im.resize((W, round(W * im.height / im.width)), Image.LANCZOS).filter(ImageFilter.UnsharpMask(2.5, 70, 2))
a = np.asarray(im).astype(np.float32) / 255
H = a.shape[0]
r, g, b = a[..., 0], a[..., 1], a[..., 2]
mx, mn = a.max(2), a.min(2)
sat = (mx - mn) / (mx + 1e-4)
hue = np.where(mx == r, ((g - b) / (mx - mn + 1e-4)) % 6, np.where(mx == g, (b - r) / (mx - mn + 1e-4) + 2, (r - g) / (mx - mn + 1e-4) + 4)) * 60

# أبيض وأسود بكونتراست سينمائي
lum = r * .35 + g * .5 + b * .15
bw = np.clip((lum - .06) / .78, 0, 1) ** 1.15

# الأحمر: هيو قريب من الصفر + تشبّع عالي (البدلة والدم مش الجلد البرتقالي)
redness = np.clip((sat - .45) / .2, 0, 1) * np.clip(1 - np.minimum(hue, 360 - hue) / 14, 0, 1)
redness = np.asarray(Image.fromarray((redness * 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(2))).astype(np.float32) / 255
blood = np.stack([np.clip(bw * 1.25 + .05, 0, 1) * .86 + .06, bw * .1, bw * .12], -1)   # أحمر دم مش برتقالي
grey = np.stack([bw * .97, bw * .94, bw * .93], -1)                                        # رمادي دافي سنة
out = grey * (1 - redness[..., None]) + blood * redness[..., None]

# خلفية التيل/الأخضر → أسود
teal = np.clip(((g + b) / 2 - r - .04) / .12, 0, 1)
teal = np.asarray(Image.fromarray((teal * 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(6))).astype(np.float32) / 255
out *= (1 - teal * .96)[..., None]

# بقايا الخلفية ورا الراس → أسود (بره بيضاوي الراس وفوق الكتاف)
Y, X = np.mgrid[0:H, 0:W]
outside = (((X - 1020) / 700) ** 2 + ((Y - 1000) / 960) ** 2 > 1) & (Y < .63 * H)
om = np.asarray(Image.fromarray((outside * 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(40))).astype(np.float32) / 255
out *= (1 - om * .88)[..., None]

# كمان: سحق الرمادي الغامق حوالين الشعر (حرف البوكس الباهت) — بره بيضاوي الوش والودان
face = (((X - 1020) / 560) ** 2 + ((Y - 1180) / 640) ** 2 < 1)
fm = np.asarray(Image.fromarray(((~face & (Y < .62 * H)) * 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(50))).astype(np.float32) / 255
crushed = np.clip((out - .2) / .8, 0, 1)
out = out * (1 - fm[..., None]) + crushed * fm[..., None]

rng = np.random.default_rng(5)
out = np.clip(out + rng.normal(0, .03, (H, W, 1)), 0, 1)

yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
alpha = (np.clip(np.minimum(xx, W - xx) / 260, 0, 1) * np.clip((H - yy) / 700, 0, 1) ** 1.6 * np.clip(yy / 90, 0, 1))
p = Image.fromarray((out * 255).astype(np.uint8)).convert('RGBA'); p.putalpha(Image.fromarray((alpha * 255).astype(np.uint8)))
p.save('portrait.png'); print(p.size)
