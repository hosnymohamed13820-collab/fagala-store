# بيكبّر البورتريه ويلوّنه بباليتة الدم (أسود → نبيتي → أحمر دم) بدل البرتقالي
from PIL import Image, ImageFilter
import numpy as np

W = 1880                                   # عرض الكانفاس بالنزيف على ٦٠٠ dpi
im = Image.open('src/portrait.jpg').convert('RGB')
im = im.resize((W, round(W * im.height / im.width)), Image.LANCZOS)
im = im.filter(ImageFilter.UnsharpMask(3, 60, 2))
a = np.asarray(im).astype(np.float32) / 255
H = a.shape[0]

lum = a[..., 0] * .55 + a[..., 1] * .4 + a[..., 2] * .05
lum = np.clip((lum - .03) / .78, 0, 1) ** .95

# جريدينت ٤ نقط: أسود فحمي · نبيتي · أحمر دم · وردي دافي للهايلايت
stops = np.array([0, .3, .68, 1.0])
cols = np.array([[8, 3, 4], [74, 10, 15], [176, 18, 27], [255, 150, 130]], np.float32) / 255
out = np.stack([np.interp(lum, stops, cols[:, c]) for c in range(3)], -1)

# جرين فيلم ثابت
rng = np.random.default_rng(11)
out = np.clip(out + rng.normal(0, .035, (H, W, 1)), 0, 1)

# الشفافية: تلاشي من الجناب ومن تحت عشان الصورة تدوب في الخلفية
yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
side = np.clip(np.minimum(xx, W - xx) / 260, 0, 1)
bottom = np.clip((H - yy) / 520, 0, 1) ** 1.4
top = np.clip(yy / 120, 0, 1)
alpha = side * bottom * top

rgba = Image.fromarray((out * 255).astype(np.uint8)).convert('RGBA')
rgba.putalpha(Image.fromarray((alpha * 255).astype(np.uint8)))
rgba.save('portrait.png')
print(rgba.size)
