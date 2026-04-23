<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    render_error_page('We were unable to start Square checkout. Please call (408) 890-6850 or try again.');
}

$config = [];
$configPath = __DIR__ . '/secure/config.php';

if (file_exists($configPath)) {
    $loadedConfig = require $configPath;
    if (is_array($loadedConfig)) {
        $config = $loadedConfig;
    }
}

function clean_text(mixed $value): string
{
    return trim(strip_tags((string) $value));
}

function parse_money_to_cents(mixed $value): int
{
    if (is_int($value)) {
        return max(0, $value);
    }

    if (is_float($value)) {
        return max(0, (int) round($value * 100));
    }

    $normalized = preg_replace('/[^0-9.\-]/', '', (string) $value);
    if ($normalized === null || $normalized === '') {
        return 0;
    }

    $amount = (float) $normalized;
    if (!is_finite($amount) || $amount <= 0) {
        return 0;
    }

    return (int) round($amount * 100);
}

function cents_to_display(int $amountCents): string
{
    return '$' . number_format($amountCents / 100, 2, '.', '');
}

function app_base_path(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    return rtrim(dirname($scriptName), '/.');
}

function app_url(string $path): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $basePath = app_base_path();

    return $scheme . '://' . $host . ($basePath !== '' ? $basePath : '') . $path;
}

function render_error_page(string $message, string $detail = ''): never
{
    http_response_code(http_response_code() >= 400 ? http_response_code() : 500);
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeDetail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');

    echo '<!DOCTYPE html>';
    echo '<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Square Checkout Error | Absolute Notary</title>';
    echo '<style>';
    echo 'body{margin:0;font-family:Arial,sans-serif;background:#f8f3ea;color:#2d2118;}';
    echo '.wrap{max-width:760px;margin:64px auto;padding:0 20px;}';
    echo '.card{background:#fffdf9;border:1px solid #ddcfb7;border-radius:22px;box-shadow:0 10px 28px rgba(60,36,21,.08);padding:32px;}';
    echo 'h1{margin:0 0 12px;color:#3c2415;font-size:2rem;}';
    echo 'p{margin:0 0 14px;line-height:1.6;}';
    echo '.detail{margin-top:18px;padding:14px 16px;border-radius:14px;background:#efe4d0;color:#4d3a2c;font-size:14px;white-space:pre-wrap;}';
    echo 'a{color:#3c2415;font-weight:700;text-decoration:none;}';
    echo '</style></head><body><div class="wrap"><div class="card">';
    echo '<h1>Unable to Start Checkout</h1>';
    echo '<p>' . $safeMessage . '</p>';
    echo '<p><a href="' . htmlspecialchars(app_url('/order.html'), ENT_QUOTES, 'UTF-8') . '">Return to the order page</a></p>';

    if ($safeDetail !== '') {
        echo '<div class="detail">' . $safeDetail . '</div>';
    }

    echo '</div></div></body></html>';
    exit;
}

// Keep Square secrets server-side and load them from config or environment.
$squareAccessToken = getenv('ABSOLUTE_NOTARY_SQUARE_ACCESS_TOKEN') ?: ($config['square_access_token'] ?? 'YOUR_SQUARE_ACCESS_TOKEN');
$squareLocationId = getenv('ABSOLUTE_NOTARY_SQUARE_LOCATION_ID') ?: ($config['square_location_id'] ?? 'YOUR_SQUARE_LOCATION_ID');

// Default the success redirect back to the order page unless configured otherwise.
$successRedirectUrl = $config['square_success_redirect_url'] ?? app_url('/order.html');

function build_notary_line_item(array $item): ?array
{
    $type = clean_text($item['type'] ?? '');
    $quantity = max(1, (int) ($item['qty'] ?? 0));
    $allowedTypes = ['Acknowledgment', 'Copy Certification', 'Jurat'];

    if (!in_array($type, $allowedTypes, true)) {
        return null;
    }

    $priceCents = (1500 + max(0, $quantity - 1) * 1000);

    return [
        'name' => $type . ' (' . $quantity . ' signatures)',
        'quantity' => '1',
        'base_price_money' => [
            'amount' => $priceCents,
            'currency' => 'USD',
        ],
    ];
}

function build_apostille_line_item(array $item): ?array
{
    $quantity = max(1, (int) ($item['qty'] ?? 0));
    $turnaround = (int) ($item['turnaround'] ?? 0);
    $allowedTurnarounds = [99, 115, 200];

    if (($item['type'] ?? '') !== 'Apostille' || !in_array($turnaround, $allowedTurnarounds, true)) {
        return null;
    }

    return [
        'name' => 'Apostille Service',
        'quantity' => (string) $quantity,
        'base_price_money' => [
            'amount' => $turnaround * 100,
            'currency' => 'USD',
        ],
        'note' => 'Turnaround option: $' . number_format($turnaround, 2, '.', ''),
    ];
}

function build_fee_line_item(string $label, int $amountCents): ?array
{
    if ($amountCents <= 0) {
        return null;
    }

    return [
        'name' => $label,
        'quantity' => '1',
        'base_price_money' => [
            'amount' => $amountCents,
            'currency' => 'USD',
        ],
    ];
}

if ($squareAccessToken === 'YOUR_SQUARE_ACCESS_TOKEN' || $squareLocationId === 'YOUR_SQUARE_LOCATION_ID') {
    http_response_code(500);
    render_error_page(
        'We were unable to start Square checkout. Please call (408) 890-6850 or try again.',
        'Square credentials are not configured in the server environment or secure/config.php.'
    );
}

$fullName = clean_text($_POST['full_name'] ?? '');
$phone = clean_text($_POST['phone'] ?? '');
$email = clean_text($_POST['email'] ?? '');
$streetAddress = clean_text($_POST['street_address'] ?? '');
$city = clean_text($_POST['city'] ?? '');
$state = clean_text($_POST['state'] ?? '');
$zipCode = clean_text($_POST['zip_code'] ?? '');
$notes = clean_text($_POST['notes'] ?? '');
$cartJson = (string) ($_POST['cart_json'] ?? '[]');
$postedAmountCents = max(0, (int) ($_POST['amount_cents'] ?? 0));
$postedDisplayTotal = clean_text($_POST['display_total'] ?? '');
$termsAccepted = isset($_POST['terms_accepted']);

$feeAmounts = [
    'Travel Fee' => parse_money_to_cents($_POST['travel_fee'] ?? 0),
    'Rush Fee' => parse_money_to_cents($_POST['rush_fee'] ?? 0),
    'Extra Signers Fee' => parse_money_to_cents($_POST['extra_signers_fee'] ?? 0),
    // Edit this label if you want the custom fee to appear differently in Square checkout.
    'Custom Add-On Fee' => parse_money_to_cents($_POST['custom_addon_fee'] ?? 0),
];

if (
    $fullName === '' ||
    $phone === '' ||
    $email === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !$termsAccepted
) {
    http_response_code(400);
    render_error_page('We were unable to start Square checkout. Please call (408) 890-6850 or try again.');
}

$decodedCart = json_decode($cartJson, true);
if (!is_array($decodedCart)) {
    http_response_code(400);
    render_error_page('We were unable to start Square checkout. Please call (408) 890-6850 or try again.');
}

$lineItems = [];
$rebuiltAmountCents = 0;

foreach ($decodedCart as $cartItem) {
    if (!is_array($cartItem)) {
        continue;
    }

    $lineItem = null;

    if (($cartItem['type'] ?? '') === 'Apostille') {
        $lineItem = build_apostille_line_item($cartItem);
    } else {
        $lineItem = build_notary_line_item($cartItem);
    }

    if ($lineItem === null) {
        continue;
    }

    $lineItems[] = $lineItem;
    $rebuiltAmountCents += ((int) $lineItem['base_price_money']['amount']) * ((int) $lineItem['quantity']);
}

foreach ($feeAmounts as $feeLabel => $feeAmountCents) {
    $lineItem = build_fee_line_item($feeLabel, $feeAmountCents);
    if ($lineItem === null) {
        continue;
    }

    $lineItems[] = $lineItem;
    $rebuiltAmountCents += $feeAmountCents;
}

$postedDisplayTotalCents = parse_money_to_cents($postedDisplayTotal);

if ($rebuiltAmountCents <= 0 || empty($lineItems)) {
    http_response_code(400);
    render_error_page('We were unable to start Square checkout. Please call (408) 890-6850 or try again.');
}

if (
    $postedAmountCents <= 0 ||
    $postedAmountCents !== $rebuiltAmountCents ||
    ($postedDisplayTotal !== '' && $postedDisplayTotalCents !== $rebuiltAmountCents)
) {
    http_response_code(400);
    render_error_page(
        'We were unable to start Square checkout. Please call (408) 890-6850 or try again.',
        'The submitted total did not match the server-calculated order total. Please return to the order page and try again.'
    );
}

$orderNoteParts = [
    'Customer: ' . $fullName,
    'Phone: ' . $phone,
    'Email: ' . $email,
];

if ($streetAddress !== '' || $city !== '' || $state !== '' || $zipCode !== '') {
    $orderNoteParts[] = 'Address: ' . trim($streetAddress . ', ' . $city . ', ' . $state . ' ' . $zipCode, ', ');
}

if ($notes !== '') {
    $orderNoteParts[] = 'Notes: ' . $notes;
}

$orderNote = implode("\n", $orderNoteParts);

try {
    $idempotencyKey = bin2hex(random_bytes(16));
} catch (Throwable $throwable) {
    $idempotencyKey = uniqid('square_', true);
}

$requestBody = [
    'idempotency_key' => $idempotencyKey,
    'description' => $orderNote,
    'order' => [
        'location_id' => $squareLocationId,
        'line_items' => $lineItems,
    ],
    'checkout_options' => [
        // Change this URL above if you need buyers to land on a different page after successful payment.
        'redirect_url' => $successRedirectUrl,
    ],
    'pre_populated_data' => [
        'buyer_email' => $email,
    ],
];

$ch = curl_init('https://connect.squareup.com/v2/online-checkout/payment-links');
if ($ch === false) {
    http_response_code(500);
    render_error_page('We were unable to start Square checkout. Please call (408) 890-6850 or try again.');
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $squareAccessToken,
        'Content-Type: application/json',
        'Accept: application/json',
        'Square-Version: 2025-10-16',
    ],
    CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 30,
]);

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($responseBody === false || $curlError !== '') {
    http_response_code(502);
    render_error_page(
        'We were unable to start Square checkout. Please call (408) 890-6850 or try again.',
        $curlError !== '' ? $curlError : ''
    );
}

$responseData = json_decode($responseBody, true);
$paymentUrl = is_array($responseData) ? ($responseData['payment_link']['url'] ?? '') : '';

if ($httpStatus >= 200 && $httpStatus < 300 && is_string($paymentUrl) && $paymentUrl !== '') {
    header('Location: ' . $paymentUrl, true, 303);
    exit;
}

$errorDetails = 'Square checkout could not be created.';
if (is_array($responseData) && !empty($responseData['errors']) && is_array($responseData['errors'])) {
    $messages = [];
    foreach ($responseData['errors'] as $error) {
        if (!is_array($error)) {
            continue;
        }

        $code = clean_text($error['code'] ?? '');
        $detail = clean_text($error['detail'] ?? '');
        $messages[] = trim($code . ($detail !== '' ? ': ' . $detail : ''));
    }

    if (!empty($messages)) {
        $errorDetails = implode("\n", $messages);
    }
}

http_response_code(502);
render_error_page(
    'We were unable to start Square checkout. Please call (408) 890-6850 or try again.',
    $errorDetails
);
