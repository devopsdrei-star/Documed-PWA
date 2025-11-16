-- Drop the existing appointments table
DROP TABLE IF EXISTS appointments;

-- Create updated appointments table with new fields
CREATE TABLE appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    role ENUM('student', 'teacher', 'non-teaching') NOT NULL,
    department VARCHAR(100),
    year_course VARCHAR(100),
    purpose TEXT NOT NULL,
    date DATE NOT NULL,
    time TIME NOT NULL,
    status ENUM('scheduled','cancelled','completed') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
