<?php
/**
 * Vervangt het Google Forms-formulier "Artikel toevoegen" (Artikelen-
 * tabblad submit) EN de oude onEdit-trigger die GS1 parste zodra kolom
 * C werd bewerkt. Beide worden hier één moment: GS1 wordt geparsed bij
 * het opslaan van een nieuw artikel (migratieplan hoofdstuk 6).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/artikelen.php';
// Als dit nieuwe artikel een EAN
// heeft die eerder als "onbekend" in een eenmalige bestelling is
// gescand, moet die alsnog als ontvangen geboekt worden -- zie
// verwerkAchterstalligeEenmaligeBestellingOntvangsten() hieronder.
require_once __DIR__ . '/../includes/eenmalige_bestellingen.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['error' => 'Alleen POST toegestaan']);
}

// POST-only endpoint: apiRequireWriteAccess() dekt zowel "is ingelogd"
// als "mag schrijven" (blokkeert gast/alleen-lezen) in één aanroep, zie
// includes/api_helpers.php.
$user = apiRequireWriteAccess();
apiRequireScherm('artikel-toevoegen');

$body = readJsonBody();
apiTry(function () use ($body, $user) {
    $result = addArtikel($body, $user['naam']);
    // Als dit nieuwe artikel specifiek
    // is aangemaakt als vervanger voor een ander (niet leverbaar)
    // artikel -- zie showArtikelToevoegen()/renderProductEdit() in
    // assets/js/app.js -- meteen na het aanmaken die koppeling leggen,
    // zodat er geen aparte tweede opslagstap nodig is.
    if ($result['success'] && !empty($body['vervangtVoorEan'])) {
        koppelAlsVervanger((string) $body['vervangtVoorEan'], $result['ean'], $user['naam']);
    }
    // Los van waar dit artikel vandaan
    // komt, altijd checken of er eenmalige-bestelling-items op deze EAN
    // liggen te wachten op een artikel dat er nu is -- no-op als dat niet
    // het geval is.
    if ($result['success']) {
        verwerkAchterstalligeEenmaligeBestellingOntvangsten($result['ean'], $user['naam']);
    }
    return $result;
});
