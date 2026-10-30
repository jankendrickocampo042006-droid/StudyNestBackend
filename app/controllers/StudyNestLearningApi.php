<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/StudyNestApiBase.php';

class StudyNestLearningApi extends StudyNestApiBase
{
    public function bootstrap()
    {
        $user = $this->current_user();
        $payload = [
            'csrf_token' => $this->csrf_token(),
            'user' => $user ? [
                'id' => (int) $user['id'],
                'name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'teacher_id' => $user['teacher_id'],
                'teacher_status' => $user['teacher_status'],
            ] : null,
            'sets' => [],
            'schedules' => [],
            'favorites' => [],
            'attempts' => [],
            'reviewedLessons' => [],
            'activities' => [],
            'studyTimeSeconds' => 0,
            'weeklyStudy' => [],
            'weakTopics' => [],
            'topics' => $this->all('SELECT id, subject_id, title, summary FROM topics ORDER BY subject_id, title'),
            'subjects' => $this->all(
                'SELECT s.id, s.name, s.description, COUNT(DISTINCT l.id) AS lessons, COUNT(DISTINCT q.id) AS quizzes,
                 CASE WHEN COUNT(DISTINCT l.id) = 0 THEN 0
                 ELSE ROUND(100 * COUNT(DISTINCT lr.lesson_id) / COUNT(DISTINCT l.id)) END AS progress
                 FROM subjects s LEFT JOIN topics t ON t.subject_id = s.id
                 LEFT JOIN lessons l ON l.topic_id = t.id LEFT JOIN quizzes q ON q.topic_id = t.id
                 LEFT JOIN lesson_reviews lr ON lr.lesson_id = l.id AND lr.user_id = ?
                 GROUP BY s.id, s.name, s.description ORDER BY s.id'
                , [$user ? (int) $user['id'] : 0]
            ),
            'lessons' => $this->all(
                'SELECT l.id, l.topic_id, s.name AS subject, t.title AS topic, l.title, l.content, l.pdf_original_name
                 FROM lessons l JOIN topics t ON t.id = l.topic_id JOIN subjects s ON s.id = t.subject_id ORDER BY l.id'
            ),
            'quizzes' => $this->all(
                'SELECT q.id, q.topic_id, q.title, s.name AS subject, t.title AS topic, q.duration_minutes AS time,
                 COUNT(qq.id) AS questions
                 FROM quizzes q JOIN topics t ON t.id = q.topic_id JOIN subjects s ON s.id = t.subject_id
                 LEFT JOIN quiz_questions qq ON qq.quiz_id = q.id GROUP BY q.id, q.title, s.name, t.title, q.duration_minutes ORDER BY q.id'
            ),
        ];
        foreach ($payload['subjects'] as &$subject) {
            $subject['lessons'] = (int) $subject['lessons'];
            $subject['quizzes'] = (int) $subject['quizzes'];
            $subject['short'] = strtoupper(substr(preg_replace('/[^a-z]/i', '', $subject['name']), 0, 2));
            $subject['progress'] = (int) $subject['progress'];
            $subject['id'] = (int) $subject['id'];
            $subject['color'] = 'mint';
            $subject['icon'] = '';
        }
        unset($subject);
        foreach ($payload['topics'] as &$topic) {
            $topic['id'] = (int) $topic['id'];
            $topic['subject_id'] = (int) $topic['subject_id'];
        }
        unset($topic);
        foreach ($payload['lessons'] as &$lesson) {
            $lesson['id'] = (int) $lesson['id'];
            $lesson['topic_id'] = (int) $lesson['topic_id'];
            $lesson['read'] = max(1, (int) ceil(str_word_count(strip_tags($lesson['content'])) / 200)) . ' min read';
        }
        unset($lesson);
        foreach ($payload['quizzes'] as &$quiz) {
            $quiz['id'] = (int) $quiz['id'];
            $quiz['topic_id'] = (int) $quiz['topic_id'];
            $quiz['time'] = (int) $quiz['time'];
            $quiz['questions'] = (int) $quiz['questions'];
            $quiz['difficulty'] = '';
            $quiz['score'] = null;
        }
        unset($quiz);
        if ($user) {
            $user_id = (int) $user['id'];
            $payload['sets'] = $this->all(
                'SELECT ss.id, ss.title, ss.description, ss.created_at, s.name AS subject, t.title AS topic
                 FROM study_sets ss LEFT JOIN subjects s ON s.id = ss.subject_id LEFT JOIN topics t ON t.id = ss.topic_id
                 WHERE ss.user_id = ? ORDER BY ss.created_at DESC',
                [$user_id]
            );
            foreach ($payload['sets'] as &$set) {
                $set['cards'] = $this->all(
                    'SELECT id, term_question AS term, answer_definition AS answer FROM flashcards WHERE study_set_id = ? ORDER BY id',
                    [$set['id']]
                );
            }
            unset($set);
            $payload['schedules'] = $this->all(
                'SELECT id, title, schedule_date AS date, schedule_time AS time, reminder_note AS note
                 FROM study_schedules WHERE user_id = ? ORDER BY schedule_date, schedule_time',
                [$user_id]
            );
            $payload['favorites'] = array_map(
                'intval',
                array_column($this->all('SELECT lesson_id FROM favorite_lessons WHERE user_id = ?', [$user_id]), 'lesson_id')
            );
            $payload['reviewedLessons'] = array_map(
                'intval',
                array_column($this->all('SELECT lesson_id FROM lesson_reviews WHERE user_id = ?', [$user_id]), 'lesson_id')
            );
            $payload['activities'] = $this->all(
                'SELECT activity_type AS type, title, details, created_at
                 FROM user_activities WHERE user_id = ? ORDER BY created_at DESC LIMIT 30',
                [$user_id]
            );
            $payload['studyTimeSeconds'] = (int) ($this->one(
                'SELECT COALESCE(SUM(duration_seconds), 0) AS total FROM user_activities WHERE user_id = ?',
                [$user_id]
            )['total'] ?? 0);
            $payload['weeklyStudy'] = $this->all(
                'SELECT DATE(created_at) AS study_date, SUM(duration_seconds) AS duration_seconds
                 FROM user_activities WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                 GROUP BY DATE(created_at) ORDER BY study_date',
                [$user_id]
            );
            $payload['weakTopics'] = $this->all(
                'SELECT t.id, t.title, s.name AS subject, ROUND(AVG(qa.percentage)) AS average_score, COUNT(qa.id) AS attempts
                 FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id JOIN topics t ON t.id = q.topic_id
                 JOIN subjects s ON s.id = t.subject_id WHERE qa.user_id = ?
                 GROUP BY t.id, t.title, s.name HAVING AVG(qa.percentage) < 70
                 ORDER BY average_score ASC LIMIT 5',
                [$user_id]
            );
            $payload['attempts'] = $this->all(
                'SELECT q.title, s.name AS subject, qa.percentage AS score, qa.completed_at AS date
                 FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id JOIN topics t ON t.id = q.topic_id
                 JOIN subjects s ON s.id = t.subject_id WHERE qa.user_id = ? ORDER BY qa.completed_at DESC',
                [$user_id]
            );
        }
        $this->respond($payload);
    }

    public function lesson_pdf($id)
    {
        $this->user_id();
        $lesson = $this->one(
            'SELECT pdf_storage_name, pdf_original_name FROM lessons WHERE id = ?',
            [(int) $id]
        );
        if (!$lesson || !$lesson['pdf_storage_name']) {
            $this->fail('This lesson does not have an attached PDF.', 404);
        }
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'lesson-pdfs';
        $path = $directory . DIRECTORY_SEPARATOR . basename($lesson['pdf_storage_name']);
        if (!is_file($path) || (new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf') {
            $this->fail('The lesson PDF is unavailable.', 404);
        }
        $filename = str_replace(["\r", "\n", '"'], '', $lesson['pdf_original_name'] ?: 'lesson.pdf');
        $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . $disposition . '; filename="lesson.pdf"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function profile()
    {
        $user_id = $this->user_id();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->respond(['user' => $this->current_user()]);
        }
        $this->verify_csrf();
        $data = $this->body();
        $name = $this->require_text($data['name'] ?? '', 'Full name', 100);
        $email = trim((string) ($data['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->fail('Enter a valid email address.');
        }
        if ($this->one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $user_id])) {
            $this->fail('That email address is already in use.', 409);
        }
        $previous = $this->one('SELECT email FROM users WHERE id = ?', [$user_id]);
        if ($previous && strcasecmp($previous['email'], $email) !== 0) {
            $this->execute(
                'UPDATE users SET full_name = ?, email = ?, email_verified_at = NULL, is_active = 0,
                 email_otp_hash = NULL, email_otp_expires_at = NULL, email_otp_sent_at = NULL, email_otp_attempts = 0,
                 email_otp_resend_count = 0, email_otp_resend_reset_at = NULL
                 WHERE id = ?',
                [$name, $email, $user_id]
            );
            $this->session->unset_userdata('studynest_user_id');
            try {
                $this->send_email_otp($user_id, $email);
            } catch (RuntimeException $error) {
                $this->respond(['error' => $error->getMessage(), 'requires_email_verification' => true, 'email' => $email], 503);
            }
            $this->respond(['requires_email_verification' => true, 'email' => $email]);
        }
        $this->execute('UPDATE users SET full_name = ? WHERE id = ?', [$name, $user_id]);
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                $this->fail('Password must be at least 8 characters long.');
            }
            $this->execute('UPDATE users SET password = ? WHERE id = ?', [password_hash($data['password'], PASSWORD_DEFAULT), $user_id]);
        }
        $user = $this->current_user();
        $this->respond([
            'user' => [
                'id' => (int) $user['id'],
                'name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
        ]);
    }

    private function set_payload($set_id, $user_id)
    {
        $set = $this->one(
            'SELECT ss.id, ss.user_id, ss.title, ss.description, ss.created_at, s.name AS subject, t.title AS topic
             FROM study_sets ss
             LEFT JOIN subjects s ON s.id = ss.subject_id
             LEFT JOIN topics t ON t.id = ss.topic_id
             WHERE ss.id = ? AND ss.user_id = ?',
            [$set_id, $user_id]
        );
        if (!$set) {
            return null;
        }
        $set['cards'] = $this->all(
            'SELECT id, term_question AS term, answer_definition AS answer FROM flashcards WHERE study_set_id = ? ORDER BY id',
            [$set_id]
        );
        return $set;
    }

    private function save_set($user_id, array $data, $set_id = null)
    {
        $is_update = (bool) $set_id;
        $title = $this->require_text($data['title'] ?? '', 'Study set title', 120);
        $description = trim((string) ($data['description'] ?? ''));
        $subject_name = trim((string) ($data['subject'] ?? ''));
        $topic_name = trim((string) ($data['topic'] ?? ''));
        $cards = $data['cards'] ?? null;
        if (!is_array($cards) || count($cards) < 1 || count($cards) > 100) {
            $this->fail('Add between 1 and 100 flashcards.');
        }
        foreach ($cards as $card) {
            if (!is_array($card) || trim((string) ($card['term'] ?? '')) === '' || trim((string) ($card['answer'] ?? '')) === '') {
                $this->fail('Every flashcard needs a term and an answer.');
            }
        }

        $subject = $subject_name === '' ? null : $this->one('SELECT id FROM subjects WHERE name = ?', [$subject_name]);
        if ($subject_name !== '' && !$subject) {
            $this->fail('Choose a valid subject.');
        }
        $topic = null;
        if ($topic_name !== '' && $subject) {
            $topic = $this->one('SELECT id FROM topics WHERE title = ? AND subject_id = ?', [$topic_name, $subject['id']]);
            if (!$topic) {
                $this->execute('INSERT INTO topics (subject_id, title, summary) VALUES (?, ?, ?)', [$subject['id'], $topic_name, '']);
                $topic = $this->one('SELECT id FROM topics WHERE title = ? AND subject_id = ?', [$topic_name, $subject['id']]);
            }
        }

        $db = $this->db();
        $db->raw('START TRANSACTION');
        try {
            if ($set_id) {
                $changed = $this->execute(
                    'UPDATE study_sets SET subject_id = ?, topic_id = ?, title = ?, description = ? WHERE id = ? AND user_id = ?',
                    [$subject['id'] ?? null, $topic['id'] ?? null, $title, $description, $set_id, $user_id]
                );
                if (!$changed && !$this->one('SELECT id FROM study_sets WHERE id = ? AND user_id = ?', [$set_id, $user_id])) {
                    $db->raw('ROLLBACK');
                    $this->fail('Study set not found.', 404);
                }
                $this->execute('DELETE FROM flashcards WHERE study_set_id = ?', [$set_id]);
            } else {
                $set_id = $this->insert(
                    'INSERT INTO study_sets (user_id, subject_id, topic_id, title, description) VALUES (?, ?, ?, ?, ?)',
                    [$user_id, $subject['id'] ?? null, $topic['id'] ?? null, $title, $description]
                );
            }
            foreach ($cards as $card) {
                $this->execute(
                    'INSERT INTO flashcards (study_set_id, term_question, answer_definition) VALUES (?, ?, ?)',
                    [$set_id, trim($card['term']), trim($card['answer'])]
                );
            }
            $db->raw('COMMIT');
        } catch (Throwable $error) {
            $db->raw('ROLLBACK');
            throw $error;
        }
        $saved = $this->set_payload($set_id, $user_id);
        $this->activity(
            $user_id,
            $is_update ? 'study_set_updated' : 'study_set_created',
            $title,
            count($cards) . ' flashcards'
        );
        return $saved;
    }

    public function study_sets()
    {
        $user_id = $this->user_id();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $sets = $this->all('SELECT id FROM study_sets WHERE user_id = ? ORDER BY created_at DESC', [$user_id]);
            $this->respond(array_values(array_filter(array_map(
                fn($set) => $this->set_payload((int) $set['id'], $user_id),
                $sets
            ))));
        }
        $this->verify_csrf();
        $set = $this->save_set($user_id, $this->body());
        $this->respond($set, 201);
    }

    public function study_set($id)
    {
        $user_id = $this->user_id();
        $id = (int) $id;
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $set = $this->set_payload($id, $user_id);
            $set ? $this->respond($set) : $this->fail('Study set not found.', 404);
        }
        $this->verify_csrf();
        if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            $set = $this->save_set($user_id, $this->body(), $id);
            $this->respond($set);
        }
        if (!$this->one('SELECT id FROM study_sets WHERE id = ? AND user_id = ?', [$id, $user_id])) {
            $this->fail('Study set not found.', 404);
        }
        $this->execute('DELETE FROM study_sets WHERE id = ? AND user_id = ?', [$id, $user_id]);
        $this->respond(['ok' => true]);
    }

    public function review_lesson($lesson_id)
    {
        $user_id = $this->user_id();
        $this->verify_csrf();
        if (!$this->one('SELECT id FROM lessons WHERE id = ?', [(int) $lesson_id])) {
            $this->fail('Lesson not found.', 404);
        }
        $this->execute(
            'INSERT INTO lesson_reviews (user_id, lesson_id, reviewed_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE reviewed_at = NOW()',
            [$user_id, (int) $lesson_id]
        );
        $lesson = $this->one(
            'SELECT l.title, s.name AS subject FROM lessons l JOIN topics t ON t.id = l.topic_id
             JOIN subjects s ON s.id = t.subject_id WHERE l.id = ?',
            [(int) $lesson_id]
        );
        $duration = min(7200, max(0, (int) ($_SERVER['HTTP_X_STUDY_DURATION'] ?? 0)));
        $this->activity($user_id, 'lesson_reviewed', $lesson['title'], $lesson['subject'], $duration);
        $this->respond(['ok' => true]);
    }

    public function schedules()
    {
        $user_id = $this->user_id();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->respond($this->all(
                'SELECT id, title, schedule_date AS date, schedule_time AS time, reminder_note AS note
                 FROM study_schedules WHERE user_id = ? ORDER BY schedule_date, schedule_time',
                [$user_id]
            ));
        }
        $this->verify_csrf();
        $data = $this->body();
        $title = $this->require_text($data['title'] ?? '', 'Session title', 120);
        $date = (string) ($data['date'] ?? '');
        $time = (string) ($data['time'] ?? '');
        $date_parts = explode('-', $date);
        if (
            count($date_parts) !== 3 ||
            !checkdate((int) $date_parts[1], (int) $date_parts[2], (int) $date_parts[0]) ||
            !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)
        ) {
            $this->fail('Choose a valid date and time.');
        }
        $note = substr(trim((string) ($data['note'] ?? '')), 0, 180);
        if (!empty($data['id'])) {
            if (!$this->one('SELECT id FROM study_schedules WHERE id = ? AND user_id = ?', [(int) $data['id'], $user_id])) {
                $this->fail('Study session not found.', 404);
            }
            $this->execute(
                'UPDATE study_schedules SET title = ?, schedule_date = ?, schedule_time = ?, reminder_note = ? WHERE id = ? AND user_id = ?',
                [$title, $date, $time, $note, (int) $data['id'], $user_id]
            );
            $id = (int) $data['id'];
            $this->activity($user_id, 'schedule_updated', $title, $date . ' ' . $time);
        } else {
            $id = $this->insert(
                'INSERT INTO study_schedules (user_id, title, schedule_date, schedule_time, reminder_note) VALUES (?, ?, ?, ?, ?)',
                [$user_id, $title, $date, $time, $note]
            );
            $this->activity($user_id, 'schedule_created', $title, $date . ' ' . $time);
        }
        $this->respond($this->one(
            'SELECT id, title, schedule_date AS date, schedule_time AS time, reminder_note AS note FROM study_schedules WHERE id = ? AND user_id = ?',
            [$id, $user_id]
        ), empty($data['id']) ? 201 : 200);
    }

    public function schedule($id)
    {
        $this->verify_csrf();
        $user_id = $this->user_id();
        if (!$this->one('SELECT id FROM study_schedules WHERE id = ? AND user_id = ?', [(int) $id, $user_id])) {
            $this->fail('Study session not found.', 404);
        }
        $this->execute('DELETE FROM study_schedules WHERE id = ? AND user_id = ?', [(int) $id, $user_id]);
        $this->respond(['ok' => true]);
    }

    public function favorites()
    {
        $user_id = $this->user_id();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->respond(array_map('intval', array_column(
                $this->all('SELECT lesson_id FROM favorite_lessons WHERE user_id = ?', [$user_id]),
                'lesson_id'
            )));
        }
        $this->verify_csrf();
        $data = $this->body();
        $lesson_id = (int) ($data['lesson_id'] ?? 0);
        if (!$lesson_id || !$this->one('SELECT id FROM lessons WHERE id = ?', [$lesson_id])) {
            $this->fail('Lesson not found.', 404);
        }
        $exists = $this->one('SELECT id FROM favorite_lessons WHERE user_id = ? AND lesson_id = ?', [$user_id, $lesson_id]);
        if ($exists) {
            $this->execute('DELETE FROM favorite_lessons WHERE user_id = ? AND lesson_id = ?', [$user_id, $lesson_id]);
            $lesson = $this->one('SELECT title FROM lessons WHERE id = ?', [$lesson_id]);
            $this->activity($user_id, 'favorite_removed', $lesson['title'], '');
            $this->respond(['saved' => false]);
        }
        $this->execute('INSERT INTO favorite_lessons (user_id, lesson_id) VALUES (?, ?)', [$user_id, $lesson_id]);
        $lesson = $this->one('SELECT title FROM lessons WHERE id = ?', [$lesson_id]);
        $this->activity($user_id, 'favorite_added', $lesson['title'], '');
        $this->respond(['saved' => true], 201);
    }

    public function favorite($lesson_id)
    {
        $this->verify_csrf();
        $this->execute('DELETE FROM favorite_lessons WHERE user_id = ? AND lesson_id = ?', [$this->user_id(), (int) $lesson_id]);
        $this->respond(['ok' => true]);
    }

    public function quiz($id)
    {
        $this->user_id();
        $quiz = $this->one(
            'SELECT q.id, q.title, s.name AS subject, t.title AS topic, q.duration_minutes AS time
             FROM quizzes q JOIN topics t ON t.id = q.topic_id JOIN subjects s ON s.id = t.subject_id WHERE q.id = ?',
            [(int) $id]
        );
        if (!$quiz) {
            $this->fail('Quiz not found.', 404);
        }
        $quiz['questions'] = $this->all(
            'SELECT id, question_text AS question, type, option_a, option_b, option_c, option_d
             FROM quiz_questions WHERE quiz_id = ? ORDER BY id',
            [(int) $id]
        );
        foreach ($quiz['questions'] as &$question) {
            $question['options'] = array_values(array_filter(
                [$question['option_a'], $question['option_b'], $question['option_c'], $question['option_d']],
                fn($option) => $option !== null
            ));
            unset($question['option_a'], $question['option_b'], $question['option_c'], $question['option_d']);
        }
        unset($question);
        $this->respond($quiz);
    }

    public function submit_quiz($id)
    {
        $user_id = $this->user_id();
        $this->verify_csrf();
        $data = $this->body();
        $quiz_id = (int) $id;
        if (!$this->one('SELECT id FROM quizzes WHERE id = ?', [$quiz_id])) {
            $this->fail('Quiz not found.', 404);
        }
        $questions = $this->all(
            'SELECT id, question_text AS question, type, option_a, option_b, option_c, option_d, correct_option, explanation
             FROM quiz_questions WHERE quiz_id = ? ORDER BY id',
            [$quiz_id]
        );
        if (!$questions) {
            $this->fail('This quiz does not have any questions yet.', 422);
        }
        $answers = $data['answers'] ?? [];
        if (!is_array($answers)) {
            $this->fail('Quiz answers must be an object.');
        }
        $score = 0;
        $review = [];
        foreach ($questions as $question) {
            $answer = (string) ($answers[$question['id']] ?? '');
            $correct = $question['type'] === 'identification'
                ? mb_strtolower(trim((string) $question['correct_option'])) === mb_strtolower(trim($answer))
                : hash_equals((string) $question['correct_option'], $answer);
            $score += $correct ? 1 : 0;
            $options = array_values(array_filter(
                [$question['option_a'], $question['option_b'], $question['option_c'], $question['option_d']],
                fn($option) => $option !== null
            ));
            $review[] = [
                'id' => (int) $question['id'],
                'question' => $question['question'],
                'type' => $question['type'],
                'options' => $options,
                'answer' => $question['type'] === 'identification'
                    ? $question['correct_option']
                    : ($question['type'] === 'true_false'
                        ? array_search($question['correct_option'], $options, true)
                        : strpos('ABCD', (string) $question['correct_option'])),
                'correct_option' => $question['correct_option'],
                'selected_option' => $answer,
                'correct' => $correct,
                'explanation' => $question['explanation'],
            ];
        }
        $percentage = round(($score / count($questions)) * 100, 2);
        $this->execute(
            'INSERT INTO quiz_attempts (user_id, quiz_id, score, total_questions, percentage, time_taken, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$user_id, $quiz_id, $score, count($questions), $percentage, substr((string) ($data['time_taken'] ?? '00:00'), 0, 20)]
        );
        $quiz = $this->one(
            'SELECT q.title, s.name AS subject FROM quizzes q JOIN topics t ON t.id = q.topic_id
             JOIN subjects s ON s.id = t.subject_id WHERE q.id = ?',
            [$quiz_id]
        );
        $duration = min(7200, max(0, (int) ($data['duration_seconds'] ?? 0)));
        $this->activity(
            $user_id,
            'quiz_completed',
            $quiz['title'],
            $quiz['subject'] . ' · ' . $percentage . '%',
            $duration
        );
        $this->respond([
            'score' => $score,
            'total_questions' => count($questions),
            'percentage' => $percentage,
            'review' => $review,
        ], 201);
    }
}
