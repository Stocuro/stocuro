<?php
/**
 * Detail/bewerking van één reguliere order (migratie 027, op verzoek van
 * Harm: orders getoond op Actiecentrum/Overzicht/Open Orders moeten
 * klikbaar zijn naar een detailpagina met bewerkmogelijkheid) -- zelfde
 * scherm-recht als Open Orders zelf, want dit IS onderdeel van dat
 * scherm, alleen dieper.
 *
 * GET ?id=N   -> getOrderDetail()
 * GET ?ean=X  -> detail van de huidige open order voor dit EAN (voor
 *                links vanuit een plek die alleen het EAN kent, bijv. de
 *                orderstatus-badge op Overzicht) -- {error:...} als er
 *                geen open order voor dit EAN is.
 * POST { actie: 'bewerken', id, besteld, ontvangen, besteller, kanaal, opmerking }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/orders.php';

$user = apiRequireLogin();
apiRequireScherm('open-orders');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    apiRequireWriteAccess();
    $body = readJsonBody();
    apiTry(fn() => updateOrder((int) ($body['id'] ?? 0), $body, $user['naam']));
} elseif (isset($_GET['ean'])) {
    apiTry(function () {
        $id = getOpenOrderIdVoorEan((string) $_GET['ean']);
        if ($id === null) return ['error' => 'Geen openstaande order voor dit product'];
        return getOrderDetail($id);
    });
} else {
    apiTry(fn() => getOrderDetail((int) ($_GET['id'] ?? 0)));
}
