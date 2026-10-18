# بيقص الصورة من المرجع ويشيل الكلام الأبيض/الأحمر الفاتح منها عشان يتكتب تاني فيكتور
from PIL import Image, ImageFilter
import numpy as np

X0, X1 = 170, 731          # حدود البوستر في المرجع
Y0, Y1 = 0, 760            # الجزء اللي فيه الصورة بس

im = Image.open('ref.jpg').convert('RGB').crop((X0, Y0, X1, Y1))
a = np.asarray(im).astype(np.float32)
r, g, b = a[..., 0], a[..., 1], a[..., 2]

# الكلام الأبيض: الألوان التلاتة عالية (الصورة كلها أحمر فـ G/B واطيين)
white = np.minimum(g, b) > 70
# الوش نفسه فيه إضاءة فاتحة (الشاش) — مانلمسوش
white[:450] = False
# العنوان الأحمر فوق على خلفية سودا (صفوف 40-120)
title = np.zeros_like(white)
title[30:125] = (r[30:125] > 45)
mask = Image.fromarray(((white | title) * 255).astype(np.uint8)).filter(ImageFilter.MaxFilter(7))
m = np.asarray(mask) > 0

# ملء بالانتشار: كل لفة بناخد متوسط الجيران المعروفين
fill = a.copy(); known = ~m
fill[m] = 0
for _ in range(400):
    k = known.astype(np.float32)
    s = sum(np.roll(np.roll(fill * k[..., None], dy, 0), dx, 1) for dy in (-1, 0, 1) for dx in (-1, 0, 1))
    c = sum(np.roll(np.roll(k, dy, 0), dx, 1) for dy in (-1, 0, 1) for dx in (-1, 0, 1))
    new = (~known) & (c > 0)
    if not new.any():
        break
    fill[new] = s[new] / c[new][:, None]
    known = known | new

# نعّم المناطق المملية بس عشان ماتبانش مربعات
soft = np.asarray(Image.fromarray(fill.clip(0, 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(6))).astype(np.float32)
mm = np.asarray(mask.filter(ImageFilter.GaussianBlur(4))).astype(np.float32)[..., None] / 255
fill = fill * (1 - mm) + soft * mm
out = Image.fromarray(fill.clip(0, 255).astype(np.uint8))
# تكبير ×3 مع تنعيم خفيف وحدّة عشان مايبانش مكسّر
out = out.resize((out.width * 3, out.height * 3), Image.LANCZOS)
out = out.filter(ImageFilter.GaussianBlur(1.2)).filter(ImageFilter.UnsharpMask(4, 80, 2))
out.save('photo.png')
print(out.size, int(m.sum()), 'px masked')
