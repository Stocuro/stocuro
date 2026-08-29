<?php
/**
 * Admin-scherm "Onbekende Producten" (migratie 022): overzicht van
 * gescande barcodes die nog niet in artikelen bestaan, met de
 * omschrijving die de scanner ervoor heeft ingevuld.
 *
 * Zelfde rolniveau als Foutlog (GET, gewoon ingelogd) en Artikel
 * Toevoegen (POST, schrijftoegang maar geen apart beheerdersrecht) --
 * "afronden" hier is letterlijk hetzelfde als een nieuw artikel
 * aanmaken, dus geen strenger niveau dan dat.
 *
 * GET  -> lijst open meldingen, gegroepeerd per EAN
 * POST { ean }  -> markeert als afgehandeld (aangeroepen door de
 *        frontend meteen na een succesvolle artikel-aanmaak)
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/onbekende_scans.php';

apiRequireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    apiRequireWriteAccess();
    $body = readJsonBody();
    $ean = (string) ($body['ean'] ?? '');
    if ($ean === '') {
        http_response_code(400);
        jsonResponse(['error' => 'EAN is verplicht']);
    }
    apiTry(fn() => markeerOnbekendeScanVerwerkt($ean));
} else {
    apiRequireScherm('onbekende-producten');
    apiTry(fn() => getOpenOnbekendeScans());
}
