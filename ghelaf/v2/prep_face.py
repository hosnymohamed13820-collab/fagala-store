# بيكبّر وش ويكيند ويعالجه (كيرف + هاف تون + جرين) عشان التكبير يبان مقصود مش مكسّر
from PIL import Image, ImageFilter, ImageDraw
import numpy as np

W, H = 1880, 2900                     # العرض = عرض الكانفاس بالنزيف على ٦٠٠ dpi
base = np.asarray(Image.open('../photo.png').convert('RGB')).astype(np.float32)
# شيل حروف AFTER HOURS المتكررة ورا الراس: جوه المستطيل الأحمر وبره بيضاوي الوش
Y, X = np.mgrid[0:base.shape[0], 0:base.shape[1]]
inface = ((X - 855) / 300) ** 2 + ((Y - 990) / 410) ** 2 < 1
panel = (((X > 150) & (X < 1540) & (Y > 360) & (Y < 1440)) | ((X < 760) & (Y > 1300) & (Y < 1800))) & ~inface
panel &= ~((X > 1150) & (Y > 1400))   # الياقة اليمين تفضل
pm = np.asarray(Image.fromarray((panel * 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(30))).astype(np.float32) / 255
mid = np.clip((base[..., 0] - 30) / 20, 0, 1)
base *= (1 - .85 * pm * mid)[..., None]
# ماسك الشخص (الشعر أسود جداً، خلفية المستطيل أحمر متوسط) — عشان دايرة الضوء تبقى وراه
orig = np.asarray(Image.open('../photo.png').convert('RGB')).astype(np.float32)
chroma = Image.fromarray(np.clip((orig[..., 0] - orig[..., 1]) * 8, 0, 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(7))
chroma = np.asarray(chroma).astype(np.float32) / 8     # الشعر رمادي غامق، الخلفية حمرا
person = inface | (chroma < 9) | (Y >= 1440)   # فوق وجنب المستطيل أسود أصلاً — يبقى شفاف عشان الدايرة
pmask = Image.fromarray((person * 255).astype(np.uint8)).filter(ImageFilter.MaxFilter(15)).filter(ImageFilter.MinFilter(15))   # سد الخروم جوه الشعر
pmask = pmask.filter(ImageFilter.MinFilter(15)).filter(ImageFilter.MaxFilter(15)).filter(ImageFilter.GaussianBlur(2))   # شيل النقط الشاردة
CROP = (300, 330, 1460, 2075)
src = Image.fromarray(base.astype(np.uint8)).crop(CROP)   # الوش والشعر والكتاف
img = src.resize((W, int(W * src.height / src.width)), Image.LANCZOS).crop((0, 0, W, H))
img = img.filter(ImageFilter.GaussianBlur(2.5))
a = np.asarray(img).astype(np.float32) / 255

# كيرف: يطفّي حروف AFTER HOURS الباهتة اللي ورا ويقوّي الإضاءة على الشاش
lum = a[..., 0] * .75 + a[..., 1] * .2 + a[..., 2] * .05
t = np.clip((lum - .1) / .7, 0, 1) ** .85

# هاف تون: شبكة نقط مايلة ٤٥ درجة، حجم النقطة على قد الإضاءة
cell = 11
yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
u = (xx + yy) / np.sqrt(2) / cell; v = (xx - yy) / np.sqrt(2) / cell
d = np.hypot(u - np.round(u), v - np.round(v)) * 2          # ٠ في نص النقطة → ~١٫٤ في الركن
dots = np.clip((np.sqrt(t) * 1.15 - d) * 6 + .5, 0, 1)

# جرين ثابت عشان يبقى نفس النتيجة كل رندر
rng = np.random.default_rng(7)
grain = rng.normal(0, .06, (H, W)).astype(np.float32)

v_ = np.clip(t * .7 + dots * .45 + grain, 0, 1)
# لون: أسود → أحمر دم → وردي فاتح في أعلى إضاءة
r = np.clip(v_ * 1.25, 0, 1); g = np.clip((v_ - .62) * 1.3, 0, 1) * .75; b = np.clip((v_ - .7) * 1.2, 0, 1) * .6
out = np.stack([r, g + v_ * .04, b + v_ * .05], -1)

# تلاشي من تحت عشان الكلمات تقعد على أسود
fade = np.clip((H - yy) / 700, 0, 1) ** 1.6
alpha = pmask.crop(CROP).resize((W, int(W * src.height / src.width)), Image.LANCZOS).crop((0, 0, W, H))
alpha = Image.fromarray((np.asarray(alpha).astype(np.float32) * fade).astype(np.uint8))   # التلاشي في الشفافية مش في اللون
rgba = Image.fromarray((out * 255).astype(np.uint8)).convert('RGBA'); rgba.putalpha(alpha)
rgba.save('face.png')
print('ok')
