-- Dentist-related minimal schema for DocuMed
-- Ensure doc_nurse table exists and supports status and school_id
CREATE TABLE IF NOT EXISTS doc_nurse (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role VARCHAR(50) NOT NULL,
  school_id VARCHAR(50) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB;

-- Ensure appointments_pwa exists for booking fallback and includes cancelled status
CREATE TABLE IF NOT EXISTS appointments_pwa (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  role VARCHAR(50),
  year_course VARCHAR(100),
  department VARCHAR(100),
  purpose TEXT,
  date DATE NOT NULL,
  time TIME NOT NULL,
  status ENUM('scheduled','accepted','declined','completed','cancelled') DEFAULT 'scheduled',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_date_time (date, time)
) ENGINE=InnoDB;

-- Optional: Materialized view substitute for dental-only appointments (MySQL 5/8 compatible as view)
DROP VIEW IF EXISTS dental_appointments;
CREATE VIEW dental_appointments AS
  SELECT id, name, email, role, year_course, department, purpose, date, time, status, created_at
  FROM appointments_pwa
  WHERE LOWER(purpose) LIKE '%dental%';
