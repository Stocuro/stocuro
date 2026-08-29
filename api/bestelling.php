<?php
/**
 * Vervangt het Google Forms-formulier "Handmatige bestellingen" EN de
 * dashboard-modal (submitHandmatigeBestellingen). Beide riepen in de
 * oude code dezelfde upsertOrder() aan -- hier hetzelfde principe: één
 * endpoint voor allebei (migratieplan hoofdstuk 6).
 * Verwacht JSON-body: { ean, aantal, kanaal, opmerking? }
 *
 * Migratie 033, op verzoek van Harm ("een bestelling die wordt geplaatst
 * krijgt ook altijd de koppeling van degene die besteld"): besteller is
 * niet langer een vrij, door de client meegestuurd tekstveld -- net als
 * addEenmaligeBestelling() al deed, is dit altijd de ingelogde gebruiker
 * die de aanvraag indient ($user['naam'], van apiRequireWriteAccess()
 * hieronder). Wil je vastleggen dat iemand anders de order heeft
 * geplaatst (bijv. een collega die belde), dan pas je dat na aanmaken aan
 * op de orderdetailpagina (zie api/order_detail.php), waar "Besteld door"
 * een dropdown van bekende gebruikers is.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/orders.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['error' => 'Alleen POST toegestaan']);
}

// POST-only endpoint: apiRequireWriteAccess() dekt zowel "is ingelogd"
// als "mag schrijven" (blokkeert gast/alleen-lezen) in één aanroep.
$user = apiRequireWriteAccess();
apiRequireScherm('bestellers');

$body = readJsonBody();
$ean = $body['ean'] ?? '';

if ($ean === '') {
    http_response_code(400);
    jsonResponse(['success' => false, 'error' => 'EAN is verplicht']);
}

apiTry(fn() => submitHandmatigeBestellingen(
    $ean,
    (int) ($body['aantal'] ?? 1),
    $user['naam'],
    $body['kanaal'] ?? '',
    $body['opmerking'] ?? '',
    $user['naam']
));
