<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/StudyNestApiBase.php';

class StudyNestManageApi extends StudyNestApiBase
{
    private function content_manager()
    {
        $user = $this->current_user();
        if (!$user) {
            $this->fail('Please sign in to continue.', 401);
        }
        if (!in_array($user['role'], ['admin', 'moderator', 'teacher'], true)) {
            $this->fail('Only an administrator or teacher can manage learning content.', 403);
        }
        return $user;
    }

    private function assert_subject_access($subject_id, array $manager)
    {
        $subject = $this->one('SELECT id, owner_id FROM subjects WHERE id = ?', [(int) $subject_id]);
        if (!$subject || ($manager['role'] === 'teacher' && (int) ($subject['owner_id'] ?? 0) !== (int) $manager['id'])) {
            $this->fail('Subject not found.', 404);
        }
        return $subject;
    }

    private function assert_topic_access($topic_id, array $manager)
    {
        $topic = $this->one(
            'SELECT t.id, t.subject_id, s.owner_id
             FROM topics t JOIN subjects s ON s.id = t.subject_id
             WHERE t.id = ?',
            [(int) $topic_id]
        );
        if (!$topic || ($manager['role'] === 'teacher' && (int) ($topic['owner_id'] ?? 0) !== (int) $manager['id'])) {
            $this->fail('Topic not found.', 404);
        }
        return $topic;
    }

    private function assert_catalog_access($kind, $id, array $manager)
    {
        $queries = [
            'subjects' => 'SELECT id, owner_id FROM subjects WHERE id = ?',
            'topics' => 'SELECT t.id, s.owner_id FROM topics t JOIN subjects s ON s.id = t.subject_id WHERE t.id = ?',
            'lessons' => 'SELECT l.id, l.pdf_storage_name, s.owner_id FROM lessons l JOIN topics t ON t.id = l.topic_id JOIN subjects s ON s.id = t.subject_id WHERE l.id = ?',
            'quizzes' => 'SELECT q.id, q.title, s.owner_id FROM quizzes q JOIN topics t ON t.id = q.topic_id JOIN subjects s ON s.id = t.subject_id WHERE q.id = ?',
        ];
        $item = $this->one($queries[$kind], [(int) $id]);
        if (!$item || ($manager['role'] === 'teacher' && (int) ($item['owner_id'] ?? 0) !== (int) $manager['id'])) {
            $this->fail('Content not found.', 404);
        }
        return $item;
    }

    public function manage()
    {
        $manager = $this->content_manager();
        $teacher_scope = $manager['role'] === 'teacher';
        $subject_filter = $teacher_scope ? ' WHERE s.owner_id = ?' : '';
        $scope_values = $teacher_scope ? [(int) $manager['id']] : [];
        $quizzes = $this->all(
            'SELECT q.id, q.topic_id, q.title, q.duration_minutes AS time, t.title AS topic, s.name AS subject
             FROM quizzes q JOIN topics t ON t.id = q.topic_id JOIN subjects s ON s.id = t.subject_id' .
             $subject_filter . ' ORDER BY q.id',
            $scope_values
        );
        $questions_by_quiz = [];
        foreach ($this->all(
            'SELECT qq.quiz_id, qq.id, qq.question_text AS question, qq.type, qq.option_a, qq.option_b, qq.option_c, qq.option_d, qq.correct_option, qq.explanation
             FROM quiz_questions qq JOIN quizzes q ON q.id = qq.quiz_id
             JOIN topics t ON t.id = q.topic_id JOIN subjects s ON s.id = t.subject_id' .
             $subject_filter . ' ORDER BY qq.quiz_id, qq.id',
            $scope_values
        ) as $question) {
            $questions_by_quiz[$question['quiz_id']][] = $question;
        }
        foreach ($quizzes as &$quiz) {
            $quiz['questions'] = $questions_by_quiz[$quiz['id']] ?? [];
        }
        unset($quiz);
        $this->respond([
            'topics' => $this->all(
                'SELECT t.id, t.subject_id, t.title, t.summary, s.name AS subject
                 FROM topics t JOIN subjects s ON s.id = t.subject_id' .
                 $subject_filter . ' ORDER BY s.name, t.title',
                $scope_values
            ),
            'subjects' => $this->all(
                'SELECT id, name, description FROM subjects' .
                ($teacher_scope ? ' WHERE owner_id = ?' : '') . ' ORDER BY name',
                $scope_values
            ),
            'lessons' => $this->all(
                'SELECT l.id, l.topic_id, l.title, l.content, l.pdf_original_name, t.title AS topic, s.name AS subject
                 FROM lessons l JOIN topics t ON t.id = l.topic_id JOIN subjects s ON s.id = t.subject_id' .
                 $subject_filter . ' ORDER BY l.title',
                $scope_values
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
                 FROM users
                 WHERE is_active = 1
                   AND (teacher_status = 'approved' OR (role = 'teacher' AND teacher_status IS NULL))
                 ORDER BY full_name, id"
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

    public function manage_students()
    {
        $this->require_super_admin();
        $this->respond($this->all(
            "SELECT id, full_name AS name, email, created_at, is_active, email_verified_at
             FROM users
             WHERE role = 'user' AND (teacher_status IS NULL OR teacher_status = 'rejected')
             ORDER BY created_at DESC, id DESC"
        ));
    }

    public function manage_teacher_applications()
    {
        $this->require_super_admin();
        $applications = $this->all(
            "SELECT id, full_name AS name, email, teacher_id_photo,
                    teacher_id_photo_data IS NOT NULL AS teacher_id_photo_stored, created_at
             FROM users WHERE teacher_status = 'pending' AND email_verified_at IS NOT NULL AND is_active = 1
             ORDER BY created_at, id"
        );
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'teacher-ids';
        foreach ($applications as &$application) {
            $legacy_path = $application['teacher_id_photo']
                ? $directory . DIRECTORY_SEPARATOR . basename($application['teacher_id_photo'])
                : '';
            $application['teacher_id_photo_available'] = (int) $application['teacher_id_photo_stored'] === 1
                || ($legacy_path !== '' && is_file($legacy_path));
            unset($application['teacher_id_photo_stored']);
        }
        unset($application);
        $this->respond($applications);
    }

    public function teacher_application_photo($id)
    {
        $this->require_super_admin();
        $application = $this->one(
            "SELECT teacher_id_photo, teacher_id_photo_data FROM users
             WHERE id = ? AND teacher_status = 'pending' AND email_verified_at IS NOT NULL AND is_active = 1",
            [(int) $id]
        );
        if (!$application || (!$application['teacher_id_photo'] && $application['teacher_id_photo_data'] === null)) {
            $this->fail('Teacher ID photo not found.', 404);
        }
        if ($application['teacher_id_photo_data'] !== null) {
            $contents = $application['teacher_id_photo_data'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        } else {
            $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'teacher-ids';
            $path = $directory . DIRECTORY_SEPARATOR . basename($application['teacher_id_photo']);
            if (!is_file($path)) {
                $this->fail('Teacher ID photo is unavailable. Ask the applicant to upload it again.', 404);
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            $contents = file_get_contents($path);
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $contents === false) {
            $this->fail('Teacher ID photo is unavailable.', 404);
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($contents));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        echo $contents;
        exit;
    }

    public function manage_teacher_application($id)
    {
        $admin_id = $this->require_super_admin();
        $id = (int) $id;
        $application = $this->one(
            "SELECT id, full_name AS name, email, teacher_id, created_at, is_active, email_verified_at,
                    teacher_id_photo, teacher_id_photo_data IS NOT NULL AS teacher_id_photo_stored
             FROM users
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
        if ($decision === 'approve') {
            $legacy_path = '';
            if ($application['teacher_id_photo']) {
                $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'teacher-ids';
                $legacy_path = $directory . DIRECTORY_SEPARATOR . basename($application['teacher_id_photo']);
            }
            if ((int) $application['teacher_id_photo_stored'] !== 1 && ($legacy_path === '' || !is_file($legacy_path))) {
                $this->fail('This application has no available teacher ID photo and cannot be approved. Ask the applicant to upload it again.', 422);
            }
        }

        $approved = $decision === 'approve';
        $reviewed_at = $this->one("SHOW COLUMNS FROM users LIKE 'teacher_reviewed_at'");
        $review_timestamp_sql = $reviewed_at ? ', teacher_reviewed_at = NOW()' : '';
        if (!$reviewed_at) {
            error_log('StudyNest teacher review: users.teacher_reviewed_at is missing; apply migration 012_repair_teacher_application_schema.php.');
        }
        try {
            $updated = $this->execute(
                "UPDATE users SET role = ?, teacher_status = ?" . $review_timestamp_sql . "
                 WHERE id = ? AND teacher_status = 'pending' AND email_verified_at IS NOT NULL AND is_active = 1",
                [$approved ? 'teacher' : 'user', $approved ? 'approved' : 'rejected', $id]
            );
        } catch (Throwable $error) {
            error_log(sprintf(
                'StudyNest teacher review update failed: %s: %s',
                get_class($error),
                $error->getMessage()
            ));
            throw $error;
        }
        if (!$updated) {
            $this->fail('This teacher application was already reviewed.', 409);
        }
        $this->activity(
            $admin_id,
            $approved ? 'teacher_application_approved' : 'teacher_application_rejected',
            $application['name'],
            'Teacher account request'
        );
        $account = [
            'id' => (int) $application['id'],
            'name' => $application['name'],
            'email' => $application['email'],
            'teacher_id' => $application['teacher_id'],
            'created_at' => $application['created_at'],
        ];
        if (!$approved) {
            $account['is_active'] = (int) $application['is_active'];
            $account['email_verified_at'] = $application['email_verified_at'];
        }
        $this->respond(['ok' => true, 'decision' => $decision, 'account' => $account]);
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

    private function validate_catalog($kind, array $data, array $manager)
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
        if ($kind === 'topics') {
            $this->assert_subject_access($values['subject_id'], $manager);
        }
        if ($kind === 'lessons') {
            $this->assert_topic_access($values['topic_id'], $manager);
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
        $manager = $this->content_manager();
        $user_id = (int) $manager['id'];
        $teacher_scope = $manager['role'] === 'teacher';
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $config = $this->catalog_config($kind);
            if ($kind === 'lessons') {
                $this->respond($this->all(
                    'SELECT l.id, l.topic_id, l.title, l.content, l.pdf_original_name
                     FROM lessons l JOIN topics t ON t.id = l.topic_id
                     JOIN subjects s ON s.id = t.subject_id' .
                     ($teacher_scope ? ' WHERE s.owner_id = ?' : '') . ' ORDER BY l.id DESC',
                    $teacher_scope ? [$user_id] : []
                ));
            }
            if ($kind === 'subjects') {
                $this->respond($this->all(
                    'SELECT id, name, description FROM subjects' .
                    ($teacher_scope ? ' WHERE owner_id = ?' : '') . ' ORDER BY id DESC',
                    $teacher_scope ? [$user_id] : []
                ));
            }
            if ($kind === 'topics') {
                $this->respond($this->all(
                    'SELECT t.* FROM topics t JOIN subjects s ON s.id = t.subject_id' .
                    ($teacher_scope ? ' WHERE s.owner_id = ?' : '') . ' ORDER BY t.id DESC',
                    $teacher_scope ? [$user_id] : []
                ));
            }
            $this->fail('Unknown content type.', 404);
        }
        $this->verify_csrf();
        $data = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false
            ? $_POST
            : $this->body();
        [$config, $values] = $this->validate_catalog($kind, $data, $manager);
        if ($kind === 'subjects') {
            $values['owner_id'] = $user_id;
        }
        $pdf = $kind === 'lessons' ? $this->save_lesson_pdf() : null;
        if ($pdf) {
            $values['pdf_storage_name'] = null;
            $values['pdf_original_name'] = $pdf['original_name'];
            $values['pdf_data'] = $pdf['contents'];
        }
        $columns = array_keys($values);
        $id = $this->insert(
            'INSERT INTO ' . $config['table'] . ' (' . implode(', ', $columns) . ') VALUES (' .
            implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($values)
        );
        $this->activity($user_id, 'content_created', $values['name'] ?? $values['title'], ucfirst(rtrim($kind, 's')));
        unset($values['pdf_storage_name'], $values['pdf_data']);
        $this->respond(array_merge(['id' => $id], $values), 201);
    }

    public function catalog_item($kind, $id)
    {
        $manager = $this->content_manager();
        $user_id = (int) $manager['id'];
        $config = $this->catalog_config($kind);
        $id = (int) $id;
        $existing = $this->assert_catalog_access($kind, $id, $manager);
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
        [$config, $values] = $this->validate_catalog($kind, $data, $manager);
        $pdf = $kind === 'lessons' ? $this->save_lesson_pdf() : null;
        $remove_pdf = $kind === 'lessons' && ($data['remove_pdf_attachment'] ?? '') === '1';
        if ($pdf) {
            $values['pdf_storage_name'] = null;
            $values['pdf_original_name'] = $pdf['original_name'];
            $values['pdf_data'] = $pdf['contents'];
        } elseif ($remove_pdf) {
            $values['pdf_storage_name'] = null;
            $values['pdf_original_name'] = null;
            $values['pdf_data'] = null;
        }
        $assignments = implode(', ', array_map(fn($field) => $field . ' = ?', array_keys($values)));
        $this->execute(
            'UPDATE ' . $config['table'] . ' SET ' . $assignments . ' WHERE id = ?',
            array_merge(array_values($values), [$id])
        );
        if ($kind === 'lessons' && ($pdf || $remove_pdf)) {
            $this->delete_private_lesson_pdf($existing['pdf_storage_name']);
        }
        $this->activity($user_id, 'content_updated', $values['name'] ?? $values['title'], ucfirst(rtrim($kind, 's')));
        unset($values['pdf_storage_name'], $values['pdf_data']);
        $this->respond(array_merge(['id' => $id], $values));
    }

    private function quiz_data(array $data, array $manager)
    {
        $title = $this->catalog_text($data, 'title', 'Quiz title', 180);
        $topic_id = (int) ($data['topic_id'] ?? 0);
        $duration = (int) ($data['duration_minutes'] ?? 0);
        $questions = $data['questions'] ?? null;
        if (!$topic_id) {
            $this->fail('Choose a valid topic.');
        }
        $this->assert_topic_access($topic_id, $manager);
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
        $manager = $this->content_manager();
        $user_id = (int) $manager['id'];
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->respond($this->all(
                'SELECT q.id, q.topic_id, q.title, q.duration_minutes
                 FROM quizzes q JOIN topics t ON t.id = q.topic_id
                 JOIN subjects s ON s.id = t.subject_id' .
                 ($manager['role'] === 'teacher' ? ' WHERE s.owner_id = ?' : '') . ' ORDER BY q.id DESC',
                $manager['role'] === 'teacher' ? [$user_id] : []
            ));
        }
        $this->verify_csrf();
        [$title, $topic_id, $duration, $questions] = $this->quiz_data($this->body(), $manager);
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
        $manager = $this->content_manager();
        $user_id = (int) $manager['id'];
        $id = (int) $id;
        $quiz = $this->assert_catalog_access('quizzes', $id, $manager);
        $this->verify_csrf();
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $this->execute('DELETE FROM quizzes WHERE id = ?', [$id]);
            $this->activity($user_id, 'content_deleted', $quiz['title'], 'Quiz');
            $this->respond(['ok' => true]);
        }
        [$title, $topic_id, $duration, $questions] = $this->quiz_data($this->body(), $manager);
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
