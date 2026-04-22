<?php
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$config = require __DIR__ . '/../secure/config.php';
$input = json_decode(file_get_contents('php://input'), true);

$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$phone = trim($input['phone'] ?? '');
$service = trim($input['service'] ?? '');
$date = trim($input['date'] ?? '');
$time = trim($input['time'] ?? '');
$notes = trim($input['notes'] ?? '');
$address = trim($input['address'] ?? '');

if (!$name || !$email || !$service || !$date || !$time) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing required fields.']);
    exit;
}

$start = new DateTime("$date $time", new DateTimeZone($config['timezone']));
$end = clone $start;
$end->modify('+1 hour');

$client = new Google_Client();
$client->setClientId($config['client_id']);
$client->setClientSecret($config['client_secret']);
$client->setRedirectUri($config['redirect_uri']);
$client->addScope(Google_Service_Calendar::CALENDAR);

$tokenPath = __DIR__ . '/../secure/token.json';
if (!file_exists($tokenPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Google Calendar is not connected yet.']);
    exit;
}

$accessToken = json_decode(file_get_contents($tokenPath), true);
$client->setAccessToken($accessToken);

if ($client->isAccessTokenExpired()) {
    if (!empty($accessToken['refresh_token'])) {
        $newToken = $client->fetchAccessTokenWithRefreshToken($accessToken['refresh_token']);
        $merged = array_merge($accessToken, $newToken);
        file_put_contents($tokenPath, json_encode($merged, JSON_PRETTY_PRINT));
        $client->setAccessToken($merged);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Refresh token missing. Reconnect Google Calendar.']);
        exit;
    }
}

$serviceObj = new Google_Service_Calendar($client);

$freeBusy = new Google_Service_Calendar_FreeBusyRequest([
    'timeMin' => $start->format(DateTime::RFC3339),
    'timeMax' => $end->format(DateTime::RFC3339),
    'items' => [['id' => $config['calendar_id']]]
]);

$freeBusyResult = $serviceObj->freebusy->query($freeBusy);
$calendarBusy = $freeBusyResult->getCalendars()[$config['calendar_id']]->getBusy();

if (!empty($calendarBusy)) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'That time is unavailable. Please choose another slot.']);
    exit;
}

$event = new Google_Service_Calendar_Event([
    'summary' => $service . ' - ' . $name,
    'location' => $address,
    'description' => "Client: $name\nEmail: $email\nPhone: $phone\nService: $service\nNotes: $notes",
    'start' => [
        'dateTime' => $start->format(DateTime::RFC3339),
        'timeZone' => $config['timezone'],
    ],
    'end' => [
        'dateTime' => $end->format(DateTime::RFC3339),
        'timeZone' => $config['timezone'],
    ],
]);

$created = $serviceObj->events->insert($config['calendar_id'], $event);

echo json_encode([
    'ok' => true,
    'message' => 'Appointment booked successfully.',
    'eventLink' => $created->htmlLink,
]);