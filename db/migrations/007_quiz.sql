CREATE TABLE sp_questions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  unit_id INT UNSIGNED NULL,
  material_id INT UNSIGNED NULL,
  type ENUM('mcq','ox','short','essay','card') NOT NULL,
  prompt TEXT NOT NULL,
  choices TEXT NULL,
  answer TEXT NOT NULL,
  explanation TEXT NULL,
  source ENUM('manual','ai') NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sp_questions_user_subject (user_id, subject_id),
  KEY idx_sp_questions_unit (unit_id),
  CONSTRAINT sp_fk_questions_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT sp_fk_questions_subject FOREIGN KEY (subject_id) REFERENCES sp_subjects (id) ON DELETE CASCADE,
  CONSTRAINT sp_fk_questions_unit FOREIGN KEY (unit_id) REFERENCES sp_units (id) ON DELETE SET NULL,
  CONSTRAINT sp_fk_questions_material FOREIGN KEY (material_id) REFERENCES sp_materials (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_quiz_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NULL,
  unit_id INT UNSIGNED NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  correct SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_sp_attempts_user (user_id, id),
  CONSTRAINT sp_fk_attempts_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT sp_fk_attempts_subject FOREIGN KEY (subject_id) REFERENCES sp_subjects (id) ON DELETE SET NULL,
  CONSTRAINT sp_fk_attempts_unit FOREIGN KEY (unit_id) REFERENCES sp_units (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_quiz_answers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  attempt_id INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  given TEXT NULL,
  is_correct TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sp_answers_attempt_question (attempt_id, question_id),
  KEY idx_sp_answers_question (user_id, question_id, id),
  CONSTRAINT sp_fk_answers_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT sp_fk_answers_attempt FOREIGN KEY (attempt_id) REFERENCES sp_quiz_attempts (id) ON DELETE CASCADE,
  CONSTRAINT sp_fk_answers_question FOREIGN KEY (question_id) REFERENCES sp_questions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sp_review_items
  ADD COLUMN question_id INT UNSIGNED NULL AFTER unit_id,
  ADD CONSTRAINT sp_fk_reviews_question FOREIGN KEY (question_id) REFERENCES sp_questions (id) ON DELETE CASCADE;
