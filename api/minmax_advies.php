<?php
/**
 * Min/Max Advies (zie includes/minmax.php).
 *
 * GET  -> volledig advies-overzicht (getMinMaxAdviesOverzicht()) -- toont
 *         alleen nog rijen die daadwerkelijk iets voorstellen (migratie
 *         026: geen no-op- of recent genegeerde rijen meer, zie
 *         includes/minmax.php voor de volledige toelichting).
 * POST { actie: 'negeren', ean, locatie } -> negeerMinMaxAdvies(): dit
 *         voorstel verdwijnt van het overzicht tot MINMAX_NEGEER_MAANDEN
 *         later.
 * POST { ean, locatie, min, max } (geen 'actie', of actie:'toepassen')
 *         -> pas het advies van één rij toe op het echte min/max-veld
 *         (applyMinMaxAdvies()). Zelfde toegang als een handmatige
 *         productbewerking (product_edit.php): gewoon ingelogd, geen
 *         apart beheerdersrecht.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/minmax.php';

$user = apiRequireLogin();
apiRequireScherm('minmax-advies');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // GET (lezen) is hierboven al gastvriendelijk via apiRequireLogin();
    // schrijven (dit blok) blijft alleen voor echt ingelogde, niet-
    // alleen-lezen accounts.
    apiRequireWriteAccess();
    $body = readJsonBody();
    $ean = (string) ($body['ean'] ?? '');
    $locatie = (string) ($body['locatie'] ?? '');

    if (($body['actie'] ?? '') === 'negeren') {
        apiTry(fn() => negeerMinMaxAdvies($ean, $locatie));
    } else {
        $min = (int) ($body['min'] ?? 0);
        $max = (int) ($body['max'] ?? 0);
        apiTry(fn() => applyMinMaxAdvies($ean, $locatie, $min, $max, $user['naam']));
    }
} else {
    apiTry(fn() => getMinMaxAdviesOverzicht());
}
