<?php
/**
 * Vervangt het Google Forms-formulier "Verplaatsingen en Retouren"
 * (migratieplan hoofdstuk 6). Verwacht JSON-body met 'actie':
 *   'verplaatsen'  -> { actie, gs1_raw, van_locatie, naar_locatie, reden, opmerking? }
 *   'retourneren'  -> { actie, gs1_raw, van_locatie, reden, opmerking? }
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
apiRequireScherm('verplaatsing');

$body = readJsonBody();
$actie = $body['actie'] ?? '';
$gs1Raw = $body['gs1_raw'] ?? '';

if (trim($gs1Raw) === '') {
    http_response_code(400);
    jsonResponse(['error' => 'Minstens één GS1-scan is verplicht']);
}

if ($actie === 'verplaatsen') {
    $van = $body['van_locatie'] ?? '';
    $naar = $body['naar_locatie'] ?? '';
    if ($van === '' || $naar === '') {
        http_response_code(400);
        jsonResponse(['error' => 'Van- en naar-locatie zijn verplicht']);
    }
    apiTry(fn() => verplaatsing($gs1Raw, $van, $naar, $body['reden'] ?? '', $body['opmerking'] ?? '', $user['naam'], $body['omschrijvingen'] ?? []));
} elseif ($actie === 'retourneren') {
    $van = $body['van_locatie'] ?? '';
    if ($van === '') {
        http_response_code(400);
        jsonResponse(['error' => 'Locatie van retour is verplicht']);
    }
    apiTry(fn() => retournering($gs1Raw, $van, $body['reden'] ?? '', $body['opmerking'] ?? '', $user['naam'], $body['omschrijvingen'] ?? []));
} else {
    http_response_code(400);
    jsonResponse(['error' => "Onbekende actie: verwacht 'verplaatsen' of 'retourneren'"]);
}
