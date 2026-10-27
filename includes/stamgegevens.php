<?php
/**
 * Beheerde keuzelijst voor procedure-typen, plus (migratie
 * 019) de samengevoegde specialisten/laboranten-lijst die nu rechtstreeks
 * uit 'users' komt.
 *
 * De aparte
 * 'specialisten'-tabel (een naamlijst los van een inlogaccount) is
 * samengevoegd met 'users' -- iedereen die met het systeem werkt logt
 * zelf in, dus is een specialist/laborant nu altijd een users-rij. Deze
 * functie heet nog steeds getSpecialisten() en geeft nog steeds dezelfde
 * vorm terug (id, naam, type, email, actief) zodat alle bestaande
 * aanroepers (Scan Usage, Eenmalige Bestelling, Gebruikers, Leaderboard)
 * ongewijzigd kunnen blijven -- alleen de bron is nu 'users' i.p.v. de
 * oude 'specialisten'-tabel, en 'email' is nu altijd het echte
 * inlog-e-mailadres (voorheen een los, vaak leeg contactveld).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';

function getSpecialisten(): array {
    // Licentie_geblokkeerd meegestuurd ("Licentiemodel:
    // geen naam-registratie zonder licentie"): de frontend gebruikt dit om
    // een specialist/laborant zonder geldige licentie niet-selecteerbaar te
    // tonen bij Materiaal Verbruik en Eenmalige Bestelling, met een
    // duidelijke "geen geldige licentie"-melding -- zie
    // includes/license.php::controleerLicentieGebruiker() voor hoe deze
    // cache-kolom wordt bijgehouden. De daadwerkelijke afdwinging gebeurt
    // hoe dan ook server-side in includes/transacties.php::verwerkScan() en
    // includes/eenmalige_bestellingen.php; dit is puur zodat de gebruiker
    // niet eerst iets hoeft in te dienen om die weigering te zien.
    // 'foto' meegestuurd ("Leaderboard
    // met wie aan de beurt is uitbreiden met foto") -- getPiccRotatie()
    // hieronder in includes/leaderboard.php geeft dit door aan de
    // Home-widget/Rapportage-weergave (zie avatarHtml() in assets/js/app.js).
    return db()->query(
        "SELECT id, naam, rol AS type, email, actief, licentie_geblokkeerd, foto FROM users WHERE rol IN ('specialist', 'laborant') ORDER BY naam"
    )->fetchAll();
}

/**
 * Alle actieve gebruikersnamen (elke rol, dus ook beheerder/bekijker) --
 * De "Besteld door"-velden (bestel-
 * modal, order-detail, eenmalige-bestelling-bewerken) moeten een dropdown
 * van bekende systeemgebruikers zijn i.p.v. vrije tekst, en "wie plaatst
 * een order" is niet beperkt tot specialist/laborant (zie getSpecialisten()
 * hierboven, die is voor de klinische velden). Bewust alleen id+naam --
 * geen e-mail/rol/etc. -- dit is puur een naamkeuzelijst, en namen staan
 * toch al overal in de app zichtbaar (gescand door, leaderboard,
 * audit-log).
 */
function getActieveGebruikersNamen(): array {
    return db()->query(
        "SELECT id, naam FROM users WHERE actief = 1 ORDER BY naam"
    )->fetchAll();
}

/**
 * Oude 'specialisten'-rijen die nog geen gekoppeld account hebben (op
 * naam gematcht, geen FK meer sinds de koppelkolom is verwijderd) --
 * getoond op het Gebruikers-scherm onder "Nog te
 * migreren" zodat de beheerder ze eenvoudig alsnog een echt account kan
 * geven. Er komen geen nieuwe rijen meer bij in 'specialisten'; dit is
 * puur de resterende, aflopende lijst van vóór de samenvoeging.
 */
function getNietGemigreerdeSpecialisten(): array {
    return db()->query(
        "SELECT s.id, s.naam, s.type FROM specialisten s
         WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.naam = s.naam)
         ORDER BY s.naam"
    )->fetchAll();
}

function getProcedureTypen(): array {
    return db()->query('SELECT id, naam, actief, laborant_toegestaan FROM procedure_typen ORDER BY naam')->fetchAll();
}

function addProcedureType(string $naam, ?string $gebruikerNaam = null): array {
    $naam = trim($naam);
    if ($naam === '') return ['success' => false, 'error' => 'Naam is verplicht'];

    try {
        db()->prepare('INSERT INTO procedure_typen (naam) VALUES (:naam)')->execute([':naam' => $naam]);
        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'procedure_type_toegevoegd', 'procedure_type', $naam, null);
        }
        return ['success' => true];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Kon niet toevoegen (bestaat deze naam al?)'];
    }
}

function setProcedureTypeActief(int $id, bool $actief, ?string $gebruikerNaam = null): array {
    db()->prepare('UPDATE procedure_typen SET actief = :a WHERE id = :id')
        ->execute([':a' => $actief ? 1 : 0, ':id' => $id]);
    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'procedure_type_status_gewijzigd', 'procedure_type', (string) $id, 'actief=' . ($actief ? '1' : '0'));
    }
    return ['success' => true];
}

/**
 * Mag een Laborant deze procedure ook zelf uitvoeren (in de
 * praktijk nu alleen PICC-lijn) -- zie includes/transacties.php::
 * verwerkScan() voor de daadwerkelijke afdwinging bij Scan Usage.
 */
function setProcedureTypeLaborantToegestaan(int $id, bool $toegestaan, ?string $gebruikerNaam = null): array {
    db()->prepare('UPDATE procedure_typen SET laborant_toegestaan = :t WHERE id = :id')
        ->execute([':t' => $toegestaan ? 1 : 0, ':id' => $id]);
    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'procedure_type_laborant_gewijzigd', 'procedure_type', (string) $id, 'laborant_toegestaan=' . ($toegestaan ? '1' : '0'));
    }
    return ['success' => true];
}
