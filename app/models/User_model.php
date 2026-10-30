<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class User_model extends Model
{
    protected $table = 'users';
    protected $fillable = ['username', 'full_name', 'email', 'password', 'role', 'teacher_id', 'teacher_status', 'teacher_reviewed_at', 'teacher_id_photo', 'email_verified_at', 'email_otp_hash', 'email_otp_expires_at', 'email_otp_sent_at', 'email_otp_attempts', 'email_otp_resend_count', 'email_otp_resend_reset_at', 'password_reset_otp_hash', 'password_reset_otp_expires_at', 'password_reset_otp_sent_at', 'password_reset_otp_attempts', 'password_reset_otp_send_count', 'password_reset_otp_send_reset_at', 'is_active'];
    protected $timestamps = true;
}
