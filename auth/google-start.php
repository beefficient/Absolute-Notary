<?php
require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../secure/config.php';

$client = new Google_Client();
$client->setClientId($config['client_id']);
$client->setClientSecret($config['client_secret']);
$client->setRedirectUri($config['redirect_uri']);
$client->setAccessType('offline');
$client->setPrompt('consent');
$client->addScope(Google_Service_Calendar::CALENDAR);

header('Location: ' . $client->createAuthUrl());
exit;