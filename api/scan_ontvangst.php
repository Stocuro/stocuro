<?php
/**
 * Vervangt het Google Forms-formulier "Ontvangst scannen"
 * (migratieplan hoofdstuk 6). Verwacht JSON-body:
 *   { locatie: string, gs1_raw: string, opmerking?: string }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/transacties.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['error' => 'Alleen POST toegestaan']);
}

// POST-only endpoint: apiRequireWriteAccess() dekt zowel "is ingelogd"
// als "mag schrijven" (blokkeert gast/alleen-lezen) in één aanroep.
$user = apiRequireWriteAccess();
apiRequireScherm('scan-ontvangst');

$body = readJsonBody();
$locatie = $body['locatie'] ?? '';
$gs1Raw = $body['gs1_raw'] ?? '';
$opmerking = $body['opmerking'] ?? '';

if ($locatie === '' || trim($gs1Raw) === '') {
    http_response_code(400);
    jsonResponse(['error' => 'Locatie en minstens één GS1-scan zijn verplicht']);
}

apiTry(fn() => verwerkScan('Ontvangst', $locatie, $gs1Raw, [
    'opmerking' => $opmerking, 'gebruikerNaam' => $user['naam'],
    'omschrijvingen' => $body['omschrijvingen'] ?? [],
]));
