<?php

function redirect_to_order(string $status, ?string $reason = null): void
{
    $params = ['mobile_notary_status' => $status];

    if ($reason !== null) {
        $params['mobile_notary_reason'] = $reason;
    }

    header('Location: /order.html?' . http_build_query($params));
    exit;
}

function sanitize_text(string $value): string
{
    $value = trim($value);
    $value = strip_tags($value);

    return preg_replace('/\s+/', ' ', $value) ?? '';
}

function sanitize_phone(string $value): string
{
    $value = trim($value);

    return preg_replace('/[^0-9+()\-\.\s]/', '', $value) ?? '';
}

function sanitize_zip(string $value): string
{
    $value = trim($value);

    return preg_replace('/[^A-Za-z0-9\-\s]/', '', $value) ?? '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect_to_order('error', 'invalid_request');
}

$name = sanitize_text($_POST['name'] ?? '');
$phone = sanitize_phone($_POST['phone'] ?? '');
$streetAddress = sanitize_text($_POST['street_address'] ?? '');
$city = sanitize_text($_POST['city'] ?? '');
$zipCode = sanitize_zip($_POST['zip_code'] ?? '');
$emergency = isset($_POST['emergency']) ? 'Yes' : 'No';

$requiredFields = [$name, $phone, $streetAddress, $city, $zipCode];

foreach ($requiredFields as $field) {
    if ($field === '') {
        redirect_to_order('error', 'missing_required');
    }
}

$to = 'info@absolute-notary.com';
$subject = '24 Hour Mobile Notary Request';
$body = implode("\n", [
    '24 Hour Mobile Notary Request',
    '',
    'Name: ' . $name,
    'Phone: ' . $phone,
    'Street Address: ' . $streetAddress,
    'City: ' . $city,
    'Zip Code: ' . $zipCode,
    'Emergency: ' . $emergency,
]);

$headers = [
    'From: Absolute Notary <info@absolute-notary.com>',
    'Reply-To: info@absolute-notary.com',
    'Content-Type: text/plain; charset=UTF-8',
];

$sent = mail($to, $subject, $body, implode("\r\n", $headers));

if (!$sent) {
    redirect_to_order('error', 'send_failed');
}

redirect_to_order('success');