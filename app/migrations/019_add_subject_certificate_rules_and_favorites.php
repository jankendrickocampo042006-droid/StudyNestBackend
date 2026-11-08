<?php

class Add_subject_certificate_rules_and_favorites
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $statements = [
            "CREATE TABLE IF NOT EXISTS subject_certificate_settings (
                subject_id INT UNSIGNED NOT NULL PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                required_completion_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
                minimum_quiz_score TINYINT UNSIGNED NOT NULL DEFAULT 70,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT subject_certificate_settings_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS favorite_subjects (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                subject_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY favorite_subject_user_unique (user_id, subject_id),
                INDEX favorite_subject_subject_idx (subject_id),
                CONSTRAINT favorite_subjects_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT favorite_subjects_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($statements as $statement) {
            $db->raw($statement);
        }
    }

    public function down()
    {
        // Keep subject preferences and favorites when rolling back application code.
    }
}
