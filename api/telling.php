<?php
/**
 * Kastcontrole ("telling", takenlijst-item, zie includes/taken.php).
 * Zelfde rolbeperking als recente-transacties-correcties
 * (api/transactielog.php): een routine correctietaak voor het team, geen
 * beheerderstaak, dus iedereen ingelogd behalve 'alleen-lezen' (en, sinds
 * gast-modus, ook geen gast -- zie apiRequireWriteAccess()).
 *
 * GET  -> { locatie } (querystring)               systeemaantallen voor die locatie
 * GET  -> { locatie, actie: 'kastlocaties' }       Migratie 043: kastlocatie-keuzelijst
 *                                                  voor die locatie (zie
 *                                                  kastlocatiesVoorLocatie() in
 *                                                  includes/locaties.php) -- zelfde
 *                                                  éne-bestand-met-actie-parameter-
 *                                                  patroon als api/leaderboard.php
 *                                                  (getPiccRotatie).
 * POST -> { locatie, gs1_raw, kastlocatie? }       verwerkt de telling, boekt
 *                                                  afwijkingen. kastlocatie
 *                                                  (migratie 043, optioneel): beperkt
 *                                                  de telling tot één kast binnen de
 *                                                  locatie -- zie verwerkTelling().
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/telling.php';

$user = apiRequireLogin();
// Migratie 026: kastcontrole is het Laborant-taken-dashboard, niet het
// Beheerder-taken-dashboard (zie includes/schermen.php).
apiRequireScherm('taken-laborant');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Was hier voorheen een losse ad-hoc alleen-lezen-check; nu de
    // gedeelde apiRequireWriteAccess(), die ook gast blokkeert.
    apiRequireWriteAccess();

    $body = readJsonBody();
    $locatie = (string) ($body['locatie'] ?? '');
    $gs1Raw = (string) ($body['gs1_raw'] ?? '');
    // Migratie 043: leeg/ontbrekend blijft null (= volledige locatie),
    // verwerkTelling() normaliseert dit verder zelf ook nog eens.
    $kastlocatie = isset($body['kastlocatie']) && trim((string) $body['kastlocatie']) !== ''
        ? (string) $body['kastlocatie'] : null;

    if ($locatie === '' || trim($gs1Raw) === '') {
        http_response_code(400);
        jsonResponse(['error' => 'Locatie en minstens één GS1-scan zijn verplicht']);
    }

    apiTry(fn() => verwerkTelling($locatie, $gs1Raw, $user['naam'], $body['omschrijvingen'] ?? [], $kastlocatie));
} else {
    $locatie = (string) ($_GET['locatie'] ?? '');
    if ($locatie === '') {
        http_response_code(400);
        jsonResponse(['error' => 'Locatie is verplicht']);
    }
    if (($_GET['actie'] ?? '') === 'kastlocaties') {
        apiTry(fn() => kastlocatiesVoorLocatie($locatie));
    } else {
        apiTry(fn() => getTellingArtikelen($locatie));
    }
}
