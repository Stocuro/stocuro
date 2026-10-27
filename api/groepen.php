<?php
/**
 * Groepenbeheer : permissiegroepen
 * aanmaken/bewerken/verwijderen, elk met een eigen schrijftoegang-vlag en
 * een checkbox-matrix van schermen. Volledig beheerder-only, GET en POST
 * allebei -- dit is zelf een van de twee schermen die nooit via een groep
 * toe te kennen zijn (zie includes/schermen.php), dus consistent dat het
 * beheren ervan ook altijd is_beheerder vereist.
 *
 * GET  ?id=<id> -> getGroepDetail() (naam, schrijftoegang, aangevinkte
 *         schermen, volledige schermenlijst per sectie, leden)
 * GET  ?nieuw=1  -> getGroepTemplate() (leeg sjabloon, voor het "nieuwe
 *         groep aanmaken"-formulier -- alleen de schermenlijst, nog geen
 *         bestaande groep)
 * GET  (geen van beide) -> getGroepen() (overzichtslijst)
 * POST -> { actie: 'aanmaken', naam, mag_schrijven, schermen: [...], landing_scherm }
 *      -> { actie: 'bewerken', id, naam, mag_schrijven, schermen: [...], landing_scherm }
 *      -> { actie: 'verwijderen', id }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/groepen.php';

$beheerder = apiRequireBeheerder();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    $actie = (string) ($body['actie'] ?? 'aanmaken');
    $naam = (string) ($body['naam'] ?? '');
    $magSchrijven = !empty($body['mag_schrijven']);
    $schermen = is_array($body['schermen'] ?? null) ? $body['schermen'] : [];
    // Optioneel veld, leeg/afwezig = NULL
    // (groepen.php::valideerLandingScherm() zet dit verder recht als de
    // waarde geen geldig, aan deze groep toegekend scherm is).
    $landingScherm = isset($body['landing_scherm']) && $body['landing_scherm'] !== ''
        ? (string) $body['landing_scherm'] : null;

    if ($actie === 'verwijderen') {
        apiTry(fn() => deleteGroep((int) ($body['id'] ?? 0), $beheerder['naam']));
    } elseif ($actie === 'bewerken') {
        apiTry(fn() => updateGroep((int) ($body['id'] ?? 0), $naam, $magSchrijven, $schermen, $landingScherm, $beheerder['naam']));
    } else {
        apiTry(fn() => addGroep($naam, $magSchrijven, $schermen, $landingScherm, $beheerder['naam']));
    }
} else {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if (($_GET['nieuw'] ?? '') === '1') {
        apiTry(fn() => getGroepTemplate());
    } else {
        apiTry(fn() => $id > 0 ? getGroepDetail($id) : getGroepen());
    }
}
