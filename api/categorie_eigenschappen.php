<?php
/**
 * Migratie 028: beheerde categorielijst + categorie-specifieke
 * eigenschappen. Zie includes/categorieen.php.
 *
 * Let op de bestandsnaam: dit is NIET hetzelfde endpoint als
 * api/categorieen.php (dat blijft het bestaande Categorieën-dashboard,
 * getCategorieOverzicht() -- voorraad/waarde per categorie). Dit hier is
 * het nieuwe stamgegevens-beheer voor de categorielijst zelf en de
 * eigenschappen erbinnen.
 *
 * GET  -> volledige lijst (incl. inactieve, voor het Stamgegevens-beheer-
 *         scherm en de keuzelijst bij Artikel Toevoegen) met eigenschappen
 *         per categorie.
 * POST { actie: 'toevoegen', naam }
 *      { actie: 'wisselen', id, actief }
 *      { actie: 'spec_toevoegen', categorie_id, label }
 *      { actie: 'spec_verwijderen', id }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/categorieen.php';

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
            return setCategorieActief((int) ($body['id'] ?? 0), (bool) ($body['actief'] ?? false), $user['naam']);
        }
        if ($actie === 'spec_toevoegen') {
            return addCategorieSpec((int) ($body['categorie_id'] ?? 0), (string) ($body['label'] ?? ''), $user['naam']);
        }
        if ($actie === 'spec_verwijderen') {
            return removeCategorieSpec((int) ($body['id'] ?? 0), $user['naam']);
        }
        return addCategorie((string) ($body['naam'] ?? ''), $user['naam']);
    });
} else {
    apiTry(fn() => getCategorieenMetSpecs());
}
