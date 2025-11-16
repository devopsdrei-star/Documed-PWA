-- Adds normalized role column used by reports (Student | Faculty | Staff)
ALTER TABLE checkups
  ADD COLUMN role VARCHAR(20) NULL AFTER student_faculty_id;

-- Optional backfill: try to infer from year_and_course or remarks (manual step)
-- UPDATE checkups SET role = 'Student' WHERE role IS NULL AND year_and_course IS NOT NULL AND year_and_course <> '';
