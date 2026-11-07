<?php

class Create_certificates
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $this->_lava->call->database()->raw(
            "CREATE TABLE IF NOT EXISTS certificates (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down()
    {
        // Keep awarded certificates when rolling back application code.
    }
}
