<?php
/**
 * Configureerbare locatienamen + onbeperkt aantal extra
 * Locaties. Stocuro is generiek bedoeld -- niet elke
 * gebruiker heeft een "Interventiekamer" of "Hybride OK" -- dus de vier
 * vaste locatie-"slots" (ik/hybok/kar/berging) zijn herbenoembaar, en
 * daarnaast kunnen er willekeurig veel extra locaties worden toegevoegd
 * (tabel locaties_extra).
 *
 * De vier vaste slots behouden hun eigen kolommen op `artikelen`
 * (min_ik/max_ik/etc.) en hun eigen vaste gedrag: 'kar' is mobiel (geen
 * kastlocatie), 'berging' is de centrale aanvulbron (uitgesloten van de
 * boodschappenlijst als bestemming, wél de bron waarmee bijvultekorten
 * worden vergeleken). Dat gedrag verandert niet.
 *
 * Extra locaties gedragen zich altijd als een gewone algemene locatie
 * (zoals 'ik'/'hybok'): eigen min/max per artikel, meegenomen als
 * bestembestemming in de boodschappenlijst, nooit een aanvulbron. Hun
 * min/max staat niet als kolom op `artikelen` (dat zou een nieuwe kolom
 * per locatie vereisen, niet schaalbaar) maar in een aparte tabel
 * (artikel_locatie_extra), opgezocht via artikelLocatieExtraMinMax().
 *
 * Om bestaande code die met een simpele lijst van locatienamen werkt
 * (actieveLocatieNamen() e.d.) niet overal te hoeven aanpassen, worden
 * extra locaties daar gewoon aan toegevoegd na de vier vaste slots.
 * api/locaties.php geeft ze naar de frontend toe als rijen met hetzelfde
 * vorm als de vaste slots, met een gesynthetiseerd "slot"-veld
 * ('extra_<id>') zodat de bestaande frontend-helpers (die op
 * naam/volgorde/actief filteren) ongewijzigd kunnen blijven.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/hernoemingen.php';

function getLocatieInstellingen(): array {
    return db()->query('SELECT slot, naam, volgorde, actief, laatst_geteld_op FROM locatie_instellingen ORDER BY volgorde')->fetchAll();
}

/** Namen van actieve locaties (vaste slots + extra), in volgorde -- vervangt de oude LOCATIES-constante. */
function actieveLocatieNamen(): array {
    $namen = [];
    foreach (getLocatieInstellingen() as $r) {
        if ($r['actief']) $namen[] = $r['naam'];
    }
    foreach (getLocatiesExtra() as $r) {
        if ($r['actief']) $namen[] = $r['naam'];
    }
    return $namen;
}

/**
 * Actieve locatienamen exclusief de 'berging'-slot (die is de aanvulbron,
 * geen bestembestemming) -- vervangt LOCATIES_BESTELLING. Extra locaties
 * zijn altijd wél een bestembestemming (ze gedragen zich als een
 * algemene locatie, nooit als aanvulbron).
 */
function actieveLocatieNamenBestelling(): array {
    $namen = [];
    foreach (getLocatieInstellingen() as $r) {
        if ($r['actief'] && $r['slot'] !== 'berging') $namen[] = $r['naam'];
    }
    foreach (getLocatiesExtra() as $r) {
        if ($r['actief']) $namen[] = $r['naam'];
    }
    return $namen;
}

// ---------------------------------------------------------------------
// Extra locaties -- onbeperkt aantal, naast de vier vaste
// slots hierboven.
// ---------------------------------------------------------------------
function getLocatiesExtra(): array {
    return db()->query('SELECT id, naam, volgorde, actief, laatst_geteld_op FROM locaties_extra ORDER BY volgorde, naam')->fetchAll();
}

function voegLocatieToe(string $naam, ?string $gebruikerNaam = null): array {
    $naam = trim($naam);
    if ($naam === '') return ['success' => false, 'error' => 'Naam is verplicht'];

    foreach (getLocatieInstellingen() as $r) {
        if (strcasecmp($r['naam'], $naam) === 0) {
            return ['success' => false, 'error' => 'Er bestaat al een locatie met deze naam'];
        }
    }

    // Nieuwe locaties achteraan (hoogste bestaande volgorde + 1), zowel
    // t.o.v. de vaste slots als t.o.v. andere extra locaties.
    $maxVolgorde = 0;
    foreach (getLocatieInstellingen() as $r) $maxVolgorde = max($maxVolgorde, (int) $r['volgorde']);
    foreach (getLocatiesExtra() as $r) $maxVolgorde = max($maxVolgorde, (int) $r['volgorde']);

    try {
        db()->prepare('INSERT INTO locaties_extra (naam, volgorde) VALUES (:naam, :volgorde)')
            ->execute([':naam' => $naam, ':volgorde' => $maxVolgorde + 1]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            return ['success' => false, 'error' => 'Er bestaat al een locatie met deze naam'];
        }
        throw $e;
    }

    $nieuwId = (int) db()->lastInsertId();
    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'locatie_toegevoegd', 'locatie', (string) $nieuwId, "naam=$naam");
    }

    return ['success' => true, 'id' => $nieuwId];
}

function updateLocatieExtra(int $id, string $naam, bool $actief, ?string $gebruikerNaam = null): array {
    $naam = trim($naam);
    if ($naam === '') return ['success' => false, 'error' => 'Naam is verplicht'];

    // (locatie-hernoemen cascade-bug):
    // oude naam opzoeken VOORDAT die overschreven wordt, zodat de
    // hernoeming geregistreerd kan worden -- zie includes/hernoemingen.php.
    $stmtOud = db()->prepare('SELECT naam FROM locaties_extra WHERE id = :id');
    $stmtOud->execute([':id' => $id]);
    $oudeNaam = $stmtOud->fetchColumn();

    try {
        db()->prepare('UPDATE locaties_extra SET naam = :naam, actief = :actief WHERE id = :id')
            ->execute([':naam' => $naam, ':actief' => $actief ? 1 : 0, ':id' => $id]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            return ['success' => false, 'error' => 'Er bestaat al een locatie met deze naam'];
        }
        throw $e;
    }

    if ($oudeNaam !== false) {
        registreerHernoeming('locatie', (string) $oudeNaam, $naam);
    }

    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'locatie_gewijzigd', 'locatie', 'extra_' . $id, "naam=$naam, actief=" . ($actief ? '1' : '0'));
    }

    return ['success' => true];
}

/**
 * Min/max voor een artikel op een extra (niet-vaste) locatie, in-memory
 * gecached voor de rest van dit request. Voorkomt een N+1-queryprobleem:
 * dit wordt aangeroepen vanuit artikelMinMax() binnen een dubbele lus
 * over elk artikel x elke actieve locatie (voorraadStatus()).
 *
 * Extra locaties gedragen zich altijd
 * als een kamer (nooit als aanvulbron, zie de bestandskop hierboven) en
 * worden dus ook gegarandeerd dezelfde dag tot hun maximum bijgevuld --
 * hetzelfde argument als bij de vier vaste slots (zie artikelMinMax() in
 * includes/voorraad.php). artikel_locatie_extra.min_aantal is daarom
 * verwijderd; min is hier altijd 0 door constructie.
 */
function artikelLocatieExtraMinMax(string $ean, string $locatieNaam): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $rows = db()->query(
            'SELECT ale.ean, le.naam AS locatie_naam, ale.max_aantal
             FROM artikel_locatie_extra ale
             JOIN locaties_extra le ON le.id = ale.locatie_extra_id'
        )->fetchAll();
        foreach ($rows as $r) {
            $cache[$r['ean']][$r['locatie_naam']] = [0, (int) $r['max_aantal']];
        }
    }
    return $cache[$ean][$locatieNaam] ?? [0, 0];
}

function artikelLocatieExtraKastlocatie(string $ean, string $locatieNaam): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $rows = db()->query(
            'SELECT ale.ean, le.naam AS locatie_naam, ale.kastlocatie
             FROM artikel_locatie_extra ale
             JOIN locaties_extra le ON le.id = ale.locatie_extra_id'
        )->fetchAll();
        foreach ($rows as $r) {
            $cache[$r['ean']][$r['locatie_naam']] = (string) ($r['kastlocatie'] ?? '');
        }
    }
    return $cache[$ean][$locatieNaam] ?? '';
}

/**
 * Overzicht van alle actieve extra locaties voor één artikel, met
 * min/max (0 als er nog geen rij bestaat) -- gebruikt door
 * getArtikelEditData() om het generieke bewerk-formulier aan te vullen.
 */
function getArtikelLocatieExtraOverzicht(string $ean): array {
    $stmt = db()->prepare(
        'SELECT locatie_extra_id, max_aantal, kastlocatie FROM artikel_locatie_extra WHERE ean = :ean'
    );
    $stmt->execute([':ean' => $ean]);
    $bestaand = [];
    foreach ($stmt->fetchAll() as $r) $bestaand[(int) $r['locatie_extra_id']] = $r;

    $result = [];
    foreach (getLocatiesExtra() as $loc) {
        if (!$loc['actief']) continue;
        $b = $bestaand[(int) $loc['id']] ?? null;
        $result[] = [
            'locatie_extra_id' => (int) $loc['id'],
            'locatie_naam' => $loc['naam'],
            'max_aantal' => $b ? (int) $b['max_aantal'] : 0,
            'kastlocatie' => $b['kastlocatie'] ?? null,
        ];
    }
    return $result;
}

// Geen $min-parameter meer -- extra
// locaties gedragen zich als een kamer (zie artikelLocatieExtraMinMax()
// hierboven), min_aantal is verwijderd uit artikel_locatie_extra.
function setArtikelLocatieExtra(string $ean, int $locatieExtraId, int $max, ?string $kastlocatie): void {
    db()->prepare(
        'INSERT INTO artikel_locatie_extra (ean, locatie_extra_id, max_aantal, kastlocatie)
         VALUES (:ean, :loc, :max, :kastlocatie)
         ON DUPLICATE KEY UPDATE max_aantal = VALUES(max_aantal), kastlocatie = VALUES(kastlocatie)'
    )->execute([
        ':ean' => $ean, ':loc' => $locatieExtraId,
        ':max' => max(0, $max), ':kastlocatie' => $kastlocatie,
    ]);
}

/**
 * Naam -> locaties_extra.id, of null als de naam een vast slot is (of
 * helemaal niet bestaat). Spiegelbeeld van locatieSlotVoorNaam() hieronder
 * -- gebruikt door includes/minmax.php om bij het toepassen van een
 * min/max-advies op een extra locatie de juiste extra_min_<id>/extra_max_<id>
 * sleutel voor updateArtikelVeld() te synthetiseren.
 */
function locatieExtraIdVoorNaam(string $naam): ?int {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (getLocatiesExtra() as $r) $cache[$r['naam']] = (int) $r['id'];
    }
    return $cache[$naam] ?? null;
}

/** Naam -> slot, met een kleine statische cache binnen één request. */
function locatieSlotVoorNaam(string $naam): ?string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (getLocatieInstellingen() as $r) $cache[$r['naam']] = $r['slot'];
    }
    return $cache[$naam] ?? null;
}

function locatieNaamVoorSlot(string $slot): ?string {
    foreach (getLocatieInstellingen() as $r) {
        if ($r['slot'] === $slot) return $r['naam'];
    }
    return null;
}

/**
 * ("het is niet heel waarschijnlijk dat
 * ik de hele berging in 1 keer ga scannen"): distincte kastlocatie-
 * waarden binnen ÉÉN specifieke locatie -- in tegenstelling tot
 * getKastlocatieSuggesties() (includes/artikelen.php), die een globale
 * suggestielijst over ALLE locaties heen geeft (puur voor het invulveld
 * bij Artikel Toevoegen/bewerken), is deze functie bedoeld om bij een
 * vrijwillige herhaalde voorraadtelling (zie includes/telling.php) een
 * keuzelijst van "welke kasten bestaan er binnen deze ene locatie" te
 * tonen, zodat een laborant een kastcontrole kan beperken tot bijv.
 * "Berging - Plank A3" i.p.v. verplicht de hele Berging in één keer te
 * moeten scannen.
 *
 * Zelfde vaste-slot-vs-extra-locatie-onderscheid als artikelKastlocatie()
 * (includes/voorraad.php): 'kar' is mobiel en heeft geen kastlocatie
 * (geeft altijd een lege lijst), de drie overige vaste slots lezen
 * artikelen.locatie_ik/locatie_berging/locatie_hybok, een extra locatie
 * leest artikel_locatie_extra.kastlocatie via locatieExtraIdVoorNaam().
 */
function kastlocatiesVoorLocatie(string $locatieNaam): array {
    $slot = locatieSlotVoorNaam($locatieNaam);

    if ($slot !== null) {
        $kolom = match ($slot) {
            'ik' => 'locatie_ik',
            'berging' => 'locatie_berging',
            'hybok' => 'locatie_hybok',
            default => null, // 'kar': mobiel, geen kastlocatie
        };
        if ($kolom === null) return [];
        $stmt = db()->query(
            "SELECT DISTINCT $kolom AS v FROM artikelen WHERE $kolom IS NOT NULL AND $kolom <> '' ORDER BY $kolom"
        );
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $locatieExtraId = locatieExtraIdVoorNaam($locatieNaam);
    if ($locatieExtraId === null) return [];
    $stmt = db()->prepare(
        'SELECT DISTINCT kastlocatie AS v FROM artikel_locatie_extra
         WHERE locatie_extra_id = :id AND kastlocatie IS NOT NULL AND kastlocatie <> \'\'
         ORDER BY kastlocatie'
    );
    $stmt->execute([':id' => $locatieExtraId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function updateLocatieInstelling(string $slot, string $naam, bool $actief, ?string $gebruikerNaam = null): array {
    $naam = trim($naam);
    if ($naam === '') return ['success' => false, 'error' => 'Naam is verplicht'];
    if (!in_array($slot, ['ik', 'hybok', 'kar', 'berging'], true)) {
        return ['success' => false, 'error' => 'Onbekende locatie-slot'];
    }

    // (locatie-hernoemen cascade-bug):
    // oude naam opzoeken VOORDAT die overschreven wordt, zie
    // includes/hernoemingen.php.
    $oudeNaam = locatieNaamVoorSlot($slot);

    db()->prepare('UPDATE locatie_instellingen SET naam = :naam, actief = :actief WHERE slot = :slot')
        ->execute([':naam' => $naam, ':actief' => $actief ? 1 : 0, ':slot' => $slot]);

    if ($oudeNaam !== null) {
        registreerHernoeming('locatie', $oudeNaam, $naam);
    }

    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'locatie_gewijzigd', 'locatie', $slot, "naam=$naam, actief=" . ($actief ? '1' : '0'));
    }

    return ['success' => true];
}

/**
 * Zet laatst_geteld_op (kastcontrole, zie includes/taken.php) voor een
 * locatie op basis van naam -- werkt zowel voor een vast slot als een
 * extra locatie, dus de aanroeper (includes/telling.php) hoeft dat
 * onderscheid niet te kennen.
 */
function zetLaatstGeteld(string $locatieNaam, string $tijdstempel): void {
    $slot = locatieSlotVoorNaam($locatieNaam);
    if ($slot !== null) {
        db()->prepare('UPDATE locatie_instellingen SET laatst_geteld_op = :t WHERE slot = :slot')
            ->execute([':t' => $tijdstempel, ':slot' => $slot]);
        return;
    }
    db()->prepare('UPDATE locaties_extra SET laatst_geteld_op = :t WHERE naam = :naam')
        ->execute([':t' => $tijdstempel, ':naam' => $locatieNaam]);
}
