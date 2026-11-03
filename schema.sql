CREATE DATABASE IF NOT EXISTS agazat2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agazat2;

CREATE TABLE IF NOT EXISTS employees (
  code        VARCHAR(64)  PRIMARY KEY,
  name        VARCHAR(255) NOT NULL,
  number      VARCHAR(64),
  workplace   VARCHAR(255),
  status      ENUM('in','out') NOT NULL DEFAULT 'in',
  last_out    DATE NULL,
  last_in     DATE NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trips (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  employee_code  VARCHAR(64) NOT NULL,
  date_out       DATE NOT NULL,
  date_in        DATE NOT NULL,
  days_away      INT NOT NULL,
  FOREIGN KEY (employee_code) REFERENCES employees(code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
