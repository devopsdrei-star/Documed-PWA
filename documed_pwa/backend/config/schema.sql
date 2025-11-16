/* Enter "USE {database};" to start exploring your data.
Press Ctrl + I to try out AI-generated SQL queries or SQL rewrite using Chat2Query. */
use db_med;

-- DocuMed Database Schema (TiDB/MySQL compatible)

-- Staff/Clinician table
CREATE TABLE IF NOT EXISTS doc_nurse (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id VARCHAR(50) NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('doctor','dentist','nurse') NOT NULL,
    photo VARCHAR(255) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    last_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_initial VARCHAR(10),
    age INT,
    address VARCHAR(255),
    civil_status VARCHAR(50),
    nationality VARCHAR(50),
    religion VARCHAR(50),
    gender VARCHAR(255),
    date_of_birth DATE,
    place_of_birth VARCHAR(100),
    year_course VARCHAR(100),
    student_faculty_id VARCHAR(50) NOT NULL,
    contact_person VARCHAR(100),
    contact_number VARCHAR(50),
    email VARCHAR(150) NOT NULL,
    password VARCHAR(255) NOT NULL,
    client_type VARCHAR(50),
    department VARCHAR(100),
    qr_code VARCHAR(255),
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    qr_enabled TINYINT(1) NOT NULL DEFAULT 1,
    qr_token_hash VARCHAR(255) NULL,
    qr_token_lookup CHAR(64) NULL,
    qr_last_rotated DATETIME NULL,
    photo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_sid (student_faculty_id),
    INDEX idx_users_qr_lookup (qr_token_lookup)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id VARCHAR(50),
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    name VARCHAR(100),
    address VARCHAR(255),
    age_sex VARCHAR(20),
    contact_number VARCHAR(30),
    date_of_examination DATE,
    chief_complaint TEXT,
    bp VARCHAR(20),
    cr VARCHAR(20),
    rr VARCHAR(20),
    temp VARCHAR(20),
    wt VARCHAR(20),
    ht VARCHAR(20),
    bmi VARCHAR(20),
    impression TEXT,
    treatment TEXT,
    nurses_notes TEXT,
    last_visit DATETIME,
    follow_up TINYINT(1) NOT NULL DEFAULT 0,
    follow_up_date DATE NULL
) ENGINE=InnoDB;

-- Appointments table aligned with API (appointments_new.php)
CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    role VARCHAR(50),
    year_course VARCHAR(100),
    department VARCHAR(100),
    purpose TEXT,
    date DATE NOT NULL,
    time TIME NOT NULL,
    status ENUM('scheduled','accepted','declined','completed','cancelled','rescheduled','pending') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date_time (date, time)
) ENGINE=InnoDB;

-- Fallback PWA appointments table (used if primary schema unavailable)
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
    status ENUM('scheduled','accepted','declined','completed','cancelled','rescheduled','pending') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date_time_pwa (date, time)
) ENGINE=InnoDB;

-- Reschedule windows for appointments
CREATE TABLE IF NOT EXISTS reschedule_windows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_appt (appointment_id),
    INDEX idx_active (active)
) ENGINE=InnoDB;

-- New table for nurse/admin check-up form (initial check-up)
CREATE TABLE IF NOT EXISTS checkups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_faculty_id VARCHAR(50) NOT NULL,
    client_type VARCHAR(50) NULL,
    exam_category VARCHAR(200) NULL,
    name VARCHAR(100) NOT NULL,
    age INT NULL,
    address VARCHAR(255),
    civil_status VARCHAR(50),
    nationality VARCHAR(50),
    religion VARCHAR(50),
    gender VARCHAR(32) NULL,
    date_of_birth DATE,
    place_of_birth VARCHAR(100),
    year_and_course VARCHAR(100),
    department VARCHAR(100) NULL,
    contact_person VARCHAR(100),
    contact_number VARCHAR(30),
    history_past_illness TEXT,
    present_illness TEXT,
    operations_hospitalizations TEXT,
    immunization_history TEXT,
    social_environmental_history TEXT,
    ob_gyne_history TEXT,
    physical_exam_general_survey TINYINT(1) DEFAULT 0,
    physical_exam_skin TINYINT(1) DEFAULT 0,
    physical_exam_heart TINYINT(1) DEFAULT 0,
    physical_exam_chest_lungs TINYINT(1) DEFAULT 0,
    physical_exam_abdomen TINYINT(1) DEFAULT 0,
    physical_exam_genitourinary TINYINT(1) DEFAULT 0,
    physical_exam_musculoskeletal TINYINT(1) DEFAULT 0,
    neurological_exam TEXT,
    laboratory_results TEXT,
    assessment TEXT,
    remarks TEXT,
    photo VARCHAR(255),
    doctor_nurse VARCHAR(150) NULL,
    doc_nurse_id INT NULL,
    follow_up TINYINT(1) NOT NULL DEFAULT 0,
    follow_up_date DATE NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    admin_id INT NULL,
    type VARCHAR(50),
    date DATE NOT NULL,
    time TIME NOT NULL,
    details TEXT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_trail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_admin (admin_id)
) ENGINE=InnoDB;

-- Password reset tokens/OTPs
CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        email VARCHAR(255) NOT NULL,
        token VARCHAR(128) NULL,
        otp VARCHAR(16) NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        UNIQUE KEY uq_token (token),
        INDEX idx_otp (otp)
) ENGINE=InnoDB;

-- Announcements for notifications
CREATE TABLE IF NOT EXISTS announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message TEXT NOT NULL,
        audience VARCHAR(50) DEFAULT 'All',
        expires_at DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Preferences for dentist/doc/nurse booking setup
CREATE TABLE IF NOT EXISTS doc_nurse_prefs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        hours VARCHAR(255) NOT NULL,
        slot_per_hour INT NOT NULL DEFAULT 2,
        notify_email VARCHAR(150) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_prefs_user (user_id)
) ENGINE=InnoDB;

-- Dentist closures (days unavailable)
CREATE TABLE IF NOT EXISTS dentist_closures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        date DATE NOT NULL,
        reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_closure_user (user_id),
        INDEX idx_closure_date (date)
) ENGINE=InnoDB;

-- Medicine inventory core tables
CREATE TABLE IF NOT EXISTS medicines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    campus VARCHAR(100) NOT NULL DEFAULT 'Lingayen',
    unit VARCHAR(100) NULL,
    form VARCHAR(100) NULL,
    strength VARCHAR(100) NULL,
    quantity INT NOT NULL DEFAULT 0,
    baseline_qty INT NULL,
    reorder_threshold_percent INT NOT NULL DEFAULT 20,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uniq_name_campus (name, campus)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medicine_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    campus VARCHAR(100) NOT NULL DEFAULT 'Lingayen',
    qty INT NOT NULL,
    expiry_date DATE NULL,
    received_at DATE NULL,
    batch_no VARCHAR(100) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_medicine_batches_medicine FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
    INDEX idx_medicine (medicine_id),
    INDEX idx_expiry (expiry_date)
) ENGINE=InnoDB;
