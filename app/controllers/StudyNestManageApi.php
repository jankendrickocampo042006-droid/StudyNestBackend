<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/StudyNestApiBase.php';

class StudyNestManageApi extends StudyNestApiBase
{
    public function manage()
    {
        $this->require_admin();
        $quizzes = $this->all(
            'SELECT q.id, q.topic_id, q.title, q.duration_minutes AS time, t.title AS topic, s.name AS subject
             FROM quizzes q JOIN topics t ON t.id = q.topic_id JOIN subjects s ON s.id = t.subject_id ORDER BY q.id'
        );
        foreach ($quizzes as &$quiz) {
            $quiz['questions'] = $this->all(
                'SELECT id, question_text AS question, type, option_a, option_b, option_c, option_d, correct_option, explanation
                 FROM quiz_questions WHERE quiz_id = ? ORDER BY id',
                [$quiz['id']]
            );
        }
        unset($quiz);
        $this->respond([
            'topics' => $this->all(
                'SELECT t.id, t.subject_id, t.title, t.summary, s.name AS subject
                 FROM topics t JOIN subjects s ON s.id = t.subject_id ORDER BY s.name, t.title'
            ),
            'subjects' => $this->all(
                'SELECT id, name, description FROM subjects ORDER BY name'
            ),
            'lessons' => $this->all(
                'SELECT l.id, l.topic_id, l.title, l.content, l.pdf_original_name, t.title AS topic, s.name AS subject
                 FROM lessons l JOIN topics t ON t.id = l.topic_id JOIN subjects s ON s.id = t.subject_id ORDER BY l.title'
            ),
            'quizzes' => $quizzes,
        ]);
    }

    public function manage_teachers()
    {
        $this->require_super_admin();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->respond($this->all(
                "SELECT id, full_name AS name, email, teacher_id, created_at
                 FROM users WHERE role = 'teacher' ORDER BY full_name, id"
            ));
        }

        $this->verify_csrf();
        $data = $this->body();
        $name = $this->require_text($data['name'] ?? '', 'Full name', 100);
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->fail('Enter a valid email address.');
        }
        if (strlen($password) < 8) {
            $this->fail('The temporary password must be at least 8 characters long.');
        }
        if ($this->one('SELECT id FROM users WHERE email = ?', [$email])) {
            $this->fail('An account with that email already exists.', 409);
        }

        $username = substr(preg_replace('/[^a-z0-9_]/i', '_', strstr($email, '@', true)), 0, 80) . '_' . bin2hex(random_bytes(3));
        $id = $this->insert(
            'INSERT INTO users (username, full_name, email, password, role, email_verified_at, is_active) VALUES (?, ?, ?, ?, ?, NOW(), 1)',
            [$username, $name, $email, password_hash($password, PASSWORD_DEFAULT), 'teacher']
        );
        $this->respond(['id' => $id, 'name' => $name, 'email' => $email, 'role' => 'teacher'], 201);
    }

    public function manage_teacher_applications()
    {
        $this->require_super_admin();
        $this->respond($this->all(
            "SELECT id, full_name AS name, email, teacher_id_photo, created_at
             FROM users WHERE teacher_status = 'pending' AND email_verified_at IS NOT NULL AND is_active = 1
             ORDER BY created_at, id"
        ));
    }

    public function teacher_application_photo($id)
    {
        $this->require_super_admin();
        $application = $this->one(
            "SELECT teacher_id_photo FROM users
             WHERE id = ? AND teacher_status = 'pending' AND email_verified_at IS NOT NULL AND is_active = 1",
            [(int) $id]
        );
        if (!$application || !$application['teacher_id_photo']) {
            $this->fail('Teacher ID photo not found.', 404);
        }
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'teacher-ids';
        $path = $directory . DIRECTORY_SEPARATOR . basename($application['teacher_id_photo']);
        if (!is_file($path)) {
            $this->fail('Teacher ID photo is unavailable.', 404);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->fail('Teacher ID photo is unavailable.', 404);
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function manage_teacher_application($id)
    {
        $admin_id = $this->require_super_admin();
        $id = (int) $id;
        $application = $this->one(
            "SELECT id, full_name AS name, teacher_id_photo FROM users
             WHERE id = ? AND teacher_status = 'pending' AND email_verified_at IS NOT NULL AND is_active = 1",
            [$id]
        );
        if (!$application) {
            $this->fail('Pending teacher application not found.', 404);
        }

        $this->verify_csrf();
        $data = $this->body();
        $decision = $data['decision'] ?? '';
        if (!in_array($decision, ['approve', 'reject'], true)) {
            $this->fail('Choose whether to approve or reject this application.');
        }
        if ($decision === 'approve' && !$application['teacher_id_photo']) {
            $this->fail('This application has no teacher ID photo and cannot be approved.', 422);
        }

        $approved = $decision === 'approve';
        $updated = $this->execute(
            "UPDATE users SET role = ?, teacher_status = ?, teacher_reviewed_at = NOW()
             WHERE id = ? AND teacher_status = 'pending' AND email_verified_at IS NOT NULL AND is_active = 1",
            [$approved ? 'teacher' : 'user', $approved ? 'approved' : 'rejected', $id]
        );
        if (!$updated) {
            $this->fail('This teacher application was already reviewed.', 409);
        }
        $this->activity(
            $admin_id,
            $approved ? 'teacher_application_approved' : 'teacher_application_rejected',
            $application['name'],
            'Teacher account request'
        );
        $this->respond(['ok' => true, 'decision' => $decision]);
    }

    private function catalog_config($kind)
    {
        $config = [
            'subjects' => [
                'table' => 'subjects',
                'fields' => ['name', 'description'],
                'required' => ['name'],
            ],
            'topics' => [
                'table' => 'topics',
                'fields' => ['subject_id', 'title', 'summary'],
                'required' => ['subject_id', 'title'],
            ],
            'lessons' => [
                'table' => 'lessons',
                'fields' => ['topic_id', 'title', 'content'],
                'required' => ['topic_id', 'title', 'content'],
            ],
        ];
        if (!isset($config[$kind])) {
            $this->fail('Unknown content type.', 404);
        }
        return $config[$kind];
    }

    private function validate_catalog($kind, array $data)
    {
        $config = $this->catalog_config($kind);
        $values = [];
        foreach ($config['fields'] as $field) {
            $value = $data[$field] ?? null;
            if (in_array($field, $config['required'], true)) {
                if ($field === 'subject_id' || $field === 'topic_id') {
                    $value = (int) $value;
                    if ($value < 1) {
                        $this->fail('Choose a valid ' . str_replace('_id', '', $field) . '.');
                    }
                } else {
                    $max = $field === 'content' ? 500000 : ($field === 'name' ? 150 : 180);
                    $value = $this->catalog_text($data, $field, ucfirst($field), $max);
                }
            } elseif ($field === 'description' || $field === 'summary') {
                $value = trim((string) $value);
            }
            $values[$field] = $value;
        }
        if ($kind === 'topics' && !$this->one('SELECT id FROM subjects WHERE id = ?', [$values['subject_id']])) {
            $this->fail('Subject not found.', 404);
        }
        if ($kind === 'lessons' && !$this->one('SELECT id FROM topics WHERE id = ?', [$values['topic_id']])) {
            $this->fail('Topic not found.', 404);
        }
        return [$config, $values];
    }

    public function manage_subjects()
    {
        $this->catalog('subjects');
    }

    public function manage_subject($id)
    {
        $this->catalog_item('subjects', $id);
    }

    public function manage_topics()
    {
        $this->catalog('topics');
    }

    public function manage_topic($id)
    {
        $this->catalog_item('topics', $id);
    }

    public function manage_lessons()
    {
        $this->catalog('lessons');
    }

    public function manage_lesson($id)
    {
        $this->catalog_item('lessons', $id);
    }

    public function catalog($kind)
    {
        $user_id = $this->require_admin();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $config = $this->catalog_config($kind);
            if ($kind === 'lessons') {
                $this->respond($this->all(
                    'SELECT id, topic_id, title, content, pdf_original_name FROM lessons ORDER BY id DESC'
                ));
            }
            $this->respond($this->all('SELECT * FROM ' . $config['table'] . ' ORDER BY id DESC'));
        }
        $this->verify_csrf();
        $data = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false
            ? $_POST
            : $this->body();
        [$config, $values] = $this->validate_catalog($kind, $data);
        $pdf = $kind === 'lessons' ? $this->save_lesson_pdf() : null;
        if ($pdf) {
            $values['pdf_storage_name'] = $pdf['storage_name'];
            $values['pdf_original_name'] = $pdf['original_name'];
        }
        $columns = array_keys($values);
        try {
            $id = $this->insert(
                'INSERT INTO ' . $config['table'] . ' (' . implode(', ', $columns) . ') VALUES (' .
                implode(', ', array_fill(0, count($columns), '?')) . ')',
                array_values($values)
            );
        } catch (Throwable $error) {
            if ($pdf) {
                $this->delete_private_lesson_pdf($pdf['storage_name']);
            }
            throw $error;
        }
        $this->activity($user_id, 'content_created', $values['name'] ?? $values['title'], ucfirst(rtrim($kind, 's')));
        unset($values['pdf_storage_name']);
        $this->respond(array_merge(['id' => $id], $values), 201);
    }

    public function catalog_item($kind, $id)
    {
        $user_id = $this->require_admin();
        $config = $this->catalog_config($kind);
        $id = (int) $id;
        $existing = $this->one(
            'SELECT id' . ($kind === 'lessons' ? ', pdf_storage_name' : '') .
            ' FROM ' . $config['table'] . ' WHERE id = ?',
            [$id]
        );
        if (!$existing) {
            $this->fail('Content not found.', 404);
        }
        $this->verify_csrf();
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $this->execute('DELETE FROM ' . $config['table'] . ' WHERE id = ?', [$id]);
            if ($kind === 'lessons') {
                $this->delete_private_lesson_pdf($existing['pdf_storage_name']);
            }
            $this->activity($user_id, 'content_deleted', $config['table'] . ' #' . $id);
            $this->respond(['ok' => true]);
        }
        $data = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false
            ? $_POST
            : $this->body();
        [$config, $values] = $this->validate_catalog($kind, $data);
        $pdf = $kind === 'lessons' ? $this->save_lesson_pdf() : null;
        $remove_pdf = $kind === 'lessons' && ($data['remove_pdf_attachment'] ?? '') === '1';
        if ($pdf) {
            $values['pdf_storage_name'] = $pdf['storage_name'];
            $values['pdf_original_name'] = $pdf['original_name'];
        } elseif ($remove_pdf) {
            $values['pdf_storage_name'] = null;
            $values['pdf_original_name'] = null;
        }
        $assignments = implode(', ', array_map(fn($field) => $field . ' = ?', array_keys($values)));
        try {
            $this->execute(
                'UPDATE ' . $config['table'] . ' SET ' . $assignments . ' WHERE id = ?',
                array_merge(array_values($values), [$id])
            );
        } catch (Throwable $error) {
            if ($pdf) {
                $this->delete_private_lesson_pdf($pdf['storage_name']);
            }
            throw $error;
        }
        if ($kind === 'lessons' && ($pdf || $remove_pdf)) {
            $this->delete_private_lesson_pdf($existing['pdf_storage_name']);
        }
        $this->activity($user_id, 'content_updated', $values['name'] ?? $values['title'], ucfirst(rtrim($kind, 's')));
        unset($values['pdf_storage_name']);
        $this->respond(array_merge(['id' => $id], $values));
    }

    private function quiz_data(array $data)
    {
        $title = $this->catalog_text($data, 'title', 'Quiz title', 180);
        $topic_id = (int) ($data['topic_id'] ?? 0);
        $duration = (int) ($data['duration_minutes'] ?? 0);
        $questions = $data['questions'] ?? null;
        if (!$topic_id || !$this->one('SELECT id FROM topics WHERE id = ?', [$topic_id])) {
            $this->fail('Choose a valid topic.');
        }
        if ($duration < 1 || $duration > 600) {
            $this->fail('Quiz duration must be between 1 and 600 minutes.');
        }
        if (!is_array($questions) || count($questions) < 1 || count($questions) > 100) {
            $this->fail('A quiz needs between 1 and 100 questions.');
        }
        foreach ($questions as &$question) {
            if (!is_array($question)) {
                $this->fail('Each quiz question must be an object.');
            }
            $question['question_text'] = $this->catalog_text($question, 'question_text', 'Question', 10000);
            $question['type'] = $question['type'] ?? '';
            $question['explanation'] = trim((string) ($question['explanation'] ?? ''));
            if ($question['type'] === 'true_false') {
                $question['option_a'] = 'True';
                $question['option_b'] = 'False';
                $question['option_c'] = null;
                $question['option_d'] = null;
                if (!in_array($question['correct_option'] ?? '', ['True', 'False'], true)) {
                    $this->fail('Choose True or False as the correct answer.');
                }
            } elseif ($question['type'] === 'multiple_choice') {
                foreach (['a', 'b', 'c', 'd'] as $letter) {
                    $question['option_' . $letter] = $this->catalog_text(
                        $question,
                        'option_' . $letter,
                        'Option ' . strtoupper($letter),
                        255
                    );
                }
                if (!in_array($question['correct_option'] ?? '', ['A', 'B', 'C', 'D'], true)) {
                    $this->fail('Choose a valid correct option.');
                }
            } elseif ($question['type'] === 'identification') {
                $question['option_a'] = null;
                $question['option_b'] = null;
                $question['option_c'] = null;
                $question['option_d'] = null;
                $question['correct_option'] = $this->catalog_text($question, 'correct_option', 'Correct answer', 255);
            } else {
                $this->fail('Choose a supported question type.');
            }
        }
        unset($question);
        return [$title, $topic_id, $duration, $questions];
    }

    public function manage_quizzes()
    {
        $user_id = $this->require_admin();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->respond($this->all('SELECT id, topic_id, title, duration_minutes FROM quizzes ORDER BY id DESC'));
        }
        $this->verify_csrf();
        [$title, $topic_id, $duration, $questions] = $this->quiz_data($this->body());
        $db = $this->db();
        $db->raw('START TRANSACTION');
        try {
            $quiz_id = $this->insert(
                'INSERT INTO quizzes (topic_id, title, duration_minutes) VALUES (?, ?, ?)',
                [$topic_id, $title, $duration]
            );
            $this->replace_quiz_questions($quiz_id, $questions);
            $db->raw('COMMIT');
        } catch (Throwable $error) {
            $db->raw('ROLLBACK');
            throw $error;
        }
        $this->activity($user_id, 'content_created', $title, 'Quiz');
        $this->respond(['id' => $quiz_id], 201);
    }

    public function manage_quiz($id)
    {
        $user_id = $this->require_admin();
        $id = (int) $id;
        $quiz = $this->one('SELECT id, title FROM quizzes WHERE id = ?', [$id]);
        if (!$quiz) {
            $this->fail('Quiz not found.', 404);
        }
        $this->verify_csrf();
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $this->execute('DELETE FROM quizzes WHERE id = ?', [$id]);
            $this->activity($user_id, 'content_deleted', $quiz['title'], 'Quiz');
            $this->respond(['ok' => true]);
        }
        [$title, $topic_id, $duration, $questions] = $this->quiz_data($this->body());
        $db = $this->db();
        $db->raw('START TRANSACTION');
        try {
            $this->execute('UPDATE quizzes SET topic_id = ?, title = ?, duration_minutes = ? WHERE id = ?', [$topic_id, $title, $duration, $id]);
            $this->replace_quiz_questions($id, $questions);
            $db->raw('COMMIT');
        } catch (Throwable $error) {
            $db->raw('ROLLBACK');
            throw $error;
        }
        $this->activity($user_id, 'content_updated', $title, 'Quiz');
        $this->respond(['id' => $id]);
    }

    private function replace_quiz_questions($quiz_id, array $questions)
    {
        $this->execute('DELETE FROM quiz_questions WHERE quiz_id = ?', [$quiz_id]);
        foreach ($questions as $question) {
            $this->execute(
                'INSERT INTO quiz_questions
                 (quiz_id, question_text, type, option_a, option_b, option_c, option_d, correct_option, explanation)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $quiz_id, $question['question_text'], $question['type'],
                    $question['option_a'], $question['option_b'], $question['option_c'], $question['option_d'],
                    $question['correct_option'], $question['explanation'],
                ]
            );
        }
    }
}
