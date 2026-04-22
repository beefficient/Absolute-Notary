<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

error_log('send-mobile-notary-request.php reached');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	exit('Invalid request method.');
}

function clean_input(string $value): string {
	return trim(strip_tags($value));
}

$name = clean_input($_POST['name'] ?? '');
$phone = clean_input($_POST['phone'] ?? '');
$street_address = clean_input($_POST['street_address'] ?? '');
$city = clean_input($_POST['city'] ?? '');
$zip_code = clean_input($_POST['zip_code'] ?? '');
$emergency = isset($_POST['emergency']) ? 'Yes' : 'No';

error_log('send-mobile-notary-request.php values: ' . json_encode([
	'name' => $name,
	'phone' => $phone,
	'street_address' => $street_address,
	'city' => $city,
	'zip_code' => $zip_code,
	'emergency' => $emergency,
]));

if ($name === '' || $phone === '' || $street_address === '' || $city === '' || $zip_code === '') {
	header('Location: /order.html?status=error');
	exit;
}

$to = 'info@absolute-notary.com';
$subject = '24 Hour Mobile Notary Request';

$message = "24 Hour Mobile Notary Request\n\n";
$message .= "Name: {$name}\n";
$message .= "Phone: {$phone}\n";
$message .= "Street Address: {$street_address}\n";
$message .= "City: {$city}\n";
$message .= "Zip Code: {$zip_code}\n";
$message .= "Emergency: {$emergency}\n";

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
	error_log('send-mobile-notary-request.php PHPMailer is not available via Composer autoload');
	header('Location: /order.html?status=error');
	exit;
}

if ($smtp_password === '' || $smtp_password === 'PASTE_REAL_EMAIL_PASSWORD_HERE') {
	error_log('send-mobile-notary-request.php SMTP password is not configured');
	header('Location: /order.html?status=error');
	exit;
}

$success = false;

try {
	$mail = new PHPMailer(true);
	$mail->isSMTP();
	$mail->Host = $smtp_host;
	$mail->Port = $smtp_port;
	$mail->SMTPSecure = $smtp_encryption === 'ssl'
		? PHPMailer::ENCRYPTION_SMTPS
		: PHPMailer::ENCRYPTION_STARTTLS;
	$mail->SMTPAuth = true;
	$mail->Username = $smtp_username;
	$mail->Password = $smtp_password;
	$mail->setFrom($smtp_username, 'Absolute Notary');
	$mail->addAddress('info@absolute-notary.com');
	$mail->Subject = $subject;
	$mail->Body = $message;
	$success = $mail->send();
	error_log('send-mobile-notary-request.php PHPMailer send result: ' . ($success ? 'success' : 'failure'));
} catch (Exception $exception) {
	error_log('send-mobile-notary-request.php PHPMailer error: ' . $mail->ErrorInfo);
	$success = false;
}

if ($success) {
	header('Location: /order.html?status=success');
	exit;
}

header('Location: /order.html?status=error');
exit;