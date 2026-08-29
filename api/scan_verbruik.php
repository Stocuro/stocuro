<?php
/**
 * Vervangt het Google Forms-formulier "Verbruik scannen"
 * (migratieplan hoofdstuk 6). Verwacht JSON-body:
 *   { locatie: string, gs1_raw: string, specialist: string,
 *     procedure_type: string, opmerking?: string, reden?: string }
 *
 * reden === 'vervaldatum_bereikt': boekt af als 'Vervallen' i.p.v.
 * 'Verbruik' (takenlijst-item "over de datum", zie includes/taken.php --
 * het scannen HIER is het afhandelen van die taak, geen apart scherm).
 * specialist/procedure_type zijn dan niet van toepassing; verwerkScan()
 * negeert ze toch al voor elk type behalve 'Verbruik'.
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

$body = readJsonBody();
$locatie = $body['locatie'] ?? '';
$gs1Raw = $body['gs1_raw'] ?? '';
$type = ($body['reden'] ?? '') === 'vervaldatum_bereikt' ? 'Vervallen' : 'Verbruik';
// Migratie 024: dit ene endpoint bedient twee schermen (zie de
// bestandskop hierboven) -- welk scherm-recht nodig is hangt af van
// $type, niet van het bestand zelf.
apiRequireScherm($type === 'Vervallen' ? 'verval-afboeken' : 'scan-verbruik');

if ($locatie === '' || trim($gs1Raw) === '') {
    http_response_code(400);
    jsonResponse(['error' => 'Locatie en minstens één GS1-scan zijn verplicht']);
}

apiTry(fn() => verwerkScan($type, $locatie, $gs1Raw, [
    'specialist' => $body['specialist'] ?? '',
    'procedureType' => $body['procedure_type'] ?? '',
    'opmerking' => $body['opmerking'] ?? '',
    'gebruikerNaam' => $user['naam'],
    // Migratie 022: bij een hersubmit na een "onbekende barcode"-melding
    // stuurt de frontend een map ruwe regel -> omschrijving mee.
    'omschrijvingen' => $body['omschrijvingen'] ?? [],
]));
