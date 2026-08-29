<?php
/**
 * Zelf-service taalvoorkeur (migratie 009): elke ingelogde gebruiker mag
 * zijn eigen taal wijzigen. Vervangt de vroegere localStorage-toggle in
 * de ingelogde app -- zie assets/js/app.js.
 *
 * POST -> { taal: 'en'|'nl' }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/auth.php';

apiRequireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['error' => 'Alleen POST toegestaan']);
}

$body = readJsonBody();
apiTry(fn() => setEigenTaal((string) ($body['taal'] ?? '')));
