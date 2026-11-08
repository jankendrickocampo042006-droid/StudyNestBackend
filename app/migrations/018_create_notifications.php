<?php

class Create_notifications
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
            "CREATE TABLE IF NOT EXISTS studynest_notifications (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS studynest_notification_preferences (
                user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                email_certificates TINYINT(1) NOT NULL DEFAULT 1,
                email_content_updates TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT studynest_notification_preferences_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS studynest_notification_email_outbox (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($statements as $statement) {
            $db->raw($statement);
        }
    }

    public function down()
    {
        // Keep notification history and delivery records when rolling back application code.
    }
}
