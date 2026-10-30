-- Check-in kalender 7 hari: simpan streak beruntun di users
ALTER TABLE users ADD COLUMN IF NOT EXISTS checkin_streak SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_checkin;
