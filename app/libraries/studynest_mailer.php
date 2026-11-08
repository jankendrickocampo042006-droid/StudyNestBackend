<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class StudyNest_mailer
{
    public function send_otp($recipient, $code)
    {
        $this->send_code($recipient, $code, 'Your StudyNest verification code', 'Use this code to verify your email address.', 15);
    }

    public function send_password_reset_otp($recipient, $code)
    {
        $this->send_code($recipient, $code, 'Reset your StudyNest password', 'Use this code to reset your StudyNest password. If you did not request a reset, ignore this email.', 10);
    }

    public function send_notification($recipient, $subject, $title, $message, $link)
    {
        $username = (string) config_item('studynest_smtp_username');
        $password = (string) config_item('studynest_smtp_password');
        $from = (string) config_item('studynest_mail_from');
        if ($username === '' || $password === '' || $from === '') {
            throw new RuntimeException('Notification email is not configured. Set the Brevo SMTP environment variables on the backend.');
        }

        $safe_title = htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8');
        $safe_message = nl2br(htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8'));
        $app_url = rtrim((string) config_item('studynest_app_url'), '/');
        $safe_link = htmlspecialchars($app_url . '/' . ltrim((string) $link, '/'), ENT_QUOTES, 'UTF-8');
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) config_item('studynest_smtp_host');
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int) config_item('studynest_smtp_port');
            $mail->Timeout = (int) config_item('studynest_smtp_timeout');
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($from, (string) config_item('studynest_mail_from_name'));
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = (string) $subject;
            $mail->Body = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#39242a">'
                . '<p style="color:#762838;font-weight:bold;letter-spacing:1px">STUDYNEST</p>'
                . '<h1 style="font-size:22px">' . $safe_title . '</h1>'
                . '<p style="font-size:15px;line-height:1.7">' . $safe_message . '</p>'
                . '<p><a href="' . $safe_link . '" style="display:inline-block;padding:12px 18px;background:#762838;color:#fff;text-decoration:none;border-radius:6px">Open StudyNest</a></p>'
                . '<p style="font-size:12px;color:#8e7d81">You can manage these email notifications in your StudyNest notification settings.</p></div>';
            $mail->AltBody = (string) $title . "\n\n" . (string) $message . "\n\nOpen StudyNest: " . $app_url . '/' . ltrim((string) $link, '/');
            $mail->send();
        } catch (Exception $error) {
            error_log('StudyNest notification email delivery error: ' . $error->getMessage());
            throw new RuntimeException('StudyNest could not send the notification email through the configured SMTP provider.', 0, $error);
        }
    }

    private function send_code($recipient, $code, $subject, $purpose, $expiry_minutes)
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
            $mail->Timeout = (int) config_item('studynest_smtp_timeout');
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($from, (string) config_item('studynest_mail_from_name'));
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = '<p>' . htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8') . '</p><p>Your StudyNest code is <strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong>.</p><p>It expires in ' . $expiry_minutes . ' minutes. If you did not request this code, you can ignore this message.</p>';
            $mail->AltBody = $purpose . ' Your StudyNest code is ' . $code . '. It expires in ' . $expiry_minutes . ' minutes.';
            $mail->send();
        } catch (Exception $error) {
            error_log('StudyNest OTP email delivery error: ' . $error->getMessage());
            throw new RuntimeException('StudyNest could not send the email. Check the SMTP provider settings and credentials.', 0, $error);
        }
    }
}
