<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

$config['studynest_smtp_host'] = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$config['studynest_smtp_port'] = (int) (getenv('SMTP_PORT') ?: 587);
$config['studynest_smtp_username'] = getenv('SMTP_USERNAME') ?: '';
$config['studynest_smtp_password'] = getenv('SMTP_PASSWORD') ?: '';
$config['studynest_mail_from'] = getenv('SMTP_FROM_EMAIL') ?: (getenv('SMTP_USERNAME') ?: '');
$config['studynest_mail_from_name'] = getenv('SMTP_FROM_NAME') ?: 'StudyNest';
