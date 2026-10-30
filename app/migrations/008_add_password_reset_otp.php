<?php

class Add_password_reset_otp
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
            'password_reset_otp_hash' => 'VARCHAR(255) NULL',
            'password_reset_otp_expires_at' => 'DATETIME NULL',
            'password_reset_otp_sent_at' => 'DATETIME NULL',
            'password_reset_otp_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'password_reset_otp_send_count' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'password_reset_otp_send_reset_at' => 'DATETIME NULL',
        ];
        foreach ($columns as $name => $definition) {
            $exists = $db->raw("SHOW COLUMNS FROM users LIKE '" . $name . "'")->fetch(PDO::FETCH_ASSOC);
            if (!$exists) {
                $db->raw("ALTER TABLE users ADD COLUMN " . $name . ' ' . $definition);
            }
        }
    }

    public function down()
    {
        $db = $this->_lava->call->database();
        $names = [
            'password_reset_otp_send_reset_at',
            'password_reset_otp_send_count',
            'password_reset_otp_attempts',
            'password_reset_otp_sent_at',
            'password_reset_otp_expires_at',
            'password_reset_otp_hash',
        ];
        foreach ($names as $name) {
            $exists = $db->raw("SHOW COLUMNS FROM users LIKE '" . $name . "'")->fetch(PDO::FETCH_ASSOC);
            if ($exists) {
                $db->raw('ALTER TABLE users DROP COLUMN ' . $name);
            }
        }
    }
}
