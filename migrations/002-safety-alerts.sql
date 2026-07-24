-- Delta H Safety incidents and missed shift alert migration
-- Safe to run more than once on MySQL 8 / MariaDB 10.5+.

CREATE TABLE IF NOT EXISTS incidents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  incident_number VARCHAR(40) NOT NULL DEFAULT '',
  title VARCHAR(180) NOT NULL,
  incident_type VARCHAR(60) NOT NULL DEFAULT 'Other',
  severity VARCHAR(20) NOT NULL DEFAULT 'Low',
  status VARCHAR(30) NOT NULL DEFAULT 'Open',
  occurred_at DATETIME NOT NULL,
  location VARCHAR(180) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  actions_taken TEXT NULL,
  people_involved TEXT NULL,
  witnesses TEXT NULL,
  medical_attention TINYINT(1) NOT NULL DEFAULT 0,
  police_contacted TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_incident_number (incident_number),
  KEY idx_incident_status (status, severity),
  KEY idx_incident_occurred (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS incident_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  incident_id BIGINT UNSIGNED NOT NULL,
  evidence_type VARCHAR(20) NOT NULL DEFAULT 'upload',
  file_name VARCHAR(255) NOT NULL DEFAULT '',
  file_path VARCHAR(500) NOT NULL DEFAULT '',
  source_url VARCHAR(2048) NOT NULL DEFAULT '',
  mime_type VARCHAR(120) NOT NULL DEFAULT '',
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  caption VARCHAR(500) NOT NULL DEFAULT '',
  uploaded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_evidence_incident (incident_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS incident_activity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  incident_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_name VARCHAR(160) NOT NULL DEFAULT '',
  action VARCHAR(80) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_incident_activity (incident_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shift_alert_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shift_id VARCHAR(40) NOT NULL,
  shift_date DATE NOT NULL,
  grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  recipient_user_ids_json TEXT NOT NULL,
  message_template TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_shift_alert_rule (shift_id),
  KEY idx_shift_alert_date (shift_date, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shift_alert_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rule_id BIGINT UNSIGNED NOT NULL,
  shift_id VARCHAR(40) NOT NULL,
  shift_date DATE NOT NULL,
  volunteer_user_id BIGINT UNSIGNED NOT NULL,
  recipient_user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  message TEXT NULL,
  last_error VARCHAR(1000) NOT NULL DEFAULT '',
  sent_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_shift_alert_delivery (rule_id, shift_date, volunteer_user_id, recipient_user_id),
  KEY idx_alert_delivery_status (status, updated_at),
  KEY idx_alert_delivery_shift (shift_id, shift_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
