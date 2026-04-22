<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Invalid request method.');
}

function clean_input(string $value): string
{
    return trim(strip_tags($value));
}

function redirect_with_status(string $status): void
{
    header('Location: /order.html?service_intake_status=' . rawurlencode($status));
    exit;
}

$service = clean_input($_POST['service'] ?? '');
$need = clean_input($_POST['need'] ?? '');
$name = clean_input($_POST['name'] ?? '');
$phone = clean_input($_POST['phone'] ?? '');
$email = clean_input($_POST['email'] ?? '');
$best_time = clean_input($_POST['best_time'] ?? '');
$details = clean_input($_POST['details'] ?? '');

$valid_need_values = [
    'price-only' => 'Price only',
    'appointment-only' => 'Appointment only',
    'price-and-appointment' => 'Price and appointment',
];

if (
    $service === '' ||
    $name === '' ||
    $phone === '' ||
    $email === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !isset($valid_need_values[$need])
) {
    redirect_with_status('error');
}

$config_path = __DIR__ . '/../secure/config.php';
$config = [];

if (file_exists($config_path)) {
    $loaded_config = require $config_path;
    if (is_array($loaded_config)) {
        $config = $loaded_config;
    }
}

$smtp_host = $config['smtp_host'] ?? 'smtp.ionos.com';
$smtp_port = (int) ($config['smtp_port'] ?? 587);
$smtp_encryption = $config['smtp_encryption'] ?? 'tls';
$smtp_username = $config['smtp_username'] ?? 'info@absolute-notary.com';
$smtp_password = getenv('ABSOLUTE_NOTARY_SMTP_PASSWORD') ?: ($config['smtp_password'] ?? '');

$autoload_paths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../absolute-notary-calendar/vendor/autoload.php',
];

$autoload_loaded = false;

foreach ($autoload_paths as $autoload_path) {
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
        $autoload_loaded = true;
        break;
    }
}

if (!$autoload_loaded || !class_exists(PHPMailer::class)) {
    error_log('send-service-intake-request.php: PHPMailer is not available via Composer autoload');
    redirect_with_status('error');
}

if ($smtp_password === '' || $smtp_password === 'PASTE_REAL_EMAIL_PASSWORD_HERE') {
    error_log('send-service-intake-request.php: SMTP password is not configured');
    redirect_with_status('error');
}

$to = 'info@absolute-notary.com';
$subject = 'Service Intake Request';

$message = "Service Intake Request\n\n";
$message .= "Service: {$service}\n";
$message .= "What They Need: {$valid_need_values[$need]}\n";
$message .= "Name: {$name}\n";
$message .= "Phone: {$phone}\n";
$message .= "Email: {$email}\n";
$message .= "Best Time To Reach You: " . ($best_time !== '' ? $best_time : 'Not provided') . "\n";
$message .= "Service Details: " . ($details !== '' ? $details : 'Not provided') . "\n";

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = $smtp_host;
    $mail->Port = $smtp_port;
    $mail->SMTPAuth = true;
    $mail->Username = $smtp_username;
    $mail->Password = $smtp_password;
    $mail->SMTPSecure = $smtp_encryption === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;

    $mail->CharSet = 'UTF-8';
    $mail->isHTML(false);

    $mail->setFrom($smtp_username, 'Absolute Notary');
    $mail->addAddress($to);
    $mail->addReplyTo($email, $name);

    $mail->Subject = $subject;
    $mail->Body = $message;

    $mail->send();
    redirect_with_status('success');
} catch (Exception $exception) {
    error_log('send-service-intake-request.php PHPMailer error: ' . $mail->ErrorInfo);
    redirect_with_status('error');
}