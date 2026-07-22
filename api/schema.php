<?php
declare(strict_types=1);

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensureColumnDefinition(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
    }
}

function ensureSchema(PDO $pdo): void {
    ensureColumn($pdo, 'users', 'phone', 'VARCHAR(40) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'emergency_contact', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'shirt_size', 'VARCHAR(10) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'shirt_picked_up', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'users', 'shirt_picked_up_at', 'DATETIME NULL');
    ensureColumn($pdo, 'users', 'shirt_picked_up_by', 'BIGINT UNSIGNED NULL');
    ensureColumn($pdo, 'users', 'allergies', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'gender', 'VARCHAR(30) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'date_of_birth', 'DATE NULL');
    ensureColumn($pdo, 'users', 'requested_hours', 'VARCHAR(40) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'previous_experience', 'TEXT NULL');
    ensureColumn($pdo, 'users', 'skills', 'TEXT NULL');
    ensureColumn($pdo, 'users', 'additional_notes', 'TEXT NULL');
    ensureColumn($pdo, 'users', 'application_submitted_at', 'DATETIME NULL');
    ensureColumn($pdo, 'users', 'hotel_room', 'VARCHAR(100) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'hotel_checked_in', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'users', 'profile_photo', 'MEDIUMTEXT NULL');
    ensureColumn($pdo, 'users', 'friend', 'VARCHAR(120) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'discord', 'VARCHAR(120) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'password_reset_requested', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'users', 'password_reset_requested_at', 'DATETIME NULL');
    ensureColumn($pdo, 'users', 'availability_json', 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
    ensureColumn($pdo, 'users', 'buddy_request', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'carpool_request', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'discord_id', 'VARCHAR(80) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'discord_username', 'VARCHAR(120) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'discord_avatar', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
    ensureColumn($pdo, 'users', 'discord_roles_json', 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
    ensureColumn($pdo, 'users', 'clocked_in', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'users', 'clocked_at', 'DATETIME NULL');
    ensureColumnDefinition($pdo, 'shifts', 'note', 'TEXT NULL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        actor_user_id BIGINT UNSIGNED NULL,
        actor_name VARCHAR(160) NOT NULL DEFAULT '',
        action VARCHAR(80) NOT NULL,
        details TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_created_at (created_at),
        INDEX idx_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS volunteer_management_profiles (
        user_id BIGINT UNSIGNED NOT NULL,
        strengths TEXT NULL,
        growth_areas TEXT NULL,
        next_year_recommendation VARCHAR(40) NOT NULL DEFAULT 'Undecided',
        private_summary TEXT NULL,
        updated_by BIGINT UNSIGNED NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS volunteer_management_notes (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_availability (
        user_id BIGINT UNSIGNED NOT NULL,
        available_day VARCHAR(20) NOT NULL,
        hour_slot VARCHAR(20) NOT NULL,
        PRIMARY KEY (user_id, available_day, hour_slot)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hotel_rooms (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        room_name VARCHAR(100) NOT NULL UNIQUE,
        capacity INT UNSIGNED NOT NULL DEFAULT 4,
        gender VARCHAR(30) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS guest_flights (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensureColumn($pdo, 'guest_flights', 'confirmation_number', "VARCHAR(80) NOT NULL DEFAULT ''");
    ensureColumnDefinition($pdo, 'guest_flights', 'flight_status', "VARCHAR(255) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'guest_flights', 'assigned_user_id', 'BIGINT UNSIGNED NULL');
    ensureColumn($pdo, 'guest_flights', 'last_notified_status', "VARCHAR(255) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'guest_flights', 'last_notified_arrival', "VARCHAR(80) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'guest_flights', 'notification_error', "VARCHAR(255) NOT NULL DEFAULT ''");
    $pdo->exec("CREATE TABLE IF NOT EXISTS time_clock_entries (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS missed_punch_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        discord_id VARCHAR(80) NOT NULL DEFAULT '',
        request_note VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(30) NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_status (status),
        INDEX idx_user_created (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incidents (
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
        INDEX idx_incident_status (status, severity),
        INDEX idx_incident_occurred (occurred_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incident_evidence (
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
        INDEX idx_evidence_incident (incident_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incident_activity (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        incident_id BIGINT UNSIGNED NOT NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        actor_name VARCHAR(160) NOT NULL DEFAULT '',
        action VARCHAR(80) NOT NULL,
        details TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_incident_activity (incident_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS shift_alert_rules (
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
        INDEX idx_shift_alert_date (shift_date, enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS shift_alert_deliveries (
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
        INDEX idx_alert_delivery_status (status, updated_at),
        INDEX idx_alert_delivery_shift (shift_id, shift_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
