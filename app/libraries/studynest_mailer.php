<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class StudyNest_mailer
{
    public function send_otp($recipient, $code)
    {
        $this->send_code($recipient, $code, 'Your StudyNest verification code', 'Use this code to verify your email address.');
    }

    public function send_password_reset_otp($recipient, $code)
    {
        $this->send_code($recipient, $code, 'Reset your StudyNest password', 'Use this code to reset your StudyNest password. If you did not request a reset, ignore this email.');
    }

    private function send_code($recipient, $code, $subject, $purpose)
    {
        $username = (string) config_item('studynest_smtp_username');
        $password = (string) config_item('studynest_smtp_password');
        $from = (string) config_item('studynest_mail_from');
        if ($username === '' || $password === '' || $from === '') {
            throw new RuntimeException('Email verification is not configured. Set SMTP_USERNAME, SMTP_PASSWORD, and SMTP_FROM_EMAIL in the backend .env file.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) config_item('studynest_smtp_host');
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int) config_item('studynest_smtp_port');
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($from, (string) config_item('studynest_mail_from_name'));
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = '<p>' . htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8') . '</p><p>Your StudyNest code is <strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong>.</p><p>It expires in 10 minutes. If you did not request this code, you can ignore this message.</p>';
            $mail->AltBody = $purpose . ' Your StudyNest code is ' . $code . '. It expires in 10 minutes.';
            $mail->send();
        } catch (Exception $error) {
            error_log('StudyNest OTP email delivery error: ' . $error->getMessage());
            throw new RuntimeException('StudyNest could not send the verification email. Check the SMTP settings and Gmail App Password.', 0, $error);
        }
    }
}
