<?php

class Create_user_activities
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $db->raw('CREATE TABLE IF NOT EXISTS user_activities (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            activity_type VARCHAR(40) NOT NULL,
            title VARCHAR(180) NOT NULL,
            details VARCHAR(255) NULL,
            duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_activities_user_date_idx (user_id, created_at),
            CONSTRAINT user_activities_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down()
    {
        // Preserve user activity history when rolling back application code.
    }
}
