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
        $columns = [
            'email_verified_at' => 'DATETIME NULL',
            'email_otp_hash' => 'VARCHAR(255) NULL',
            'email_otp_expires_at' => 'DATETIME NULL',
            'email_otp_sent_at' => 'DATETIME NULL',
            'email_otp_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'email_otp_resend_count' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'email_otp_resend_reset_at' => 'DATETIME NULL',
            'teacher_id_photo' => 'VARCHAR(80) NULL',
            'teacher_id_photo_data' => 'MEDIUMBLOB NULL',
        ];
        foreach ($columns as $name => $definition) {
            $exists = $db->raw("SHOW COLUMNS FROM users LIKE '" . $name . "'")->fetch(PDO::FETCH_ASSOC);
            if (!$exists) {
                $db->raw('ALTER TABLE users ADD COLUMN ' . $name . ' ' . $definition);
            }
        }
    }

    public function down()
    {
        // Preserve account verification and teacher application data on rollback.
    }
}
