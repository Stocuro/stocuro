<?php
/**
 * Producenten-contactgegevens (migratie 012). Iedereen die ingelogd is
 * mag opvragen; alleen beheerder mag toevoegen/bewerken/verwijderen --
 * zelfde rolverdeling als api/specialisten.php.
 *
 * GET  ?id=<id>  -> getProducentDetail() (detailpagina + contacten + gekoppelde artikelen)
 * GET  (geen id) -> getProducenten() (overzichtslijst)
 * POST -> { actie: 'toevoegen' | 'bewerken' | 'verwijderen', ... }
 *         { actie: 'contact_toevoegen' | 'contact_bewerken' | 'contact_verwijderen', ... }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/producenten.php';

apiRequireLogin();
apiRequireScherm('producenten');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Zie api/locaties.php voor waarom deze volgorde: geeft gast/
    // alleen-lezen de duidelijke "alleen-lezen demo"-melding.
    apiRequireWriteAccess();
    $user = apiRequireBeheerder();
    $body = readJsonBody();
    $actie = $body['actie'] ?? 'toevoegen';

    apiTry(function () use ($actie, $body, $user) {
        return match ($actie) {
            'toevoegen' => addProducent($body, $user['naam']),
            'bewerken' => updateProducent((int) ($body['id'] ?? 0), $body, $user['naam']),
            'verwijderen' => deleteProducent((int) ($body['id'] ?? 0), $user['naam']),
            'contact_toevoegen' => addProducentContact((int) ($body['producent_id'] ?? 0), $body, $user['naam']),
            'contact_bewerken' => updateProducentContact((int) ($body['id'] ?? 0), $body, $user['naam']),
            'contact_verwijderen' => deleteProducentContact((int) ($body['id'] ?? 0), $user['naam']),
            default => ['success' => false, 'error' => 'Onbekende actie'],
        };
    });
} else {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    apiTry(fn() => $id > 0 ? getProducentDetail($id) : getProducenten());
}
