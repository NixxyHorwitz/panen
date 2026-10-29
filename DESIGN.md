# DESIGN.md · PanenCuan (halaman pengguna)

Arah desain untuk beranda (`user/home.php`), header (`partials/header.php`) dan navbar bawah
(`partials/footer.php`). Game `/farm` memakai gaya game kayu & perkamen sendiri, tetapi berbagi
palet, font dan ikon 3D dengan dokumen ini.

## Tema: "Golden Hour Harvest"
Sawah di sore hari: langit oranye keemasan, padi bergoyang, cahaya hangat. Terasa hidup,
meriah dan seperti game, tapi tetap rapi dan cepat dipakai di HP (360 sampai 440 px).

## Warna
| Peran | Nilai |
|---|---|
| Hijau padi (utama) | `#16a34a`, tua `#15803d` / `#14532d`, muda `#dcfce7` |
| Emas panen (uang) | `#f6b70a`, tua `#c98a00`, muda `#fff3c4` |
| Langit sore (latar atas) | `#ffd9a8` → `#ffe9c7` → `#e9f7ff` → `#f4f8ec` |
| Pendukung | biru `#38bdf8` (nonton), ungu `#8b5cf6` (misi), merah `#f43f5e` (notifikasi) |
| Teks | `#1b2a1e`, muted `#5c6b5f` |

Token ada di `:root` header (`--pc-*`), dipakai semua halaman.

## Tipografi
- **Fredoka 600/700** untuk judul, angka saldo, tombol.
- **Nunito 700/800/900** untuk teks.

## Komponen kunci
- **Header:** transparan di atas langit, berubah jadi kaca (blur) saat discroll. Pil saldo dengan koin 3D, lonceng.
- **Hero beranda:** pemandangan sawah ber-lapisan (parallax saat scroll) + panel kaca saldo + 4 aksi bulat.
- **Quest:** dua kartu gradien berdampingan (Nonton biru dengan cincin progres, Sawah hijau dengan status panen).
- **Story carousel:** promo dengan bar progres gaya story, geser otomatis, jeda saat disentuh.
- **Navbar:** bar putih menempel di bawah dengan cekungan (mask radial) di tengah; tombol Sawah bulat
  duduk di cekungan dengan halo conic berputar dan badge jumlah petak siap panen.
- **Modal:** lembar bawah yang memantul, ikon 3D pop, taburan konfeti saat terbuka.
- **Ikon:** objek 3D dari `assets/img/icons3d/` untuk dunia sawah & uang; Phosphor (fill/bold) untuk ikon UI.

## Gerak
Banyak animasi adalah bagian dari karakter: awan, matahari bersinar, padi bergoyang, burung, kilau,
koin melayang, teks saldo berkilau emas, hitung naik saldo, cincin progres, efek riak saat tap (`.rp`),
stagger saat muncul, parallax hero, navbar memantul. Easing utama `cubic-bezier(.34, 1.56, .64, 1)`.
Hanya transform/opacity (ringan di HP). Semua dimatikan saat `prefers-reduced-motion: reduce`.

## Aksesibilitas
- Target sentuh sekitar 44 px, zoom tidak dikunci, tombol ikon punya `aria-label`.
