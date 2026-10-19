# طبقة تراب وخدوش فيلم شفافة على مقاس الكانفاس (٦٠٠ dpi) — seed ثابت
from PIL import Image, ImageDraw, ImageFilter
import random

W, H = 1880, 3907
random.seed(23)
img = Image.new('RGBA', (W, H), (0, 0, 0, 0))
d = ImageDraw.Draw(img)

for _ in range(900):                                   # نقط تراب
    x, y, r = random.uniform(0, W), random.uniform(0, H), random.choice([1, 1, 1.5, 2, 3])
    d.ellipse((x - r, y - r, x + r, y + r), fill=(235, 220, 215, random.randint(40, 140)))
for _ in range(60):                                    # شعر وألياف
    x, y = random.uniform(0, W), random.uniform(0, H)
    pts = [(x, y)]
    for _ in range(random.randint(3, 7)):
        x += random.uniform(-14, 14); y += random.uniform(-14, 14); pts.append((x, y))
    d.line(pts, fill=(230, 215, 210, random.randint(40, 100)), width=1)
for _ in range(7):                                     # خدوش طولية
    x = random.uniform(80, W - 80); y0 = random.uniform(0, H * .6); L = random.uniform(400, 1600)
    d.line([(x, y0), (x + random.uniform(-6, 6), y0 + L)], fill=(240, 225, 220, random.randint(35, 70)), width=random.choice([1, 2]))

img.filter(ImageFilter.GaussianBlur(.6)).save('dust.png')
