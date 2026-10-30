<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/StudyNestApiBase.php';

class StudyNestAuthApi extends StudyNestApiBase
{
    public function health()
    {
        $this->respond(['status' => 'ok', 'app' => 'StudyNest']);
    }

    public function register()
    {
        $this->verify_csrf();
        $data = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false ? $_POST : $this->body();
        $name = $this->require_text($data['name'] ?? $data['full_name'] ?? '', 'Full name', 100);
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirmation = (string) ($data['password_confirmation'] ?? '');
        $requested_role = $data['role'] ?? 'student';
        if (!in_array($requested_role, ['student', 'teacher'], true)) {
            $this->fail('Choose either a student or teacher account.');
        }
        $photo = null;
        $teacher_status = null;
        if ($requested_role === 'teacher') {
            $teacher_status = 'pending';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->fail('Enter a valid email address.');
        }
        if (strlen($password) < 8) {
            $this->fail('Password must be at least 8 characters long.');
        }
        if (!hash_equals($password, $confirmation)) {
            $this->fail('The password and confirmation do not match.');
        }
        $existing = $this->one('SELECT id, password, email_verified_at, email_otp_sent_at, email_otp_resend_count, email_otp_resend_reset_at FROM users WHERE email = ?', [$email]);
        if ($existing && $existing['email_verified_at'] === null && password_verify($password, $existing['password'])) {
            $this->guard_email_otp_resend($existing);
            try {
                $this->send_email_otp((int) $existing['id'], $email);
            } catch (RuntimeException $error) {
                $this->respond(['error' => $error->getMessage(), 'requires_email_verification' => true, 'email' => $email], 503);
            }
            $this->respond(['requires_email_verification' => true, 'email' => $email]);
        }
        if ($existing) {
            $this->fail('An account with that email already exists.', 409);
        }
        if ($requested_role === 'teacher') {
            $photo = $this->save_teacher_id_photo();
        }
        $username = substr(preg_replace('/[^a-z0-9_]/i', '_', strstr($email, '@', true)), 0, 80) . '_' . bin2hex(random_bytes(3));
        $user_id = $this->insert(
            'INSERT INTO users (username, full_name, email, password, role, teacher_status, teacher_id_photo, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
            [$username, $name, $email, password_hash($password, PASSWORD_DEFAULT), 'user', $teacher_status, $photo]
        );
        try {
            $this->send_email_otp($user_id, $email);
        } catch (RuntimeException $error) {
            $this->respond(['error' => $error->getMessage(), 'requires_email_verification' => true, 'email' => $email], 503);
        }
        $this->respond(['requires_email_verification' => true, 'email' => $email], 201);
    }

    public function verify_email()
    {
        $this->verify_csrf();
        $data = $this->body();
        $email = trim((string) ($data['email'] ?? ''));
        $code = trim((string) ($data['code'] ?? ''));
        $user = $this->one(
            'SELECT id, email_verified_at, email_otp_hash, email_otp_expires_at, email_otp_attempts
             FROM users WHERE email = ?',
            [$email]
        );
        if (!$user || $user['email_verified_at'] !== null || !$user['email_otp_hash']) {
            $this->fail('That verification code is invalid or expired. Request a new code and try again.', 400);
        }
        if ((int) $user['email_otp_attempts'] >= 5) {
            $this->fail('Too many incorrect codes. Request a new verification code.', 429);
        }
        if (!$user['email_otp_expires_at'] || strtotime($user['email_otp_expires_at']) < time()) {
            $this->fail('That verification code has expired. Request a new code.', 400);
        }
        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $user['email_otp_hash'])) {
            $this->execute('UPDATE users SET email_otp_attempts = email_otp_attempts + 1 WHERE id = ?', [(int) $user['id']]);
            $this->fail('That verification code is incorrect.', 400);
        }
        $this->execute(
            'UPDATE users SET email_verified_at = NOW(), email_otp_hash = NULL, email_otp_expires_at = NULL,
             email_otp_sent_at = NULL, email_otp_attempts = 0, is_active = 1 WHERE id = ?',
            [(int) $user['id']]
        );
        $this->respond(['verified' => true, 'email' => $email]);
    }

    public function resend_otp()
    {
        $this->verify_csrf();
        $data = $this->body();
        $email = trim((string) ($data['email'] ?? ''));
        $user = $this->one(
            'SELECT id, email_verified_at, email_otp_sent_at, email_otp_resend_count, email_otp_resend_reset_at FROM users WHERE email = ?',
            [$email]
        );
        if ($user && $user['email_verified_at'] === null) {
            $this->guard_email_otp_resend($user);
            try {
                $this->send_email_otp((int) $user['id'], $email);
            } catch (RuntimeException $error) {
                $this->respond(['error' => $error->getMessage()], 503);
            }
        }
        $this->respond(['ok' => true, 'message' => 'If the account needs verification, a new code has been sent.']);
    }

    public function forgot_password()
    {
        $this->verify_csrf();
        $data = $this->body();
        $email = trim((string) ($data['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 255) {
            $user = $this->one(
                'SELECT id, email, password_reset_otp_sent_at, password_reset_otp_send_count, password_reset_otp_send_reset_at
                 FROM users WHERE email = ? AND is_active = 1 AND email_verified_at IS NOT NULL',
                [$email]
            );
            if ($user) {
                if ($user['password_reset_otp_sent_at'] && strtotime($user['password_reset_otp_sent_at']) > time() - 60) {
                    $this->fail('Please wait one minute before requesting another password reset code.', 429);
                }
                if (!$user['password_reset_otp_send_reset_at'] || strtotime($user['password_reset_otp_send_reset_at']) < time() - 86400) {
                    $this->execute(
                        'UPDATE users SET password_reset_otp_send_count = 0, password_reset_otp_send_reset_at = NOW() WHERE id = ?',
                        [(int) $user['id']]
                    );
                    $user['password_reset_otp_send_count'] = 0;
                }
                if ((int) $user['password_reset_otp_send_count'] >= 5) {
                    $this->fail('The daily password reset code limit has been reached. Try again tomorrow.', 429);
                }

                $code = (string) random_int(100000, 999999);
                $this->execute(
                    'UPDATE users SET password_reset_otp_hash = ?, password_reset_otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
                     password_reset_otp_sent_at = NOW(), password_reset_otp_attempts = 0,
                     password_reset_otp_send_count = CASE WHEN password_reset_otp_send_reset_at IS NULL OR password_reset_otp_send_reset_at < DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE password_reset_otp_send_count + 1 END,
                     password_reset_otp_send_reset_at = CASE WHEN password_reset_otp_send_reset_at IS NULL OR password_reset_otp_send_reset_at < DATE_SUB(NOW(), INTERVAL 1 DAY) THEN NOW() ELSE password_reset_otp_send_reset_at END
                     WHERE id = ?',
                    [password_hash($code, PASSWORD_DEFAULT), (int) $user['id']]
                );
                try {
                    $this->call->library('studynest_mailer')->send_password_reset_otp($email, $code);
                } catch (RuntimeException $error) {
                    error_log('StudyNest password reset email delivery failed: ' . ($error->getPrevious() ? $error->getPrevious()->getMessage() : $error->getMessage()));
                    $this->execute(
                        'UPDATE users SET password_reset_otp_hash = NULL, password_reset_otp_expires_at = NULL,
                         password_reset_otp_sent_at = NULL, password_reset_otp_attempts = 0 WHERE id = ?',
                        [(int) $user['id']]
                    );
                    $this->fail('StudyNest could not send the password reset email. Check your email settings or try again later.', 503);
                }
            }
        }
        $this->respond([
            'ok' => true,
            'message' => 'If an active account uses that email, a password reset code has been sent.',
        ]);
    }

    public function reset_password()
    {
        $this->verify_csrf();
        $data = $this->body();
        $email = trim((string) ($data['email'] ?? ''));
        $code = trim((string) ($data['code'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirmation = (string) ($data['password_confirmation'] ?? '');
        if (strlen($password) < 8) {
            $this->fail('Password must be at least 8 characters long.');
        }
        if (!hash_equals($password, $confirmation)) {
            $this->fail('The password and confirmation do not match.');
        }
        $user = $this->one(
            'SELECT id, password_reset_otp_hash, password_reset_otp_expires_at, password_reset_otp_attempts
             FROM users WHERE email = ? AND is_active = 1 AND email_verified_at IS NOT NULL',
            [$email]
        );
        if (!$user || !$user['password_reset_otp_hash']) {
            $this->fail('That password reset code is invalid or expired. Request a new code and try again.', 400);
        }
        if ((int) $user['password_reset_otp_attempts'] >= 5) {
            $this->fail('Too many incorrect codes. Request a new password reset code.', 429);
        }
        if (!$user['password_reset_otp_expires_at'] || strtotime($user['password_reset_otp_expires_at']) < time()) {
            $this->fail('That password reset code has expired. Request a new code.', 400);
        }
        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $user['password_reset_otp_hash'])) {
            $this->execute(
                'UPDATE users SET password_reset_otp_attempts = password_reset_otp_attempts + 1 WHERE id = ?',
                [(int) $user['id']]
            );
            $this->fail('That password reset code is incorrect.', 400);
        }
        $this->execute(
            'UPDATE users SET password = ?, password_reset_otp_hash = NULL, password_reset_otp_expires_at = NULL,
             password_reset_otp_sent_at = NULL, password_reset_otp_attempts = 0
             WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]
        );
        if ((int) ($this->session->userdata('studynest_user_id') ?? 0) === (int) $user['id']) {
            $this->session->unset_userdata('studynest_user_id');
        }
        $this->respond(['ok' => true, 'message' => 'Your password has been reset. Sign in with your new password.']);
    }

    public function login()
    {
        $this->verify_csrf();
        $data = $this->body();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $user = $this->one(
            "SELECT id, full_name, email, password, is_active, email_verified_at, teacher_status,
             CASE WHEN teacher_status = 'pending' THEN 'teacher_pending' ELSE role END AS role
             FROM users WHERE email = ?",
            [$email]
        );
        if ($user && $user['email_verified_at'] === null && password_verify($password, $user['password'])) {
            $this->respond([
                'error' => 'Please verify your email address before signing in. A new code can be requested on the verification page.',
                'requires_email_verification' => true,
                'email' => $email,
            ], 403);
        }
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, $user['password'])) {
            $this->fail('Email or password is incorrect.', 401);
        }
        $this->session->regenerate_on_login();
        $this->session->set_userdata('studynest_user_id', (int) $user['id']);
        $this->respond([
            'user' => [
                'id' => (int) $user['id'],
                'name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'teacher_status' => $user['teacher_status'],
            ],
            'csrf_token' => $this->csrf_token(),
        ]);
    }

    public function logout()
    {
        $this->verify_csrf();
        if (empty($this->session->userdata('studynest_user_id'))) {
            $this->fail('Please sign in to continue.', 401);
        }
        $this->session->unset_userdata('studynest_user_id');
        $this->session->sess_regenerate(true);
        $this->session->set_userdata('studynest_csrf', bin2hex(random_bytes(32)));
        $this->respond(['ok' => true, 'csrf_token' => $this->session->userdata('studynest_csrf')]);
    }
}
