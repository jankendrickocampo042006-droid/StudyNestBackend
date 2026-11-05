SET @studynest_schema = DATABASE();
SET @studynest_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role') = 1
    AND LOCATE(
        "'teacher'",
        (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @studynest_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role')
    ) = 0,
    "ALTER TABLE users MODIFY role ENUM('admin','moderator','teacher','user') NOT NULL DEFAULT 'user'",
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
    "ALTER TABLE users ADD COLUMN teacher_status ENUM('pending','approved','rejected') NULL AFTER teacher_id",
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
