CREATE TABLE sp_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  login_id VARCHAR(30) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(50) NOT NULL,
  school_name VARCHAR(100) NULL,
  school_code VARCHAR(20) NULL,
  office_code VARCHAR(10) NULL,
  grade TINYINT UNSIGNED NULL,
  class_no TINYINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_login (login_id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  login_id VARCHAR(30) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_login (login_id, created_at),
  KEY idx_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_subjects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(40) NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#5B6475',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subjects_user_name (user_id, name),
  CONSTRAINT fk_subjects_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_textbooks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  kind ENUM('textbook','guide','workbook','academy','etc') NOT NULL DEFAULT 'textbook',
  publisher VARCHAR(60) NULL,
  total_pages SMALLINT UNSIGNED NULL,
  catalog_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_textbooks_subject (subject_id),
  CONSTRAINT fk_textbooks_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_textbooks_subject FOREIGN KEY (subject_id) REFERENCES sp_subjects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_units (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  textbook_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NULL,
  title VARCHAR(120) NOT NULL,
  page_start SMALLINT UNSIGNED NULL,
  page_end SMALLINT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_units_textbook (textbook_id, sort_order),
  CONSTRAINT fk_units_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_units_textbook FOREIGN KEY (textbook_id) REFERENCES sp_textbooks (id) ON DELETE CASCADE,
  CONSTRAINT fk_units_parent FOREIGN KEY (parent_id) REFERENCES sp_units (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_materials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  unit_id INT UNSIGNED NOT NULL,
  type ENUM('note','link','file') NOT NULL,
  title VARCHAR(120) NOT NULL,
  content MEDIUMTEXT NULL,
  url VARCHAR(500) NULL,
  file_path VARCHAR(255) NULL,
  file_name VARCHAR(255) NULL,
  file_size INT UNSIGNED NULL,
  mime VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_materials_unit (unit_id),
  CONSTRAINT fk_materials_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_materials_unit FOREIGN KEY (unit_id) REFERENCES sp_units (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
