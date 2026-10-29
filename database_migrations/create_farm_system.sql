-- ══════════════════════════════════════════════════════════
-- Sawah Panen Cuan — Farm System
-- Dijalankan otomatis oleh farm_ensure_schema() (lib/farm.php).
-- Semua waktu tanam disimpan sebagai epoch detik (INT) agar
-- timer klien bisa dikalibrasi persis terhadap jam server.
-- ══════════════════════════════════════════════════════════

-- Katalog bibit (dikelola admin). Harga bibit dibayar Saldo Beli,
-- hasil panen dijual ke Pasar Pengepul → Saldo Tarik.
CREATE TABLE IF NOT EXISTS farm_crops (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(30)  NOT NULL UNIQUE,
  name          VARCHAR(80)  NOT NULL,
  model         VARCHAR(20)  NOT NULL DEFAULT 'rice',
  color         VARCHAR(9)   NOT NULL DEFAULT '#facc15',
  growth_secs   INT UNSIGNED NOT NULL DEFAULT 60,
  seed_price    DECIMAL(12,2) NOT NULL DEFAULT 0,
  yield_min     INT UNSIGNED NOT NULL DEFAULT 1,
  yield_max     INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price    DECIMAL(12,2) NOT NULL DEFAULT 0,
  xp            INT UNSIGNED NOT NULL DEFAULT 5,
  min_level     INT UNSIGNED NOT NULL DEFAULT 1,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Katalog barang toko: speed (Pupuk Kilat), cure (Pestisida), barn (perluas Lumbung).
CREATE TABLE IF NOT EXISTS farm_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(30)  NOT NULL UNIQUE,
  name          VARCHAR(80)  NOT NULL,
  effect        VARCHAR(20)  NOT NULL,
  effect_value  INT          NOT NULL DEFAULT 0,
  price         DECIMAL(12,2) NOT NULL DEFAULT 0,
  description   VARCHAR(190) NOT NULL DEFAULT '',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Profil petani: jumlah petak terbuka, XP, kapasitas lumbung.
CREATE TABLE IF NOT EXISTS farm_users (
  user_id        INT UNSIGNED NOT NULL PRIMARY KEY,
  unlocked_plots TINYINT UNSIGNED NOT NULL DEFAULT 4,
  xp             INT UNSIGNED NOT NULL DEFAULT 0,
  barn_capacity  INT UNSIGNED NOT NULL DEFAULT 30,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Petak lahan (hanya petak yang sudah terbuka punya baris).
-- pest_at: epoch saat hama muncul (0 = tidak ada hama).
CREATE TABLE IF NOT EXISTS farm_plots (
  user_id     INT UNSIGNED NOT NULL,
  plot_index  TINYINT UNSIGNED NOT NULL,
  status      ENUM('empty','growing','ready') NOT NULL DEFAULT 'empty',
  crop_id     INT UNSIGNED NULL DEFAULT NULL,
  planted_at  INT UNSIGNED NOT NULL DEFAULT 0,
  matures_at  INT UNSIGNED NOT NULL DEFAULT 0,
  pest_at     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, plot_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventori: bibit (seed), hasil panen di lumbung (harvest), barang toko (item).
CREATE TABLE IF NOT EXISTS farm_inventory (
  user_id  INT UNSIGNED NOT NULL,
  kind     ENUM('seed','harvest','item') NOT NULL,
  ref_id   INT UNSIGNED NOT NULL,
  qty      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, kind, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jejak semua aksi bernilai uang (audit).
CREATE TABLE IF NOT EXISTS farm_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  action      VARCHAR(20) NOT NULL,
  plot_index  TINYINT UNSIGNED NULL DEFAULT NULL,
  ref_id      INT UNSIGNED NULL DEFAULT NULL,
  qty         INT NOT NULL DEFAULT 0,
  amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
  wallet      ENUM('dep','wd','none') NOT NULL DEFAULT 'none',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jejak perubahan oleh admin (Console › Sawah Panen Cuan).
CREATE TABLE IF NOT EXISTS farm_admin_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin       VARCHAR(80) NOT NULL,
  action      VARCHAR(40) NOT NULL,
  detail      TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data awal (hanya bila katalog kosong; INSERT IGNORE aman diulang).
INSERT IGNORE INTO farm_crops (code, name, model, color, growth_secs, seed_price, yield_min, yield_max, unit_price, xp, min_level, sort_order) VALUES
  ('padi',     'Padi Emas',     'rice',       '#facc15',  60,  500, 3, 5,  150,  5, 1, 1),
  ('cabai',    'Cabai Merah',   'chili',      '#ef4444', 180, 1500, 4, 7,  330, 12, 1, 2),
  ('jagung',   'Jagung Manis',  'corn',       '#f59e0b', 300, 2500, 5, 8,  450, 20, 2, 3),
  ('semangka', 'Semangka Emas', 'watermelon', '#eab308', 600, 5000, 1, 3, 3000, 40, 3, 4);

INSERT IGNORE INTO farm_items (code, name, effect, effect_value, price, description, sort_order) VALUES
  ('pupuk_kilat', 'Pupuk Kilat',     'speed', 0,  1000, 'Tanaman langsung siap panen.', 1),
  ('pestisida',   'Pestisida',       'cure',  0,   500, 'Basmi hama agar panen kembali penuh.', 2),
  ('lumbung_xl',  'Perluas Lumbung', 'barn',  20, 5000, 'Kapasitas lumbung +20 hasil panen.', 3);
