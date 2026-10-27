<?php
/**
 * Keuzelijst procedure-typen.
 *
 * GET  -> lijst procedure-typen
 * POST -> { actie: 'toevoegen', naam } of { actie: 'wisselen', id, actief }
 *      -> { actie: 'laborant_wisselen', id, laborant_toegestaan } --
 *         mag een Laborant deze procedure ook zelf
 *         uitvoeren (in de praktijk nu alleen PICC-lijn), zie
 *         includes/transacties.php::verwerkScan().
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/stamgegevens.php';

apiRequireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Zie api/locaties.php voor waarom deze volgorde: geeft gast/
    // bekijker de duidelijke "alleen-lezen demo"-melding.
    apiRequireWriteAccess();
    $user = apiRequireBeheerder();
    $body = readJsonBody();
    $actie = $body['actie'] ?? 'toevoegen';

    apiTry(function () use ($actie, $body, $user) {
        if ($actie === 'wisselen') {
            return setProcedureTypeActief((int) ($body['id'] ?? 0), (bool) ($body['actief'] ?? false), $user['naam']);
        }
        if ($actie === 'laborant_wisselen') {
            return setProcedureTypeLaborantToegestaan((int) ($body['id'] ?? 0), (bool) ($body['laborant_toegestaan'] ?? false), $user['naam']);
        }
        return addProcedureType($body['naam'] ?? '', $user['naam']);
    });
} else {
    apiTry(fn() => getProcedureTypen());
}
