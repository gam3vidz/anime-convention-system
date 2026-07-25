-- Delta H Vendor Hall interactive booth map migration
-- Adds append-only notes tied to each booth position and ensures the
-- position-specific vendor assignment table exists.
-- Safe to run more than once on MySQL 8 / MariaDB 10.5+.

CREATE TABLE IF NOT EXISTS vendor_hall_assignments (
  spot_code VARCHAR(8) NOT NULL,
  vendor_name VARCHAR(160) NOT NULL DEFAULT '',
  notes TEXT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (spot_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only: every note is a new row keyed by booth spot_code. Existing
-- notes are never mutated so the full chronological history is preserved.
CREATE TABLE IF NOT EXISTS vendor_hall_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  spot_code VARCHAR(8) NOT NULL,
  note_text TEXT NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  author_name VARCHAR(160) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vendor_notes_spot (spot_code, created_at),
  KEY idx_vendor_notes_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
