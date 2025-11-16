-- Drop existing appointments table if it exists
DROP TABLE IF EXISTS appointments;

-- Create new simplified appointments table
CREATE TABLE appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    role VARCHAR(50),
    year_course VARCHAR(100),
    department VARCHAR(100),
    purpose TEXT,
    date DATE NOT NULL,
    time TIME NOT NULL,
    status ENUM('scheduled', 'accepted', 'declined', 'completed') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date_time (date, time)
) ENGINE=InnoDB;