<?php

class Create_private_quiz_ai_usage
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $this->_lava->call->database()->raw(
            "CREATE TABLE IF NOT EXISTS private_quiz_ai_usage (
                user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                window_started_at DATETIME NOT NULL,
                generation_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT private_quiz_ai_usage_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down()
    {
        // Keep generation limits when rolling back application code.
    }
}
