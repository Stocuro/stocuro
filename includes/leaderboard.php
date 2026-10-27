<?php
/**
 * Leaderboard: wie heeft de meeste acties (scans) uitgevoerd, en wie
 * heeft de meeste keer een bepaalde procedure gedaan (bijv. "wie heeft
 * de meeste PICC-lijnen geplaatst") -- gevraagd om twee dingen mogelijk
 * te maken: (1) inzicht in wie het meeste werk verzet, en (2) een
 * eerlijke(re) rotatie bepalen voor wie aan de beurt is bij de volgende
 * procedure van een bepaald type. Bewust alleen een overzicht (tellingen
 * per persoon per periode) -- geen automatische "jij bent aan de beurt"-
 * aanwijzing, dat blijft een menselijke afweging.
 *
 * Twee verschillende, losstaande tellingen (niet samengevoegd), want het
 * zijn twee verschillende concepten:
 * - "Meeste scans": wie heeft (via zijn/haar account) de handeling
 *   Verricht -- transactielog.aangemaakt_door. Dekt élke
 *   scanactie (ontvangst, verbruik, verplaatsing/retour, telling,
 *   vervallen afboeken), ongeacht voor wie/welke procedure het was.
 * - "Meeste procedures per type": voor wie/welke specialist een
 *   verbruiksregistratie was, ongeacht wie de scan zelf uitvoerde --
 *   transactielog.specialist + procedure_type, alleen transactietype
 *   'Verbruik' (dat is de enige die een klinische procedure
 *   representeert; ontvangst/verplaatsing/telling hebben geen
 *   Specialist/procedure). users.rol (specialist/laborant,
 *   voorheen specialisten.type vóór de samenvoeging van die tabel met
 *   users) wordt erbij gehaald zodat je kunt filteren/zien of het een
 *   specialist of laborant was.
 *
 * Periodes zijn rollende dagvensters (net als TRANSACTIELOG_BEWERKBAAR_DAGEN
 * elders in deze codebase), geen kalendermaand/kwartaal/jaar -- eenvoudiger
 * en voorspelbaarder ("laatste N dagen" i.p.v. randgevallen rond
 * maandwisselingen).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/stamgegevens.php';
// ActueleNaam() -- getMeesteProcedures()/getPiccRotatie()
// hieronder groeperen op transactielog.specialist, een naam-op-transactie-
// moment i.p.v. FK. Zie includes/hernoemingen.php voor de achtergrond.
require_once __DIR__ . '/hernoemingen.php';

const LEADERBOARD_PERIODES = ['week' => 7, 'maand' => 30, 'kwartaal' => 90, 'jaar' => 365, 'totaal' => null];

function leaderboardPeriodeDagen(string $periode): ?int {
    return array_key_exists($periode, LEADERBOARD_PERIODES) ? LEADERBOARD_PERIODES[$periode] : 30;
}

/**
 * Aantal transactielog-regels per uitvoerende gebruiker (aangemaakt_door),
 * binnen de gekozen periode. Regels zonder aangemaakt_door (van een
 * oudere datum, of acties waarvoor destijds geen gebruiker werd
 * meegegeven) tellen niet mee -- er is niets zinvols om ze aan toe te
 * wijzen.
 */
function getMeesteScans(string $periode): array {
    $dagen = leaderboardPeriodeDagen($periode);

    $sql = "SELECT aangemaakt_door AS naam, COUNT(*) AS aantal
            FROM transactielog
            WHERE aangemaakt_door IS NOT NULL AND aangemaakt_door <> ''";
    if ($dagen !== null) {
        $sql .= " AND tijdstempel >= (NOW() - INTERVAL :dagen DAY)";
    }
    $sql .= " GROUP BY aangemaakt_door ORDER BY aantal DESC, naam ASC";

    $stmt = db()->prepare($sql);
    if ($dagen !== null) $stmt->bindValue(':dagen', $dagen, PDO::PARAM_INT);
    $stmt->execute();

    return array_map(fn($r) => ['naam' => $r['naam'], 'aantal' => (int) $r['aantal']], $stmt->fetchAll());
}

/**
 * Aantal 'Verbruik'-registraties per specialist, optioneel gefilterd op
 * één procedure_type (bijv. "PICC lijn"), binnen de gekozen periode.
 * users.rol wordt erbij gehaald op naam (nog steeds geen FK vanuit
 * transactielog -- de naam-match is hetzelfde patroon als de rest van
 * deze koppeling, alleen wijst hij sinds de rollenmodel-herziening naar
 * 'users' i.p.v. de opgeheven 'specialisten'-tabel).
 */
function getMeesteProcedures(string $periode, ?string $procedureType = null): array {
    $dagen = leaderboardPeriodeDagen($periode);

    $sql = "SELECT t.specialist AS specialist, COUNT(*) AS aantal
            FROM transactielog t
            WHERE t.transactietype = 'Verbruik' AND t.specialist IS NOT NULL AND t.specialist <> ''";
    $params = [];
    if ($dagen !== null) {
        $sql .= " AND t.tijdstempel >= (NOW() - INTERVAL :dagen DAY)";
    }
    if ($procedureType !== null && $procedureType !== '') {
        $sql .= " AND t.procedure_type = :procType";
        $params[':procType'] = $procedureType;
    }
    // (voorbereiding gebruikersnaam
    // bewerkbaar maken): GROUP BY hier bewust alleen op t.specialist, niet
    // meer op s.rol -- de LEFT JOIN op de huidige users-naam viel eerder
    // hier al weg zodra de opgeslagen naam niet meer letterlijk matchte
    // (een geval dat na het bewerkbaar maken van gebruikersnamen normaal
    // wordt). Het type/rol wordt hieronder in PHP opgezocht, NA het
    // samenvoegen op actuele naam, zodat een omgedoopte specialist zijn
    // type niet kwijtraakt.
    $sql .= " GROUP BY t.specialist";

    $stmt = db()->prepare($sql);
    if ($dagen !== null) $stmt->bindValue(':dagen', $dagen, PDO::PARAM_INT);
    foreach ($params as $key => $value) $stmt->bindValue($key, $value);
    $stmt->execute();

    // Zie includes/hernoemingen.php -- de GROUP BY hierboven
    // groepeert nog op de RUWE, ten tijde van elke transactie opgeslagen
    // specialistnaam; actueleNaam() lost dat op naar de huidige naam en de
    // aantallen worden hier samengevoegd (zelfde patroon als
    // transactieNettoPerLocatie() in includes/voorraad.php).
    $samengevoegd = [];
    foreach ($stmt->fetchAll() as $r) {
        $naam = actueleNaam('specialist', $r['specialist']);
        $samengevoegd[$naam] = ($samengevoegd[$naam] ?? 0) + (int) $r['aantal'];
    }

    if (!$samengevoegd) return [];

    // Rol/type opzoeken op de HUIDIGE naam, na normalisatie hierboven.
    $placeholders = implode(',', array_fill(0, count($samengevoegd), '?'));
    $stmtRol = db()->prepare(
        "SELECT naam, rol FROM users WHERE naam IN ($placeholders) AND rol IN ('specialist', 'laborant')"
    );
    $stmtRol->execute(array_keys($samengevoegd));
    $rollen = [];
    foreach ($stmtRol->fetchAll() as $r) {
        $rollen[$r['naam']] = $r['rol'];
    }

    $result = [];
    foreach ($samengevoegd as $naam => $aantal) {
        $result[] = ['specialist' => $naam, 'type' => $rollen[$naam] ?? null, 'aantal' => $aantal];
    }
    usort($result, function ($a, $b) {
        if ($a['aantal'] !== $b['aantal']) return $b['aantal'] <=> $a['aantal'];
        return strcmp($a['specialist'], $b['specialist']);
    });
    return $result;
}

/**
 * Alle actieve procedure-typen die daadwerkelijk in transactielog
 * voorkomen (niet zomaar de hele procedure_typen-lijst) -- voorkomt een
 * filter-dropdown vol procedures die nog nooit zijn geregistreerd.
 */
function getGebruikteProcedureTypen(): array {
    $rows = db()->query(
        "SELECT DISTINCT procedure_type FROM transactielog
         WHERE transactietype = 'Verbruik' AND procedure_type IS NOT NULL AND procedure_type <> ''
         ORDER BY procedure_type"
    )->fetchAll();
    return array_map(fn($r) => $r['procedure_type'], $rows);
}

function getLeaderboardData(string $periode, ?string $procedureType): array {
    if (!array_key_exists($periode, LEADERBOARD_PERIODES)) $periode = 'maand';

    return [
        'periode' => $periode,
        'scans' => getMeesteScans($periode),
        'procedures' => getMeesteProcedures($periode, $procedureType),
        'procedureTypen' => getGebruikteProcedureTypen(),
    ];
}

/**
 * "wie is er aan de beurt
 * voor het prikken van een PICC-lijn, wie het laatst de procedure heeft
 * uitgevoerd is als eerste aan de beurt, vanuit de groep laboranten" --
 * een eerlijke rotatie i.p.v. de bestaande "meeste procedures"-telling
 * hierboven (dat beantwoordt een ander soort vraag: wie heeft het MEEST
 * gedaan, niet wie is nu aan de beurt).
 *
 * Generiek over elke procedure met procedure_typen.laborant_toegestaan = 1
 * (in de praktijk alleen PICC-lijn, maar niet hardcoded op die naam --
 * zelfde flag als de bestaande PICC-regel in includes/transacties.php),
 * niet alleen over actieve laboranten (users.rol = 'laborant', actief = 1).
 * Voor elke combinatie wordt de laatste 'Verbruik'-registratie opgezocht
 * (transactielog.specialist + procedure_type); een laborant die de
 * procedure nog nooit heeft gedaan komt bovenaan (nooit gedaan = altijd
 * eerst aan de beurt), de rest oplopend op laatst-uitgevoerd (langst
 * geleden eerst). volgorde[0] is dus altijd "aan de beurt".
 *
 * Geen nieuwe tabel, geen nieuwe kolom -- puur een andere sortering over
 * dezelfde transactielog-gegevens als getMeesteProcedures() hierboven.
 */
function getPiccRotatie(): array {
    $procedures = array_values(array_filter(
        getProcedureTypen(),
        fn($p) => $p['actief'] && $p['laborant_toegestaan']
    ));
    if (!$procedures) return [];

    $laboranten = array_values(array_filter(
        getSpecialisten(),
        fn($u) => $u['type'] === 'laborant' && $u['actief']
    ));
    if (!$laboranten) return [];

    $rows = db()->query(
        "SELECT specialist, procedure_type, MAX(tijdstempel) AS laatst
         FROM transactielog
         WHERE transactietype = 'Verbruik' AND specialist IS NOT NULL AND specialist <> ''
           AND procedure_type IS NOT NULL AND procedure_type <> ''
         GROUP BY specialist, procedure_type"
    )->fetchAll();

    // Zie toelichting bij getMeesteProcedures() hierboven --
    // zelfde actueleNaam()-normalisatie, hier met een MAX i.p.v. een SUM
    // (het laatst uitgevoerde tijdstip, niet een aantal), zodat een
    // omgedoopte laborant zijn "laatst uitgevoerd"-tijdstip niet
    // kwijtraakt en niet als "nog nooit gedaan" bovenaan komt te staan.
    $laatstPerProcedureSpecialist = [];
    foreach ($rows as $r) {
        $naam = actueleNaam('specialist', $r['specialist']);
        $bestaand = $laatstPerProcedureSpecialist[$r['procedure_type']][$naam] ?? null;
        if ($bestaand === null || $r['laatst'] > $bestaand) {
            $laatstPerProcedureSpecialist[$r['procedure_type']][$naam] = $r['laatst'];
        }
    }

    $result = [];
    foreach ($procedures as $proc) {
        $naam = $proc['naam'];
        $volgorde = [];
        foreach ($laboranten as $lab) {
            $volgorde[] = [
                'naam' => $lab['naam'],
                // ("Leaderboard met wie
                // aan de beurt is uitbreiden met foto"): meegeven zodat de
                // Home-widget/Rapportage-weergave een avatar kan tonen i.p.v.
                // alleen platte tekst (zie avatarHtml() in assets/js/app.js).
                'foto' => $lab['foto'] ?? null,
                'laatstUitgevoerd' => $laatstPerProcedureSpecialist[$naam][$lab['naam']] ?? null,
            ];
        }
        // Nooit gedaan (null) eerst, daarna oplopend op datum (langst geleden eerst).
        usort($volgorde, function ($a, $b) {
            if ($a['laatstUitgevoerd'] === null && $b['laatstUitgevoerd'] === null) return strcmp($a['naam'], $b['naam']);
            if ($a['laatstUitgevoerd'] === null) return -1;
            if ($b['laatstUitgevoerd'] === null) return 1;
            return $a['laatstUitgevoerd'] <=> $b['laatstUitgevoerd'];
        });
        $result[] = ['procedureType' => $naam, 'volgorde' => $volgorde];
    }
    return $result;
}
