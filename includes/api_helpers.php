<?php
/**
 * Kleine helpers die elk bestand in /api gebruikt. Geen bedrijfslogica
 * hier, puur HTTP-plumbing -- houdt de losse api/*.php-bestanden dun,
 * zoals bedoeld in het migratieplan (hoofdstuk 5: "dunne controllers").
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

function jsonResponse(mixed $data): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Body van een POST-request met Content-Type: application/json als
 * assoc array. De frontend-shim (assets/js/app.js) stuurt altijd
 * JSON, dus dit is de enige vorm die hier ondersteund hoeft te worden.
 */
function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Elk endpoint (behalve login.php) begint met apiRequireLogin(), zoals
 * vastgelegd in migratieplan hoofdstuk 9.1/9.4: al vanaf fase 1 in elk
 * endpoint, ook met nog maar één gebruiker.
 *
 * Gast-modus: dit roept bewust requireLoginOfGast() aan, niet het
 * strikte requireLogin() uit includes/auth.php -- dat maakt ELK
 * endpoint dat alleen leest automatisch bruikbaar zonder in te loggen
 * (alle dashboards/formulieren/beheerschermen
 * bekijkbaar zonder account, voor demonstratiedoeleinden). Endpoints die
 * ook schrijven moeten daarom, vlak vóór de daadwerkelijke schrijfactie,
 * ALTIJD ook apiRequireWriteAccess() aanroepen -- dat is de enige plek
 * die een gast (of een 'alleen-lezen'-account) daadwerkelijk tegenhoudt.
 * apiRequireRole() blijft ongewijzigd strikt (roept requireRole() aan,
 * dat zelf nog steeds het strikte requireLogin() gebruikt), dus
 * beheerder-only endpoints zijn hier niet door geraakt.
 */
function apiRequireLogin(): array {
    return requireLoginOfGast();
}

function apiRequireRole(string $rol): array {
    return requireRole($rol);
}

/**
 * Vervangt apiRequireRole('beheerder') overal -- "Beheerder"
 * is nu een los vinkje (users.is_beheerder), geen functie/rol-waarde meer,
 * zie includes/auth.php::requireBeheerder().
 */
function apiRequireBeheerder(): array {
    return requireBeheerder();
}

/**
 * Aanroepen vlak vóór elke daadwerkelijke schrijfactie in een endpoint
 * dat geen apart beheerdersrecht heeft (scannen, bestellen, artikel
 * toevoegen/bewerken, min/max-advies toepassen, eenmalige bestellingen,
 * telling, transactielog-correcties, ...). Geeft 403 voor een gast of
 * een 'alleen-lezen'-account, anders het echte gebruikersarray -- zie
 * requireSchrijfToegang() in includes/auth.php voor de volledige
 * toelichting.
 */
function apiRequireWriteAccess(): array {
    return requireSchrijfToegang(requireLoginOfGast());
}

/**
 * Aanroepen vlak na apiRequireLogin() in
 * elk endpoint dat bij een specifiek scherm hoort (zie includes/
 * schermen.php voor de volledige lijst scherm-keys). Geeft een 403 als
 * geen van de groepen van deze gebruiker (of, voor een gast, de
 * standaard gast-schermenlijst) dit scherm bevat -- dit is de server-
 * side afdwinging die hoort bij het menu-filter in showHome()
 * (assets/js/app.js): een scherm dat niet in het menu staat is ook
 * daadwerkelijk niet opvraagbaar via een directe aanroep.
 *
 * $schermKey moet een bestaande sleutel uit includes/schermen.php::
 * SCHERMEN zijn; een is_beheerder-account komt hier altijd doorheen
 * (gebruikerSchermen() geeft die dan de volledige lijst terug).
 */
function apiRequireScherm(string $schermKey): array {
    $user = requireLoginOfGast();
    $isGast = ($user['rol'] ?? '') === ROL_GAST;
    $schermen = gebruikerSchermen($isGast ? null : (int) ($user['id'] ?? 0), (bool) ($user['is_beheerder'] ?? false), $isGast);
    if (!in_array($schermKey, $schermen, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Geen toegang tot dit scherm -- vraag een beheerder om je groep aan te passen.']);
        exit;
    }
    return $user;
}

/**
 * Vangt onverwachte fouten in een endpoint af en geeft een nette
 * JSON-foutmelding terug i.p.v. een kale PHP-fatal. $fn krijgt geen
 * argumenten en moet een array (of iets json_encode-baars) teruggeven.
 */
function apiTry(callable $fn): never {
    try {
        jsonResponse($fn());
    } catch (Throwable $e) {
        http_response_code(500);
        jsonResponse(['error' => $e->getMessage()]);
    }
}
