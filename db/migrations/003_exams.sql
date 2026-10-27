CREATE TABLE sp_exams (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('midterm','final','mock') NOT NULL,
  title VARCHAR(80) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  memo TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_exams_user_start (user_id, start_date),
  CONSTRAINT fk_exams_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_exam_subjects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  exam_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  exam_date DATE NOT NULL,
  period TINYINT UNSIGNED NULL,
  target_score TINYINT UNSIGNED NULL,
  target_grade TINYINT UNSIGNED NULL,
  actual_score TINYINT UNSIGNED NULL,
  actual_grade TINYINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_subject (exam_id, subject_id),
  CONSTRAINT fk_exam_subjects_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_subjects_exam FOREIGN KEY (exam_id) REFERENCES sp_exams (id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_subjects_subject FOREIGN KEY (subject_id) REFERENCES sp_subjects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_exam_scopes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  exam_subject_id INT UNSIGNED NOT NULL,
  unit_id INT UNSIGNED NULL,
  label VARCHAR(120) NOT NULL,
  page_start SMALLINT UNSIGNED NULL,
  page_end SMALLINT UNSIGNED NULL,
  progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_scopes_subject (exam_subject_id, sort_order),
  CONSTRAINT fk_scopes_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_scopes_exam_subject FOREIGN KEY (exam_subject_id) REFERENCES sp_exam_subjects (id) ON DELETE CASCADE,
  CONSTRAINT fk_scopes_unit FOREIGN KEY (unit_id) REFERENCES sp_units (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
