# DompetKu — Pencatat Keuangan (Google Apps Script)

Web app fullscreen bergaya iOS untuk mencatat keuangan pribadi. Database otomatis dibuat di Google Sheets milik Anda.

## Fitur
- **Tampilan**: gelap/terang, navbar pill melayang + tombol tambah, ikon ilustrasi berwarna buatan sendiri.
- **Kategori pintar**: ketik "kopi 25rb" atau "gojek 18k" → nominal & kategori terisi otomatis. Kamus ratusan kata kunci (slang & merek lokal) + belajar dari riwayatmu; kata baru cukup dipilih manual sekali.
- **Beranda**: sapaan, filter periode (Hari/Minggu/Bulan/Tahun/Semua), total saldo + pemasukan/pengeluaran, menu cepat, tagihan terdekat, insight, transaksi per hari.
- **Statistik**: Mingguan/Bulanan/Tahunan, donut per kategori, ringkasan harian/bulanan dengan rata-rata, komposisi Kebutuhan/Keinginan/Kewajiban.
- **Dompet**: kekayaan bersih + perubahan 30 hari, akun per jenis (Tunai, Bank, E-Wallet, Kantong Tabungan, Kartu Kredit, Paylater, Investasi).
  - Kartu kredit & paylater: tagihan, limit, sisa limit, Bayar.
  - Investasi: nilai, modal, untung/rugi %, Setor / Update Nilai / Jual.
- **Tagihan & Berulang**: tagihan jadi pengingat (Bayar / Lewati); transaksi berulang dicatat otomatis saat jatuh tempo.
- **Goals**: target tabungan dengan kantong sendiri, progres, setoran per bulan yang dibutuhkan.
- **Utang & Piutang**: jatuh tempo, cicilan, progres lunas, saldo akun ikut berubah.
- **Anggaran** per kategori (berulang tiap bulan) dengan sisa per hari & saran dari rata-rata 3 bulan.
- **Riwayat**: pencarian lintas bulan, filter jenis/akun/kategori.
- **Profil**: nama, kategori, tema, sembunyikan nominal, Kunci PIN (dicek di server), buka Google Sheets.

## Cara pasang (±3 menit)
1. Buka <https://script.google.com> → **Proyek baru**. Beri nama `DompetKu`.
2. Ganti isi `Code.gs` dengan file **Code.gs** di folder ini.
3. Klik **+ → HTML**, beri nama `Index` (tanpa `.html`), tempel isi **Index.html**.
4. (Opsional) **Setelan proyek → Tampilkan file manifes "appsscript.json"**, lalu tempel **appsscript.json** (zona waktu Asia/Jakarta).
5. **Terapkan → Deployment baru → Jenis: Aplikasi web**
   - Jalankan sebagai: **Saya**
   - Yang memiliki akses: **Siapa saja** (bisa dibuka di browser mana pun tanpa login Google). **Wajib aktifkan PIN** setelah pertama dibuka — PIN dicek di server, tanpa PIN siapa pun yang tahu URL bisa melihat data. Pilih **Hanya saya** jika ingin akses dibatasi ke akun Google Anda.
6. Izinkan akses (Google Sheets) → salin URL `/exec`.

Spreadsheet `DompetKu — Database` dibuat otomatis di Drive saat pertama dibuka.

## Versi GitHub Pages (disarankan untuk iPhone)
Alamat: <https://nixxyhorwitz.github.io/dompetku/>. Tampilan sama, tetapi dibuka langsung tanpa iframe & banner Google,
jadi pas di layar dan tidak bergeser saat keyboard muncul. Data tetap di Google Sheets lewat API `doPost` di URL `/exec`.
Pasang: buka di Safari → Bagikan → Tambahkan ke Layar Utama (tampil fullscreen seperti aplikasi).

Terbitkan perubahan ke Apps Script **dan** GitHub Pages sekaligus:

```
python tools/deploy.py "catatan versi"
```

`tools/check_gas.py` dijalankan otomatis: Apps Script membuang `//` dan `/*` di dalam string `<script>`, yang bisa membuat aplikasi macet.

## Logo bank & e-wallet
Taruh gambar logo (PNG/WebP/JPG) di folder `logos/`, nama file = kunci logo (mis. `bca.webp`, `cash.png`, `savings.png`, `emergency.png`), lalu jalankan:

```
python tools/build_logos.py
```

Margin putih dipotong otomatis, logo dikecilkan ke 96px dan ditanam ke `Index.html`. Logo dikenali otomatis dari nama akun (BCA, BRI, Jago, OVO, GoPay, DANA, SeaBank, ShopeePay, MyRepublic, Tunai, Dana Darurat), atau dipilih manual di form akun.

## Pasang di iPhone seperti aplikasi
1. Buka URL `/exec` di **Safari** (login akun Google yang sama).
2. Ketuk **Bagikan → Tambahkan ke Layar Utama**.

> Catatan: Apps Script menampilkan halaman di dalam iframe Google, jadi mode standalone penuh tergantung Safari. Jika muncul bar Safari, aplikasi tetap berfungsi normal.

## Update kode
Setelah mengedit: **Terapkan → Kelola deployment → Edit (ikon pensil) → Versi: Baru → Terapkan**. URL tetap sama.

## Preview lokal
Buka `Index.html` langsung di browser — otomatis memakai data demo (disimpan di localStorage), tanpa Apps Script.
