CREATE TABLE IF NOT EXISTS password_reset_codes (
  user_id INT PRIMARY KEY,
  code_hash VARCHAR(255) NOT NULL,
  expires_at BIGINT NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  sent_at BIGINT NOT NULL,
  window_started_at BIGINT NOT NULL,
  send_count INT NOT NULL DEFAULT 1,
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
