<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StudyNestApiBase extends Controller
{
    private $notification_email_delivery_scheduled = false;

    public function before_action()
    {
        $this->security_headers->apply_api();
        $this->session = $this->call->library('session');
        $this->ensure_csrf_token();
    }

    protected function db()
    {
        return $this->call->database();
    }

    protected function ensure_csrf_token()
    {
        if (empty($this->session->userdata('studynest_csrf'))) {
            $this->session->set_userdata('studynest_csrf', bin2hex(random_bytes(32)));
        }
    }

    protected function respond($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function fail($message, $status = 400)
    {
        $this->respond(['error' => $message], $status);
    }

    protected function body()
    {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $this->fail('A valid JSON request body is required.');
        }
        return $data;
    }

    protected function verify_csrf()
    {
        $this->ensure_csrf_token();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$token || !hash_equals($this->session->userdata('studynest_csrf'), $token)) {
            $this->fail('Your session expired. Refresh the page and try again.', 403);
        }
    }

    protected function auth_rate_limit($bucket, $identity, $limit, $window, $operation = 'check')
    {
        $identity = strtolower(trim((string) $identity));
        if ($identity === '') {
            return;
        }
        if (!in_array($operation, ['check', 'record', 'consume', 'reset'], true)) {
            throw new InvalidArgumentException('Unknown authentication rate limit operation.');
        }

        $limit = max(1, (int) $limit);
        $window = max(1, (int) $window);
        $file = config_item('auth_rate_limit_file') ?: ROOT_DIR . 'runtime/session/auth_rate_limits.json';
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            error_log('StudyNest authentication rate limit storage directory could not be created.');
            $this->fail('Sign-in is temporarily unavailable. Please try again later.', 503);
        }

        $handle = @fopen($file, 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) {
            if ($handle) {
                fclose($handle);
            }
            error_log('StudyNest authentication rate limit storage could not be locked.');
            $this->fail('Sign-in is temporarily unavailable. Please try again later.', 503);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $entries = $contents === '' ? [] : json_decode($contents, true);
        if (!is_array($entries)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            error_log('StudyNest authentication rate limit storage contains invalid data.');
            $this->fail('Sign-in is temporarily unavailable. Please try again later.', 503);
        }

        $now = time();
        foreach ($entries as $key => $timestamps) {
            if (!is_array($timestamps)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                error_log('StudyNest authentication rate limit storage contains an invalid entry.');
                $this->fail('Sign-in is temporarily unavailable. Please try again later.', 503);
            }
            $entries[$key] = array_values(array_filter(
                $timestamps,
                static fn($timestamp) => is_numeric($timestamp) && (int) $timestamp > $now - 86400
            ));
            if (!$entries[$key]) {
                unset($entries[$key]);
            }
        }

        $key = hash_hmac('sha256', $bucket . ':' . $identity, (string) config_item('session_hmac_secret'));
        $attempts = array_values(array_filter(
            $entries[$key] ?? [],
            static fn($timestamp) => (int) $timestamp > $now - $window
        ));
        if ($operation !== 'reset' && count($attempts) >= $limit) {
            $retry_after = max(1, (int) $attempts[0] + $window - $now);
            flock($handle, LOCK_UN);
            fclose($handle);
            header('Retry-After: ' . $retry_after);
            $message = $bucket === 'login'
                ? 'Too many failed sign-in attempts. Please wait before trying again.'
                : 'Too many requests. Please wait before trying again.';
            $this->fail($message, 429);
        }
        unset($entries[$key]);
        $entries[$key] = $attempts;
        if ($operation === 'record' || $operation === 'consume') {
            $entries[$key][] = $now;
        } elseif ($operation === 'reset') {
            unset($entries[$key]);
        }
        if (count($entries) > 5000) {
            $entries = array_slice($entries, -5000, null, true);
        }

        $encoded = json_encode($entries);
        $write_failed = !is_string($encoded) || !ftruncate($handle, 0);
        if (!$write_failed && !rewind($handle)) {
            $write_failed = true;
        }
        $offset = 0;
        while (!$write_failed && $offset < strlen($encoded)) {
            $written = fwrite($handle, substr($encoded, $offset));
            if ($written === false || $written === 0) {
                $write_failed = true;
                break;
            }
            $offset += $written;
        }
        if ($write_failed || !fflush($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            error_log('StudyNest authentication rate limit storage could not be updated.');
            $this->fail('Sign-in is temporarily unavailable. Please try again later.', 503);
        }
        if (!chmod($file, 0600) && DIRECTORY_SEPARATOR !== '\\') {
            flock($handle, LOCK_UN);
            fclose($handle);
            error_log('StudyNest authentication rate limit storage permissions could not be secured.');
            $this->fail('Sign-in is temporarily unavailable. Please try again later.', 503);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    protected function user_id()
    {
        $this->ensure_csrf_token();
        $id = (int) ($this->session->userdata('studynest_user_id') ?? 0);
        if (!$id) {
            $this->fail('Please sign in to continue.', 401);
        }
        $user = $this->current_user();
        if (!$user) {
            $this->fail('Please sign in to continue.', 401);
        }
        if ($user && $user['role'] === 'teacher_pending') {
            $this->fail('Your teacher application is awaiting administrator approval.', 403);
        }
        return $id;
    }

    protected function one($sql, array $values = [])
    {
        $row = $this->db()->raw($sql, $values)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    protected function all($sql, array $values = [])
    {
        return $this->db()->raw($sql, $values)->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function execute($sql, array $values = [])
    {
        return $this->db()->raw($sql, $values)->rowCount();
    }

    protected function insert($sql, array $values = [])
    {
        $db = $this->db();
        $db->raw($sql, $values);
        $id = (int) $db->last_id();
        if ($id < 1) {
            $row = $db->raw('SELECT LAST_INSERT_ID() AS id')->fetch(PDO::FETCH_ASSOC);
            $id = (int) ($row['id'] ?? 0);
        }
        if ($id < 1) {
            throw new RuntimeException('The database did not return the new record ID.');
        }
        return $id;
    }

    protected function create_user_notification($user_id, $event_key, $type, $title, $message, $link, $email_category = null)
    {
        $created = $this->execute(
            'INSERT IGNORE INTO studynest_notifications
                (user_id, event_key, type, title, message, link)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $user_id,
                substr((string) $event_key, 0, 190),
                substr((string) $type, 0, 40),
                substr((string) $title, 0, 180),
                substr((string) $message, 0, 500),
                $link === null ? null : substr((string) $link, 0, 255),
            ]
        );
        if (!$created || !$email_category) {
            return (bool) $created;
        }

        $preference_column = [
            'certificate' => 'email_certificates',
            'content_update' => 'email_content_updates',
        ][$email_category] ?? null;
        if (!$preference_column) {
            throw new InvalidArgumentException('Unknown StudyNest notification email category.');
        }
        $preference = $this->one(
            'SELECT ' . $preference_column . ' AS enabled FROM studynest_notification_preferences WHERE user_id = ?',
            [(int) $user_id]
        );
        if ($preference && (int) $preference['enabled'] !== 1) {
            return true;
        }
        $notification = $this->one(
            'SELECT id FROM studynest_notifications WHERE user_id = ? AND event_key = ?',
            [(int) $user_id, substr((string) $event_key, 0, 190)]
        );
        if (!$notification) {
            throw new RuntimeException('The StudyNest notification could not be reloaded after creation.');
        }
        $this->execute(
            'INSERT IGNORE INTO studynest_notification_email_outbox (notification_id, category) VALUES (?, ?)',
            [(int) $notification['id'], $email_category]
        );
        $this->schedule_notification_email_delivery();
        return true;
    }

    protected function schedule_notification_email_delivery()
    {
        if ($this->notification_email_delivery_scheduled) {
            return;
        }
        $this->notification_email_delivery_scheduled = true;
        register_shutdown_function(function () {
            ignore_user_abort(true);
            set_time_limit(35);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                while (ob_get_level() > 0) {
                    if (!ob_end_flush()) {
                        break;
                    }
                }
                flush();
            }
            $this->process_notification_email_outbox();
        });
    }

    protected function process_notification_email_outbox($limit = 10)
    {
        $jobs = $this->all(
            "SELECT o.id, o.notification_id, o.category, o.status, o.attempts,
                    n.title, n.message, n.link, u.email,
                    COALESCE(p.email_certificates, 1) AS email_certificates,
                    COALESCE(p.email_content_updates, 1) AS email_content_updates
             FROM studynest_notification_email_outbox o
             JOIN studynest_notifications n ON n.id = o.notification_id
             JOIN users u ON u.id = n.user_id
             LEFT JOIN studynest_notification_preferences p ON p.user_id = n.user_id
             WHERE (o.status = 'pending' AND o.attempts < 5 AND o.available_at <= NOW())
                OR (o.status = 'sending' AND o.updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
             ORDER BY o.id LIMIT " . max(1, min(10, (int) $limit))
        );
        foreach ($jobs as $job) {
            if ($job['status'] === 'sending' && (int) $job['attempts'] >= 5) {
                $this->execute(
                    "UPDATE studynest_notification_email_outbox
                     SET status = 'failed', last_error = 'Delivery was interrupted at the retry limit.', updated_at = NOW()
                     WHERE id = ? AND status = 'sending' AND attempts >= 5",
                    [(int) $job['id']]
                );
                continue;
            }
            if ($job['status'] === 'pending') {
                $claimed = $this->execute(
                    "UPDATE studynest_notification_email_outbox
                     SET status = 'sending', attempts = attempts + 1, updated_at = NOW()
                     WHERE id = ? AND status = 'pending' AND available_at <= NOW()",
                    [(int) $job['id']]
                );
            } else {
                $claimed = $this->execute(
                    "UPDATE studynest_notification_email_outbox
                     SET attempts = attempts + 1, updated_at = NOW()
                     WHERE id = ? AND status = 'sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
                    [(int) $job['id']]
                );
            }
            if (!$claimed) {
                continue;
            }

            $preference_enabled = $job['category'] === 'certificate'
                ? (int) $job['email_certificates'] === 1
                : ($job['category'] === 'content_update' && (int) $job['email_content_updates'] === 1);
            if (!$preference_enabled) {
                $this->execute(
                    "UPDATE studynest_notification_email_outbox
                     SET status = 'failed', last_error = 'Email notifications are disabled by the user.', updated_at = NOW()
                     WHERE id = ? AND status = 'sending'",
                    [(int) $job['id']]
                );
                continue;
            }

            try {
                $subject = $job['category'] === 'certificate'
                    ? 'Your StudyNest certificate is ready'
                    : 'A new learning update is available on StudyNest';
                $this->call->library('studynest_mailer')->send_notification(
                    $job['email'],
                    $subject,
                    $job['title'],
                    $job['message'],
                    $job['link'] ?: '#/notifications'
                );
                $this->execute(
                    "UPDATE studynest_notification_email_outbox
                     SET status = 'sent', sent_at = NOW(), last_error = NULL, updated_at = NOW()
                     WHERE id = ? AND status = 'sending'",
                    [(int) $job['id']]
                );
            } catch (Throwable $error) {
                $attempts = (int) $job['attempts'] + 1;
                $status = $attempts >= 5 ? 'failed' : 'pending';
                $delay_minutes = min(60, 2 ** $attempts);
                $available_at = date('Y-m-d H:i:s', time() + ($delay_minutes * 60));
                $this->execute(
                    'UPDATE studynest_notification_email_outbox
                     SET status = ?, available_at = ?, last_error = ?, updated_at = NOW()
                     WHERE id = ? AND status = \'sending\'',
                    [$status, $available_at, substr($error->getMessage(), 0, 500), (int) $job['id']]
                );
                error_log(sprintf(
                    'StudyNest notification email job %d failed on attempt %d: %s',
                    (int) $job['id'],
                    $attempts,
                    $error->getMessage()
                ));
            }
        }
    }

    protected function send_email_otp($user_id, $email)
    {
        $code = (string) random_int(100000, 999999);
        $this->execute(
            'UPDATE users SET email_otp_hash = ?, email_otp_expires_at = NULL,
             email_otp_sent_at = NULL, email_otp_attempts = 0 WHERE id = ?',
            [password_hash($code, PASSWORD_DEFAULT), $user_id]
        );
        try {
            $this->call->library('studynest_mailer')->send_otp($email, $code);
        } catch (RuntimeException $error) {
            $this->execute(
                'UPDATE users SET email_otp_hash = NULL, email_otp_expires_at = NULL,
                 email_otp_sent_at = NULL, email_otp_attempts = 0 WHERE id = ?',
                [$user_id]
            );
            throw $error;
        }
        $this->execute(
            'UPDATE users SET email_otp_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
             email_otp_sent_at = NOW(), email_otp_attempts = 0,
             email_otp_resend_count = CASE WHEN email_otp_resend_reset_at IS NULL OR email_otp_resend_reset_at <= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE email_otp_resend_count + 1 END,
             email_otp_resend_reset_at = CASE WHEN email_otp_resend_reset_at IS NULL OR email_otp_resend_reset_at <= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN NOW() ELSE email_otp_resend_reset_at END
             WHERE id = ?',
            [$user_id]
        );
    }

    protected function guard_email_otp_resend(array $user)
    {
        $limits = $this->one(
            'SELECT
                CASE WHEN email_otp_sent_at IS NOT NULL AND email_otp_sent_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) THEN 1 ELSE 0 END AS cooldown_active,
                CASE WHEN email_otp_resend_reset_at IS NOT NULL AND email_otp_resend_reset_at > DATE_SUB(NOW(), INTERVAL 1 DAY) THEN email_otp_resend_count ELSE 0 END AS daily_count
             FROM users WHERE id = ?',
            [(int) $user['id']]
        );
        if (!$limits) {
            throw new RuntimeException('The account email verification limits could not be checked.');
        }
        if ((int) $limits['cooldown_active'] === 1) {
            $this->fail('Please wait one minute before requesting another code.', 429);
        }
        if ((int) $limits['daily_count'] >= 5) {
            $this->fail('The daily verification-code limit has been reached. Try again tomorrow.', 429);
        }
    }

    protected function save_teacher_id_photo()
    {
        $file = $_FILES['teacher_id_photo'] ?? null;
        if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $this->fail('A clear photo of your teacher ID is required.');
        }
        $actual_size = is_uploaded_file($file['tmp_name']) ? filesize($file['tmp_name']) : false;
        if ($actual_size === false || $actual_size < 1 || $actual_size > 5 * 1024 * 1024) {
            $this->fail('The teacher ID photo must be smaller than 5 MB.');
        }
        $image = @getimagesize($file['tmp_name']);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$image || !isset($extensions[$mime]) || $image['mime'] !== $mime) {
            $this->fail('Upload a valid JPEG, PNG, or WebP teacher ID photo.');
        }
        if ($image[0] > 8000 || $image[1] > 8000 || $image[0] * $image[1] > 32000000) {
            $this->fail('The teacher ID photo dimensions are too large.');
        }
        $contents = file_get_contents($file['tmp_name']);
        if ($contents === false || strlen($contents) !== $actual_size) {
            throw new RuntimeException('The teacher ID photo could not be read for secure storage.');
        }
        return [
            'filename' => bin2hex(random_bytes(24)) . '.' . $extensions[$mime],
            'contents' => $contents,
        ];
    }

    protected function save_lesson_pdf()
    {
        $file = $_FILES['pdf_attachment'] ?? null;
        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->fail('The PDF could not be uploaded. Try again with a smaller file.');
        }
        $actual_size = is_uploaded_file($file['tmp_name']) ? filesize($file['tmp_name']) : false;
        if ($actual_size === false || $actual_size < 1 || $actual_size > 20 * 1024 * 1024) {
            $this->fail('The lesson PDF must be smaller than 20 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $signature = file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($mime !== 'application/pdf' || $signature !== '%PDF-') {
            $this->fail('Upload a valid PDF document.');
        }
        $original_name = trim((string) ($file['name'] ?? 'lesson.pdf'));
        $original_name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $original_name)));
        if ($original_name === '' || strlen($original_name) > 180) {
            $this->fail('The PDF filename must be no longer than 180 characters.');
        }
        $contents = file_get_contents($file['tmp_name']);
        if ($contents === false || strlen($contents) !== $actual_size) {
            throw new RuntimeException('The lesson PDF could not be read for secure storage.');
        }
        return ['contents' => $contents, 'original_name' => $original_name];
    }

    protected function delete_private_lesson_pdf($storage_name)
    {
        if (!$storage_name || basename($storage_name) !== $storage_name) {
            return;
        }
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'lesson-pdfs' . DIRECTORY_SEPARATOR . $storage_name;
        if (is_file($path) && !unlink($path)) {
            error_log('StudyNest: could not delete replaced private lesson PDF.');
        }
    }

    protected function csrf_token()
    {
        $this->ensure_csrf_token();
        return $this->session->userdata('studynest_csrf');
    }

    protected function require_text($value, $label, $max)
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $max) {
            $this->fail($label . ' is required and must be no longer than ' . $max . ' characters.');
        }
        return $value;
    }

    protected function current_user()
    {
        $id = (int) ($this->session->userdata('studynest_user_id') ?? 0);
        if (!$id) {
            return null;
        }
        $user = $this->one(
            "SELECT id, full_name, email, is_active,
             CASE
                 WHEN teacher_status = 'pending' THEN 'teacher_pending'
                 WHEN teacher_status = 'approved' THEN 'teacher'
                 ELSE role
             END AS role,
             teacher_id, teacher_status
             FROM users WHERE id = ?",
            [$id]
        );
        if ($user && (int) $user['is_active'] !== 1) {
            $this->session->unset_userdata('studynest_user_id');
            return null;
        }
        if ($user) {
            unset($user['is_active']);
        }
        return $user;
    }

    protected function require_admin()
    {
        $user = $this->current_user();
        if (!$user) {
            $this->fail('Please sign in to continue.', 401);
        }
        if (!in_array($user['role'], ['admin', 'moderator', 'teacher'], true)) {
            $this->fail('Only an administrator or teacher can manage learning content.', 403);
        }
        return (int) $user['id'];
    }

    protected function require_super_admin()
    {
        $user = $this->current_user();
        if (!$user) {
            $this->fail('Please sign in to continue.', 401);
        }
        if ($user['role'] !== 'admin') {
            $this->fail('Only an administrator can manage teacher accounts.', 403);
        }
        return (int) $user['id'];
    }

    protected function activity($user_id, $type, $title, $details = '', $duration = 0)
    {
        $this->execute(
            'INSERT INTO user_activities (user_id, activity_type, title, details, duration_seconds) VALUES (?, ?, ?, ?, ?)',
            [$user_id, $type, $title, $details, max(0, (int) $duration)]
        );
    }

    protected function catalog_text($data, $key, $label, $max)
    {
        return $this->require_text($data[$key] ?? '', $label, $max);
    }
}
