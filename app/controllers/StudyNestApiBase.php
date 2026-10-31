<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StudyNestApiBase extends Controller
{
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

    protected function send_email_otp($user_id, $email)
    {
        $code = (string) random_int(100000, 999999);
        $this->execute(
            'UPDATE users SET email_otp_hash = ?, email_otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
             email_otp_sent_at = NOW(), email_otp_attempts = 0,
             email_otp_resend_count = CASE WHEN email_otp_resend_reset_at IS NULL OR email_otp_resend_reset_at < DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE email_otp_resend_count + 1 END,
             email_otp_resend_reset_at = CASE WHEN email_otp_resend_reset_at IS NULL OR email_otp_resend_reset_at < DATE_SUB(NOW(), INTERVAL 1 DAY) THEN NOW() ELSE email_otp_resend_reset_at END
             WHERE id = ?',
            [password_hash($code, PASSWORD_DEFAULT), $user_id]
        );
        $this->call->library('studynest_mailer')->send_otp($email, $code);
    }

    protected function guard_email_otp_resend(array $user)
    {
        if ($user['email_otp_sent_at'] && strtotime($user['email_otp_sent_at']) > time() - 60) {
            $this->fail('Please wait one minute before requesting another code.', 429);
        }
        if (!$user['email_otp_resend_reset_at'] || strtotime($user['email_otp_resend_reset_at']) < time() - 86400) {
            $this->execute(
                'UPDATE users SET email_otp_resend_count = 0, email_otp_resend_reset_at = NOW() WHERE id = ?',
                [(int) $user['id']]
            );
            $user['email_otp_resend_count'] = 0;
        }
        if ((int) $user['email_otp_resend_count'] >= 5) {
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
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'teacher-ids';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private teacher ID storage could not be created.');
        }
        $filename = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
            throw new RuntimeException('The teacher ID photo could not be stored securely.');
        }
        return $filename;
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
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'lesson-pdfs';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private lesson PDF storage could not be created.');
        }
        $storage_name = bin2hex(random_bytes(24)) . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $storage_name)) {
            throw new RuntimeException('The lesson PDF could not be stored securely.');
        }
        return ['storage_name' => $storage_name, 'original_name' => $original_name];
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
             CASE WHEN teacher_status = 'pending' THEN 'teacher_pending' ELSE role END AS role,
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
