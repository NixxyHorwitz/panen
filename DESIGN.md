# DESIGN.md · PanenCuan (halaman pengguna)

Arah desain untuk beranda, header, dan navigasi bawah. Disusun mengikuti aturan
[anti-slop](https://github.com/miqdadbadjuber/anti-slop): setiap keputusan besar punya
alasan satu baris. Game `/farm` memakai gaya game (kayu & perkamen) sendiri, tetapi
berbagi palet dan font dengan dokumen ini.

## Design Read
Beranda aplikasi penghasil saldo untuk pengguna kasual Indonesia (HP, layar 360 sampai
430 px), dalam bahasa visual "sawah yang tenang": hijau padi, emas panen, tanah hangat.
Dial: **ENERGY 2 · RHYTHM 2 · MOTION 2**.

## Identitas
- **Motif:** garis terasering sawah + objek 3D hasil panen (padi, keranjang, koin, lumbung).
  Alasan: objek yang sama ada di game, jadi beranda dan game terasa satu dunia.
- **Fokus per layar:** kartu saldo di atas. Hanya satu elemen yang memakai warna aksen penuh.

## Warna (2 inti + 1 aksen)
| Peran | Nilai | Alasan |
|---|---|---|
| Inti: hijau padi | `#15803d` (tua `#14532d`, muda `#dcfce7`) | warna sawah, tombol utama & status sehat |
| Inti: tanah/teks | `#1d2b1f`, muted `#5f6f62` | teks gelap hangat, kontras AA di latar terang |
| Aksen: emas panen | `#f5b301` (muda `#fff4cc`) | hanya untuk momen uang: tombol Tarik, "siap panen" |
| Status | merah `#e11d48` | hanya badge notifikasi & galat, bukan dekorasi |
| Latar | `#f5f8f0`, kartu `#ffffff`, garis `rgba(29,43,31,.08)` | netral; tanpa orb, titik, atau grid |

## Tipografi
- **Fredoka 600/700** untuk judul & angka. Alasan: bentuk bulat ramah, sama dengan game.
- **Nunito 700/800** untuk teks. Alasan: tetap terbaca di 11–13 px.
- Huruf kalimat (bukan KAPITAL berjarak lebar) untuk label.

## Bentuk & kedalaman
- Radius: 22 (kartu saldo) · 18 (kartu) · 12 (tombol) · 10 (chip). Tidak semuanya pil.
- Bayangan hanya untuk elemen yang benar-benar melayang: kartu saldo, dock bawah, modal, toast.
  Kartu biasa: putih + garis tipis.
- Efek kaca (blur) hanya di dock bawah, karena dock melayang di atas konten yang discroll.

## Gerak (MOTION 2)
- Boleh: muncul bertahap saat discroll, hitung naik angka saldo, cincin progres terisi,
  umpan balik tekan (`scale .95`), penanda dock meluncur, modal memantul saat dibuka.
- Ambient hanya satu: padi bergoyang di kartu sawah, 3 kali lalu berhenti.
- Tidak boleh: animasi tanpa akhir yang tidak dipicu pengguna atau state nyata.
- Semua dimatikan saat `prefers-reduced-motion: reduce`.

## Teks
- Bahasa Indonesia santai, spesifik ("3 petak siap panen"), tanpa emoji di UI.
- Tanpa em dash. Tanpa angka/klaim yang tidak berasal dari data.

## Aksesibilitas
- Target sentuh minimal 44×44 px, kontras teks AA, zoom tidak dikunci.
- Tombol ikon punya `aria-label`; aksi = `<button>`, navigasi = `<a>`.
