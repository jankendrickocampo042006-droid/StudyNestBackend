<?php

class Create_studynest_tables
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $columns = $db->raw("SHOW COLUMNS FROM users LIKE 'full_name'")->fetchAll(PDO::FETCH_ASSOC);
        if (!$columns) {
            $db->raw('ALTER TABLE users ADD COLUMN full_name VARCHAR(100) NULL AFTER username');
            $db->raw("UPDATE users SET full_name = username WHERE full_name IS NULL OR full_name = ''");
            $db->raw('ALTER TABLE users MODIFY full_name VARCHAR(100) NOT NULL');
        }

        $tables = [
            'subjects' => 'CREATE TABLE IF NOT EXISTS subjects (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL UNIQUE,
                description TEXT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'topics' => 'CREATE TABLE IF NOT EXISTS topics (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                subject_id INT UNSIGNED NOT NULL,
                title VARCHAR(180) NOT NULL,
                summary TEXT NULL,
                PRIMARY KEY (id),
                KEY topics_subject_idx (subject_id),
                CONSTRAINT topics_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'lessons' => 'CREATE TABLE IF NOT EXISTS lessons (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                topic_id INT UNSIGNED NOT NULL,
                title VARCHAR(180) NOT NULL,
                content LONGTEXT NOT NULL,
                pdf_storage_name VARCHAR(80) NULL,
                pdf_original_name VARCHAR(180) NULL,
                PRIMARY KEY (id),
                KEY lessons_topic_idx (topic_id),
                CONSTRAINT lessons_topic_fk FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'study_sets' => 'CREATE TABLE IF NOT EXISTS study_sets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                subject_id INT UNSIGNED NULL,
                topic_id INT UNSIGNED NULL,
                title VARCHAR(120) NOT NULL,
                description TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY study_sets_user_idx (user_id),
                KEY study_sets_subject_idx (subject_id),
                KEY study_sets_topic_idx (topic_id),
                CONSTRAINT study_sets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT study_sets_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
                CONSTRAINT study_sets_topic_fk FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'flashcards' => 'CREATE TABLE IF NOT EXISTS flashcards (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                study_set_id INT UNSIGNED NOT NULL,
                term_question TEXT NOT NULL,
                answer_definition TEXT NOT NULL,
                PRIMARY KEY (id),
                KEY flashcards_set_idx (study_set_id),
                CONSTRAINT flashcards_set_fk FOREIGN KEY (study_set_id) REFERENCES study_sets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'quizzes' => 'CREATE TABLE IF NOT EXISTS quizzes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                topic_id INT UNSIGNED NOT NULL,
                title VARCHAR(180) NOT NULL,
                duration_minutes INT NOT NULL DEFAULT 15,
                PRIMARY KEY (id),
                KEY quizzes_topic_idx (topic_id),
                CONSTRAINT quizzes_topic_fk FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'quiz_questions' => "CREATE TABLE IF NOT EXISTS quiz_questions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                quiz_id INT UNSIGNED NOT NULL,
                question_text TEXT NOT NULL,
                type ENUM('multiple_choice','true_false','identification') NOT NULL,
                option_a VARCHAR(255) NULL,
                option_b VARCHAR(255) NULL,
                option_c VARCHAR(255) NULL,
                option_d VARCHAR(255) NULL,
                correct_option VARCHAR(255) NOT NULL,
                explanation TEXT NULL,
                PRIMARY KEY (id),
                KEY quiz_questions_quiz_idx (quiz_id),
                CONSTRAINT quiz_questions_quiz_fk FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'quiz_attempts' => 'CREATE TABLE IF NOT EXISTS quiz_attempts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                quiz_id INT UNSIGNED NOT NULL,
                score INT NOT NULL,
                total_questions INT NOT NULL,
                percentage DECIMAL(5,2) NOT NULL,
                time_taken VARCHAR(20) NULL,
                completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY quiz_attempts_user_idx (user_id),
                KEY quiz_attempts_quiz_idx (quiz_id),
                CONSTRAINT quiz_attempts_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT quiz_attempts_quiz_fk FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'study_schedules' => 'CREATE TABLE IF NOT EXISTS study_schedules (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                title VARCHAR(120) NOT NULL,
                schedule_date DATE NOT NULL,
                schedule_time TIME NOT NULL,
                reminder_note VARCHAR(180) NULL,
                PRIMARY KEY (id),
                KEY study_schedules_user_date_idx (user_id, schedule_date),
                CONSTRAINT study_schedules_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'favorite_lessons' => 'CREATE TABLE IF NOT EXISTS favorite_lessons (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                lesson_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY favorite_lesson_user_unique (user_id, lesson_id),
                CONSTRAINT favorite_lessons_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT favorite_lessons_lesson_fk FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'lesson_reviews' => 'CREATE TABLE IF NOT EXISTS lesson_reviews (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                lesson_id INT UNSIGNED NOT NULL,
                reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY lesson_review_user_unique (user_id, lesson_id),
                CONSTRAINT lesson_reviews_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT lesson_reviews_lesson_fk FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'user_activities' => 'CREATE TABLE IF NOT EXISTS user_activities (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
        foreach ($tables as $sql) {
            $db->raw($sql);
        }

    }

    public function down()
    {
        $db = $this->_lava->call->database();
        foreach (['user_activities', 'lesson_reviews', 'favorite_lessons', 'study_schedules', 'quiz_attempts', 'quiz_questions', 'quizzes', 'flashcards', 'study_sets', 'lessons', 'topics', 'subjects'] as $table) {
            $db->raw('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
