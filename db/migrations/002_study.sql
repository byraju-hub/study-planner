CREATE TABLE sp_timetable_slots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  weekday TINYINT UNSIGNED NOT NULL,
  period TINYINT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_timetable_slot (user_id, weekday, period),
  CONSTRAINT fk_timetable_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_timetable_subject FOREIGN KEY (subject_id) REFERENCES sp_subjects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_tasks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NULL,
  source ENUM('study','exam','assessment','review') NOT NULL DEFAULT 'study',
  ref_id INT UNSIGNED NULL,
  title VARCHAR(120) NOT NULL,
  task_date DATE NOT NULL,
  est_minutes SMALLINT UNSIGNED NULL,
  done_at DATETIME NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tasks_user_date (user_id, task_date),
  KEY idx_tasks_source_ref (user_id, source, ref_id),
  CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_subject FOREIGN KEY (subject_id) REFERENCES sp_subjects (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_review_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NULL,
  unit_id INT UNSIGNED NULL,
  title VARCHAR(120) NOT NULL,
  stage TINYINT UNSIGNED NOT NULL DEFAULT 1,
  next_review_date DATE NULL,
  last_reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reviews_due (user_id, next_review_date),
  CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_subject FOREIGN KEY (subject_id) REFERENCES sp_subjects (id) ON DELETE SET NULL,
  CONSTRAINT fk_reviews_unit FOREIGN KEY (unit_id) REFERENCES sp_units (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_lesson_reviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  lesson_date DATE NOT NULL,
  period TINYINT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  topic VARCHAR(120) NULL,
  done_at DATETIME NULL,
  review_item_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lesson (user_id, lesson_date, period),
  CONSTRAINT fk_lessons_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_lessons_subject FOREIGN KEY (subject_id) REFERENCES sp_subjects (id) ON DELETE CASCADE,
  CONSTRAINT fk_lessons_review FOREIGN KEY (review_item_id) REFERENCES sp_review_items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_study_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  minutes SMALLINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user_start (user_id, started_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_subject FOREIGN KEY (subject_id) REFERENCES sp_subjects (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_daily_journals (
  user_id INT UNSIGNED NOT NULL,
  journal_date DATE NOT NULL,
  good TEXT NULL,
  bad TEXT NULL,
  self_rating TINYINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, journal_date),
  CONSTRAINT fk_journals_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
