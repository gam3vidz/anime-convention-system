SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN friend VARCHAR(120) NOT NULL DEFAULT ''''',
    'SELECT ''friend already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'friend'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN buddy_request VARCHAR(255) NOT NULL DEFAULT ''''',
    'SELECT ''buddy_request already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'buddy_request'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN gender VARCHAR(30) NOT NULL DEFAULT ''''',
    'SELECT ''gender already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'gender'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN hotel_room VARCHAR(100) NOT NULL DEFAULT ''''',
    'SELECT ''hotel_room already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'hotel_room'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN hotel_checked_in TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''hotel_checked_in already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'hotel_checked_in'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN date_of_birth DATE NULL',
    'SELECT ''date_of_birth already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'date_of_birth'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN requested_hours VARCHAR(40) NOT NULL DEFAULT ''''',
    'SELECT ''requested_hours already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'requested_hours'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN previous_experience TEXT NULL',
    'SELECT ''previous_experience already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'previous_experience'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN skills TEXT NULL',
    'SELECT ''skills already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'skills'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN additional_notes TEXT NULL',
    'SELECT ''additional_notes already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'additional_notes'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN application_submitted_at DATETIME NULL',
    'SELECT ''application_submitted_at already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'application_submitted_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN carpool_request VARCHAR(255) NOT NULL DEFAULT ''''',
    'SELECT ''carpool_request already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'carpool_request'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN discord VARCHAR(120) NOT NULL DEFAULT ''''',
    'SELECT ''discord already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'discord'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN discord_id VARCHAR(80) NOT NULL DEFAULT ''''',
    'SELECT ''discord_id already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'discord_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN discord_username VARCHAR(120) NOT NULL DEFAULT ''''',
    'SELECT ''discord_username already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'discord_username'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN discord_avatar VARCHAR(255) NOT NULL DEFAULT ''''',
    'SELECT ''discord_avatar already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'discord_avatar'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN discord_roles_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
    'SELECT ''discord_roles_json already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'discord_roles_json'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN clocked_in TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''clocked_in already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'clocked_in'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN clocked_at DATETIME NULL',
    'SELECT ''clocked_at already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'clocked_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS user_availability (
  user_id BIGINT UNSIGNED NOT NULL,
  available_day VARCHAR(20) NOT NULL,
  hour_slot VARCHAR(20) NOT NULL,
  PRIMARY KEY (user_id, available_day, hour_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hotel_rooms (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_name VARCHAR(100) NOT NULL UNIQUE,
  capacity INT UNSIGNED NOT NULL DEFAULT 4,
  gender VARCHAR(30) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_name VARCHAR(160) NOT NULL DEFAULT '',
  action VARCHAR(80) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_created_at (created_at),
  INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS guest_flights (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  guest_name VARCHAR(160) NOT NULL,
  confirmation_number VARCHAR(80) NOT NULL DEFAULT '',
  flight_number VARCHAR(40) NOT NULL,
  flight_date DATE NOT NULL,
  flight_status VARCHAR(255) NOT NULL DEFAULT '',
  airline VARCHAR(160) NOT NULL DEFAULT '',
  departure_airport VARCHAR(160) NOT NULL DEFAULT '',
  arrival_airport VARCHAR(160) NOT NULL DEFAULT '',
  scheduled_departure VARCHAR(80) NOT NULL DEFAULT '',
  scheduled_arrival VARCHAR(80) NOT NULL DEFAULT '',
  assigned_user_id BIGINT UNSIGNED NULL,
  last_notified_status VARCHAR(255) NOT NULL DEFAULT '',
  last_notified_arrival VARCHAR(80) NOT NULL DEFAULT '',
  notification_error VARCHAR(255) NOT NULL DEFAULT '',
  raw_json LONGTEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_flight_date (flight_date),
  INDEX idx_flight_number (flight_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE guest_flights MODIFY COLUMN flight_status VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE guest_flights ADD COLUMN IF NOT EXISTS confirmation_number VARCHAR(80) NOT NULL DEFAULT '' AFTER guest_name;
ALTER TABLE guest_flights ADD COLUMN IF NOT EXISTS assigned_user_id BIGINT UNSIGNED NULL AFTER scheduled_arrival;
ALTER TABLE guest_flights ADD COLUMN IF NOT EXISTS last_notified_status VARCHAR(255) NOT NULL DEFAULT '' AFTER assigned_user_id;
ALTER TABLE guest_flights ADD COLUMN IF NOT EXISTS last_notified_arrival VARCHAR(80) NOT NULL DEFAULT '' AFTER last_notified_status;
ALTER TABLE guest_flights ADD COLUMN IF NOT EXISTS notification_error VARCHAR(255) NOT NULL DEFAULT '' AFTER last_notified_arrival;

CREATE TABLE IF NOT EXISTS volunteer_management_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  strengths TEXT NULL,
  growth_areas TEXT NULL,
  next_year_recommendation VARCHAR(40) NOT NULL DEFAULT 'Undecided',
  private_summary TEXT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS volunteer_management_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  note_type VARCHAR(40) NOT NULL DEFAULT 'General',
  event_year SMALLINT UNSIGNED NOT NULL,
  note_text TEXT NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_volunteer_notes_user (user_id, created_at),
  INDEX idx_volunteer_notes_year (event_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS time_clock_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  clock_in_at DATETIME NOT NULL,
  clock_out_at DATETIME NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'web',
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_user_clock_in (user_id, clock_in_at),
  INDEX idx_open_clock (user_id, clock_out_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS missed_punch_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  discord_id VARCHAR(80) NOT NULL DEFAULT '',
  request_note VARCHAR(255) NOT NULL DEFAULT '',
  status VARCHAR(30) NOT NULL DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_status (status),
  INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE shifts MODIFY COLUMN note TEXT NULL;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN shirt_picked_up TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''shirt_picked_up already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'shirt_picked_up'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN shirt_picked_up_at DATETIME NULL',
    'SELECT ''shirt_picked_up_at already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'shirt_picked_up_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN shirt_picked_up_by BIGINT UNSIGNED NULL',
    'SELECT ''shirt_picked_up_by already exists'''
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'shirt_picked_up_by'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
