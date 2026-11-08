<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/StudyNestApiBase.php';

class StudyNestLearningApi extends StudyNestApiBase
{
    private function issue_completed_subject_certificates($user_id)
    {
        $eligible_subjects = $this->all(<<<'SQL'
            SELECT s.id AS subject_id, s.name AS subject_name,
                   student.full_name AS student_name, teacher.full_name AS teacher_name,
                   COALESCE(certificate_settings.required_completion_percent, 100) AS required_completion_percent,
                   COALESCE(certificate_settings.minimum_quiz_score, 70) AS minimum_quiz_score
            FROM subjects s
            LEFT JOIN subject_certificate_settings certificate_settings ON certificate_settings.subject_id = s.id
            JOIN users teacher ON teacher.id = s.owner_id
                AND (teacher.teacher_status = 'approved'
                     OR (teacher.role = 'teacher' AND teacher.teacher_status IS NULL))
            JOIN users student ON student.id = ?
            WHERE EXISTS (
                SELECT 1 FROM topics t JOIN lessons l ON l.topic_id = t.id
                WHERE t.subject_id = s.id
            )
            AND COALESCE(certificate_settings.enabled, 0) = 1
            AND student.role = 'user'
            AND (student.teacher_status IS NULL OR student.teacher_status = 'rejected')
            AND (
                SELECT COUNT(*)
                FROM topics t JOIN lessons l ON l.topic_id = t.id
                JOIN lesson_reviews lr ON lr.user_id = student.id AND lr.lesson_id = l.id
                WHERE t.subject_id = s.id
            ) * 100 >= COALESCE(certificate_settings.required_completion_percent, 100) * (
                SELECT COUNT(*)
                FROM topics t JOIN lessons l ON l.topic_id = t.id
                WHERE t.subject_id = s.id
            )
            AND NOT EXISTS (
                SELECT 1
                FROM topics t JOIN quizzes q ON q.topic_id = t.id
                WHERE t.subject_id = s.id
                  AND (
                      NOT EXISTS (
                          SELECT 1 FROM quiz_questions qq WHERE qq.quiz_id = q.id
                      )
                      OR NOT EXISTS (
                          SELECT 1 FROM quiz_attempts qa
                          WHERE qa.user_id = student.id AND qa.quiz_id = q.id
                            AND qa.percentage >= COALESCE(certificate_settings.minimum_quiz_score, 70)
                      )
                  )
            )
            AND NOT EXISTS (
                SELECT 1 FROM certificates c
                WHERE c.user_id = student.id AND c.subject_id = s.id
            )
            SQL, [(int) $user_id]);

        foreach ($eligible_subjects as $subject) {
            $code = 'SN-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(5)));
            $created = $this->execute(
                'INSERT IGNORE INTO certificates
                    (user_id, subject_id, student_name, subject_name, teacher_name, certificate_code)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    (int) $user_id,
                    (int) $subject['subject_id'],
                    $subject['student_name'],
                    $subject['subject_name'],
                    $subject['teacher_name'],
                    $code,
                ]
            );
            if ($created) {
                $certificate = $this->one(
                    'SELECT id, subject_name, teacher_name FROM certificates WHERE user_id = ? AND subject_id = ?',
                    [(int) $user_id, (int) $subject['subject_id']]
                );
                if ($certificate) {
                    $this->create_user_notification(
                        (int) $user_id,
                        'certificate:' . (int) $certificate['id'],
                        'certificate_earned',
                        'Your certificate is ready!',
                        'Congratulations! You completed ' . $certificate['subject_name']
                            . ' with ' . $certificate['teacher_name'] . '. Your downloadable certificate is now available in your profile.',
                        '#/certificates',
                        'certificate'
                    );
                }
            }
        }
    }

    public function notifications()
    {
        $user_id = $this->user_id();
        $this->schedule_notification_email_delivery();
        $this->respond($this->all(
            'SELECT id, type, title, message, link, read_at, created_at
             FROM studynest_notifications
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 100',
            [$user_id]
        ));
    }

    public function notification_read($id)
    {
        $user_id = $this->user_id();
        $this->verify_csrf();
        $updated = $this->execute(
            'UPDATE studynest_notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?',
            [(int) $id, $user_id]
        );
        if (!$updated && !$this->one(
            'SELECT id FROM studynest_notifications WHERE id = ? AND user_id = ? AND read_at IS NOT NULL',
            [(int) $id, $user_id]
        )) {
            $this->fail('Notification not found.', 404);
        }
        $this->respond(['ok' => true]);
    }

    public function notifications_read_all()
    {
        $user_id = $this->user_id();
        $this->verify_csrf();
        $updated = $this->execute(
            'UPDATE studynest_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL',
            [$user_id]
        );
        $this->respond(['ok' => true, 'updated' => $updated]);
    }

    public function notification_preferences()
    {
        $user_id = $this->user_id();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $preferences = $this->one(
                'SELECT email_certificates, email_content_updates
                 FROM studynest_notification_preferences WHERE user_id = ?',
                [$user_id]
            );
            $this->respond([
                'email_certificates' => $preferences ? (bool) $preferences['email_certificates'] : true,
                'email_content_updates' => $preferences ? (bool) $preferences['email_content_updates'] : true,
            ]);
        }

        $this->verify_csrf();
        $data = $this->body();
        foreach (['email_certificates', 'email_content_updates'] as $key) {
            if (!array_key_exists($key, $data) || !is_bool($data[$key])) {
                $this->fail('Choose whether to receive each type of email notification.');
            }
        }
        $this->execute(
            'INSERT INTO studynest_notification_preferences
                (user_id, email_certificates, email_content_updates)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                email_certificates = VALUES(email_certificates),
                email_content_updates = VALUES(email_content_updates)',
            [$user_id, (int) $data['email_certificates'], (int) $data['email_content_updates']]
        );
        $this->respond([
            'email_certificates' => $data['email_certificates'],
            'email_content_updates' => $data['email_content_updates'],
        ]);
    }

    private function certificate_pdf_string($value)
    {
        $source = (string) $value;
        $value = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $source);
        if ($value === false) {
            $value = preg_replace('/[^\x20-\x7E]/', '?', $source);
        }
        return str_replace(["\\", '(', ')', "\r", "\n"], ["\\\\", '\\(', '\\)', ' ', ' '], $value);
    }

    private function certificate_pdf_text($text, $font, $size, $y, $color = '0.16 0.25 0.20', $x = null, $max_width = 760)
    {
        $text = $this->certificate_pdf_string($text);
        while ($size > 8 && strlen($text) * $size * 0.52 > $max_width) {
            $size--;
        }
        $width = strlen($text) * $size * 0.52;
        $x = $x === null ? max(40, (842 - $width) / 2) : $x;
        return sprintf(
            "BT /%s %d Tf %s rg 1 0 0 1 %.2f %d Tm (%s) Tj ET\n",
            $font,
            $size,
            $color,
            $x,
            $y,
            $text
        );
    }

    private function render_certificate_pdf(array $certificate)
    {
        $content = "q\n";
        $content .= "0.99 0.99 0.97 rg 0 0 842 595 re f\n";
        $content .= "0.12 0.25 0.20 rg 0 0 842 12 re f\n";
        $content .= "0.18 0.34 0.27 RG 2 w 24 24 794 547 re S\n";
        $content .= "0.78 0.66 0.40 RG 0.8 w 34 34 774 527 re S\n";
        $content .= "0.91 0.93 0.87 rg 44 447 754 94 re f\n";
        $content .= "0.78 0.66 0.40 RG 1 w 44 447 m 798 447 l S\n";
        $content .= "0.78 0.66 0.40 rg 72 476 4 4 re f\n";
        $content .= "0.78 0.66 0.40 rg 766 476 4 4 re f\n";
        $content .= "0.18 0.34 0.27 RG 1.2 w 300 372 m 542 372 l S\n";
        $content .= "0.78 0.66 0.40 RG 1 w 226 250 m 616 250 l S\n";
        $content .= "Q\n";
        $content .= $this->certificate_pdf_text('STUDYNEST', 'F3', 14, 508, '0.18 0.34 0.27');
        $content .= $this->certificate_pdf_text('LEARNING  /  ACHIEVEMENT', 'F1', 9, 489, '0.42 0.48 0.43');
        $content .= $this->certificate_pdf_text('CERTIFICATE OF COMPLETION', 'F3', 28, 406, '0.13 0.25 0.19');
        $content .= $this->certificate_pdf_text('This certificate is awarded to', 'F2', 15, 345, '0.34 0.39 0.35');
        $content .= $this->certificate_pdf_text($certificate['student_name'], 'F3', 26, 298, '0.18 0.34 0.27');
        $content .= $this->certificate_pdf_text('for successfully completing', 'F2', 14, 269, '0.34 0.39 0.35');
        $content .= $this->certificate_pdf_text($certificate['subject_name'], 'F3', 22, 225, '0.13 0.25 0.19');
        $content .= $this->certificate_pdf_text('Conducted through the StudyNest learning platform.', 'F2', 12, 193, '0.34 0.39 0.35');
        $content .= $this->certificate_pdf_text('In recognition of this achievement and the learning credit earned.', 'F2', 12, 174, '0.34 0.39 0.35');
        $content .= $this->certificate_pdf_text($certificate['teacher_name'], 'F3', 12, 132, '0.18 0.34 0.27', 150, 430);
        $content .= $this->certificate_pdf_text('COURSE INSTRUCTOR', 'F1', 8, 116, '0.42 0.46 0.42', 150, 430);
        $content .= $this->certificate_pdf_text('ISSUED  ' . strtoupper(date('F j, Y', strtotime($certificate['issued_at']))), 'F1', 9, 132, '0.42 0.46 0.42', 485, 210);
        $content .= $this->certificate_pdf_text('Certificate ID  ' . $certificate['certificate_code'], 'F1', 8, 58, '0.42 0.46 0.42');

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R >> >> /Contents 7 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Italic /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref_offset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref_offset . "\n%%EOF";
        return $pdf;
    }

    public function certificates()
    {
        $user_id = $this->user_id();
        $user = $this->current_user();
        if (!$user || $user['role'] !== 'user') {
            $this->respond([]);
        }
        $this->issue_completed_subject_certificates($user_id);
        foreach ($this->all(
            'SELECT id, subject_name, teacher_name
             FROM certificates WHERE user_id = ? ORDER BY id',
            [$user_id]
        ) as $certificate) {
            $this->create_user_notification(
                $user_id,
                'certificate:' . (int) $certificate['id'],
                'certificate_earned',
                'Your certificate is ready!',
                'Congratulations! You completed ' . $certificate['subject_name']
                    . ' with ' . $certificate['teacher_name'] . '. Your downloadable certificate is now available in your profile.',
                '#/certificates'
            );
        }
        $this->respond($this->all(
            'SELECT id, subject_name, teacher_name, certificate_code, issued_at
             FROM certificates WHERE user_id = ? ORDER BY issued_at DESC, id DESC',
            [$user_id]
        ));
    }

    public function download_certificate($id)
    {
        $user_id = $this->user_id();
        $certificate = $this->one(
            'SELECT student_name, subject_name, teacher_name, certificate_code, issued_at
             FROM certificates WHERE id = ? AND user_id = ?',
            [(int) $id, $user_id]
        );
        if (!$certificate) {
            $this->fail('Certificate not found.', 404);
        }
        $pdf = $this->render_certificate_pdf($certificate);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: attachment; filename="studynest-certificate-' . $certificate['certificate_code'] . '.pdf"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }

    private function assert_teacher_subject_owner($owner_id)
    {
        $user = $this->current_user();
        if ($user && $user['role'] === 'teacher' && (int) ($owner_id ?? 0) !== (int) $user['id']) {
            $this->fail('Content not found.', 404);
        }
    }

    public function bootstrap()
    {
        $user = $this->current_user();
        $teacher_scope = $user && $user['role'] === 'teacher';
        $catalog = $this->one(<<<'SQL'
            SELECT
                (
                    SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(
                        'id', t.id,
                        'subject_id', t.subject_id,
                        'title', t.title,
                        'summary', t.summary
                    )), JSON_ARRAY())
                    FROM topics t
                    JOIN subjects s ON s.id = t.subject_id
                    WHERE (? = 0 OR s.owner_id = ?)
                ) AS topics,
                (
                    SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(
                        'id', s.id,
                        'name', s.name,
                        'description', s.description,
                        'certificate_enabled', COALESCE(certificate_settings.enabled, 0),
                        'required_completion_percent', COALESCE(certificate_settings.required_completion_percent, 100),
                        'minimum_quiz_score', COALESCE(certificate_settings.minimum_quiz_score, 70),
                        'lessons', COALESCE(lc.lessons, 0),
                        'quizzes', COALESCE(qc.quizzes, 0),
                        'progress', CASE WHEN COALESCE(lc.lessons, 0) = 0 THEN 0
                            ELSE ROUND(100 * COALESCE(lc.reviewed, 0) / lc.lessons) END
                    )), JSON_ARRAY())
                    FROM subjects s
                    LEFT JOIN subject_certificate_settings certificate_settings ON certificate_settings.subject_id = s.id
                    LEFT JOIN (
                        SELECT t.subject_id, COUNT(l.id) AS lessons, COUNT(lr.lesson_id) AS reviewed
                        FROM topics t
                        LEFT JOIN lessons l ON l.topic_id = t.id
                        LEFT JOIN lesson_reviews lr ON lr.lesson_id = l.id AND lr.user_id = ?
                        GROUP BY t.subject_id
                    ) lc ON lc.subject_id = s.id
                    LEFT JOIN (
                        SELECT t.subject_id, COUNT(q.id) AS quizzes
                        FROM topics t
                        LEFT JOIN quizzes q ON q.topic_id = t.id
                        GROUP BY t.subject_id
                    ) qc ON qc.subject_id = s.id
                    WHERE (? = 0 OR s.owner_id = ?)
                ) AS subjects,
                (
                    SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(
                        'id', l.id,
                        'topic_id', l.topic_id,
                        'subject', s.name,
                        'topic', t.title,
                        'title', l.title,
                        'content', l.content,
                        'pdf_original_name', l.pdf_original_name
                    )), JSON_ARRAY())
                    FROM lessons l
                    JOIN topics t ON t.id = l.topic_id
                    JOIN subjects s ON s.id = t.subject_id
                    WHERE (? = 0 OR s.owner_id = ?)
                ) AS lessons,
                (
                    SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(
                        'id', q.id,
                        'topic_id', q.topic_id,
                        'title', q.title,
                        'subject', s.name,
                        'topic', t.title,
                        'time', q.duration_minutes,
                        'questions', COALESCE(qc.questions, 0)
                    )), JSON_ARRAY())
                    FROM quizzes q
                    JOIN topics t ON t.id = q.topic_id
                    JOIN subjects s ON s.id = t.subject_id
                    LEFT JOIN (
                        SELECT quiz_id, COUNT(*) AS questions
                        FROM quiz_questions
                        GROUP BY quiz_id
                    ) qc ON qc.quiz_id = q.id
                    WHERE (? = 0 OR s.owner_id = ?)
                ) AS quizzes
            SQL, [
                $teacher_scope ? 1 : 0,
                $user ? (int) $user['id'] : 0,
                $user ? (int) $user['id'] : 0,
                $teacher_scope ? 1 : 0,
                $user ? (int) $user['id'] : 0,
                $teacher_scope ? 1 : 0,
                $user ? (int) $user['id'] : 0,
                $teacher_scope ? 1 : 0,
                $user ? (int) $user['id'] : 0,
            ]);
        $needs_learning_data = $user && !in_array($user['role'], ['admin', 'moderator', 'teacher'], true);
        $user_data = null;
        if ($needs_learning_data) {
            $user_id = (int) $user['id'];
            $user_data = $this->one(<<<'SQL'
                SELECT
                    (
                        SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(
                            'id', ss.id,
                            'title', ss.title,
                            'description', ss.description,
                            'created_at', ss.created_at,
                            'subject', s.name,
                            'topic', COALESCE(ss.custom_topic, t.title),
                            'topic_id', ss.topic_id,
                            'custom_topic', ss.custom_topic,
                            'pdf_original_name', ss.pdf_original_name,
                            'cards', (
                                SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(
                                    'id', f.id,
                                    'term', f.term_question,
                                    'answer', f.answer_definition
                                )), JSON_ARRAY())
                                FROM flashcards f
                                WHERE f.study_set_id = ss.id
                            )
                        )), JSON_ARRAY())
                        FROM study_sets ss
                        LEFT JOIN subjects s ON s.id = ss.subject_id
                        LEFT JOIN topics t ON t.id = ss.topic_id
                        WHERE ss.user_id = ?
                    ) AS sets,
                    (
                        SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(
                            'id', id,
                            'title', title,
                            'date', schedule_date,
                            'time', schedule_time,
                            'note', reminder_note
                        )), JSON_ARRAY())
                        FROM study_schedules
                        WHERE user_id = ?
                    ) AS schedules,
                    (
                        SELECT COALESCE(JSON_ARRAYAGG(lesson_id), JSON_ARRAY())
                        FROM favorite_lessons
                        WHERE user_id = ?
                    ) AS favorites,
                    (
                        SELECT COALESCE(JSON_ARRAYAGG(subject_id), JSON_ARRAY())
                        FROM favorite_subjects
                        WHERE user_id = ?
                    ) AS favorite_subjects,
                    (
                        SELECT COALESCE(JSON_ARRAYAGG(lesson_id), JSON_ARRAY())
                        FROM lesson_reviews
                        WHERE user_id = ?
                    ) AS reviewed_lessons,
                    (
                        SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(
                            'type', recent.activity_type,
                            'title', recent.title,
                            'details', recent.details,
                            'created_at', recent.created_at
                        )), JSON_ARRAY())
                        FROM (
                            SELECT activity_type, title, details, created_at
                            FROM user_activities
                            WHERE user_id = ?
                            ORDER BY created_at DESC
                            LIMIT 30
                        ) recent
                    ) AS activities,
                    (
                        SELECT COALESCE(SUM(duration_seconds), 0)
                        FROM user_activities
                        WHERE user_id = ?
                    ) AS study_time_seconds,
                    (
                        SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(
                            'study_date', daily.study_date,
                            'duration_seconds', daily.duration_seconds
                        )), JSON_ARRAY())
                        FROM (
                            SELECT DATE(created_at) AS study_date, SUM(duration_seconds) AS duration_seconds
                            FROM user_activities
                            WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                            GROUP BY DATE(created_at)
                        ) daily
                    ) AS weekly_study,
                    (
                        SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(
                            'id', weak.id,
                            'title', weak.title,
                            'subject', weak.subject,
                            'average_score', weak.average_score,
                            'attempts', weak.attempts
                        )), JSON_ARRAY())
                        FROM (
                            SELECT t.id, t.title, s.name AS subject,
                                ROUND(AVG(qa.percentage)) AS average_score, COUNT(qa.id) AS attempts
                            FROM quiz_attempts qa
                            JOIN quizzes q ON q.id = qa.quiz_id
                            JOIN topics t ON t.id = q.topic_id
                            JOIN subjects s ON s.id = t.subject_id
                            WHERE qa.user_id = ?
                            GROUP BY t.id, t.title, s.name
                            HAVING AVG(qa.percentage) < 70
                            ORDER BY average_score ASC
                            LIMIT 5
                        ) weak
                    ) AS weak_topics,
                    (
                        SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(
                            'title', attempts.title,
                            'subject', attempts.subject,
                            'score', attempts.percentage,
                            'date', attempts.completed_at
                        )), JSON_ARRAY())
                        FROM (
                            SELECT q.title, s.name AS subject, qa.percentage, qa.completed_at
                            FROM quiz_attempts qa
                            JOIN quizzes q ON q.id = qa.quiz_id
                            JOIN topics t ON t.id = q.topic_id
                            JOIN subjects s ON s.id = t.subject_id
                            WHERE qa.user_id = ?
                            UNION ALL
                            SELECT pq.title, ps.name AS subject, pqa.percentage, pqa.completed_at
                            FROM private_quiz_attempts pqa
                            JOIN private_quizzes pq ON pq.id = pqa.quiz_id
                            JOIN private_topics pt ON pt.id = pq.topic_id
                            JOIN private_subjects ps ON ps.id = pt.subject_id
                            WHERE pqa.user_id = ?
                        ) attempts
                    ) AS attempts
                SQL, array_fill(0, 11, $user_id));
        }
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
            'favoriteSubjects' => [],
            'attempts' => [],
            'reviewedLessons' => [],
            'activities' => [],
            'studyTimeSeconds' => 0,
            'weeklyStudy' => [],
            'weakTopics' => [],
            'topics' => json_decode($catalog['topics'], true, 512, JSON_THROW_ON_ERROR),
            'subjects' => json_decode($catalog['subjects'], true, 512, JSON_THROW_ON_ERROR),
            'lessons' => json_decode($catalog['lessons'], true, 512, JSON_THROW_ON_ERROR),
            'quizzes' => json_decode($catalog['quizzes'], true, 512, JSON_THROW_ON_ERROR),
        ];
        usort($payload['topics'], static function ($a, $b) {
            return ((int) $a['subject_id'] <=> (int) $b['subject_id'])
                ?: strcasecmp($a['title'], $b['title']);
        });
        foreach (['subjects', 'lessons', 'quizzes'] as $collection) {
            usort($payload[$collection], static function ($a, $b) {
                return (int) $a['id'] <=> (int) $b['id'];
            });
        }
        foreach ($payload['subjects'] as &$subject) {
            $subject['lessons'] = (int) $subject['lessons'];
            $subject['quizzes'] = (int) $subject['quizzes'];
            $subject['certificate_enabled'] = (bool) $subject['certificate_enabled'];
            $subject['required_completion_percent'] = (int) $subject['required_completion_percent'];
            $subject['minimum_quiz_score'] = (int) $subject['minimum_quiz_score'];
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
        if ($needs_learning_data) {
            foreach (['sets', 'schedules', 'favorites', 'reviewed_lessons', 'activities', 'weekly_study', 'weak_topics', 'attempts'] as $key) {
                $payload_key = [
                    'reviewed_lessons' => 'reviewedLessons',
                    'weekly_study' => 'weeklyStudy',
                    'weak_topics' => 'weakTopics',
                ][$key] ?? $key;
                $payload[$payload_key] = json_decode($user_data[$key], true, 512, JSON_THROW_ON_ERROR);
            }
            $payload['favorites'] = array_map('intval', $payload['favorites']);
            $payload['favoriteSubjects'] = array_map(
                'intval',
                json_decode($user_data['favorite_subjects'], true, 512, JSON_THROW_ON_ERROR)
            );
            $payload['reviewedLessons'] = array_map('intval', $payload['reviewedLessons']);
            $payload['studyTimeSeconds'] = (int) $user_data['study_time_seconds'];
            usort($payload['sets'], static function ($a, $b) {
                return strcmp($b['created_at'], $a['created_at']);
            });
            foreach ($payload['sets'] as &$set) {
                usort($set['cards'], static function ($a, $b) {
                    return (int) $a['id'] <=> (int) $b['id'];
                });
            }
            unset($set);
            usort($payload['schedules'], static function ($a, $b) {
                return [$a['date'], $a['time']] <=> [$b['date'], $b['time']];
            });
            usort($payload['activities'], static function ($a, $b) {
                return strcmp($b['created_at'], $a['created_at']);
            });
            usort($payload['weeklyStudy'], static function ($a, $b) {
                return strcmp($a['study_date'], $b['study_date']);
            });
            usort($payload['weakTopics'], static function ($a, $b) {
                return (float) $a['average_score'] <=> (float) $b['average_score'];
            });
            usort($payload['attempts'], static function ($a, $b) {
                return strcmp($b['date'], $a['date']);
            });
        }
        if ($user && in_array($user['role'], ['admin', 'moderator', 'teacher'], true)) {
            $subject_filter = $teacher_scope ? ' WHERE s.owner_id = ?' : '';
            $scope_values = $teacher_scope ? [(int) $user['id']] : [];
            $management_quizzes = $this->all(
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
            foreach ($management_quizzes as &$quiz) {
                $quiz['id'] = (int) $quiz['id'];
                $quiz['topic_id'] = (int) $quiz['topic_id'];
                $quiz['time'] = (int) $quiz['time'];
                $quiz['questions'] = $questions_by_quiz[$quiz['id']] ?? [];
            }
            unset($quiz);
            $subject_names = [];
            foreach ($payload['subjects'] as $subject) {
                $subject_names[$subject['id']] = $subject['name'];
            }
            $management_topics = $payload['topics'];
            foreach ($management_topics as &$topic) {
                $topic['subject'] = $subject_names[$topic['subject_id']] ?? '';
            }
            unset($topic);
            $payload['management'] = [
                'subjects' => $payload['subjects'],
                'topics' => $management_topics,
                'lessons' => $payload['lessons'],
                'quizzes' => $management_quizzes,
            ];
        }
        if ($user && $user['role'] === 'admin') {
            $payload['teachers'] = $this->all(
                "SELECT id, full_name AS name, email, teacher_id, created_at
                 FROM users
                 WHERE is_active = 1
                   AND (teacher_status = 'approved' OR (role = 'teacher' AND teacher_status IS NULL))
                 ORDER BY full_name, id"
            );
            $payload['teacherApplications'] = $this->all(
                "SELECT id, full_name AS name, email, teacher_id_photo, created_at
                 FROM users
                 WHERE teacher_status = 'pending' AND email_verified_at IS NOT NULL AND is_active = 1
                 ORDER BY created_at, id"
            );
            $payload['students'] = $this->all(
                "SELECT id, full_name AS name, email, created_at, is_active, email_verified_at
                 FROM users
                 WHERE role = 'user' AND (teacher_status IS NULL OR teacher_status = 'rejected')
                 ORDER BY created_at DESC, id DESC"
            );
        }
        $this->respond($payload);
    }

    public function lesson_pdf($id)
    {
        $user_id = $this->user_id();
        $lesson = $this->one(
            'SELECT l.id, l.title, l.pdf_storage_name, l.pdf_original_name, l.pdf_data,
                    s.owner_id, s.name AS subject
             FROM lessons l JOIN topics t ON t.id = l.topic_id
             JOIN subjects s ON s.id = t.subject_id WHERE l.id = ?',
            [(int) $id]
        );
        if (!$lesson || (!$lesson['pdf_storage_name'] && ($lesson['pdf_data'] === null || $lesson['pdf_data'] === ''))) {
            $this->fail('This lesson does not have an attached PDF.', 404);
        }
        $this->assert_teacher_subject_owner($lesson['owner_id']);
        $pdf = null;
        $path = null;
        if ($lesson['pdf_data'] !== null && $lesson['pdf_data'] !== '') {
            $pdf = $lesson['pdf_data'];
            if (substr($pdf, 0, 5) !== '%PDF-') {
                $this->fail('The lesson PDF is unavailable.', 404);
            }
        } else {
            $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'lesson-pdfs';
            $path = $directory . DIRECTORY_SEPARATOR . basename($lesson['pdf_storage_name']);
            if (!is_file($path) || (new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf') {
                $this->fail('The lesson PDF is unavailable. Upload it again to restore access.', 404);
            }
        }

        if (!isset($_GET['download'])) {
            $viewed_pdfs = $this->session->userdata('studynest_viewed_pdfs') ?: [];
            $now = time();
            foreach ($viewed_pdfs as $viewed_id => $viewed_at) {
                if ($now - (int) $viewed_at > 600) {
                    unset($viewed_pdfs[$viewed_id]);
                }
            }
            $viewed_pdfs[(int) $lesson['id']] = $now;
            $this->session->set_userdata('studynest_viewed_pdfs', $viewed_pdfs);
        }

        $filename = str_replace(["\r", "\n", '"'], '', $lesson['pdf_original_name'] ?: 'lesson.pdf');
        $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
        header('Content-Type: application/pdf');
        header('Content-Length: ' . ($pdf !== null ? strlen($pdf) : filesize($path)));
        header('Content-Disposition: ' . $disposition . '; filename="lesson.pdf"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if ($pdf !== null) {
            echo $pdf;
        } else {
            readfile($path);
        }
        exit;
    }

    public function lesson_pdf_text($id)
    {
        $this->user_id();
        $lesson = $this->one(
            'SELECT l.id, l.title, l.pdf_storage_name, l.pdf_original_name, l.pdf_data, s.owner_id
             FROM lessons l JOIN topics t ON t.id = l.topic_id
             JOIN subjects s ON s.id = t.subject_id WHERE l.id = ?',
            [(int) $id]
        );
        if (!$lesson || (!$lesson['pdf_storage_name'] && ($lesson['pdf_data'] === null || $lesson['pdf_data'] === ''))) {
            $this->fail('This lesson does not have an attached PDF.', 404);
        }
        $this->assert_teacher_subject_owner($lesson['owner_id']);

        if ($lesson['pdf_data'] !== null && $lesson['pdf_data'] !== '') {
            $pdf = $lesson['pdf_data'];
        } else {
            $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'lesson-pdfs';
            $path = $directory . DIRECTORY_SEPARATOR . basename($lesson['pdf_storage_name']);
            if (!is_file($path) || (new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf') {
                $this->fail('The lesson PDF is unavailable. Upload it again to restore access.', 404);
            }
            $size = filesize($path);
            if ($size === false || $size > 20 * 1024 * 1024) {
                $this->fail('This PDF is too large to read aloud.', 413);
            }
            $pdf = file_get_contents($path);
            if ($pdf === false) {
                error_log('StudyNest could not read lesson PDF text for lesson ' . (int) $lesson['id'] . '.');
                $this->fail('Could not read the attached PDF. Please try again later.', 503);
            }
        }

        if (substr($pdf, 0, 5) !== '%PDF-') {
            $this->fail('The lesson PDF is unavailable.', 404);
        }
        if (strlen($pdf) > 20 * 1024 * 1024) {
            $this->fail('This PDF is too large to read aloud.', 413);
        }

        try {
            $document = (new \Smalot\PdfParser\Parser())->parseContent($pdf);
            $text = trim($document->getText());
        } catch (\Exception $error) {
            error_log(sprintf(
                'StudyNest could not extract text from lesson PDF %d: %s',
                (int) $lesson['id'],
                $error->getMessage()
            ));
            $this->fail('Could not extract readable text from this PDF. It may be scanned, image-only, or password-protected.', 422);
        }

        if ($text === '') {
            $this->fail('This PDF has no selectable text to read aloud. Scanned or image-only PDFs are not supported.', 422);
        }

        $truncated = mb_strlen($text, 'UTF-8') > 100000;
        if ($truncated) {
            $text = mb_substr($text, 0, 100000, 'UTF-8');
        }
        $this->respond([
            'title' => $lesson['title'],
            'text' => $text,
            'truncated' => $truncated,
        ]);
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
            'SELECT ss.id, ss.user_id, ss.title, ss.description, ss.created_at,
                    ss.topic_id, ss.custom_topic, ss.pdf_original_name,
                    s.name AS subject, COALESCE(ss.custom_topic, t.title) AS topic
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

    private function save_study_set_pdf($field_name = 'study_pdf')
    {
        $file = $_FILES[$field_name] ?? null;
        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->fail('The PDF could not be uploaded. Try again with a smaller file.');
        }
        $actual_size = is_uploaded_file($file['tmp_name']) ? filesize($file['tmp_name']) : false;
        if ($actual_size === false || $actual_size < 1 || $actual_size > 20 * 1024 * 1024) {
            $this->fail('The PDF must be smaller than 20 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $signature = file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($mime !== 'application/pdf' || $signature !== '%PDF-') {
            $this->fail('Upload a valid PDF document.');
        }
        $original_name = trim((string) ($file['name'] ?? 'study-material.pdf'));
        $original_name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $original_name)));
        if ($original_name === '' || strlen($original_name) > 180) {
            $this->fail('The PDF filename must be no longer than 180 characters.');
        }
        $contents = file_get_contents($file['tmp_name']);
        if ($contents === false || strlen($contents) !== $actual_size) {
            throw new RuntimeException('The uploaded PDF could not be read for secure storage.');
        }
        return ['original_name' => $original_name, 'contents' => $contents];
    }

    private function save_set($user_id, array $data, $set_id = null)
    {
        $is_update = (bool) $set_id;
        $title = $this->require_text($data['title'] ?? '', 'Study set title', 120);
        $description = trim((string) ($data['description'] ?? ''));
        $subject_name = trim((string) ($data['subject'] ?? ''));
        $topic_name = trim((string) ($data['topic'] ?? ''));
        $topic_id = trim((string) ($data['topic_id'] ?? ''));
        $custom_topic = $topic_id === 'custom'
            ? $this->require_text($data['custom_topic'] ?? '', 'My topic', 180)
            : null;
        $cards = $data['cards'] ?? null;
        if (is_string($cards)) {
            $cards = json_decode($cards, true);
        }
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
        if ($topic_id !== '' && $topic_id !== 'custom') {
            if (!ctype_digit($topic_id)) {
                $this->fail('Choose a valid topic.');
            }
            $topic = $this->one('SELECT id, subject_id FROM topics WHERE id = ?', [(int) $topic_id]);
            if (!$topic) {
                $this->fail('Choose a valid topic.');
            }
            if ($subject && (int) $subject['id'] !== (int) $topic['subject_id']) {
                $this->fail('Choose a topic from the selected subject.');
            }
            $subject = $this->one('SELECT id FROM subjects WHERE id = ?', [(int) $topic['subject_id']]);
        } elseif ($topic_id === '' && $topic_name !== '' && $subject) {
            $topic = $this->one('SELECT id FROM topics WHERE title = ? AND subject_id = ?', [$topic_name, $subject['id']]);
            if (!$topic) {
                $this->execute('INSERT INTO topics (subject_id, title, summary) VALUES (?, ?, ?)', [$subject['id'], $topic_name, '']);
                $topic = $this->one('SELECT id FROM topics WHERE title = ? AND subject_id = ?', [$topic_name, $subject['id']]);
            }
        }

        $existing_set = null;
        if ($set_id) {
            $existing_set = $this->one(
                'SELECT id, pdf_original_name FROM study_sets WHERE id = ? AND user_id = ?',
                [$set_id, $user_id]
            );
            if (!$existing_set) {
                $this->fail('Study set not found.', 404);
            }
        }
        $pdf = $this->save_study_set_pdf();
        $remove_pdf = ($data['remove_study_pdf'] ?? '') === '1';
        if ($custom_topic && !$pdf && !$remove_pdf && empty($existing_set['pdf_original_name'])) {
            $this->fail('Choose a PDF for your custom topic.');
        }
        if ($remove_pdf && !$pdf && $custom_topic) {
            $this->fail('A custom topic needs a PDF. Choose another PDF or keep the current one.');
        }

        $db = $this->db();
        $db->raw('START TRANSACTION');
        try {
            if ($set_id) {
                if ($pdf) {
                    $this->execute(
                        'UPDATE study_sets SET subject_id = ?, topic_id = ?, custom_topic = ?, pdf_original_name = ?, pdf_data = ?, title = ?, description = ? WHERE id = ? AND user_id = ?',
                        [$subject['id'] ?? null, $topic['id'] ?? null, $custom_topic, $pdf['original_name'], $pdf['contents'], $title, $description, $set_id, $user_id]
                    );
                } elseif ($remove_pdf) {
                    $this->execute(
                        'UPDATE study_sets SET subject_id = ?, topic_id = ?, custom_topic = ?, pdf_original_name = NULL, pdf_data = NULL, title = ?, description = ? WHERE id = ? AND user_id = ?',
                        [$subject['id'] ?? null, $topic['id'] ?? null, $custom_topic, $title, $description, $set_id, $user_id]
                    );
                } else {
                    $this->execute(
                        'UPDATE study_sets SET subject_id = ?, topic_id = ?, custom_topic = ?, title = ?, description = ? WHERE id = ? AND user_id = ?',
                        [$subject['id'] ?? null, $topic['id'] ?? null, $custom_topic, $title, $description, $set_id, $user_id]
                    );
                }
                $this->execute('DELETE FROM flashcards WHERE study_set_id = ?', [$set_id]);
            } else {
                $set_id = $this->insert(
                    'INSERT INTO study_sets (user_id, subject_id, topic_id, custom_topic, pdf_original_name, pdf_data, title, description)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$user_id, $subject['id'] ?? null, $topic['id'] ?? null, $custom_topic, $pdf['original_name'] ?? null, $pdf['contents'] ?? null, $title, $description]
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
        $data = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false ? $_POST : $this->body();
        $set = $this->save_set($user_id, $data);
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
            $data = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false ? $_POST : $this->body();
            $set = $this->save_set($user_id, $data, $id);
            $this->respond($set);
        }
        if (!$this->one('SELECT id FROM study_sets WHERE id = ? AND user_id = ?', [$id, $user_id])) {
            $this->fail('Study set not found.', 404);
        }
        $this->execute('DELETE FROM study_sets WHERE id = ? AND user_id = ?', [$id, $user_id]);
        $this->respond(['ok' => true]);
    }

    public function study_set_pdf($id)
    {
        $user_id = $this->user_id();
        $set = $this->one(
            'SELECT pdf_original_name, pdf_data FROM study_sets WHERE id = ? AND user_id = ?',
            [(int) $id, $user_id]
        );
        if (!$set || !$set['pdf_data']) {
            $this->fail('Study set PDF not found.', 404);
        }
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $set['pdf_original_name'] ?: 'study-material.pdf');
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($set['pdf_data']));
        header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $set['pdf_data'];
        exit;
    }

    public function review_lesson($lesson_id)
    {
        $user_id = $this->user_id();
        $this->verify_csrf();
        $lesson = $this->one(
            'SELECT l.id, l.title, l.pdf_storage_name, l.pdf_data,
                    s.owner_id, s.name AS subject
             FROM lessons l JOIN topics t ON t.id = l.topic_id
             JOIN subjects s ON s.id = t.subject_id WHERE l.id = ?',
            [(int) $lesson_id]
        );
        if (!$lesson) {
            $this->fail('Lesson not found.', 404);
        }
        $this->assert_teacher_subject_owner($lesson['owner_id']);
        if ($lesson['pdf_storage_name'] || ($lesson['pdf_data'] !== null && $lesson['pdf_data'] !== '')) {
            $this->fail('View the attached PDF to mark this lesson as read.', 409);
        }
        $duration = min(7200, max(0, (int) ($this->body()['duration_seconds'] ?? 0)));
        $this->record_lesson_review($user_id, $lesson, $duration);
        $this->respond(['ok' => true]);
    }

    public function lesson_pdf_viewed($lesson_id)
    {
        $user_id = $this->user_id();
        $this->verify_csrf();
        $lesson_id = (int) $lesson_id;
        $viewed_pdfs = $this->session->userdata('studynest_viewed_pdfs') ?: [];
        $viewed_at = (int) ($viewed_pdfs[$lesson_id] ?? 0);
        if (!$viewed_at || time() - $viewed_at > 600) {
            $this->fail('Open the attached PDF before marking this lesson as read.', 409);
        }

        $lesson = $this->one(
            'SELECT l.id, l.title, s.owner_id, s.name AS subject
             FROM lessons l JOIN topics t ON t.id = l.topic_id
             JOIN subjects s ON s.id = t.subject_id WHERE l.id = ?',
            [$lesson_id]
        );
        if (!$lesson) {
            $this->fail('Lesson not found.', 404);
        }
        $this->assert_teacher_subject_owner($lesson['owner_id']);
        unset($viewed_pdfs[$lesson_id]);
        $this->session->set_userdata('studynest_viewed_pdfs', $viewed_pdfs);
        $this->record_lesson_review($user_id, $lesson);
        $this->respond(['ok' => true]);
    }

    private function record_lesson_review($user_id, array $lesson, $duration = 0)
    {
        $inserted = $this->execute(
            'INSERT IGNORE INTO lesson_reviews (user_id, lesson_id, reviewed_at) VALUES (?, ?, NOW())',
            [(int) $user_id, (int) $lesson['id']]
        );
        if ($inserted > 0) {
            $this->activity(
                (int) $user_id,
                'lesson_reviewed',
                $lesson['title'],
                $lesson['subject'],
                min(7200, max(0, (int) $duration))
            );
        }
        $this->issue_completed_subject_certificates((int) $user_id);
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

    public function subject_favorites()
    {
        $user_id = $this->user_id();
        $this->verify_csrf();
        $data = $this->body();
        $subject_id = (int) ($data['subject_id'] ?? 0);
        $subject = $this->one('SELECT id, name FROM subjects WHERE id = ?', [$subject_id]);
        if (!$subject) {
            $this->fail('Subject not found.', 404);
        }
        $exists = $this->one(
            'SELECT id FROM favorite_subjects WHERE user_id = ? AND subject_id = ?',
            [$user_id, $subject_id]
        );
        if ($exists) {
            $this->execute(
                'DELETE FROM favorite_subjects WHERE user_id = ? AND subject_id = ?',
                [$user_id, $subject_id]
            );
            $this->activity($user_id, 'favorite_removed', $subject['name'], 'Subject');
            $this->respond(['saved' => false]);
        }
        $this->execute(
            'INSERT INTO favorite_subjects (user_id, subject_id) VALUES (?, ?)',
            [$user_id, $subject_id]
        );
        $this->activity($user_id, 'favorite_added', $subject['name'], 'Subject');
        $this->respond(['saved' => true], 201);
    }

    private function private_subject_owned($subject_id, $user_id)
    {
        return $this->one('SELECT id FROM private_subjects WHERE id = ? AND user_id = ?', [(int) $subject_id, $user_id]);
    }

    private function private_topic_owned($topic_id, $user_id)
    {
        return $this->one(
            'SELECT t.id, t.subject_id FROM private_topics t
             JOIN private_subjects s ON s.id = t.subject_id
             WHERE t.id = ? AND s.user_id = ?',
            [(int) $topic_id, $user_id]
        );
    }

    private function private_quiz_owned($quiz_id, $user_id)
    {
        return $this->one(
            'SELECT q.id, q.title, q.duration_minutes, t.title AS topic, s.name AS subject
             FROM private_quizzes q
             JOIN private_topics t ON t.id = q.topic_id
             JOIN private_subjects s ON s.id = t.subject_id
             WHERE q.id = ? AND s.user_id = ?',
            [(int) $quiz_id, $user_id]
        );
    }

    public function private_library()
    {
        $user_id = $this->user_id();
        $subjects = $this->all(
            'SELECT id, name, description FROM private_subjects WHERE user_id = ? ORDER BY created_at DESC, id DESC',
            [$user_id]
        );
        foreach ($subjects as &$subject) {
            $subject['topics'] = $this->all(
                'SELECT id, title FROM private_topics WHERE subject_id = ? ORDER BY id',
                [(int) $subject['id']]
            );
            foreach ($subject['topics'] as &$topic) {
                $topic['lessons'] = $this->all(
                    'SELECT id, title, content, pdf_original_name FROM private_lessons WHERE topic_id = ? ORDER BY id DESC',
                    [(int) $topic['id']]
                );
                $topic['quizzes'] = $this->all(
                    'SELECT q.id, q.title, q.duration_minutes AS time, COUNT(qq.id) AS questions
                     FROM private_quizzes q LEFT JOIN private_quiz_questions qq ON qq.quiz_id = q.id
                     WHERE q.topic_id = ? GROUP BY q.id ORDER BY q.id DESC',
                    [(int) $topic['id']]
                );
            }
            unset($topic);
        }
        unset($subject);
        $this->respond($subjects);
    }

    public function private_library_resource($kind, $id = null)
    {
        $user_id = $this->user_id();
        if (!in_array($kind, ['subjects', 'topics', 'lessons', 'quizzes'], true)) {
            $this->fail('Private library content type not found.', 404);
        }
        $method = $_SERVER['REQUEST_METHOD'];
        $record_id = (int) ($id ?? 0);
        if ($method !== 'POST' && !$record_id) {
            $this->fail('Private library item not found.', 404);
        }

        if ($kind === 'subjects') {
            $table = 'private_subjects';
            $owned = $record_id ? $this->private_subject_owned($record_id, $user_id) : true;
        } elseif ($kind === 'topics') {
            $table = 'private_topics';
            $owned = $record_id ? $this->private_topic_owned($record_id, $user_id) : true;
        } elseif ($kind === 'lessons') {
            $table = 'private_lessons';
            $owned = $record_id ? $this->one(
                'SELECT l.id FROM private_lessons l JOIN private_topics t ON t.id = l.topic_id
                 JOIN private_subjects s ON s.id = t.subject_id WHERE l.id = ? AND s.user_id = ?',
                [$record_id, $user_id]
            ) : true;
        } else {
            $table = 'private_quizzes';
            $owned = $record_id ? $this->one(
                'SELECT q.id FROM private_quizzes q JOIN private_topics t ON t.id = q.topic_id
                 JOIN private_subjects s ON s.id = t.subject_id WHERE q.id = ? AND s.user_id = ?',
                [$record_id, $user_id]
            ) : true;
        }
        if (!$owned) {
            $this->fail('Private library item not found.', 404);
        }
        if ($method === 'DELETE') {
            $this->verify_csrf();
            $this->execute("DELETE FROM {$table} WHERE id = ?", [$record_id]);
            $this->respond(['ok' => true]);
        }
        if ($method !== 'POST' && $method !== 'PUT') {
            $this->fail('Unsupported private library operation.', 405);
        }
        $this->verify_csrf();
        $multipart = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
        $data = $multipart ? $_POST : $this->body();
        $is_update = $method === 'PUT';

        if ($kind === 'subjects') {
            $name = trim((string) ($data['name'] ?? ''));
            $description = trim((string) ($data['description'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) {
                $this->fail('Enter a subject name up to 150 characters.');
            }
            if ($is_update) {
                $this->execute('UPDATE private_subjects SET name = ?, description = ? WHERE id = ? AND user_id = ?', [$name, $description, $record_id, $user_id]);
            } else {
                $record_id = $this->insert('INSERT INTO private_subjects (user_id, name, description) VALUES (?, ?, ?)', [$user_id, $name, $description]);
            }
        } elseif ($kind === 'topics') {
            $subject_id = (int) ($data['subject_id'] ?? 0);
            $title = trim((string) ($data['title'] ?? ''));
            if (!$this->private_subject_owned($subject_id, $user_id)) {
                $this->fail('Choose one of your private subjects.');
            }
            if ($title === '' || mb_strlen($title) > 180) {
                $this->fail('Enter a topic name up to 180 characters.');
            }
            if ($is_update) {
                $this->execute('UPDATE private_topics SET subject_id = ?, title = ? WHERE id = ?', [$subject_id, $title, $record_id]);
            } else {
                $record_id = $this->insert('INSERT INTO private_topics (subject_id, title) VALUES (?, ?)', [$subject_id, $title]);
            }
        } elseif ($kind === 'lessons') {
            $topic_id = (int) ($data['topic_id'] ?? 0);
            $title = trim((string) ($data['title'] ?? ''));
            $content = trim((string) ($data['content'] ?? ''));
            if (!$this->private_topic_owned($topic_id, $user_id)) {
                $this->fail('Choose one of your private topics.');
            }
            if ($title === '' || mb_strlen($title) > 180 || $content === '') {
                $this->fail('Enter a lesson title and notes.');
            }
            $pdf = $this->save_study_set_pdf('private_pdf');
            if ($is_update) {
                if ($pdf) {
                    $this->execute('UPDATE private_lessons SET topic_id = ?, title = ?, content = ?, pdf_original_name = ?, pdf_data = ? WHERE id = ?', [$topic_id, $title, $content, $pdf['original_name'], $pdf['contents'], $record_id]);
                } else {
                    $this->execute('UPDATE private_lessons SET topic_id = ?, title = ?, content = ? WHERE id = ?', [$topic_id, $title, $content, $record_id]);
                }
            } else {
                $record_id = $this->insert(
                    'INSERT INTO private_lessons (topic_id, title, content, pdf_original_name, pdf_data) VALUES (?, ?, ?, ?, ?)',
                    [$topic_id, $title, $content, $pdf['original_name'] ?? null, $pdf['contents'] ?? null]
                );
            }
        } else {
            $topic_id = (int) ($data['topic_id'] ?? 0);
            $title = trim((string) ($data['title'] ?? ''));
            $duration = max(1, min(180, (int) ($data['duration_minutes'] ?? 15)));
            $questions = $data['questions'] ?? [];
            if (!$this->private_topic_owned($topic_id, $user_id)) {
                $this->fail('Choose one of your private topics.');
            }
            if ($title === '' || mb_strlen($title) > 180 || !is_array($questions) || count($questions) < 1 || count($questions) > 100) {
                $this->fail('Add a quiz title and between 1 and 100 questions.');
            }
            foreach ($questions as $question) {
                $type = $question['type'] ?? '';
                $correct = $type === 'identification'
                    ? trim((string) ($question['correct_option_text'] ?? $question['correct_option'] ?? ''))
                    : trim((string) ($question['correct_option'] ?? ''));
                if (trim((string) ($question['question_text'] ?? '')) === '' || !in_array($type, ['multiple_choice', 'true_false', 'identification'], true) || $correct === '') {
                    $this->fail('Complete each quiz question and its correct answer.');
                }
                $options = [
                    'A' => trim((string) ($question['option_a'] ?? '')),
                    'B' => trim((string) ($question['option_b'] ?? '')),
                    'C' => trim((string) ($question['option_c'] ?? '')),
                    'D' => trim((string) ($question['option_d'] ?? '')),
                ];
                if ($type === 'multiple_choice' && (
                    $options['A'] === '' || $options['B'] === ''
                    || !in_array($correct, ['A', 'B', 'C', 'D'], true)
                    || $options[$correct] === ''
                )) {
                    $this->fail('Multiple-choice questions need at least two options and a correct answer.');
                }
                if ($type === 'true_false' && !in_array($correct, ['True', 'False'], true)) {
                    $this->fail('Choose True or False as the correct answer.');
                }
            }
            $db = $this->db();
            $db->raw('START TRANSACTION');
            try {
                if ($is_update) {
                    $this->execute('UPDATE private_quizzes SET topic_id = ?, title = ?, duration_minutes = ? WHERE id = ?', [$topic_id, $title, $duration, $record_id]);
                    $this->execute('DELETE FROM private_quiz_questions WHERE quiz_id = ?', [$record_id]);
                } else {
                    $record_id = $this->insert('INSERT INTO private_quizzes (topic_id, title, duration_minutes) VALUES (?, ?, ?)', [$topic_id, $title, $duration]);
                }
                foreach ($questions as $question) {
                    $type = $question['type'];
                    $correct = $type === 'identification'
                        ? trim((string) ($question['correct_option_text'] ?? $question['correct_option']))
                        : trim((string) $question['correct_option']);
                    $this->execute(
                        'INSERT INTO private_quiz_questions (quiz_id, question_text, type, option_a, option_b, option_c, option_d, correct_option, explanation)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $record_id,
                            trim((string) $question['question_text']),
                            $type,
                            $type === 'identification' ? null : ($question['option_a'] ?? null),
                            $type === 'identification' ? null : ($question['option_b'] ?? null),
                            $type === 'multiple_choice' ? ($question['option_c'] ?? null) : null,
                            $type === 'multiple_choice' ? ($question['option_d'] ?? null) : null,
                            $correct,
                            trim((string) ($question['explanation'] ?? '')),
                        ]
                    );
                }
                $db->raw('COMMIT');
            } catch (Throwable $error) {
                $db->raw('ROLLBACK');
                throw $error;
            }
        }
        $this->respond(['ok' => true, 'id' => $record_id], $is_update ? 200 : 201);
    }

    public function private_lesson_pdf($id)
    {
        $user_id = $this->user_id();
        $lesson = $this->one(
            'SELECT l.pdf_original_name, l.pdf_data FROM private_lessons l
             JOIN private_topics t ON t.id = l.topic_id
             JOIN private_subjects s ON s.id = t.subject_id
             WHERE l.id = ? AND s.user_id = ?',
            [(int) $id, $user_id]
        );
        if (!$lesson || !$lesson['pdf_data']) {
            $this->fail('Private lesson PDF not found.', 404);
        }
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $lesson['pdf_original_name'] ?: 'lesson.pdf');
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($lesson['pdf_data']));
        header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        echo $lesson['pdf_data'];
        exit;
    }

    public function private_quiz($id)
    {
        $quiz = $this->private_quiz_owned((int) $id, $this->user_id());
        if (!$quiz) {
            $this->fail('Private quiz not found.', 404);
        }
        $quiz['time'] = (int) $quiz['duration_minutes'];
        unset($quiz['duration_minutes']);
        $quiz['questions'] = $this->all(
            'SELECT id, question_text AS question, type, option_a, option_b, option_c, option_d
             FROM private_quiz_questions WHERE quiz_id = ? ORDER BY id',
            [(int) $id]
        );
        foreach ($quiz['questions'] as &$question) {
            $question['options'] = $question['type'] === 'true_false'
                ? ['True', 'False']
                : array_values(array_filter(
                    [$question['option_a'], $question['option_b'], $question['option_c'], $question['option_d']],
                    fn($option) => $option !== null && $option !== ''
                ));
            unset($question['option_a'], $question['option_b'], $question['option_c'], $question['option_d']);
        }
        unset($question);
        $this->respond($quiz);
    }

    public function submit_private_quiz($id)
    {
        $user_id = $this->user_id();
        $this->verify_csrf();
        $data = $this->body();
        $quiz_id = (int) $id;
        $quiz = $this->private_quiz_owned($quiz_id, $user_id);
        if (!$quiz) {
            $this->fail('Private quiz not found.', 404);
        }
        $duration_seconds = $this->finish_quiz_timer($data['timer_token'] ?? '', 'private', $quiz_id, $user_id);
        $questions = $this->all(
            'SELECT id, question_text AS question, type, option_a, option_b, option_c, option_d, correct_option, explanation
             FROM private_quiz_questions WHERE quiz_id = ? ORDER BY id',
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
            $options = $question['type'] === 'true_false'
                ? ['True', 'False']
                : array_values(array_filter(
                    [$question['option_a'], $question['option_b'], $question['option_c'], $question['option_d']],
                    fn($option) => $option !== null && $option !== ''
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
        $total = count($questions);
        $percentage = round(100 * $score / $total, 2);
        $time_taken = $duration_seconds . 's';
        $this->execute(
            'INSERT INTO private_quiz_attempts (user_id, quiz_id, score, total_questions, percentage, time_taken) VALUES (?, ?, ?, ?, ?, ?)',
            [$user_id, $quiz_id, $score, $total, $percentage, substr($time_taken, 0, 20)]
        );
        $this->respond([
            'score' => $score,
            'total' => $total,
            'percentage' => $percentage,
            'review' => $review,
        ]);
    }

    public function start_private_quiz($id)
    {
        $user_id = $this->user_id();
        $this->verify_csrf();
        $quiz_id = (int) $id;
        $quiz = $this->private_quiz_owned($quiz_id, $user_id);
        if (!$quiz) {
            $this->fail('Private quiz not found.', 404);
        }
        $question_count = (int) $this->one(
            'SELECT COUNT(*) AS question_count FROM private_quiz_questions WHERE quiz_id = ?',
            [$quiz_id]
        )['question_count'];
        if ($question_count === 0) {
            $this->fail('This quiz does not have any questions yet.', 422);
        }
        $this->create_quiz_timer($quiz_id, $user_id, (int) $quiz['duration_minutes'], 'private');
    }

    public function quiz($id)
    {
        $this->user_id();
        $quiz = $this->one(
            'SELECT q.id, q.title, s.name AS subject, t.title AS topic, q.duration_minutes AS time, s.owner_id
             FROM quizzes q JOIN topics t ON t.id = q.topic_id JOIN subjects s ON s.id = t.subject_id WHERE q.id = ?',
            [(int) $id]
        );
        if (!$quiz) {
            $this->fail('Quiz not found.', 404);
        }
        $this->assert_teacher_subject_owner($quiz['owner_id']);
        unset($quiz['owner_id']);
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
        $quiz = $this->one(
            'SELECT q.id, s.owner_id FROM quizzes q JOIN topics t ON t.id = q.topic_id
             JOIN subjects s ON s.id = t.subject_id WHERE q.id = ?',
            [$quiz_id]
        );
        if (!$quiz) {
            $this->fail('Quiz not found.', 404);
        }
        $this->assert_teacher_subject_owner($quiz['owner_id']);
        $duration_seconds = $this->finish_quiz_timer($data['timer_token'] ?? '', 'public', $quiz_id, $user_id);
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
            [$user_id, $quiz_id, $score, count($questions), $percentage, $duration_seconds . 's']
        );
        $this->issue_completed_subject_certificates($user_id);
        $quiz = $this->one(
            'SELECT q.title, s.name AS subject FROM quizzes q JOIN topics t ON t.id = q.topic_id
             JOIN subjects s ON s.id = t.subject_id WHERE q.id = ?',
            [$quiz_id]
        );
        $this->activity(
            $user_id,
            'quiz_completed',
            $quiz['title'],
            $quiz['subject'] . ' · ' . $percentage . '%',
            $duration_seconds
        );
        $this->respond([
            'score' => $score,
            'total_questions' => count($questions),
            'percentage' => $percentage,
            'review' => $review,
        ], 201);
    }

    public function start_quiz($id)
    {
        $user_id = $this->user_id();
        $this->verify_csrf();
        $quiz_id = (int) $id;
        $quiz = $this->one(
            'SELECT q.id, q.duration_minutes, s.owner_id
             FROM quizzes q JOIN topics t ON t.id = q.topic_id
             JOIN subjects s ON s.id = t.subject_id WHERE q.id = ?',
            [$quiz_id]
        );
        if (!$quiz) {
            $this->fail('Quiz not found.', 404);
        }
        $this->assert_teacher_subject_owner($quiz['owner_id']);
        $question_count = (int) $this->one(
            'SELECT COUNT(*) AS question_count FROM quiz_questions WHERE quiz_id = ?',
            [$quiz_id]
        )['question_count'];
        if ($question_count === 0) {
            $this->fail('This quiz does not have any questions yet.', 422);
        }
        $this->create_quiz_timer($quiz_id, $user_id, (int) $quiz['duration_minutes'], 'public');
    }

    private function create_quiz_timer($quiz_id, $user_id, $duration_minutes, $kind)
    {
        $duration_seconds = min(36000, max(60, (int) $duration_minutes * 60));
        $started_at = microtime(true);
        $token = bin2hex(random_bytes(24));
        $timers = $this->session->userdata('studynest_quiz_timers');
        $timers = is_array($timers) ? $timers : [];
        foreach ($timers as $existing_token => $timer) {
            if (!is_array($timer) || ($started_at - (float) ($timer['started_at'] ?? 0)) > 36000) {
                unset($timers[$existing_token]);
            }
        }
        while (count($timers) >= 10) {
            array_shift($timers);
        }
        $timers[$token] = [
            'user_id' => (int) $user_id,
            'quiz_id' => (int) $quiz_id,
            'kind' => $kind,
            'started_at' => $started_at,
            'duration_seconds' => $duration_seconds,
        ];
        $this->session->set_userdata('studynest_quiz_timers', $timers);
        $this->respond([
            'timer_token' => $token,
            'duration_seconds' => $duration_seconds,
            'server_time_ms' => (int) round($started_at * 1000),
            'expires_at_ms' => (int) round(($started_at + $duration_seconds) * 1000),
        ]);
    }

    private function finish_quiz_timer($token, $kind, $quiz_id, $user_id)
    {
        $token = trim((string) $token);
        $timers = $this->session->userdata('studynest_quiz_timers');
        if ($token === '' || !is_array($timers) || !isset($timers[$token])) {
            $this->fail('This quiz timer has expired. Please start the quiz again.', 409);
        }
        $timer = $timers[$token];
        if (
            !is_array($timer)
            || (int) ($timer['user_id'] ?? 0) !== (int) $user_id
            || (int) ($timer['quiz_id'] ?? 0) !== (int) $quiz_id
            || ($timer['kind'] ?? '') !== $kind
        ) {
            $this->fail('This quiz timer does not match the quiz. Please start again.', 409);
        }
        unset($timers[$token]);
        $this->session->set_userdata('studynest_quiz_timers', $timers);
        $elapsed = max(0, (int) floor(microtime(true) - (float) $timer['started_at']));
        return min((int) $timer['duration_seconds'], $elapsed);
    }
}
