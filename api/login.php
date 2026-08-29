<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';

$body = readJsonBody();
$email = trim($body['email'] ?? '');
$wachtwoord = (string) ($body['wachtwoord'] ?? '');

if ($email === '' || $wachtwoord === '') {
    http_response_code(400);
    jsonResponse(['success' => false, 'error' => 'E-mail en wachtwoord zijn verplicht']);
}

apiTry(fn() => attemptLogin($email, $wachtwoord));
