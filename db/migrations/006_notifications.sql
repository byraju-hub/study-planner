CREATE TABLE sp_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(255) NULL,
  link VARCHAR(60) NOT NULL DEFAULT '/',
  dedupe_key VARCHAR(60) NOT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sp_notifications_dedupe (user_id, dedupe_key),
  KEY idx_sp_notifications_user (user_id, id),
  CONSTRAINT sp_fk_notifications_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_notification_settings (
  user_id INT UNSIGNED NOT NULL,
  tasks_on TINYINT(1) NOT NULL DEFAULT 1,
  review_on TINYINT(1) NOT NULL DEFAULT 1,
  exam_on TINYINT(1) NOT NULL DEFAULT 1,
  assessment_on TINYINT(1) NOT NULL DEFAULT 1,
  morning_time CHAR(5) NOT NULL DEFAULT '07:30',
  evening_time CHAR(5) NULL DEFAULT '21:00',
  PRIMARY KEY (user_id),
  CONSTRAINT sp_fk_notification_settings_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sp_push_subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  endpoint VARCHAR(1000) NOT NULL,
  p256dh VARCHAR(120) NOT NULL,
  auth VARCHAR(60) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sp_push_endpoint (endpoint_hash),
  KEY idx_sp_push_user (user_id),
  CONSTRAINT sp_fk_push_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
