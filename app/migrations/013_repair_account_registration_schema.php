<?php

class Repair_account_registration_schema
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $role = $db->raw("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if ($role && strpos($role['Type'], "'teacher'") === false) {
            $db->raw("ALTER TABLE users MODIFY role ENUM('admin','moderator','teacher','user') NOT NULL DEFAULT 'user'");
        }

        $columns = [
            'teacher_id' => 'VARCHAR(60) NULL AFTER role',
            'teacher_status' => "ENUM('pending','approved','rejected') NULL AFTER teacher_id",
            'teacher_reviewed_at' => 'DATETIME NULL AFTER teacher_status',
            'teacher_id_photo' => 'VARCHAR(80) NULL AFTER teacher_reviewed_at',
            'teacher_id_photo_data' => 'MEDIUMBLOB NULL AFTER teacher_id_photo',
            'email_verified_at' => 'DATETIME NULL',
            'email_otp_hash' => 'VARCHAR(255) NULL',
            'email_otp_expires_at' => 'DATETIME NULL',
            'email_otp_sent_at' => 'DATETIME NULL',
            'email_otp_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'email_otp_resend_count' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'email_otp_resend_reset_at' => 'DATETIME NULL',
        ];
        foreach ($columns as $name => $definition) {
            $exists = $db->raw("SHOW COLUMNS FROM users LIKE '" . $name . "'")->fetch(PDO::FETCH_ASSOC);
            if (!$exists) {
                $db->raw('ALTER TABLE users ADD COLUMN ' . $name . ' ' . $definition);
            }
        }

        $index = $db->raw("SHOW INDEX FROM users WHERE Key_name = 'teacher_application_status_idx'")->fetch(PDO::FETCH_ASSOC);
        if (!$index) {
            $db->raw('ALTER TABLE users ADD INDEX teacher_application_status_idx (teacher_status)');
        }
    }

    public function down()
    {
        // Preserve account verification and teacher application data on rollback.
    }
}
