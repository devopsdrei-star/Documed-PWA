-- Add new metadata fields to checkups to track performer and follow-up status
ALTER TABLE checkups
  ADD COLUMN IF NOT EXISTS doctor_nurse VARCHAR(150) NULL AFTER photo,
  ADD COLUMN IF NOT EXISTS follow_up TINYINT(1) NOT NULL DEFAULT 0 AFTER doctor_nurse;

-- Backfill suggestions (manual):
-- UPDATE checkups SET doctor_nurse = CONCAT('AutoBackfill ', id) WHERE doctor_nurse IS NULL;
-- UPDATE checkups SET follow_up = 0 WHERE follow_up IS NULL;