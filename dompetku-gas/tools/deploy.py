"""Terbitkan DompetKu ke Apps Script (server + versi lama) dan GitHub Pages sekaligus.

Pakai:  python tools/deploy.py "catatan versi"
Urutan: cek aman-GAS -> clasp push + deploy ulang (URL tetap) -> build web/ -> commit & push repo dompetku.
"""
import os, subprocess, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEPLOYMENT_ID = 'AKfycbyDLE5XUHq9xyGxzx4OFvnAH2jjlaLOm87SVkBpCWitWbua_0CduiBR3C98v11zKTTf9w'
NOTE = sys.argv[1] if len(sys.argv) > 1 else 'Pembaruan DompetKu'


def run(cmd, cwd=ROOT):
    print('>', ' '.join(cmd))
    r = subprocess.run(cmd, cwd=cwd, shell=os.name == 'nt')
    if r.returncode:
        sys.exit(f'Gagal: {" ".join(cmd)}')


run([sys.executable, 'tools/check_gas.py'])
run(['clasp', 'push', '--force'])
run(['clasp', 'create-deployment', '--deploymentId', DEPLOYMENT_ID, '--description', NOTE])
run([sys.executable, 'tools/build_web.py'])
web = os.path.join(ROOT, 'web')
run(['git', 'add', '-A'], cwd=web)
if subprocess.run(['git', 'diff', '--cached', '--quiet'], cwd=web).returncode:
    run(['git', 'commit', '-q', '-m', NOTE + '\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>'], cwd=web)
    run(['git', 'push', '-q'], cwd=web)
    print('GitHub Pages diperbarui (aktif dalam ~1 menit): https://nixxyhorwitz.github.io/dompetku/')
else:
    print('Tampilan web tidak berubah.')
