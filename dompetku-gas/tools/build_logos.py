"""Tanam logo dari folder logos/ ke Index.html (base64 WebP 96px).

Pakai:  python tools/build_logos.py
Nama file = kunci logo (mis. logos/bca.webp -> "bca"). Margin putih/transparan otomatis dipotong.
Logo baru otomatis muncul di pilihan logo akun; tambahkan pola nama di LOGO_MATCH (Index.html)
bila ingin terdeteksi otomatis dari nama akun.
"""
import base64, io, os, re, sys
from PIL import Image, ImageChops

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'logos')
HTML = os.path.join(ROOT, 'Index.html')
SIZE = 96


def trim(img):
    """Potong ruang kosong di sekitar logo tanpa menyentuh tepi logo (sisakan margin 2%)."""
    rgba = img.convert('RGBA')
    alpha = rgba.getchannel('A')
    if alpha.getextrema()[0] < 250:
        box = alpha.point(lambda v: 255 if v > 8 else 0).getbbox()
    else:
        # tanpa transparansi: anggap latar putih
        diff = ImageChops.difference(rgba.convert('RGB'), Image.new('RGB', rgba.size, (255, 255, 255)))
        box = diff.convert('L').point(lambda v: 255 if v > 18 else 0).getbbox()
    if not box:
        return rgba
    l, t, r, b = box
    side = int(max(r - l, b - t) * 1.04)
    cx, cy = (l + r) / 2, (t + b) / 2
    canvas = Image.new('RGBA', (side, side), (0, 0, 0, 0))
    canvas.paste(rgba.crop(box), (int(side / 2 - (cx - l)), int(side / 2 - (cy - t))))
    return canvas


def encode(path):
    img = trim(Image.open(path))
    img = img.resize((SIZE, SIZE), Image.LANCZOS)
    buf = io.BytesIO()
    img.save(buf, 'WEBP', quality=86, method=6)
    data = base64.b64encode(buf.getvalue()).decode()
    # Apps Script membuang teks setelah "//" di dalam <script> (dianggap komentar) — tulis "/" sebagai "\/"
    return 'data:image/webp;base64,' + data.replace('/', '\\/')


def main():
    files = sorted(f for f in os.listdir(SRC) if f.lower().endswith(('.png', '.webp', '.jpg', '.jpeg')))
    logos = {os.path.splitext(f)[0].lower(): encode(os.path.join(SRC, f)) for f in files}
    body = ',\n'.join(f"  '{k}':'{v}'" for k, v in logos.items())
    block = f"/*LOGOS-START*/\nconst LOGOS = {{\n{body}\n}};\n/*LOGOS-END*/"
    html = open(HTML, encoding='utf-8').read()
    if '/*LOGOS-START*/' not in html:
        sys.exit('Penanda /*LOGOS-START*/ tidak ditemukan di Index.html')
    html = re.sub(r'/\*LOGOS-START\*/.*?/\*LOGOS-END\*/', lambda _: block, html, flags=re.S)
    open(HTML, 'w', encoding='utf-8', newline='\n').write(html)
    total = sum(len(v) for v in logos.values())
    print(f'{len(logos)} logo ditanam ({total // 1024} KB): ' + ', '.join(logos))


if __name__ == '__main__':
    main()
