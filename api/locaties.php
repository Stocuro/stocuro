<?php
/**
 * Locatie-instellingen: de vier vaste slots (migratie 005) + een
 * onbeperkt aantal extra locaties (migratie 007). Iedereen ingelogd mag
 * de lijst opvragen (nodig voor scanformulieren); alleen beheerder mag
 * namen wijzigen, een locatie uitzetten of een nieuwe toevoegen.
 *
 * GET  -> vaste slots + extra locaties, samengevoegd tot één vlakke
 *         lijst met hetzelfde vorm ({slot, naam, volgorde, actief}) --
 *         extra locaties krijgen een gesynthetiseerd "slot"-veld
 *         ('extra_<id>') zodat de bestaande frontend-helpers
 *         (actieveLocatieNamen() e.d. in app.js, die simpelweg op
 *         naam/volgorde/actief filteren) niets hoeven te weten van het
 *         onderscheid tussen vast en extra.
 * POST -> { actie: 'toevoegen', naam }              nieuwe extra locatie
 *      -> { slot: 'extra_<id>', naam, actief }       extra locatie bewerken
 *      -> { slot: 'ik'|'hybok'|'kar'|'berging', naam, actief }  vaste slot bewerken
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/locaties.php';

$user = apiRequireLogin();

function alleLocatiesSamengevoegd(): array {
    $rijen = getLocatieInstellingen();
    foreach (getLocatiesExtra() as $r) {
        $rijen[] = ['slot' => 'extra_' . $r['id'], 'naam' => $r['naam'], 'volgorde' => $r['volgorde'], 'actief' => $r['actief']];
    }
    return $rijen;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // apiRequireWriteAccess() eerst: geeft een gast/alleen-lezen-account
    // de duidelijke "alleen-lezen demo"-melding i.p.v. de generieke
    // "niet ingelogd"/"onvoldoende rechten" die apiRequireRole() alleen
    // zou geven. Voor een echte medewerker (mag wel schrijven, geen
    // beheerder) verandert er niets: die komt hier voorbij en wordt
    // daarna alsnog door apiRequireRole('beheerder') tegengehouden.
    apiRequireWriteAccess();
    apiRequireBeheerder();
    $body = readJsonBody();
    $actie = (string) ($body['actie'] ?? '');
    $slot = (string) ($body['slot'] ?? '');

    if ($actie === 'toevoegen') {
        apiTry(fn() => voegLocatieToe((string) ($body['naam'] ?? ''), $user['naam']));
    } elseif (str_starts_with($slot, 'extra_')) {
        $id = (int) substr($slot, strlen('extra_'));
        apiTry(fn() => updateLocatieExtra($id, (string) ($body['naam'] ?? ''), (bool) ($body['actief'] ?? true), $user['naam']));
    } else {
        apiTry(fn() => updateLocatieInstelling($slot, (string) ($body['naam'] ?? ''), (bool) ($body['actief'] ?? true), $user['naam']));
    }
} else {
    apiTry(fn() => alleLocatiesSamengevoegd());
}
