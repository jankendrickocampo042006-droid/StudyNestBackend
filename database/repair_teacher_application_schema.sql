SET @studynest_schema = DATABASE();
SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role') = 1
    AND LOCATE(
        '''teacher''',
        (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role')
    ) = 0,
    'ALTER TABLE users MODIFY role ENUM(''admin'',''moderator'',''teacher'',''user'') NOT NULL DEFAULT ''user''',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'teacher_id') = 0,
    'ALTER TABLE users ADD COLUMN teacher_id VARCHAR(60) NULL AFTER role',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'teacher_status') = 0,
    'ALTER TABLE users ADD COLUMN teacher_status ENUM(''pending'',''approved'',''rejected'') NULL AFTER teacher_id',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'teacher_reviewed_at') = 0,
    'ALTER TABLE users ADD COLUMN teacher_reviewed_at DATETIME NULL AFTER teacher_status',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'teacher_id_photo') = 0,
    'ALTER TABLE users ADD COLUMN teacher_id_photo VARCHAR(80) NULL AFTER teacher_reviewed_at',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'teacher_id_photo_data') = 0,
    'ALTER TABLE users ADD COLUMN teacher_id_photo_data MEDIUMBLOB NULL AFTER teacher_id_photo',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND INDEX_NAME = 'teacher_application_status_idx') = 0,
    'ALTER TABLE users ADD INDEX teacher_application_status_idx (teacher_status)',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at') = 0,
    'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_otp_hash') = 0,
    'ALTER TABLE users ADD COLUMN email_otp_hash VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_otp_expires_at') = 0,
    'ALTER TABLE users ADD COLUMN email_otp_expires_at DATETIME NULL',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_otp_sent_at') = 0,
    'ALTER TABLE users ADD COLUMN email_otp_sent_at DATETIME NULL',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_otp_attempts') = 0,
    'ALTER TABLE users ADD COLUMN email_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_otp_resend_count') = 0,
    'ALTER TABLE users ADD COLUMN email_otp_resend_count TINYINT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_otp_resend_reset_at') = 0,
    'ALTER TABLE users ADD COLUMN email_otp_resend_reset_at DATETIME NULL',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'study_sets' AND COLUMN_NAME = 'custom_topic') = 0,
    'ALTER TABLE study_sets ADD COLUMN custom_topic VARCHAR(180) NULL AFTER topic_id',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'study_sets' AND COLUMN_NAME = 'pdf_original_name') = 0,
    'ALTER TABLE study_sets ADD COLUMN pdf_original_name VARCHAR(180) NULL AFTER custom_topic',
    'SELECT 1'
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'study_sets' AND COLUMN_NAME = 'pdf_data') = 0,
    'ALTER TABLE study_sets ADD COLUMN pdf_data LONGBLOB NULL AFTER pdf_original_name',
    IF(
        (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'study_sets' AND COLUMN_NAME = 'pdf_data') <> 'longblob',
        'ALTER TABLE study_sets MODIFY COLUMN pdf_data LONGBLOB NULL',
        'SELECT 1'
    )
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'lessons' AND COLUMN_NAME = 'pdf_data') = 0,
    'ALTER TABLE lessons ADD COLUMN pdf_data LONGBLOB NULL AFTER pdf_original_name',
    IF(
        (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'lessons' AND COLUMN_NAME = 'pdf_data') <> 'longblob',
        'ALTER TABLE lessons MODIFY COLUMN pdf_data LONGBLOB NULL',
        'SELECT 1'
    )
);
PREPARE studynest_repair_stmt FROM @studynest_sql;
EXECUTE studynest_repair_stmt;
DEALLOCATE PREPARE studynest_repair_stmt;

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(512) NOT NULL,
    timestamp INT UNSIGNED NOT NULL,
    data LONGBLOB NOT NULL,
    PRIMARY KEY (id),
    INDEX sessions_timestamp_idx (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS private_subjects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX private_subjects_user_idx (user_id),
    CONSTRAINT private_subjects_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS private_topics (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subject_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX private_topics_subject_idx (subject_id),
    CONSTRAINT private_topics_subject_fk FOREIGN KEY (subject_id) REFERENCES private_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS private_lessons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    topic_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    content LONGTEXT NOT NULL,
    pdf_original_name VARCHAR(180) NULL,
    pdf_data LONGBLOB NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX private_lessons_topic_idx (topic_id),
    CONSTRAINT private_lessons_topic_fk FOREIGN KEY (topic_id) REFERENCES private_topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS private_quizzes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    topic_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 15,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX private_quizzes_topic_idx (topic_id),
    CONSTRAINT private_quizzes_topic_fk FOREIGN KEY (topic_id) REFERENCES private_topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS private_quiz_questions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT UNSIGNED NOT NULL,
    question_text TEXT NOT NULL,
    type ENUM('multiple_choice','true_false','identification') NOT NULL,
    option_a VARCHAR(255) NULL,
    option_b VARCHAR(255) NULL,
    option_c VARCHAR(255) NULL,
    option_d VARCHAR(255) NULL,
    correct_option VARCHAR(255) NOT NULL,
    explanation TEXT NULL,
    INDEX private_quiz_questions_quiz_idx (quiz_id),
    CONSTRAINT private_quiz_questions_quiz_fk FOREIGN KEY (quiz_id) REFERENCES private_quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS private_quiz_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    quiz_id INT UNSIGNED NOT NULL,
    score INT NOT NULL,
    total_questions INT NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    time_taken VARCHAR(20) NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX private_quiz_attempts_user_idx (user_id),
    INDEX private_quiz_attempts_quiz_idx (quiz_id),
    CONSTRAINT private_quiz_attempts_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT private_quiz_attempts_quiz_fk FOREIGN KEY (quiz_id) REFERENCES private_quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS certificates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    student_name VARCHAR(100) NOT NULL,
    subject_name VARCHAR(150) NOT NULL,
    teacher_name VARCHAR(100) NOT NULL,
    certificate_code VARCHAR(24) NOT NULL UNIQUE,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY certificates_user_subject_unique (user_id, subject_id),
    INDEX certificates_user_issued_idx (user_id, issued_at),
    CONSTRAINT certificates_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS favorite_subjects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY favorite_subject_user_unique (user_id, subject_id),
    INDEX favorite_subject_subject_idx (subject_id),
    CONSTRAINT favorite_subjects_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT favorite_subjects_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subject_certificate_settings (
    subject_id INT UNSIGNED NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    required_completion_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
    minimum_quiz_score TINYINT UNSIGNED NOT NULL DEFAULT 70,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT subject_certificate_settings_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS studynest_notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    event_key VARCHAR(190) NOT NULL,
    type VARCHAR(40) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(255) NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY studynest_notification_event_unique (user_id, event_key),
    INDEX studynest_notification_user_date_idx (user_id, created_at),
    CONSTRAINT studynest_notifications_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS studynest_notification_preferences (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    email_certificates TINYINT(1) NOT NULL DEFAULT 1,
    email_content_updates TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT studynest_notification_preferences_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS studynest_notification_email_outbox (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    notification_id INT UNSIGNED NOT NULL,
    category VARCHAR(40) NOT NULL,
    status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    UNIQUE KEY studynest_notification_email_unique (notification_id),
    INDEX studynest_notification_email_due_idx (status, available_at),
    CONSTRAINT studynest_notification_email_notification_fk FOREIGN KEY (notification_id) REFERENCES studynest_notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
