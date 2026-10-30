<?php

class Add_email_verification_and_teacher_id_photo
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $columns = [
            'email_verified_at' => 'DATETIME NULL',
            'email_otp_hash' => 'VARCHAR(255) NULL',
            'email_otp_expires_at' => 'DATETIME NULL',
            'email_otp_sent_at' => 'DATETIME NULL',
            'email_otp_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'email_otp_resend_count' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'email_otp_resend_reset_at' => 'DATETIME NULL',
            'teacher_id_photo' => 'VARCHAR(80) NULL',
        ];
        foreach ($columns as $name => $definition) {
            $exists = $db->raw("SHOW COLUMNS FROM users LIKE '" . $name . "'")->fetch(PDO::FETCH_ASSOC);
            if (!$exists) {
                $db->raw("ALTER TABLE users ADD COLUMN " . $name . ' ' . $definition);
            }
        }
        $db->raw("UPDATE users SET email_verified_at = COALESCE(created_at, NOW()) WHERE email_verified_at IS NULL AND is_active = 1");
    }

    public function down()
    {
        $db = $this->_lava->call->database();
        $names = ['teacher_id_photo', 'email_otp_resend_reset_at', 'email_otp_resend_count', 'email_otp_attempts', 'email_otp_sent_at', 'email_otp_expires_at', 'email_otp_hash', 'email_verified_at'];
        foreach ($names as $name) {
            $exists = $db->raw("SHOW COLUMNS FROM users LIKE '" . $name . "'")->fetch(PDO::FETCH_ASSOC);
            if ($exists) {
                $db->raw('ALTER TABLE users DROP COLUMN ' . $name);
            }
        }
    }
}
