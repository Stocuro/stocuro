<?php
/**
 * Groepen (migratie 024, op verzoek van Harm): vrij aanmaakbare
 * permissiegroepen naast de bestaande rol ('specialist'/'laborant'/
 * 'bekijker', migratie 019 -- die blijft ONGEWIJZIGD bestaan voor zijn
 * klinische betekenis, zie de uitgebreide toelichting bovenaan
 * sql/migrations/024_groepen.sql). Een groep bepaalt: welke schermen
 * (includes/schermen.php) de leden te zien krijgen, en of ze mogen
 * schrijven. Een gebruiker kan bij meerdere groepen horen -- de
 * effectieve rechten zijn de unie van alle groepen waar hij/zij bij
 * hoort.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/schermen.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/audit.php';

/** Overzicht van alle groepen, met aantal leden en aantal toegekende schermen (voor het Groepen-scherm). */
function getGroepen(): array {
    $rows = db()->query(
        'SELECT g.id, g.naam, g.mag_schrijven, g.landing_scherm,
                (SELECT COUNT(*) FROM gebruiker_groepen gg WHERE gg.groep_id = g.id) AS aantal_leden,
                (SELECT COUNT(*) FROM groep_schermen gs WHERE gs.groep_id = g.id) AS aantal_schermen
         FROM groepen g ORDER BY g.naam'
    )->fetchAll();

    return array_map(fn($r) => [
        'id' => (int) $r['id'], 'naam' => $r['naam'], 'magSchrijven' => (bool) $r['mag_schrijven'],
        'landingScherm' => $r['landing_scherm'],
        'aantalLeden' => (int) $r['aantal_leden'], 'aantalSchermen' => (int) $r['aantal_schermen'],
    ], $rows);
}

/** De volledige configureerbare schermenlijst, gegroepeerd per sectie -- gedeeld tussen getGroepDetail() en getGroepTemplate(). */
function schermenPerSectie(): array {
    $schermenPerSectie = [];
    foreach (configureerbareSchermen() as $key => $s) {
        $schermenPerSectie[$s['sectie']][] = ['key' => $key, 'labelKey' => $s['labelKey']];
    }
    return $schermenPerSectie;
}

/**
 * Leeg sjabloon voor het "nieuwe groep aanmaken"-formulier -- geen
 * bestaande groep nodig, alleen de schermenlijst om de checkbox-matrix
 * mee te vullen (zie ook getGroepDetail() hieronder, dezelfde vorm).
 */
function getGroepTemplate(): array {
    return [
        'id' => null, 'naam' => '', 'magSchrijven' => false, 'landingScherm' => null,
        'schermen' => [], 'schermenPerSectie' => schermenPerSectie(), 'leden' => [],
    ];
}

/**
 * Volledig detail van één groep: naam, schrijftoegang, welke schermen
 * aangevinkt staan, plus (voor de checkbox-matrix) de VOLLEDIGE
 * configureerbare schermenlijst gegroepeerd per sectie, en de leden.
 */
function getGroepDetail(int $id): array {
    $stmt = db()->prepare('SELECT id, naam, mag_schrijven, landing_scherm FROM groepen WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $groep = $stmt->fetch();
    if (!$groep) return ['error' => 'Groep niet gevonden'];

    $stmt = db()->prepare('SELECT scherm_key FROM groep_schermen WHERE groep_id = :id');
    $stmt->execute([':id' => $id]);
    $huidigeSchermen = array_column($stmt->fetchAll(), 'scherm_key');

    $stmt = db()->prepare(
        'SELECT u.id, u.naam FROM gebruiker_groepen gg
         JOIN users u ON u.id = gg.gebruiker_id
         WHERE gg.groep_id = :id ORDER BY u.naam'
    );
    $stmt->execute([':id' => $id]);
    $leden = $stmt->fetchAll();

    return [
        'id' => (int) $groep['id'], 'naam' => $groep['naam'], 'magSchrijven' => (bool) $groep['mag_schrijven'],
        'landingScherm' => $groep['landing_scherm'],
        'schermen' => $huidigeSchermen, 'schermenPerSectie' => schermenPerSectie(),
        'leden' => array_map(fn($l) => ['id' => (int) $l['id'], 'naam' => $l['naam']], $leden),
    ];
}

/** Filtert een aangeleverde scherm-key-lijst tegen de configureerbare set -- weert onbekende sleutels EN de beheerder-only sleutels ('gebruikers'/'groepen') hard, ongeacht wat een verzoek meestuurt. */
function filterConfigureerbareSchermen(array $schermKeys): array {
    $toegestaan = array_keys(configureerbareSchermen());
    return array_values(array_intersect($toegestaan, array_map('strval', $schermKeys)));
}

/**
 * Landingsscherm mag alleen een scherm zijn dat de groep ook echt mag
 * zien (anders zou een lid van deze groep na inloggen op een scherm
 * belanden dat meteen weer "geen toegang" teruggeeft) -- dus altijd
 * valideren TEGEN de al-gefilterde scherm-keys van dezelfde opslagactie,
 * niet los. Lege/onbekende waarde wordt stilzwijgend NULL (= gewoon Home),
 * geen harde fout: dit is een gemaksinstelling, geen verplicht veld.
 */
function valideerLandingScherm(?string $landingScherm, array $gefilterdeSchermKeys): ?string {
    if ($landingScherm === null || $landingScherm === '') return null;
    return in_array($landingScherm, $gefilterdeSchermKeys, true) ? $landingScherm : null;
}

function addGroep(string $naam, bool $magSchrijven, array $schermKeys, ?string $landingScherm = null, ?string $gebruikerNaam = null): array {
    $naam = trim($naam);
    if ($naam === '') return ['success' => false, 'error' => 'Naam is verplicht'];

    try {
        $pdo = db();
        $gefilterd = filterConfigureerbareSchermen($schermKeys);
        $landingScherm = valideerLandingScherm($landingScherm, $gefilterd);

        $pdo->prepare('INSERT INTO groepen (naam, mag_schrijven, landing_scherm) VALUES (:naam, :schrijven, :landing)')
            ->execute([':naam' => $naam, ':schrijven' => $magSchrijven ? 1 : 0, ':landing' => $landingScherm]);
        $id = (int) $pdo->lastInsertId();

        if ($gefilterd) {
            $stmt = $pdo->prepare('INSERT INTO groep_schermen (groep_id, scherm_key) VALUES (:id, :key)');
            foreach ($gefilterd as $key) $stmt->execute([':id' => $id, ':key' => $key]);
        }

        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'groep_aangemaakt', 'groep', (string) $id, "naam=$naam");
        }
        return ['success' => true, 'id' => $id, 'message' => 'Groep aangemaakt'];
    } catch (Throwable $e) {
        logError('GROEP_AANMAKEN_ERROR', (string) $naam, $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function updateGroep(int $id, string $naam, bool $magSchrijven, array $schermKeys, ?string $landingScherm = null, ?string $gebruikerNaam = null): array {
    $naam = trim($naam);
    if ($naam === '') return ['success' => false, 'error' => 'Naam is verplicht'];

    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id FROM groepen WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) return ['success' => false, 'error' => 'Groep niet gevonden'];

        $gefilterd = filterConfigureerbareSchermen($schermKeys);
        $landingScherm = valideerLandingScherm($landingScherm, $gefilterd);

        $pdo->prepare('UPDATE groepen SET naam = :naam, mag_schrijven = :schrijven, landing_scherm = :landing WHERE id = :id')
            ->execute([':naam' => $naam, ':schrijven' => $magSchrijven ? 1 : 0, ':landing' => $landingScherm, ':id' => $id]);

        // Schermenlijst vervangen: simpeler en minder foutgevoelig dan
        // een diff (toevoegen/verwijderen apart bijhouden) voor een
        // checkbox-matrix die toch in zijn geheel wordt opgestuurd.
        $pdo->prepare('DELETE FROM groep_schermen WHERE groep_id = :id')->execute([':id' => $id]);
        if ($gefilterd) {
            $insertStmt = $pdo->prepare('INSERT INTO groep_schermen (groep_id, scherm_key) VALUES (:id, :key)');
            foreach ($gefilterd as $key) $insertStmt->execute([':id' => $id, ':key' => $key]);
        }

        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'groep_bewerkt', 'groep', (string) $id, "naam=$naam");
        }
        return ['success' => true, 'message' => 'Groep opgeslagen'];
    } catch (Throwable $e) {
        logError('GROEP_BEWERKEN_ERROR', (string) $id, $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/** Verwijdert een groep (cascade: leden verliezen dit lidmaatschap, geen los blokkade-check -- de frontend vraagt vooraf bevestiging en toont het ledenaantal). */
function deleteGroep(int $id, ?string $gebruikerNaam = null): array {
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT naam FROM groepen WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $groep = $stmt->fetch();
        if (!$groep) return ['success' => false, 'error' => 'Groep niet gevonden'];

        $pdo->prepare('DELETE FROM groepen WHERE id = :id')->execute([':id' => $id]);

        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'groep_verwijderd', 'groep', (string) $id, "naam={$groep['naam']}");
        }
        return ['success' => true, 'message' => 'Groep verwijderd'];
    } catch (Throwable $e) {
        logError('GROEP_VERWIJDEREN_ERROR', (string) $id, $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Alle groep-id's waar een gebruiker bij hoort (voor het Gebruikers-
 * scherm: welke checkboxes al aan moeten staan bij het bewerken).
 */
function gebruikerGroepIds(int $gebruikerId): array {
    $stmt = db()->prepare('SELECT groep_id FROM gebruiker_groepen WHERE gebruiker_id = :id');
    $stmt->execute([':id' => $gebruikerId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'groep_id'));
}

/**
 * Vervangt het volledige groepslidmaatschap van een gebruiker in één
 * keer (aangeroepen vanuit api/gebruikers.php bij aanmaken/bewerken).
 * Onbekende groep-id's worden stilzwijgend genegeerd (FK-constraint zou
 * ze anders alsnog laten falen); geen validatie nodig op wie welke groep
 * mag toekennen -- dat gebeurt al op een hoger niveau (api/gebruikers.php
 * is zelf al volledig beheerder-only).
 */
function setGebruikerGroepen(int $gebruikerId, array $groepIds, ?string $gebruikerNaam = null): void {
    $pdo = db();
    $pdo->prepare('DELETE FROM gebruiker_groepen WHERE gebruiker_id = :id')->execute([':id' => $gebruikerId]);

    $geldigeIds = array_column(db()->query('SELECT id FROM groepen')->fetchAll(), 'id');
    $toeTeKennen = array_values(array_intersect(array_map('intval', $geldigeIds), array_map('intval', $groepIds)));
    if ($toeTeKennen) {
        $stmt = $pdo->prepare('INSERT INTO gebruiker_groepen (gebruiker_id, groep_id) VALUES (:gid, :grid)');
        foreach ($toeTeKennen as $groepId) $stmt->execute([':gid' => $gebruikerId, ':grid' => $groepId]);
    }
}

/**
 * Unie van alle scherm-keys die een gebruiker mag zien, over al zijn/haar
 * groepen heen. is_beheerder overstijgt alles (ziet ook 'gebruikers'/
 * 'groepen', de twee beheerder-only schermen die nooit via een groep
 * toegekend worden -- zie includes/schermen.php). Een gast (geen echt
 * account, $gebruikerId === null) krijgt dezelfde volledige
 * configureerbare lijst als vóór migratie 024 (canViewAdminScreens was
 * toen ook al gast-vriendelijk voor de vier Beheer-schermen) -- dit
 * systeem regelt alleen de rechten van ECHTE accounts, gast-gedrag blijft
 * bewust ongewijzigd.
 */
function gebruikerSchermen(?int $gebruikerId, bool $isBeheerder, bool $isGast = false): array {
    if ($isBeheerder) return array_keys(SCHERMEN);
    if ($isGast || $gebruikerId === null) return array_keys(configureerbareSchermen());

    $stmt = db()->prepare(
        'SELECT DISTINCT gs.scherm_key FROM gebruiker_groepen gg
         JOIN groep_schermen gs ON gs.groep_id = gg.groep_id
         WHERE gg.gebruiker_id = :id'
    );
    $stmt->execute([':id' => $gebruikerId]);
    return array_column($stmt->fetchAll(), 'scherm_key');
}

/** Mag deze gebruiker schrijven? is_beheerder overstijgt alles; anders: lid van minstens één groep met mag_schrijven=1. */
function gebruikerMagSchrijvenViaGroep(int $gebruikerId): bool {
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS aantal FROM gebruiker_groepen gg
         JOIN groepen g ON g.id = gg.groep_id
         WHERE gg.gebruiker_id = :id AND g.mag_schrijven = 1'
    );
    $stmt->execute([':id' => $gebruikerId]);
    return (int) $stmt->fetch()['aantal'] > 0;
}

/**
 * Migratie 030_groep_landingsscherm, op verzoek van Harm: rolgebonden
 * landingspagina's -- welk scherm moet deze gebruiker zien direct na
 * inloggen, in plaats van altijd de volledige menupagina (showHome())?
 *
 * Alleen toegepast als het ONDUBBELZINNIG is: een gebruiker kan bij
 * meerdere groepen horen, en als die groepen verschillende landing-
 * schermen instellen (of sommige geen) is er geen eenduidig antwoord --
 * dan valt dit terug op NULL (= gewoon Home), in plaats van willekeurig
 * de ene groep boven de andere te laten winnen. Alleen als ALLE groepen
 * van deze gebruiker met een landing_scherm het over exact hetzelfde
 * scherm eens zijn, wordt dat gebruikt.
 *
 * is_beheerder en gast krijgen altijd NULL (blijven op Home) -- een
 * beheerder overziet per definitie alles en hoort niet vast te zitten
 * aan één persona-scherm, en een gast heeft sowieso geen groepslidmaatschap.
 */
function gebruikerLandingScherm(?int $gebruikerId, bool $isBeheerder, bool $isGast = false): ?string {
    if ($isBeheerder || $isGast || $gebruikerId === null) return null;

    $stmt = db()->prepare(
        'SELECT DISTINCT g.landing_scherm FROM gebruiker_groepen gg
         JOIN groepen g ON g.id = gg.groep_id
         WHERE gg.gebruiker_id = :id AND g.landing_scherm IS NOT NULL'
    );
    $stmt->execute([':id' => $gebruikerId]);
    $waardes = array_column($stmt->fetchAll(), 'landing_scherm');
    if (count($waardes) !== 1) return null;

    $kandidaat = $waardes[0];
    // Vangnet: als het scherm-recht ondertussen bij deze groep is
    // ingetrokken (groep_schermen bijgewerkt zonder landing_scherm mee te
    // wissen) landt de gebruiker niet op een scherm dat meteen "geen
    // toegang" teruggeeft, maar gewoon op Home.
    return in_array($kandidaat, gebruikerSchermen($gebruikerId, false, false), true) ? $kandidaat : null;
}
