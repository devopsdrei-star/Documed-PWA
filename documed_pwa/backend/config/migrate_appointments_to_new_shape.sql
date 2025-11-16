-- Forward-safe migration to align `appointments` table with API expectations
-- Run these statements step-by-step; they are idempotent where possible.

-- 1) Ensure missing columns exist
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS year_course VARCHAR(100);
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS department VARCHAR(100);
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- 2) Relax types and constraints to match API
-- Email should not be UNIQUE per the current API (multiple appts can share email)
ALTER TABLE appointments DROP INDEX IF EXISTS email;

-- 3) Role to free-form VARCHAR
-- If role is ENUM in your DB, convert to VARCHAR(50)
-- Note: MySQL before 8.0.28 may not support IF EXISTS for MODIFY; adjust locally if needed
ALTER TABLE appointments MODIFY COLUMN role VARCHAR(50) NULL;

-- 4) Status enum must include accepted/declined/cancelled
-- If status already includes these, this statement is a no-op
ALTER TABLE appointments MODIFY COLUMN status ENUM('scheduled','accepted','declined','completed','cancelled') DEFAULT 'scheduled';

-- 5) Add composite index for faster slot lookups
CREATE INDEX IF NOT EXISTS idx_date_time ON appointments(date, time);

-- 6) Drop obsolete FK if present (legacy schema had patient_id FK)
-- Guarded by EXISTS pattern
SET @have_patient_id := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'patient_id'
);
SET @sql := IF(@have_patient_id > 0, 'ALTER TABLE appointments DROP FOREIGN KEY IF EXISTS appointments_ibfk_patient_id;', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql2 := IF(@have_patient_id > 0, 'ALTER TABLE appointments DROP COLUMN patient_id;', 'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
