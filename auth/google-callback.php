<?php
require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../secure/config.php';

$client = new Google_Client();
$client->setClientId($config['client_id']);
$client->setClientSecret($config['client_secret']);
$client->setRedirectUri($config['redirect_uri']);

if (!isset($_GET['code'])) {
    exit('Authorization code missing.');
}

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

if (isset($token['error'])) {
    exit('OAuth error: ' . htmlspecialchars($token['error']));
}

file_put_contents(__DIR__ . '/../secure/token.json', json_encode($token, JSON_PRETTY_PRINT));

echo 'Google Calendar connected successfully. You can close this page.';