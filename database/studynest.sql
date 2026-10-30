CREATE DATABASE IF NOT EXISTS studynest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE studynest;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','moderator','teacher','user') NOT NULL DEFAULT 'user',
    teacher_id VARCHAR(60) NULL,
    teacher_status ENUM('pending','approved','rejected') NULL,
    teacher_reviewed_at DATETIME NULL,
    teacher_id_photo VARCHAR(80) NULL,
    email_verified_at DATETIME NULL,
    email_otp_hash VARCHAR(255) NULL,
    email_otp_expires_at DATETIME NULL,
    email_otp_sent_at DATETIME NULL,
    email_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    email_otp_resend_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    email_otp_resend_reset_at DATETIME NULL,
    password_reset_otp_hash VARCHAR(255) NULL,
    password_reset_otp_expires_at DATETIME NULL,
    password_reset_otp_sent_at DATETIME NULL,
    password_reset_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    password_reset_otp_send_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    password_reset_otp_send_reset_at DATETIME NULL,
    is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    INDEX teacher_application_status_idx (teacher_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subjects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    description TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE topics (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subject_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    summary TEXT NULL,
    INDEX topics_subject_idx (subject_id),
    CONSTRAINT topics_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lessons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    topic_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    content LONGTEXT NOT NULL,
    pdf_storage_name VARCHAR(80) NULL,
    pdf_original_name VARCHAR(180) NULL,
    INDEX lessons_topic_idx (topic_id),
    CONSTRAINT lessons_topic_fk FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE study_sets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NULL,
    topic_id INT UNSIGNED NULL,
    title VARCHAR(120) NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX study_sets_user_idx (user_id),
    INDEX study_sets_subject_idx (subject_id),
    INDEX study_sets_topic_idx (topic_id),
    CONSTRAINT study_sets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT study_sets_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    CONSTRAINT study_sets_topic_fk FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE flashcards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    study_set_id INT UNSIGNED NOT NULL,
    term_question TEXT NOT NULL,
    answer_definition TEXT NOT NULL,
    INDEX flashcards_set_idx (study_set_id),
    CONSTRAINT flashcards_set_fk FOREIGN KEY (study_set_id) REFERENCES study_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quizzes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    topic_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 15,
    INDEX quizzes_topic_idx (topic_id),
    CONSTRAINT quizzes_topic_fk FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quiz_questions (
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
    INDEX quiz_questions_quiz_idx (quiz_id),
    CONSTRAINT quiz_questions_quiz_fk FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quiz_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    quiz_id INT UNSIGNED NOT NULL,
    score INT NOT NULL,
    total_questions INT NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    time_taken VARCHAR(20) NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX quiz_attempts_user_idx (user_id),
    INDEX quiz_attempts_quiz_idx (quiz_id),
    CONSTRAINT quiz_attempts_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT quiz_attempts_quiz_fk FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE study_schedules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    schedule_date DATE NOT NULL,
    schedule_time TIME NOT NULL,
    reminder_note VARCHAR(180) NULL,
    INDEX study_schedules_user_date_idx (user_id, schedule_date),
    CONSTRAINT study_schedules_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE favorite_lessons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED NOT NULL,
    UNIQUE KEY favorite_lesson_user_unique (user_id, lesson_id),
    CONSTRAINT favorite_lessons_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT favorite_lessons_lesson_fk FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lesson_reviews (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY lesson_review_user_unique (user_id, lesson_id),
    CONSTRAINT lesson_reviews_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT lesson_reviews_lesson_fk FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE user_activities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    activity_type VARCHAR(40) NOT NULL,
    title VARCHAR(180) NOT NULL,
    details VARCHAR(255) NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX user_activities_user_date_idx (user_id, created_at),
    CONSTRAINT user_activities_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;