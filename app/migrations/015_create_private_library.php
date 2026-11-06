<?php

class Create_private_library
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
            "CREATE TABLE IF NOT EXISTS private_subjects (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                description TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX private_subjects_user_idx (user_id),
                CONSTRAINT private_subjects_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS private_topics (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                subject_id INT UNSIGNED NOT NULL,
                title VARCHAR(180) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX private_topics_subject_idx (subject_id),
                CONSTRAINT private_topics_subject_fk FOREIGN KEY (subject_id) REFERENCES private_subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS private_lessons (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                topic_id INT UNSIGNED NOT NULL,
                title VARCHAR(180) NOT NULL,
                content LONGTEXT NOT NULL,
                pdf_original_name VARCHAR(180) NULL,
                pdf_data LONGBLOB NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX private_lessons_topic_idx (topic_id),
                CONSTRAINT private_lessons_topic_fk FOREIGN KEY (topic_id) REFERENCES private_topics(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS private_quizzes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                topic_id INT UNSIGNED NOT NULL,
                title VARCHAR(180) NOT NULL,
                duration_minutes INT UNSIGNED NOT NULL DEFAULT 15,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX private_quizzes_topic_idx (topic_id),
                CONSTRAINT private_quizzes_topic_fk FOREIGN KEY (topic_id) REFERENCES private_topics(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS private_quiz_questions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                quiz_id INT UNSIGNED NOT NULL,
                question_text TEXT NOT NULL,
                type ENUM('multiple_choice','true_false','identification') NOT NULL,
                option_a VARCHAR(255) NULL,
                option_b VARCHAR(255) NULL,
                option_c VARCHAR(255) NULL,
                option_d VARCHAR(255) NULL,
                correct_option VARCHAR(255) NOT NULL,
                explanation TEXT NULL,
                INDEX private_quiz_questions_quiz_idx (quiz_id),
                CONSTRAINT private_quiz_questions_quiz_fk FOREIGN KEY (quiz_id) REFERENCES private_quizzes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS private_quiz_attempts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                quiz_id INT UNSIGNED NOT NULL,
                score INT NOT NULL,
                total_questions INT NOT NULL,
                percentage DECIMAL(5,2) NOT NULL,
                time_taken VARCHAR(20) NULL,
                completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX private_quiz_attempts_user_idx (user_id),
                INDEX private_quiz_attempts_quiz_idx (quiz_id),
                CONSTRAINT private_quiz_attempts_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT private_quiz_attempts_quiz_fk FOREIGN KEY (quiz_id) REFERENCES private_quizzes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($statements as $statement) {
            $db->raw($statement);
        }
    }

    public function down()
    {
        // Keep personal study materials when rolling back application code.
    }
}
