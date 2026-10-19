# غلاف Heartless (٣٠٠٠ بكسل) → تلوين الدم + شفافية + ماسك للراس عشان العنوان يبان وراها
from PIL import Image, ImageFilter, ImageDraw
import numpy as np

S = 2364                                   # ١٠٠٠ وحدة × ٢٫٣٦٥ بكسل/وحدة = ٦٠٠ dpi من غير تكبير
im = Image.open('src/portrait.jpg').convert('RGB')
im.paste((0, 0, 0), (2640, 2620, 3000, 3000))            # شيل علامة Parental Advisory
im = im.resize((S, S), Image.LANCZOS)
a = np.asarray(im).astype(np.float32) / 255

lum = a[..., 0] * .5 + a[..., 1] * .42 + a[..., 2] * .08
lum = np.clip((lum - .02) / .8, 0, 1) ** .9
stops = np.array([0, .3, .66, 1.0])
cols = np.array([[8, 3, 4], [74, 10, 15], [180, 20, 28], [255, 165, 140]], np.float32) / 255
out = np.stack([np.interp(lum, stops, cols[:, c]) for c in range(3)], -1)
rng = np.random.default_rng(11)
out = np.clip(out + rng.normal(0, .028, (S, S, 1)), 0, 1)

yy, xx = np.mgrid[0:S, 0:S].astype(np.float32)
alpha = (np.clip(np.minimum(xx, S - xx) / 420, 0, 1) ** 1.3 * np.clip((S - yy) / 560, 0, 1) ** 1.5
         * np.clip((yy - 80) / 620, 0, 1) ** 1.4)
rgb = Image.fromarray((out * 255).astype(np.uint8))
p = rgb.convert('RGBA'); p.putalpha(Image.fromarray((alpha * 255).astype(np.uint8))); p.save('portrait.png')

# ماسك الراس والشعر (بيضاوي ناعم) — نسخة من الصورة بتتحط فوق العنوان
k = S / 3000
m = Image.new('L', (S, S), 0); d = ImageDraw.Draw(m)
d.ellipse(((1460 - 650) * k, (1340 - 800) * k, (1460 + 650) * k, (1340 + 800) * k), fill=255)
m = m.filter(ImageFilter.GaussianBlur(45 * k))
h = rgb.convert('RGBA'); h.putalpha(Image.fromarray((np.asarray(m) * alpha).astype(np.uint8))); h.save('head.png')
print('ok', S)
