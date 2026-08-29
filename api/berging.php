<?php
/**
 * Berging-bestellijst. GET blijft zoals het was (lezen, gastvriendelijk).
 * Migratie 025, op verzoek van Harm: POST { actie: 'markeer_besteld' } --
 * markeert de HUIDIGE lijst als besteld (apart van printen/exporteren,
 * zie renderBerging() in assets/js/app.js), dus apiRequireWriteAccess()
 * hier net als elk ander schrijf-endpoint.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/voorraad.php';

apiRequireLogin();
apiRequireScherm('berging');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = apiRequireWriteAccess();
    $body = readJsonBody();
    if (($body['actie'] ?? '') === 'markeer_besteld') {
        apiTry(fn() => markeerBergingAlsBesteld($user['naam']));
    } else {
        http_response_code(400);
        jsonResponse(['error' => 'Onbekende actie']);
    }
} else {
    apiTry(fn() => getBergingData());
}
