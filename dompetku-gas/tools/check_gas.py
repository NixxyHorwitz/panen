"""Cek Index.html sebelum push: Apps Script membuang teks setelah "//" dan "/*" di dalam <script>
walau posisinya di dalam string, sehingga seluruh JavaScript bisa rusak (aplikasi macet di layar muat).

Pakai:  python tools/check_gas.py   (exit 1 bila ada temuan)
"""
import os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
html = open(os.path.join(ROOT, 'Index.html'), encoding='utf-8').read()
bad = []
for m in re.finditer(r'<script>(.*?)</script>', html, re.S):
    js, base = m.group(1), m.start(1)
    for k in re.finditer(r'//|/\*', js):
        line_start = js.rfind('\n', 0, k.start()) + 1
        before = js[line_start:k.start()]
        # komentar sungguhan: di awal baris, atau "//" setelah kode yang diakhiri spasi tanpa tanda kutip terbuka
        if not before.strip():
            continue
        if k.group() == '//' and before.endswith(' ') and before.count("'") % 2 == 0 and before.count('`') % 2 == 0 and before.count('"') % 2 == 0:
            continue
        lineno = html.count('\n', 0, base + k.start()) + 1
        bad.append(f'Index.html:{lineno}: {js[k.start() - 40:k.start() + 20]!r}')
if bad:
    print('Ditemukan "//" atau "/*" di dalam kode yang akan dirusak Apps Script:')
    print('\n'.join(bad))
    sys.exit(1)
print('OK: aman untuk Apps Script')
