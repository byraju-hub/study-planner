CREATE TABLE sp_assessments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  kind ENUM('report','presentation','experiment','written','portfolio','practical','etc') NOT NULL DEFAULT 'report',
  max_score SMALLINT UNSIGNED NULL,
  weight_pct TINYINT UNSIGNED NULL,
  due_date DATE NOT NULL,
  rubric TEXT NULL,
  status ENUM('planned','in_progress','submitted','graded') NOT NULL DEFAULT 'planned',
  score DECIMAL(5,1) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assessments_user_due (user_id, due_date),
  CONSTRAINT fk_assessments_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_assessments_subject FOREIGN KEY (subject_id) REFERENCES sp_subjects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_assessment_steps (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  assessment_id INT UNSIGNED NOT NULL,
  title VARCHAR(80) NOT NULL,
  target_date DATE NULL,
  done_at DATETIME NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_steps_assessment (assessment_id, sort_order),
  CONSTRAINT fk_steps_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_steps_assessment FOREIGN KEY (assessment_id) REFERENCES sp_assessments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
