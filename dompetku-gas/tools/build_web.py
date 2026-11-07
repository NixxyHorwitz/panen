"""Bangun versi GitHub Pages dari Index.html ke folder web/.

Pakai:  python tools/build_web.py
Tampilan sama dengan versi Apps Script, tetapi data diambil lewat API JSON (doPost) di URL /exec,
sehingga tidak ada iframe & banner Google dan bisa dipasang sebagai aplikasi (PWA) di iPhone.
"""
import json, os
from PIL import Image, ImageDraw

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'web')
DEPLOYMENT_ID = 'AKfycbyDLE5XUHq9xyGxzx4OFvnAH2jjlaLOm87SVkBpCWitWbua_0CduiBR3C98v11zKTTf9w'
API_URL = f'https://script.google.com/macros/s/{DEPLOYMENT_ID}/exec'

HEAD = """<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="DompetKu">
<meta name="theme-color" content="#0C0D10">
<link rel="manifest" href="manifest.webmanifest">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<link rel="icon" type="image/png" href="icon-192.png">"""


def icon(size):
    """Ikon aplikasi: gradasi biru-hijau dengan dompet putih (tanpa sudut membulat; iOS memberi mask sendiri)."""
    s = size
    img = Image.new('RGB', (s, s))
    px = img.load()
    a, b = (11, 120, 194), (14, 159, 126)
    for y in range(s):
        for x in range(s):
            t = (x + y) / (2 * s)
            px[x, y] = tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))
    d = ImageDraw.Draw(img)
    u = s / 100
    white = (255, 255, 255)
    d.rounded_rectangle([24 * u, 34 * u, 76 * u, 74 * u], radius=9 * u, fill=white)
    d.polygon([(30 * u, 34 * u), (62 * u, 24 * u), (66 * u, 34 * u)], fill=(225, 245, 240))
    d.rounded_rectangle([56 * u, 46 * u, 80 * u, 62 * u], radius=6 * u, fill=(14, 140, 140))
    d.ellipse([63 * u, 50.5 * u, 70 * u, 57.5 * u], fill=(250, 204, 21))
    return img


def main():
    os.makedirs(OUT, exist_ok=True)
    html = open(os.path.join(ROOT, 'Index.html'), encoding='utf-8').read()
    assert "const API_URL = '';" in html and '<!--PWA-->' in html, 'penanda API_URL / PWA tidak ditemukan'
    html = html.replace("const API_URL = '';", f"const API_URL = '{API_URL}';").replace('<!--PWA-->', HEAD)
    open(os.path.join(OUT, 'index.html'), 'w', encoding='utf-8', newline='\n').write(html)
    manifest = {
        'name': 'DompetKu', 'short_name': 'DompetKu', 'start_url': './', 'scope': './', 'display': 'standalone',
        'background_color': '#0C0D10', 'theme_color': '#0C0D10', 'lang': 'id',
        'icons': [{'src': 'icon-192.png', 'sizes': '192x192', 'type': 'image/png'},
                  {'src': 'icon-512.png', 'sizes': '512x512', 'type': 'image/png', 'purpose': 'any maskable'}]
    }
    json.dump(manifest, open(os.path.join(OUT, 'manifest.webmanifest'), 'w'), indent=2)
    for name, size in [('icon-512.png', 512), ('icon-192.png', 192), ('apple-touch-icon.png', 180)]:
        icon(size).save(os.path.join(OUT, name))
    open(os.path.join(OUT, '.nojekyll'), 'w').close()
    print('web/ siap:', ', '.join(sorted(os.listdir(OUT))))


if __name__ == '__main__':
    main()
