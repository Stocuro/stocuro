<?php
/**
 * Vult de database met een jaar (standaard) aan volledig FICTIEVE
 * testdata: gebruikers, specialisten, procedure typen, ~20 artikelen, en
 * een gesimuleerde geschiedenis van scans/orders/kastcontroles/
 * afboekingen -- zodat elk dashboard (Overview, Leaderboard, Min/Max
 * Advies, Trends, Open Orders, Write-offs, Expiry-schermen, ...) meteen
 * iets zinnigs te laten zien heeft, zonder dat je dat allemaal handmatig
 * hoeft in te scannen.
 *
 * Draai dit bij voorkeur op een LEGE database (zie migratie/leegmaken.php
 * --ja) -- de artikel-EAN's/gebruikers-e-mailadressen hieronder zijn
 * uniek verzonnen (00000000000201 t/m 220, @demo.stocuro.test), maar
 * draai dit niet twee keer achter elkaar zonder ertussen leeg te maken:
 * dat geeft dubbele-sleutel-fouten op de EAN's/e-mailadressen.
 *
 * Simulatiemodel (bewust dicht bij hoe de app het écht bijhoudt, geen
 * losse willekeurige getallen):
 *  - Elk artikel heeft een eigen voorraad per locatie, opgebouwd uit
 *    "loten" (batches) met een eigen vervaldatum -- verbruik/afboeking
 *    consumeert steeds uit de oudste batch eerst (FEFO), net als de
 *    Vervaldatum-/Lot-tracking-schermen dat laten zien.
 *  - 'berging' is de centrale aanvulbron (zelfde als in de echte app,
 *    zie includes/locaties.php): de andere drie locaties worden vanuit
 *    berging bijgevuld via 'Verplaatsing uit'/'Verplaatsing in' zodra ze
 *    onder hun minimum zakken; alleen berging zelf krijgt een echte
 *    order wanneer DIE onder haar minimum zakt.
 *  - Orders krijgen een willekeurige, realistische levertijd (3-25
 *    dagen) voordat ze "ontvangen" worden -- orders.laatst_ontvangen_op
 *    wordt gezet zoals includes/orders.php::updateOrdersWithFIFO() dat
 *    in het echt ook doet, zodat Min/Max Advies (includes/minmax.php)
 *    er een eigen levertijdgemiddelde uit kan berekenen i.p.v. terug te
 *    vallen op de vaste aanname.
 *  - Elke ~90 dagen (TELLING_INTERVAL_MAANDEN) een kastcontrole per
 *    locatie, met af en toe een kleine afwijking (Telling: overschot/
 *    tekort) -- zoals includes/telling.php dat ook zou boeken.
 *  - Loten waarvan de vervaldatum is gepasseerd worden afgeboekt
 *    ('Vervallen'), voor het Write-offs-scherm.
 *
 * Gebruik, als je SSH/command-line-toegang tot de server hebt (kan een
 * paar minuten duren, afhankelijk van de latency naar de databaseserver):
 *   php migratie/genereer_testdata.php --ja
 *   php migratie/genereer_testdata.php --ja --dagen=180
 *   php migratie/genereer_testdata.php --ja --seed=1234   (reproduceerbaar)
 *
 * Gebruik, als je GEEN SSH hebt: de URL in je browser openen, met
 * dezelfde opties als querystring. Let op: gedeelde hosting kan een
 * eigen requesttimeout hanteren (los van set_time_limit(0) hieronder) die
 * een lang draaiend browserverzoek alsnog afbreekt -- gebruik in dat
 * geval een kleinere &dagen= (bijv. 90). Alles hieronder draait in één
 * transactie, dus een afgebroken run laat de database ongewijzigd (geen
 * halve dataset) -- je kunt het gewoon opnieuw proberen.
 *   https://jouw-domein/migratie/genereer_testdata.php?ja=1
 *   https://jouw-domein/migratie/genereer_testdata.php?ja=1&dagen=90
 * Verwijder (of hernoem) dit bestand na gebruik als je het zo draait --
 * anders kan iedereen die de URL kent (of raadt) opnieuw testdata
 * (met dezelfde EAN's/e-mailadressen, dus botsend) laten genereren.
 */

declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/../config/database.php';

function testdata_log(string $msg): void {
    // PHP_EOL is \n op de server; in een browser toont dat niets
    // zichtbaars, dus voeg ook een <br> toe zodat het leesbaar blijft als
    // dit via een URL wordt gedraaid i.p.v. de command line.
    echo $msg . (PHP_SAPI === 'cli' ? PHP_EOL : '<br>' . PHP_EOL);
}

/** STDERR bestaat alleen bij de command-line-SAPI -- fwrite(STDERR, ...) zou hier fataal zijn als dit via een URL draait. */
function testdata_fout(string $msg): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . PHP_EOL);
    } else {
        echo $msg . '<br>' . PHP_EOL;
    }
}

/** Totaal aantal stuks (som van alle loten) voor dit ean+locatie. */
function testdata_voorraad(array $batches, string $ean, string $locatie): int {
    if (empty($batches[$ean][$locatie])) return 0;
    return (int) array_sum(array_column($batches[$ean][$locatie], 'resterend'));
}

/**
 * Consumeert tot $aantal stuks uit de OUDSTE batch (FEFO) op dit
 * ean+locatie -- nooit verdeeld over meerdere loten binnen één
 * gebeurtenis (net als een echte scan: één moment, één fysieke bron).
 * Geeft mogelijk minder terug dan gevraagd als die ene batch niet genoeg
 * heeft; de aanroeper accepteert dat gewoon (realistisch: je scant wat
 * er is). Retourneert ['aantal', 'lot', 'vervaldatum'].
 */
function testdata_verbruik(array &$batches, string $ean, string $locatie, int $aantal): array {
    if (empty($batches[$ean][$locatie])) {
        return ['aantal' => 0, 'lot' => null, 'vervaldatum' => null];
    }
    $teNemen = min($aantal, $batches[$ean][$locatie][0]['resterend']);
    if ($teNemen <= 0) {
        return ['aantal' => 0, 'lot' => null, 'vervaldatum' => null];
    }
    $batches[$ean][$locatie][0]['resterend'] -= $teNemen;
    $result = [
        'aantal' => $teNemen,
        'lot' => $batches[$ean][$locatie][0]['lot'],
        'vervaldatum' => $batches[$ean][$locatie][0]['vervaldatum'],
    ];
    if ($batches[$ean][$locatie][0]['resterend'] <= 0) {
        array_shift($batches[$ean][$locatie]);
    }
    return $result;
}

/** Grofweg 3-25 dagen, met de meeste massa rond de 5-14 dagen. */
function testdata_levertijd_dagen(): int {
    $basis = mt_rand(3, 14);
    if (mt_rand(1, 100) <= 15) $basis += mt_rand(5, 11);
    return $basis;
}

function testdata_tijd(string $datumStr, int $vanUur = 8, int $totUur = 17): string {
    return $datumStr . ' ' . sprintf('%02d:%02d:00', mt_rand($vanUur, $totUur), mt_rand(0, 59));
}

// =========================================================================
// Argumenten
// =========================================================================
$args = $argv ?? [];
if (!in_array('--ja', $args, true)) {
    testdata_log('Dit vult de database met een jaar FICTIEVE testdata (producten, gebruikers, specialisten, transacties, orders, ...).');
    testdata_log('Draai dit bij voorkeur op een LEGE database (zie migratie/leegmaken.php --ja) om botsingen te voorkomen.');
    testdata_log('Voer opnieuw uit met --ja om te bevestigen:');
    testdata_log('  php migratie/genereer_testdata.php --ja [--dagen=365] [--seed=1234]');
    exit(1);
}

$AANTAL_DAGEN = 365;
$seed = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--dagen=')) $AANTAL_DAGEN = max(30, (int) substr($a, 8));
    if (str_starts_with($a, '--seed=')) $seed = (int) substr($a, 7);
}
if ($seed !== null) mt_srand($seed);

$DEMO_WACHTWOORD = 'Stocuro2026!';

// =========================================================================
// Stamdata
// =========================================================================
$GEBRUIKERS = [
    ['naam' => 'Anna de Vries', 'email' => 'anna.devries@demo.stocuro.test', 'rol' => 'beheerder', 'taal' => 'nl'],
    ['naam' => 'Bram Jansen', 'email' => 'bram.jansen@demo.stocuro.test', 'rol' => 'medewerker', 'taal' => 'nl'],
    ['naam' => 'Chantal Bakker', 'email' => 'chantal.bakker@demo.stocuro.test', 'rol' => 'medewerker', 'taal' => 'nl'],
    ['naam' => 'Dennis Visser', 'email' => 'dennis.visser@demo.stocuro.test', 'rol' => 'medewerker', 'taal' => 'en'],
    ['naam' => 'Eva Smit', 'email' => 'eva.smit@demo.stocuro.test', 'rol' => 'alleen-lezen', 'taal' => 'en'],
];

$SPECIALISTEN = [
    ['naam' => 'Dr. F. van Dijk', 'type' => 'arts'],
    ['naam' => 'Dr. G. Mulder', 'type' => 'arts'],
    ['naam' => 'Dr. H. de Boer', 'type' => 'arts'],
    ['naam' => 'I. Peters (laborant)', 'type' => 'laborant'],
    ['naam' => 'J. Hendriks (laborant)', 'type' => 'laborant'],
    ['naam' => 'K. Willems', 'type' => 'overig'],
];

$PROCEDURE_TYPEN = ['PICC Line', 'Angiography', 'Embolization', 'Stent Placement', 'Biopsy'];

// min/max volgorde per artikel: [ik, hybok, kar, berging] -- zelfde
// volgorde als de artikelen-tabelkolommen (min_ik/max_ik, min_hybok/
// max_hybok, min_kar/max_kar, min_berging/max_berging). 'verbruik' stuurt
// hoe vaak dit artikel dagelijks per verbruiklocatie wordt "gescand" (zie
// $dailyKans hieronder); 'houdbaarheid' is in maanden, gebruikt om een
// vervaldatum te genereren bij elke ontvangst.
$ARTIKELEN = [
    ['ean' => '00000000000201', 'naam' => 'Demo Stent 6mm', 'cat' => 'Stent', 'besteltype' => 'Automatisch', 'min' => [2, 1, 1, 5], 'max' => [6, 4, 3, 15], 'producent' => 'Demo Supplier Co', 'prijs' => 245.00, 'houdbaarheid' => 24, 'verbruik' => 'gemiddeld'],
    ['ean' => '00000000000202', 'naam' => 'Demo Stent 8mm', 'cat' => 'Stent', 'besteltype' => 'Automatisch', 'min' => [1, 1, 0, 4], 'max' => [4, 3, 2, 10], 'producent' => 'Demo Supplier Co', 'prijs' => 268.00, 'houdbaarheid' => 24, 'verbruik' => 'laag'],
    ['ean' => '00000000000203', 'naam' => 'Demo Guidewire 0.035"', 'cat' => 'Voerdraad', 'besteltype' => 'Automatisch', 'min' => [3, 2, 2, 8], 'max' => [8, 5, 4, 20], 'producent' => 'Demo Supplier Co', 'prijs' => 38.50, 'houdbaarheid' => 36, 'verbruik' => 'hoog'],
    ['ean' => '00000000000204', 'naam' => 'Demo Guidewire 0.018"', 'cat' => 'Voerdraad', 'besteltype' => 'Automatisch', 'min' => [2, 1, 1, 6], 'max' => [6, 4, 3, 15], 'producent' => 'Demo Supplier Co', 'prijs' => 34.00, 'houdbaarheid' => 36, 'verbruik' => 'gemiddeld'],
    ['ean' => '00000000000205', 'naam' => 'Demo Embolisation Coil Small', 'cat' => 'Embolisatie', 'besteltype' => 'Handmatig', 'min' => [1, 1, 0, 3], 'max' => [3, 2, 0, 8], 'producent' => 'Sample Medical BV', 'prijs' => 189.00, 'houdbaarheid' => 30, 'verbruik' => 'laag'],
    ['ean' => '00000000000206', 'naam' => 'Demo Embolisation Coil Large', 'cat' => 'Embolisatie', 'besteltype' => 'Handmatig', 'min' => [1, 0, 0, 2], 'max' => [2, 1, 0, 5], 'producent' => 'Sample Medical BV', 'prijs' => 210.00, 'houdbaarheid' => 30, 'verbruik' => 'laag'],
    ['ean' => '00000000000207', 'naam' => 'Demo Sheath 6F', 'cat' => 'Sheath', 'besteltype' => 'Automatisch', 'min' => [4, 2, 3, 10], 'max' => [10, 6, 6, 25], 'producent' => 'Demo Supplier Co', 'prijs' => 22.75, 'houdbaarheid' => 24, 'verbruik' => 'hoog'],
    ['ean' => '00000000000208', 'naam' => 'Demo Sheath 8F', 'cat' => 'Sheath', 'besteltype' => 'Automatisch', 'min' => [3, 2, 2, 8], 'max' => [8, 5, 4, 18], 'producent' => 'Demo Supplier Co', 'prijs' => 26.50, 'houdbaarheid' => 24, 'verbruik' => 'gemiddeld'],
    ['ean' => '00000000000209', 'naam' => 'Demo Balloon Catheter 4mm', 'cat' => 'Ballon', 'besteltype' => 'Automatisch', 'min' => [2, 1, 1, 4], 'max' => [5, 3, 2, 10], 'producent' => 'Sample Medical BV', 'prijs' => 156.00, 'houdbaarheid' => 24, 'verbruik' => 'gemiddeld'],
    ['ean' => '00000000000210', 'naam' => 'Demo Balloon Catheter 6mm', 'cat' => 'Ballon', 'besteltype' => 'Automatisch', 'min' => [2, 1, 1, 3], 'max' => [4, 3, 2, 8], 'producent' => 'Sample Medical BV', 'prijs' => 168.00, 'houdbaarheid' => 24, 'verbruik' => 'laag'],
    ['ean' => '00000000000211', 'naam' => 'Demo Diagnostic Catheter', 'cat' => 'Catheter', 'besteltype' => 'Automatisch', 'min' => [3, 2, 2, 6], 'max' => [7, 4, 4, 15], 'producent' => 'Demo Supplier Co', 'prijs' => 64.25, 'houdbaarheid' => 30, 'verbruik' => 'gemiddeld'],
    ['ean' => '00000000000212', 'naam' => 'Demo Closure Device', 'cat' => 'Catheter', 'besteltype' => 'Handmatig', 'min' => [1, 0, 0, 2], 'max' => [2, 1, 0, 5], 'producent' => 'Sample Medical BV', 'prijs' => 320.00, 'houdbaarheid' => 24, 'verbruik' => 'laag'],
    ['ean' => '00000000000213', 'naam' => 'Demo Safety Gloves L', 'cat' => 'Overig', 'besteltype' => 'Automatisch', 'min' => [5, 5, 5, 20], 'max' => [20, 20, 15, 50], 'producent' => 'Generic Supplies Inc', 'prijs' => 0.45, 'houdbaarheid' => 60, 'verbruik' => 'hoog'],
    ['ean' => '00000000000214', 'naam' => 'Demo Filter Cartridge', 'cat' => 'Overig', 'besteltype' => 'Automatisch', 'min' => [2, 1, 1, 5], 'max' => [6, 3, 3, 12], 'producent' => 'Generic Supplies Inc', 'prijs' => 18.90, 'houdbaarheid' => 18, 'verbruik' => 'gemiddeld'],
    ['ean' => '00000000000215', 'naam' => 'Demo Contrast Syringe', 'cat' => 'Overig', 'besteltype' => 'Automatisch', 'min' => [4, 3, 2, 10], 'max' => [12, 8, 6, 25], 'producent' => 'Sample Medical BV', 'prijs' => 7.20, 'houdbaarheid' => 24, 'verbruik' => 'hoog'],
    ['ean' => '00000000000216', 'naam' => 'Demo Introducer Sheath 5F', 'cat' => 'Sheath', 'besteltype' => 'Automatisch', 'min' => [3, 2, 2, 7], 'max' => [7, 5, 4, 16], 'producent' => 'Demo Supplier Co', 'prijs' => 19.90, 'houdbaarheid' => 24, 'verbruik' => 'gemiddeld'],
    ['ean' => '00000000000217', 'naam' => 'Demo PTA Balloon 5mm', 'cat' => 'Ballon', 'besteltype' => 'Automatisch', 'min' => [2, 1, 1, 3], 'max' => [4, 3, 2, 8], 'producent' => 'Sample Medical BV', 'prijs' => 172.00, 'houdbaarheid' => 24, 'verbruik' => 'laag'],
    ['ean' => '00000000000218', 'naam' => 'Demo Micro Catheter', 'cat' => 'Catheter', 'besteltype' => 'Handmatig', 'min' => [1, 0, 0, 2], 'max' => [3, 2, 0, 6], 'producent' => 'Sample Medical BV', 'prijs' => 285.00, 'houdbaarheid' => 30, 'verbruik' => 'laag'],
    ['ean' => '00000000000219', 'naam' => 'Demo Hemostatic Valve', 'cat' => 'Overig', 'besteltype' => 'Automatisch', 'min' => [2, 1, 1, 5], 'max' => [5, 3, 3, 10], 'producent' => 'Generic Supplies Inc', 'prijs' => 12.40, 'houdbaarheid' => 36, 'verbruik' => 'gemiddeld'],
    ['ean' => '00000000000220', 'naam' => 'Demo Contrast Agent 100ml', 'cat' => 'Overig', 'besteltype' => 'Automatisch', 'min' => [3, 2, 2, 8], 'max' => [9, 6, 5, 20], 'producent' => 'Generic Supplies Inc', 'prijs' => 15.60, 'houdbaarheid' => 18, 'verbruik' => 'hoog'],
];

$SLOT_INDEX = ['ik' => 0, 'hybok' => 1, 'kar' => 2, 'berging' => 3];
$VERBRUIK_LOCATIES = ['ik', 'hybok', 'kar'];
$KANALEN = ['Webshop', 'Telefonisch', 'Fax', 'E-mail leverancier'];
$DAGELIJKSE_KANS = ['hoog' => 0.35, 'gemiddeld' => 0.15, 'laag' => 0.05];

$pdo = db();

try {
    $pdo->beginTransaction();

    // ---------------------------------------------------------------
    // Gebruikers, specialisten, procedure typen, artikelen
    // ---------------------------------------------------------------
    testdata_log('Gebruikers aanmaken (' . count($GEBRUIKERS) . ')...');
    $insUser = $pdo->prepare(
        'INSERT INTO users (naam, email, wachtwoord_hash, rol, actief, taal) VALUES (:naam, :email, :hash, :rol, 1, :taal)'
    );
    foreach ($GEBRUIKERS as $g) {
        $insUser->execute([
            ':naam' => $g['naam'], ':email' => $g['email'],
            ':hash' => password_hash($DEMO_WACHTWOORD, PASSWORD_DEFAULT),
            ':rol' => $g['rol'], ':taal' => $g['taal'],
        ]);
    }
    $gebruikerNamen = array_column($GEBRUIKERS, 'naam');

    testdata_log('Specialisten aanmaken (' . count($SPECIALISTEN) . ')...');
    $insSpec = $pdo->prepare('INSERT INTO specialisten (naam, type, actief) VALUES (:naam, :type, 1)');
    foreach ($SPECIALISTEN as $s) $insSpec->execute([':naam' => $s['naam'], ':type' => $s['type']]);
    $specialistNamen = array_column($SPECIALISTEN, 'naam');

    testdata_log('Procedure typen aanmaken (' . count($PROCEDURE_TYPEN) . ')...');
    $insProc = $pdo->prepare('INSERT INTO procedure_typen (naam, actief) VALUES (:naam, 1)');
    foreach ($PROCEDURE_TYPEN as $p) $insProc->execute([':naam' => $p]);

    // Migratie 031, op verzoek van Harm: min_ik/min_hybok/min_kar bestaan
    // niet meer (kamers hebben geen eigen minimum meer, zie
    // includes/voorraad.php::artikelMinMax()) -- de 'min'-array hierboven
    // per artikel blijft ongewijzigd (handig als referentiegetal / voor
    // eventueel toekomstig gebruik), alleen index 3 (berging) wordt hier
    // nog daadwerkelijk weggeschreven.
    testdata_log('Artikelen aanmaken (' . count($ARTIKELEN) . ')...');
    $insArtikel = $pdo->prepare(
        'INSERT INTO artikelen
            (ean, productnaam, categorie, besteltype,
             max_ik, max_hybok, max_kar, min_berging, max_berging,
             locatie_ik, locatie_berging, locatie_hybok, artikelref, producent, prijs)
         VALUES
            (:ean, :naam, :cat, :besteltype,
             :max_ik, :max_hybok, :max_kar, :min_berging, :max_berging,
             :loc_ik, :loc_berging, :loc_hybok, :ref, :producent, :prijs)'
    );
    foreach ($ARTIKELEN as $i => $a) {
        $insArtikel->execute([
            ':ean' => $a['ean'], ':naam' => $a['naam'], ':cat' => $a['cat'], ':besteltype' => $a['besteltype'],
            ':max_ik' => $a['max'][0],
            ':max_hybok' => $a['max'][1],
            ':max_kar' => $a['max'][2],
            ':min_berging' => $a['min'][3], ':max_berging' => $a['max'][3],
            ':loc_ik' => 'Cabinet ' . chr(65 + ($i % 6)) . '1', ':loc_berging' => 'Shelf ' . (3 + ($i % 5)) . 'B',
            ':loc_hybok' => 'Cabinet H' . ($i + 1),
            ':ref' => sprintf('DEMO-%03d', $i + 1), ':producent' => $a['producent'], ':prijs' => $a['prijs'],
        ]);
    }

    testdata_log('Audit log aanvullen voor de zojuist aangemaakte stamdata...');
    $insAudit = $pdo->prepare(
        'INSERT INTO audit_log (tijdstempel, gebruiker_naam, actie, entiteit_type, entiteit_id, details) VALUES (:t, :g, :actie, :type, :id, :details)'
    );
    $vandaagVoorAudit = (new DateTime('today'))->modify('-' . $AANTAL_DAGEN . ' days')->format('Y-m-d') . ' 09:00:00';
    foreach ($ARTIKELEN as $a) {
        $insAudit->execute([
            ':t' => $vandaagVoorAudit, ':g' => $gebruikerNamen[0], ':actie' => 'product_toegevoegd',
            ':type' => 'artikel', ':id' => $a['ean'], ':details' => 'naam=' . $a['naam'],
        ]);
    }

    // Locatienamen ophalen i.p.v. te veronderstellen dat ze nog de
    // standaardnamen ("Room A" etc.) hebben -- respecteert een eventuele
    // eigen configuratie via Admin -> Locations.
    $locatieNaamPerSlot = $pdo->query('SELECT slot, naam FROM locatie_instellingen WHERE actief = 1')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (array_keys($SLOT_INDEX) as $slot) {
        if (!isset($locatieNaamPerSlot[$slot])) {
            throw new RuntimeException(
                "Locatie-slot '$slot' staat uit (Admin -> Locations) -- zet alle vier de vaste slots eerst actief voordat je testdata genereert."
            );
        }
    }
    $bergingLocatie = $locatieNaamPerSlot['berging'];

    // ---------------------------------------------------------------
    // Simulatie
    // ---------------------------------------------------------------
    testdata_log("Transactiehistorie simuleren ($AANTAL_DAGEN dagen)...");

    $insTrans = $pdo->prepare(
        'INSERT INTO transactielog
            (tijdstempel, ean, lot, vervaldatum, locatie, transactietype, hoeveelheid, prijs_op_moment,
             specialist, procedure_type, opmerking, productnaam, aangemaakt_door)
         VALUES
            (:tijdstempel, :ean, :lot, :vervaldatum, :locatie, :type, :hoeveelheid, :prijs,
             :specialist, :procedure, :opmerking, :naam, :gebruiker)'
    );
    $insOrder = $pdo->prepare(
        'INSERT INTO orders (tijdstempel, ean, productnaam, hoeveelheid_besteld, hoeveelheid_ontvangen, besteller, kanaal, opmerking)
         VALUES (:tijdstempel, :ean, :naam, :besteld, 0, :besteller, :kanaal, :opmerking)'
    );
    $ontvangOrder = $pdo->prepare(
        'UPDATE orders SET hoeveelheid_ontvangen = hoeveelheid_besteld, laatst_ontvangen_op = :tijdstempel WHERE id = :id'
    );
    $updGeteld = $pdo->prepare('UPDATE locatie_instellingen SET laatst_geteld_op = :t WHERE slot = :slot');

    $start = (new DateTime('today'))->modify('-' . $AANTAL_DAGEN . ' days');

    // batches[ean][locatie] = lijst van ['lot','vervaldatum','resterend'],
    // oudste eerst (FEFO) -- nieuwe loten worden altijd achteraan
    // toegevoegd, verbruik/afboeking consumeert altijd vooraan.
    $batches = [];
    $openOrders = []; // ean => ['id', 'aantal', 'ontvangOp' => 'Y-m-d']
    $lotTeller = 0;

    // Startvoorraad: elk artikel begint rond het midden van min/max op
    // elke locatie waar het hoort, zodat er vanaf dag 1 al iets te
    // verbruiken/tellen/laten vervallen is i.p.v. een jaar op de eerste
    // order te moeten wachten.
    //
    // BELANGRIJK: dit MOET ook als een 'Ontvangst'-transactieregel
    // geboekt worden, ook al is het geen echt scan-moment. De app kent
    // geen apart "openingsvoorraad"-veld -- voorraadStatus()
    // (includes/voorraad.php::transactieNettoPerLocatie()) berekent de
    // huidige voorraad puur als SUM(inkomend) - SUM(uitgaand) uit
    // transactielog, zonder enige andere bron. Zonder deze regel wordt
    // deze startvoorraad wel verbruikt/verplaatst/afgeboekt (met een
    // bijbehorende uitgaande transactie) maar nooit ontvangen -- met
    // negatieve berekende voorraad tot gevolg zodra de startvoorraad op
    // een locatie een keer helemaal weggebruikt is. (Eerder een echte bug
    // gebleken in gegenereerde testdata.)
    foreach ($ARTIKELEN as $a) {
        foreach ($SLOT_INDEX as $slot => $idx) {
            $max = $a['max'][$idx];
            if ($max <= 0) continue;
            $startAantal = $a['min'][$idx] + intdiv(max(0, $max - $a['min'][$idx]), 2);
            if ($startAantal <= 0) continue;

            $lotTeller++;
            $lot = 'STARTLOT' . str_pad((string) $lotTeller, 5, '0', STR_PAD_LEFT);
            $vervaldatum = (clone $start)->modify('+' . mt_rand(2, $a['houdbaarheid']) . ' months')->format('Y-m-d');
            $locatieNaam = $locatieNaamPerSlot[$slot];
            $batches[$a['ean']][$locatieNaam][] = [
                'lot' => $lot,
                'vervaldatum' => $vervaldatum,
                'resterend' => $startAantal,
            ];
            $insTrans->execute([
                ':tijdstempel' => $start->format('Y-m-d') . ' 08:00:00', ':ean' => $a['ean'],
                ':lot' => $lot, ':vervaldatum' => $vervaldatum,
                ':locatie' => $locatieNaam, ':type' => 'Ontvangst', ':hoeveelheid' => $startAantal,
                ':prijs' => $a['prijs'],
                ':specialist' => null, ':procedure' => null,
                ':opmerking' => 'Openingsvoorraad (testdata)', ':naam' => $a['naam'],
                ':gebruiker' => $gebruikerNamen[0],
            ]);
        }
    }

    for ($dag = 0; $dag <= $AANTAL_DAGEN; $dag++) {
        $datum = (clone $start)->modify("+$dag days");
        $datumStr = $datum->format('Y-m-d');

        foreach ($ARTIKELEN as $a) {
            $ean = $a['ean'];
            $kans = $DAGELIJKSE_KANS[$a['verbruik']];

            // --- Verbruik + bijvullen-vanuit-berging op de drie verbruiklocaties ---
            foreach ($VERBRUIK_LOCATIES as $slot) {
                $idx = $SLOT_INDEX[$slot];
                if ($a['max'][$idx] <= 0) continue;
                $locatie = $locatieNaamPerSlot[$slot];

                if (mt_rand() / mt_getrandmax() < $kans) {
                    $gewenst = (mt_rand(1, 100) <= 15) ? 2 : 1;
                    $verbruikt = testdata_verbruik($batches, $ean, $locatie, $gewenst);
                    if ($verbruikt['aantal'] > 0) {
                        $insTrans->execute([
                            ':tijdstempel' => testdata_tijd($datumStr), ':ean' => $ean,
                            ':lot' => $verbruikt['lot'], ':vervaldatum' => $verbruikt['vervaldatum'],
                            ':locatie' => $locatie, ':type' => 'Verbruik', ':hoeveelheid' => $verbruikt['aantal'],
                            ':prijs' => $a['prijs'],
                            ':specialist' => $specialistNamen[array_rand($specialistNamen)],
                            ':procedure' => $PROCEDURE_TYPEN[array_rand($PROCEDURE_TYPEN)],
                            ':opmerking' => null, ':naam' => $a['naam'],
                            ':gebruiker' => $gebruikerNamen[array_rand($gebruikerNamen)],
                        ]);
                    }
                }

                $huidig = testdata_voorraad($batches, $ean, $locatie);
                if ($huidig < $a['min'][$idx]) {
                    $benodigd = $a['max'][$idx] - $huidig;
                    $beschikbaar = testdata_voorraad($batches, $ean, $bergingLocatie);
                    $teVerplaatsen = min($benodigd, $beschikbaar);
                    if ($teVerplaatsen > 0) {
                        $vanuit = testdata_verbruik($batches, $ean, $bergingLocatie, $teVerplaatsen);
                        if ($vanuit['aantal'] > 0) {
                            $tijd = testdata_tijd($datumStr);
                            $gebruiker = $gebruikerNamen[array_rand($gebruikerNamen)];
                            $insTrans->execute([
                                ':tijdstempel' => $tijd, ':ean' => $ean, ':lot' => $vanuit['lot'], ':vervaldatum' => $vanuit['vervaldatum'],
                                ':locatie' => $bergingLocatie, ':type' => 'Verplaatsing uit', ':hoeveelheid' => $vanuit['aantal'],
                                ':prijs' => $a['prijs'], ':specialist' => null, ':procedure' => null,
                                ':opmerking' => 'Bijvullen ' . $locatie, ':naam' => $a['naam'], ':gebruiker' => $gebruiker,
                            ]);
                            $insTrans->execute([
                                ':tijdstempel' => $tijd, ':ean' => $ean, ':lot' => $vanuit['lot'], ':vervaldatum' => $vanuit['vervaldatum'],
                                ':locatie' => $locatie, ':type' => 'Verplaatsing in', ':hoeveelheid' => $vanuit['aantal'],
                                ':prijs' => $a['prijs'], ':specialist' => null, ':procedure' => null,
                                ':opmerking' => 'Bijgevuld vanuit ' . $bergingLocatie, ':naam' => $a['naam'], ':gebruiker' => $gebruiker,
                            ]);
                            $batches[$ean][$locatie][] = [
                                'lot' => $vanuit['lot'], 'vervaldatum' => $vanuit['vervaldatum'], 'resterend' => $vanuit['aantal'],
                            ];
                        }
                    }
                }
            }

            // --- Berging zelf: order plaatsen als onder minimum en nog geen order openstaat ---
            if ($a['max'][3] > 0 && !isset($openOrders[$ean])) {
                $huidigBerging = testdata_voorraad($batches, $ean, $bergingLocatie);
                if ($huidigBerging < $a['min'][3]) {
                    $besteld = $a['max'][3] - $huidigBerging;
                    $insOrder->execute([
                        ':tijdstempel' => testdata_tijd($datumStr), ':ean' => $ean, ':naam' => $a['naam'], ':besteld' => $besteld,
                        ':besteller' => $gebruikerNamen[array_rand($gebruikerNamen)], ':kanaal' => $KANALEN[array_rand($KANALEN)],
                        ':opmerking' => null,
                    ]);
                    $openOrders[$ean] = [
                        'id' => (int) $pdo->lastInsertId(),
                        'aantal' => $besteld,
                        'ontvangOp' => (clone $datum)->modify('+' . testdata_levertijd_dagen() . ' days')->format('Y-m-d'),
                    ];
                }
            }

            // --- Openstaande order ontvangen zodra de datum is bereikt ---
            if (isset($openOrders[$ean]) && $openOrders[$ean]['ontvangOp'] <= $datumStr) {
                $order = $openOrders[$ean];
                $tijd = testdata_tijd($datumStr);
                $ontvangOrder->execute([':tijdstempel' => $tijd, ':id' => $order['id']]);

                $lotTeller++;
                $vervaldatum = (clone $datum)->modify('+' . mt_rand(max(2, $a['houdbaarheid'] - 4), $a['houdbaarheid']) . ' months')->format('Y-m-d');
                $lot = 'LOT' . $datum->format('ym') . str_pad((string) $lotTeller, 4, '0', STR_PAD_LEFT);
                $batches[$ean][$bergingLocatie][] = ['lot' => $lot, 'vervaldatum' => $vervaldatum, 'resterend' => $order['aantal']];

                $insTrans->execute([
                    ':tijdstempel' => $tijd, ':ean' => $ean, ':lot' => $lot, ':vervaldatum' => $vervaldatum,
                    ':locatie' => $bergingLocatie, ':type' => 'Ontvangst', ':hoeveelheid' => $order['aantal'], ':prijs' => $a['prijs'],
                    ':specialist' => null, ':procedure' => null, ':opmerking' => null,
                    ':naam' => $a['naam'], ':gebruiker' => $gebruikerNamen[array_rand($gebruikerNamen)],
                ]);
                unset($openOrders[$ean]);
            }

            // --- Vervallen: loten waarvan de vervaldatum is gepasseerd, op elke locatie ---
            foreach (array_merge($VERBRUIK_LOCATIES, ['berging']) as $slot) {
                $locatie = $locatieNaamPerSlot[$slot];
                if (empty($batches[$ean][$locatie])) continue;

                foreach ($batches[$ean][$locatie] as $bi => $batch) {
                    if ($batch['resterend'] <= 0 || $batch['vervaldatum'] === null || $batch['vervaldatum'] > $datumStr) continue;
                    $insTrans->execute([
                        ':tijdstempel' => testdata_tijd($datumStr), ':ean' => $ean, ':lot' => $batch['lot'], ':vervaldatum' => $batch['vervaldatum'],
                        ':locatie' => $locatie, ':type' => 'Vervallen', ':hoeveelheid' => $batch['resterend'], ':prijs' => $a['prijs'],
                        ':specialist' => null, ':procedure' => null, ':opmerking' => 'Automatisch afgeboekt (vervaldatum gepasseerd)',
                        ':naam' => $a['naam'], ':gebruiker' => $gebruikerNamen[array_rand($gebruikerNamen)],
                    ]);
                    $batches[$ean][$locatie][$bi]['resterend'] = 0;
                }
                $batches[$ean][$locatie] = array_values(array_filter($batches[$ean][$locatie], fn($b) => $b['resterend'] > 0));
            }
        }

        // --- Periodieke kastcontrole: elke ~90 dagen, alle locaties, kleine afwijkingen ---
        if ($dag > 0 && $dag % 90 === 0) {
            foreach (array_keys($SLOT_INDEX) as $slot) {
                $locatie = $locatieNaamPerSlot[$slot];
                foreach ($ARTIKELEN as $a) {
                    $ean = $a['ean'];
                    $systeem = testdata_voorraad($batches, $ean, $locatie);
                    if ($systeem === 0 && mt_rand(1, 100) > 5) continue;

                    $afwijking = (mt_rand(1, 100) <= 12) ? mt_rand(-2, 2) : 0;
                    if ($afwijking === 0) continue;

                    $geteld = max(0, $systeem + $afwijking);
                    $diff = $geteld - $systeem;
                    $type = $diff > 0 ? 'Telling: overschot' : 'Telling: tekort';
                    $insTrans->execute([
                        ':tijdstempel' => testdata_tijd($datumStr, 9, 16), ':ean' => $ean, ':lot' => null, ':vervaldatum' => null,
                        ':locatie' => $locatie, ':type' => $type, ':hoeveelheid' => abs($diff), ':prijs' => $a['prijs'],
                        ':specialist' => null, ':procedure' => null,
                        ':opmerking' => "Kastcontrole: systeem $systeem, geteld $geteld.",
                        ':naam' => $a['naam'], ':gebruiker' => $gebruikerNamen[array_rand($gebruikerNamen)],
                    ]);

                    if ($diff > 0) {
                        $batches[$ean][$locatie][] = ['lot' => null, 'vervaldatum' => null, 'resterend' => $diff];
                    } else {
                        testdata_verbruik($batches, $ean, $locatie, abs($diff));
                    }
                }
                $updGeteld->execute([':t' => $datumStr . ' 10:00:00', ':slot' => $slot]);
            }
        }
    }

    // ---------------------------------------------------------------
    // Een paar bewust ingevoegde scenario's, los van de dag-simulatie
    // hierboven. Reden: met FEFO-verbruik + tijdig bijvullen "werkt" het
    // gesimuleerde systeem te goed -- de oudste partij wordt vrijwel
    // altijd op tijd verbruikt, dus er komt in de gewone simulatie bijna
    // nooit iets daadwerkelijk over datum. Precies de Vervaldatum-/
    // Afboekingen-schermen zijn er juist voor bedoeld om zulke gevallen
    // te laten zien, dus forceren we er een paar:
    //  (a) een al verlopen partij die nog in voorraad staat, nog niet
    //      afgeboekt;
    //  (b) een partij die binnenkort verloopt (SPOEDGEBRUIK-status);
    //  (c) een partij die in het verleden daadwerkelijk is afgeboekt
    //      (Write-offs-rapport heeft dan echte historie i.p.v. nul).
    // ---------------------------------------------------------------
    $vandaag = new DateTime('today');
    $scenarioProducten = $ARTIKELEN;
    shuffle($scenarioProducten);
    $scenarioProducten = array_slice($scenarioProducten, 0, 8);

    foreach (array_slice($scenarioProducten, 0, 3) as $a) {
        $slot = $a['max'][1] > 0 ? 'hybok' : 'ik';
        $locatie = $locatieNaamPerSlot[$slot];
        $dagenGeleden = $a['houdbaarheid'] * 30 + mt_rand(5, 25);
        $ontvangstDatum = (clone $vandaag)->modify("-$dagenGeleden days");
        $vervaldatum = (clone $ontvangstDatum)->modify('+' . ($a['houdbaarheid'] * 30) . ' days');
        $lotTeller++;
        $insTrans->execute([
            ':tijdstempel' => testdata_tijd($ontvangstDatum->format('Y-m-d')), ':ean' => $a['ean'],
            ':lot' => 'VERLOPEN' . str_pad((string) $lotTeller, 5, '0', STR_PAD_LEFT), ':vervaldatum' => $vervaldatum->format('Y-m-d'),
            ':locatie' => $locatie, ':type' => 'Ontvangst', ':hoeveelheid' => mt_rand(2, 4), ':prijs' => $a['prijs'],
            ':specialist' => null, ':procedure' => null, ':opmerking' => null,
            ':naam' => $a['naam'], ':gebruiker' => $gebruikerNamen[array_rand($gebruikerNamen)],
        ]);
    }

    foreach (array_slice($scenarioProducten, 3, 3) as $a) {
        $slot = $a['max'][0] > 0 ? 'ik' : 'hybok';
        $locatie = $locatieNaamPerSlot[$slot];
        $vervaldatum = (clone $vandaag)->modify('+' . mt_rand(10, 45) . ' days');
        $ontvangstDatum = (clone $vervaldatum)->modify('-' . ($a['houdbaarheid'] * 30) . ' days');
        $lotTeller++;
        $insTrans->execute([
            ':tijdstempel' => testdata_tijd($ontvangstDatum->format('Y-m-d')), ':ean' => $a['ean'],
            ':lot' => 'SPOEDIG' . str_pad((string) $lotTeller, 5, '0', STR_PAD_LEFT), ':vervaldatum' => $vervaldatum->format('Y-m-d'),
            ':locatie' => $locatie, ':type' => 'Ontvangst', ':hoeveelheid' => mt_rand(2, 5), ':prijs' => $a['prijs'],
            ':specialist' => null, ':procedure' => null, ':opmerking' => null,
            ':naam' => $a['naam'], ':gebruiker' => $gebruikerNamen[array_rand($gebruikerNamen)],
        ]);
    }

    foreach (array_slice($scenarioProducten, 6, 2) as $a) {
        $dagenGeledenOntvangst = $a['houdbaarheid'] * 30 + mt_rand(60, 120);
        $ontvangstDatum = (clone $vandaag)->modify("-$dagenGeledenOntvangst days");
        $vervaldatum = (clone $ontvangstDatum)->modify('+' . ($a['houdbaarheid'] * 30) . ' days');
        $afboekDatum = (clone $vervaldatum)->modify('+' . mt_rand(3, 20) . ' days');
        $aantal = mt_rand(1, 3);
        $lotTeller++;
        $lot = 'AFGEBOEKT' . str_pad((string) $lotTeller, 5, '0', STR_PAD_LEFT);
        $insTrans->execute([
            ':tijdstempel' => testdata_tijd($ontvangstDatum->format('Y-m-d')), ':ean' => $a['ean'],
            ':lot' => $lot, ':vervaldatum' => $vervaldatum->format('Y-m-d'),
            ':locatie' => $bergingLocatie, ':type' => 'Ontvangst', ':hoeveelheid' => $aantal, ':prijs' => $a['prijs'],
            ':specialist' => null, ':procedure' => null, ':opmerking' => null,
            ':naam' => $a['naam'], ':gebruiker' => $gebruikerNamen[array_rand($gebruikerNamen)],
        ]);
        $insTrans->execute([
            ':tijdstempel' => testdata_tijd($afboekDatum->format('Y-m-d')), ':ean' => $a['ean'],
            ':lot' => $lot, ':vervaldatum' => $vervaldatum->format('Y-m-d'),
            ':locatie' => $bergingLocatie, ':type' => 'Vervallen', ':hoeveelheid' => $aantal, ':prijs' => $a['prijs'],
            ':specialist' => null, ':procedure' => null, ':opmerking' => 'Automatisch afgeboekt (vervaldatum gepasseerd)',
            ':naam' => $a['naam'], ':gebruiker' => $gebruikerNamen[array_rand($gebruikerNamen)],
        ]);
    }

    // ---------------------------------------------------------------
    // Eenmalige (patiëntgebonden) bestellingen -- duidelijk fictieve
    // patiëntnummers (TEST-xxxxx), zelfde privacyvoorzichtigheid als
    // elders in dit project.
    // ---------------------------------------------------------------
    testdata_log('Eenmalige bestellingen aanmaken (18, fictief)...');
    $statussen = ['Aangevraagd', 'Besteld', 'Ontvangen', 'Gebruikt'];
    $insEenmalig = $pdo->prepare(
        'INSERT INTO eenmalige_bestellingen (tijdstempel, patientnummer, artikelomschrijving, ean, hoeveelheid, procedure_type, specialist, besteller, opmerking, status)
         VALUES (:tijdstempel, :patient, :omschrijving, :ean, :hoeveelheid, :procedure, :specialist, :besteller, :opmerking, :status)'
    );
    for ($i = 0; $i < 18; $i++) {
        $a = $ARTIKELEN[array_rand($ARTIKELEN)];
        $dagOffset = mt_rand(0, $AANTAL_DAGEN);
        $tijd = testdata_tijd((clone $start)->modify("+$dagOffset days")->format('Y-m-d'));
        // Recente aanvragen (laatste 3 weken) staan realistischer nog niet op "Gebruikt".
        $status = $dagOffset < $AANTAL_DAGEN - 21 ? $statussen[array_rand($statussen)] : $statussen[mt_rand(0, 1)];
        $insEenmalig->execute([
            ':tijdstempel' => $tijd, ':patient' => 'TEST-' . str_pad((string) mt_rand(1, 9999), 5, '0', STR_PAD_LEFT),
            ':omschrijving' => $a['naam'], ':ean' => $a['ean'], ':hoeveelheid' => mt_rand(1, 2),
            ':procedure' => $PROCEDURE_TYPEN[array_rand($PROCEDURE_TYPEN)], ':specialist' => $specialistNamen[array_rand($specialistNamen)],
            ':besteller' => $gebruikerNamen[array_rand($gebruikerNamen)], ':opmerking' => null, ':status' => $status,
        ]);
    }

    // ---------------------------------------------------------------
    // Foutlog -- een paar fictieve mislukte scans, voor het Error Log-scherm.
    // ---------------------------------------------------------------
    testdata_log('Foutlog aanvullen (6, fictief)...');
    $fouttypen = ['GS1_PARSE_ERROR', 'ONBEKEND_ARTIKEL', 'FOTO_LAAD_ERROR'];
    $insFout = $pdo->prepare('INSERT INTO foutlog (tijdstip, fouttype, barcode, bericht) VALUES (:tijdstip, :type, :barcode, :bericht)');
    for ($i = 0; $i < 6; $i++) {
        $dagOffset = mt_rand(0, $AANTAL_DAGEN);
        $insFout->execute([
            ':tijdstip' => testdata_tijd((clone $start)->modify("+$dagOffset days")->format('Y-m-d')),
            ':type' => $fouttypen[array_rand($fouttypen)],
            ':barcode' => '(01)0999999' . mt_rand(100000, 999999),
            ':bericht' => 'Fictieve testmelding, gegenereerd door migratie/genereer_testdata.php.',
        ]);
    }

    $pdo->commit();

    $aantalTrans = (int) $pdo->query('SELECT COUNT(*) FROM transactielog')->fetchColumn();
    $aantalOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $aantalOpenOrders = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE hoeveelheid_ontvangen < hoeveelheid_besteld"
    )->fetchColumn();

    testdata_log('');
    testdata_log('Klaar. Gegenereerd:');
    testdata_log('  - ' . count($ARTIKELEN) . ' artikelen, ' . count($GEBRUIKERS) . ' gebruikers, ' . count($SPECIALISTEN) . ' specialisten, ' . count($PROCEDURE_TYPEN) . ' procedure typen');
    testdata_log("  - $aantalTrans transactielog-regels over $AANTAL_DAGEN dagen");
    testdata_log("  - $aantalOrders orders ($aantalOpenOrders nog open)");
    testdata_log('  - 18 eenmalige bestellingen, 6 foutlog-regels');
    testdata_log('');
    testdata_log("Inloggen met elk van de gebruikers hierboven, wachtwoord: $DEMO_WACHTWOORD");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Genereren mislukt, alles teruggedraaid: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
