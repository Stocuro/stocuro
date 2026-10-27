<?php
/**
 * Takenlijst: geen aparte taken-tabel, geen handmatig aan te maken/af te
 * vinken to-do's -- alle vier soorten taken hieronder worden afgeleid uit
 * bestaande data, en "afronden" gebeurt door de onderliggende actie te
 * doen (kastcontrole via includes/telling.php, vervallen voorraad
 * afboeken via het verbruikformulier met reden 'vervaldatum bereikt',
 * zie api/scan_verbruik.php) -- niet door iets expliciet aan te vinken.
 *
 * Dit was één scherm "Tasks" met alle
 * vier onderdelen door elkaar. Kernpunt: Onbekende scans en Onvolledige
 * productgegevens zijn taken voor een BEHEERDER (databeheer: ontbrekend
 * artikel aanmaken, ontbrekende productvelden aanvullen), terwijl
 * Voorraadtelling verschuldigd en Over de vervaldatum taken voor een
 * LABORANT zijn (vloerwerk: fysiek tellen/scannen). Daarom nu twee losse
 * dashboards/scherm-keys (zie includes/schermen.php: 'taken-beheerder' en
 * 'taken-laborant'), elk met zijn eigen getTaken*()-functie hieronder --
 * geen enkele plek roept nog beide categorieën samen op.
 *
 * 1. Kastcontrole (LABORANT): elke actieve locatie hoort periodiek (om de
 *    TELLING_INTERVAL_MAANDEN maanden, config/constants.php) fysiek
 *    geteld te worden. locatie_instellingen/locaties_extra houden
 *    Laatst_geteld_op bij; een locatie zonder telling
 *    binnen dat interval verschijnt hier als open taak.
 * 2. Vervallen voorraad (LABORANT): lots die al over de vervaldatum zijn
 *    EN nog voorraad hebben (afgeleid uit transactielog, dezelfde
 *    optelling als voorraadLot() in voorraad.php) -- een seintje om die
 *    fysiek te gaan scannen en af te boeken, geen handeling die hier zelf
 *    gebeurt.
 * 3. Onbekende scans (BEHEERDER): barcodes die tijdens een
 *    scan niet herkend werden, met een omschrijving van de scanner --
 *    "afronden" gebeurt door het artikel aan te maken op het Admin-scherm
 *    "Onbekende Producten", zie includes/onbekende_scans.php.
 * 4. Onvolledige productgegevens (BEHEERDER): artikelen waar essentiële
 *    informatie ontbreekt (producent, foto, min/max, kastlocatie,
 *    houdbaarheidsdata, prijs, categorie, doosverpakking-inconsistentie)
 *    -- "afronden" gebeurt door het veld op de productpagina aan te
 *    vullen, zie includes/compleetheid.php.
 * 5. Ontbrekende batchgegevens (LABORANT): 
 *    gemeld praktijkprobleem (geen eigen schema-migratie nodig -- puur een
 *    extra afgeleide query op de bestaande transactielog-kolommen). Een
 *    doos die alleen op de GTIN-barcode is ontvangen (de batch-/
 *    vervaldatumcode gemist of overgeslagen) levert voorraad op zonder dat
 *    er ooit een lot of vervaldatum bekend is geworden. Vloerwerk, niet databeheer:
 *    "afronden" gebeurt door de betreffende transactie te verwijderen
 *    (Recente Transacties, binnen het bewerkbare venster) en het product
 *    opnieuw te scannen mét de batch-/vervaldatumcode. Zie
 *    getOntbrekendeBatchTaken() hieronder.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/locaties.php';
require_once __DIR__ . '/voorraad.php';
require_once __DIR__ . '/onbekende_scans.php';
require_once __DIR__ . '/compleetheid.php';

function kastcontroleTaakVoorLocatie(string $naam, ?string $laatstGeteld): array {
    $volgende = $laatstGeteld
        ? (new DateTime($laatstGeteld))->modify('+' . TELLING_INTERVAL_MAANDEN . ' months')
        : null;
    $verlopen = $volgende === null || $volgende <= new DateTime('today');
    return [
        'locatie' => $naam,
        'laatstGeteld' => $laatstGeteld,
        'volgendeTelling' => $volgende ? $volgende->format('Y-m-d') : null,
        'verlopen' => $verlopen,
    ];
}

/** Alleen actieve locaties die aan de beurt zijn (verlopen == true). */
function getKastcontroleTaken(): array {
    $taken = [];
    foreach (getLocatieInstellingen() as $r) {
        if (!$r['actief']) continue;
        $taken[] = kastcontroleTaakVoorLocatie($r['naam'], $r['laatst_geteld_op'] ?? null);
    }
    foreach (getLocatiesExtra() as $r) {
        if (!$r['actief']) continue;
        $taken[] = kastcontroleTaakVoorLocatie($r['naam'], $r['laatst_geteld_op'] ?? null);
    }
    $taken = array_values(array_filter($taken, fn($t) => $t['verlopen']));
    // Langst-niet-geteld (of nog nooit geteld, laatstGeteld === null) eerst.
    usort($taken, fn($a, $b) => ($a['laatstGeteld'] ?? '') <=> ($b['laatstGeteld'] ?? ''));
    return $taken;
}

/**
 * Lots die al over de vervaldatum zijn en nog voorraad hebben. Zelfde
 * in/uitgaand-indeling als voorraadLot()/transactieNettoPerLocatie()
 * (voorraad.php), via TRANSACTIETYPES_INKOMEND/_UITGAAND, dus een
 * 'Vervallen'-afboeking of een 'Telling: tekort' voor dit lot telt hier
 * meteen mee -- geen kans dat deze query en de voorraadberekening uit
 * elkaar gaan lopen.
 */
function getVervallenProductenTaken(): array {
    $rows = db()->query(
        "SELECT ean, lot, vervaldatum, locatie,
                MAX(productnaam) AS productnaam,
                SUM(CASE WHEN transactietype IN (" . sqlInList(TRANSACTIETYPES_INKOMEND) . ") THEN hoeveelheid ELSE 0 END)
                - SUM(CASE WHEN transactietype IN (" . sqlInList(TRANSACTIETYPES_UITGAAND) . ") THEN hoeveelheid ELSE 0 END) AS huidig
         FROM transactielog
         WHERE vervaldatum IS NOT NULL AND vervaldatum < CURDATE()
         GROUP BY ean, lot, vervaldatum, locatie
         HAVING huidig > 0
         ORDER BY vervaldatum ASC"
    )->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'ean' => $r['ean'],
            'productnaam' => $r['productnaam'],
            'lot' => $r['lot'],
            'vervaldatum' => $r['vervaldatum'],
            'locatie' => $r['locatie'],
            'huidig' => (int) $r['huidig'],
        ];
    }
    return $result;
}

/**
 * EAN+locatie-combinaties die nog voorraad hebben, maar waarvan GEEN
 * enkele scan tot nu toe een lot- of vervaldatum heeft vastgelegd (beide
 * NULL) -- : een doos die alleen op de
 * GTIN-barcode is binnengeboekt (batch-/vervaldatumcode gemist of
 * bewust overgeslagen via de scanhulp, zie assets/js/app.js) levert
 * voorraad op zonder dat er ooit een lot/vervaldatum bekend is geworden.
 * Zelfde afgeleide-taak-aanpak als getVervallenProductenTaken(): geen
 * aparte tabel, geen handmatig "afvinken" -- zodra deze voorraad
 * daadwerkelijk verbruikt is (huidig <= 0) verdwijnt de melding vanzelf.
 * "Afronden" betekent hier: de betreffende transactie(s) verwijderen
 * (Recente Transacties, binnen het bewerkbare venster) en het product
 * opnieuw scannen mét de batch-/vervaldatumcode -- lot/vervaldatum zijn
 * bewust niet los te bewerken (zie bewerkTransactieRegel() in
 * includes/transacties.php), dat blijft ongewijzigd.
 *
 * Bewust ALLEEN meegeteld wanneer lot ÉN vervaldatum beide ontbreken (niet
 * als er maar één van de twee mist) -- dat is het duidelijkste signaal dat
 * de batch-/vervaldatumcode nooit gescand is, en voorkomt ruis van
 * producten die legitiem maar één van de twee gegevens voeren.
 */
function getOntbrekendeBatchTaken(): array {
    $rows = db()->query(
        "SELECT ean, locatie,
                MAX(productnaam) AS productnaam,
                SUM(CASE WHEN transactietype IN (" . sqlInList(TRANSACTIETYPES_INKOMEND) . ") THEN hoeveelheid ELSE 0 END)
                - SUM(CASE WHEN transactietype IN (" . sqlInList(TRANSACTIETYPES_UITGAAND) . ") THEN hoeveelheid ELSE 0 END) AS huidig
         FROM transactielog
         WHERE lot IS NULL AND vervaldatum IS NULL
         GROUP BY ean, locatie
         HAVING huidig > 0
         ORDER BY productnaam ASC"
    )->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'ean' => $r['ean'], 'productnaam' => $r['productnaam'] ?: $r['ean'],
            'locatie' => $r['locatie'], 'huidig' => (int) $r['huidig'],
        ];
    }
    return $result;
}

/** Laborant-dashboard: fysiek vloerwerk (tellen, vervallen voorraad afboeken). */
function getTakenLaborant(): array {
    $kastcontrole = getKastcontroleTaken();
    $vervallen = getVervallenProductenTaken();
    return [
        'kastcontrole' => $kastcontrole,
        'vervallen' => $vervallen,
        'totaal' => count($kastcontrole) + count($vervallen),
    ];
}

/**
 * Beheerder-dashboard: databeheer (onbekende barcodes, onvolledige
 * productgegevens, ontbrekende batch-/vervaldatumgegevens).
 * "ontbrekende batch-/vervaldatumgegevens"
 * stond eerder bij Laborant-taken, maar is een databeheerkwestie (iemand
 * heeft ooit een doos gescand zonder lot/vervaldatum mee te scannen) --
 * geen fysiek vloerwerk zoals de rest van dat dashboard, dus verplaatst
 * naar Beheerder-taken waar het qua aard thuishoort.
 */
function getTakenBeheerder(): array {
    $onbekendeScans = getOpenOnbekendeScans();
    $onvolledigeProducten = getOnvolledigeProducten();
    $ontbrekendeBatch = getOntbrekendeBatchTaken();
    return [
        'onbekendeScans' => $onbekendeScans,
        'onvolledigeProducten' => $onvolledigeProducten,
        'ontbrekendeBatch' => $ontbrekendeBatch,
        'totaal' => count($onbekendeScans) + count($onvolledigeProducten) + count($ontbrekendeBatch),
    ];
}
