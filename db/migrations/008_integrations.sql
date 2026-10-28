ALTER TABLE sp_users ADD COLUMN ai_consent_at DATETIME NULL AFTER session_version;

CREATE TABLE sp_ai_usage (
  user_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  calls SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, day),
  CONSTRAINT sp_fk_ai_usage_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
