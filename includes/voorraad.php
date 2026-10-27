<?php
/**
 * Kern van de voorraadberekening. Vervangt de Voorraad-Status- en
 * Voorraad-Lot-tabbladen (die spreadsheetformules waren, geen
 * brontabellen -- zie migratieplan hoofdstuk 4.5): hier wordt live
 * vanuit transactielog + artikelen berekend, exact dezelfde optel-
 * /aftreklogica als de oude SUMIFS-formules.
 *
 * Twee simplificaties t.o.v. de oude Apps Script-code, mogelijk gemaakt
 * doordat MariaDB typering afdwingt waar Sheets alleen tekst had:
 *  - geen parsePrijs() meer nodig: artikelen.prijs is al DECIMAL.
 *  - geen cleanStatusText()/emoji-strip meer nodig: PHP produceert de
 *    statustekst zelf, kaal, er komt geen sheet-emoji meer aan te pas.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/ean.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/locaties.php';
// Audit-logging voor markeerBergingAlsBesteld() hieronder.
require_once __DIR__ . '/audit.php';
// ActueleNaam() -- transactieNettoPerLocatie()/voorraadLot()
// hieronder groeperen per locatienaam, dus een oude naam moet eerst naar
// zijn actuele naam opgelost worden, anders splitst een hernoemde locatie
// in twee losse groepen. Zie includes/hernoemingen.php.
require_once __DIR__ . '/hernoemingen.php';
// SpecLabels() hieronder is verplaatst van een hardcoded
// switch naar de database (categorieen/categorie_specs), beheerbaar via
// Stamgegevens -- zie includes/categorieen.php.
require_once __DIR__ . '/categorieen.php';

/**
 * Alle artikelen, geïndexeerd op EAN. Poort van buildArtikelenMap().
 */
function artikelenMap(): array {
    $rows = db()->query('SELECT * FROM artikelen')->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[$row['ean']] = $row;
    }
    return $map;
}

/**
 * Min/max voor een artikel op een specifieke locatie. Poort van de
 * IF(locatie=...)-ketens in de oude Voorraad-Status-formules (kolom F/G).
 *
 * Kamers (elke locatie behalve
 * 'berging') worden gegarandeerd dezelfde dag vanuit Storage tot hun
 * maximum bijgevuld -- een eigen reorder-minimum heeft daar dus nooit een
 * functie gehad en de kolommen (min_ik/min_hybok/min_kar,
 * artikel_locatie_extra.min_aantal) zijn verwijderd. Min blijft hier 0 voor
 * die locaties (geen aparte kolom meer om uit te lezen); alle bestaande
 * code die [$min, $max] destructureert blijft daardoor gewoon werken --
 * $min is voor kamers voortaan altijd 0 door constructie, in plaats van
 * toevallig altijd 0 door lege data. Alleen 'berging' behoudt een echt
 * ingesteld minimum (het enige reorder-punt in het systeem, zie
 * includes/minmax.php).
 */
function artikelMinMax(array $artikel, string $locatie): array {
    $slot = locatieSlotVoorNaam($locatie);
    // null = geen van de vier vaste slots -> een door de gebruiker
    // Toegevoegde extra locatie, opgezocht in een aparte
    // tabel i.p.v. een vaste kolom op artikelen.
    if ($slot === null) return artikelLocatieExtraMinMax($artikel['ean'], $locatie);
    return match ($slot) {
        'ik' => [0, (int) $artikel['max_ik']],
        'hybok' => [0, (int) $artikel['max_hybok']],
        'kar' => [0, (int) $artikel['max_kar']],
        'berging' => [(int) $artikel['min_berging'], (int) $artikel['max_berging']],
        default => [0, 0],
    };
}

/**
 * Kastlocatie voor een artikel op een specifieke locatie. Poort van
 * getKastlocatie(). De 'kar'-slot heeft geen vaste kastlocatie (mobiel)
 * -- die rol blijft aan het slot gebonden, ongeacht de gekozen naam.
 */
function artikelKastlocatie(array $artikel, string $locatie): string {
    $slot = locatieSlotVoorNaam($locatie);
    if ($slot === null) return artikelLocatieExtraKastlocatie($artikel['ean'], $locatie);
    return match ($slot) {
        'ik' => $artikel['locatie_ik'] ?? '',
        'berging' => $artikel['locatie_berging'] ?? '',
        'hybok' => $artikel['locatie_hybok'] ?? '',
        'kar' => 'Mobile (no fixed location)',
        default => '',
    };
}

/**
 * Categorie-specifieke specificaties uit artikelen.specs (JSON) als
 * label/value-paren voor weergave. Poort van getCategorieSpecs().
 *
 * De categorie -> veld-mapping stond
 * hier tot dan toe hardcoded (een switch op substrings zoals 'stent',
 * 'ballon', ...). Dat is nu een database-tabel (categorieen/
 * categorie_specs, zie includes/categorieen.php) die via het nieuwe
 * Stamgegevens-blok "Categorieën & Eigenschappen" bewerkt kan worden,
 * zodat een beheerder zelf nieuwe categorieën en eigenschappen kan
 * toevoegen zonder dat daar een codewijziging voor nodig is. Deze functie
 * behoudt bewust dezelfde naam/signatuur -- alle bestaande aanroepers
 * (includes/artikelen.php: getArtikelEditData(), addArtikel(),
 * getProductDetail()) blijven ongewijzigd werken.
 */
function specLabels(string $categorie): array {
    return specLabelsVoorCategorie($categorie);
}

function getCategorieSpecs(string $categorie, ?string $specsJson): array {
    if (!$specsJson) return [];
    $specs = json_decode($specsJson, true) ?: [];
    $labels = specLabels($categorie);
    $result = [];
    foreach ($labels as $key => $label) {
        if (isset($specs[$key]) && $specs[$key] !== '' && $specs[$key] !== null) {
            $result[] = ['label' => $label, 'value' => $specs[$key]];
        }
    }
    return $result;
}

/**
 * VERLOPEN/SPOEDGEBRUIK/MONITOREN/GELDIG/LANGDURIG op basis van dagen
 * tot vervaldatum. In de oude code vier keer identiek gedupliceerd
 * (getVervaldatumDetail, getLotTracking, getActiecentrumData,
 * getVervaldatumFocus) -- hier één keer.
 */
function classifyVervalStatus(int $daysLeft): string {
    $monthsLeft = $daysLeft / 30.44;
    if ($daysLeft < 0) return 'VERLOPEN';
    if ($monthsLeft < 3) return 'SPOEDGEBRUIK';
    if ($monthsLeft < 12) return 'MONITOREN';
    if ($monthsLeft < 24) return 'GELDIG';
    return 'LANGDURIG';
}

function daysLeftUntil(?string $sqlDate): ?int {
    if (!$sqlDate) return null;
    $today = new DateTime('today');
    $expiry = new DateTime($sqlDate);
    return (int) $today->diff($expiry)->format('%r%a');
}

/**
 * Zet een array vaste, letterlijke transactietype-strings (nooit
 * gebruikersinvoer) om in een kant-en-klare SQL IN(...)-lijst. Gebruikt
 * door transactieNettoPerLocatie()/voorraadLot() hieronder en door
 * includes/taken.php, zodat TRANSACTIETYPES_INKOMEND/_UITGAAND
 * (config/constants.php) de enige plek is waar bepaald wordt welk type
 * de voorraad ophoogt/verlaagt -- een nieuw transactietype toevoegen
 * (zoals 'Telling: overschot'/'Telling: tekort'/'Vervallen')
 * hoeft dan nergens anders meer aangepast te worden.
 */
function sqlInList(array $waarden): string {
    return "'" . implode("','", array_map(fn($v) => str_replace("'", "''", $v), $waarden)) . "'";
}

/**
 * Netto in/uit per EAN+locatie, uit transactielog. Eén query, hierna
 * in PHP tegen elke artikel x locatie-combinatie gelegd -- zelfde
 * aanpak als de oude SUMIFS, maar dan client-side over een kleine array
 * (265 artikelen x 4 locaties is triviaal, geen N+1-risico).
 */
function transactieNettoPerLocatie(): array {
    $rows = db()->query(
        "SELECT ean, locatie,
                SUM(CASE WHEN transactietype IN (" . sqlInList(TRANSACTIETYPES_INKOMEND) . ") THEN hoeveelheid ELSE 0 END) AS inkomend,
                SUM(CASE WHEN transactietype IN (" . sqlInList(TRANSACTIETYPES_UITGAAND) . ") THEN hoeveelheid ELSE 0 END) AS uitgaand
         FROM transactielog GROUP BY ean, locatie"
    )->fetchAll();

    // (locatie-hernoemen cascade-bug):
    // GROUP BY hierboven groepeert op de RUWE, ten tijde van elke
    // transactie opgeslagen locatienaam -- na een rename (zie
    // includes/locaties.php::updateLocatieInstelling()/updateLocatieExtra())
    // vormen oude en nieuwe naam anders twee losse sleutels in $map, en
    // voorraadStatus() (die alleen op de HUIDIGE naam opzoekt) zou de
    // oude-naam-rij dan nooit meer vinden. actueleNaam() lost elke ruwe
    // naam op naar zijn huidige naam vóórdat de map gevuld wordt, en de
    // += hieronder merget wat voorheen aparte sleutels waren.
    $map = [];
    foreach ($rows as $r) {
        $locatie = actueleNaam('locatie', $r['locatie']);
        $map[$r['ean']][$locatie] = ($map[$r['ean']][$locatie] ?? 0) + (int) $r['inkomend'] - (int) $r['uitgaand'];
    }
    return $map;
}

/**
 * Zelfde berekening als transactieNettoPerLocatie(), maar voor één EAN --
 * Bij Verbruik Scannen moet het
 * systeem kunnen zien of een product wel/niet voorraad heeft in de
 * gescande ruimte, zonder de hele artikelenlijst te hoeven doorrekenen
 * (dat gebeurt per scanregel, zie includes/transacties.php::
 * verwerkScan()). Geeft locatie -> huidige voorraad terug, alleen voor
 * locaties waar ooit een transactie is geweest (geen 0-rijen voor
 * locaties die dit artikel nog nooit gezien hebben).
 */
function huidigeVoorraadPerLocatie(string $ean): array {
    $stmt = db()->prepare(
        "SELECT locatie,
                SUM(CASE WHEN transactietype IN (" . sqlInList(TRANSACTIETYPES_INKOMEND) . ") THEN hoeveelheid ELSE 0 END) AS inkomend,
                SUM(CASE WHEN transactietype IN (" . sqlInList(TRANSACTIETYPES_UITGAAND) . ") THEN hoeveelheid ELSE 0 END) AS uitgaand
         FROM transactielog WHERE ean = :ean GROUP BY locatie"
    );
    $stmt->execute([':ean' => $ean]);

    // Zelfde actueleNaam()-normalisatie/merge als
    // transactieNettoPerLocatie() hierboven, zelfde reden.
    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $locatie = actueleNaam('locatie', $r['locatie']);
        $result[$locatie] = ($result[$locatie] ?? 0) + (int) $r['inkomend'] - (int) $r['uitgaand'];
    }
    return $result;
}

/**
 * Flat lijst, één rij per artikel x locatie -- het exacte equivalent
 * van de oude Voorraad-Status-sheet. Elke dashboard-functie filtert/
 * groepeert hierop verder, precies zoals de oude code per dashboard
 * apart door de sheet-rijen liep.
 */
function voorraadStatus(): array {
    $artikelen = artikelenMap();
    $netto = transactieNettoPerLocatie();
    $orderStatusMap = openOrderStatusMap();

    $result = [];
    // (string)-cast hier om dezelfde reden als in includes/telling.php en
    // includes/minmax.php: PHP zet array-sleutels die er als een geheel
    // getal uitzien (en dat doet een EAN, want die is altijd numeriek)
    // stilzwijgend om naar int. Zonder de cast geeft deze foreach een int
    // $ean terug, die via getBergingData()/getBoodschappenData() hieronder
    // uiteindelijk bij upsertOrder(string $ean, ...) terechtkomt -- met
    // declare(strict_types=1) een fatale TypeError ("must be of type
    // string, int given") in plaats van een stille coercion. Gemelde bug:
    // "Markeer als besteld" op Bestellijst Berging crashte hierdoor.
    foreach ($artikelen as $eanRaw => $artikel) {
        $ean = (string) $eanRaw;
        foreach (actieveLocatieNamen() as $locatie) {
            [$min, $max] = artikelMinMax($artikel, $locatie);
            $huidig = $netto[$ean][$locatie] ?? 0;

            // Skip alleen als min=0 EN max=0 EN huidig=0 (zelfde regel als getOverzichtData)
            if ($min === 0 && $max === 0 && $huidig === 0) continue;

            // ("als een artikel beneden de
            // voorraad vereiste ligt in een kamer t.o.v. wat er zou moeten
            // liggen, dan is dat Laag -- nu staat er soms OK"): sinds
            // (zie artikelMinMax() hierboven) is $min voor elke
            // kamer altijd 0 door constructie -- alleen Berging heeft een
            // echt ingesteld reorder-minimum. De oude regel hieronder
            // ("$huidig < $min") kon een kamer dus NOOIT als LAAG markeren,
            // ongeacht hoe ver de voorraad onder het vereiste dagvoorraad-
            // niveau (max/par) lag -- die kwam pas als LAAG naar voren zodra
            // hij helemaal LEEG (0) was. Voor kamers (par-systeem) IS max het
            // vereiste niveau, dus daar geldt nu: alles onder max is Laag.
            // Berging behoudt zijn eigen, echte min-drempel ongewijzigd.
            $status = 'OK';
            if ($huidig === 0) {
                $status = 'LEEG';
            } elseif ($min > 0) {
                // Berging: echt ingesteld reorder-minimum.
                if ($huidig < $min) $status = 'LAAG';
                elseif ($max > 0 && $huidig > $max) $status = 'HOOG';
            } elseif ($max > 0) {
                // Kamer (par-systeem): max is het vereiste niveau zelf.
                if ($huidig < $max) $status = 'LAAG';
                elseif ($huidig > $max) $status = 'HOOG';
            }

            $prijs = (float) $artikel['prijs'];

            $result[] = [
                'ean' => $ean,
                'naam' => $artikel['productnaam'],
                'locatie' => $locatie,
                'huidig' => $huidig,
                'min' => $min,
                'max' => $max,
                'voorraadStatus' => $status,
                'orderStatus' => $orderStatusMap[$ean] ?? '',
                'kastlocatie' => artikelKastlocatie($artikel, $locatie),
                'besteltype' => $artikel['besteltype'],
                'prijs' => $prijs,
                'waarde' => $huidig * $prijs,
                // Alleen gezet als dit artikel ook in een
                // doosverpakking geleverd wordt (zie rondAfOpDoos()) --
                // anders null, wat rondAfOpDoos() als "geen doos" leest.
                'eenhedenPerDoos' => !empty($artikel['eenheden_per_doos']) ? (int) $artikel['eenheden_per_doos'] : null,
                // Meegeven zodat afgeleide bestel-adviesschermen
                // (getBergingData()/getBoodschappenData() hieronder,
                // getMinMaxAdviesOverzicht() in includes/minmax.php) een
                // niet-Actief artikel kunnen overslaan, zonder dat deze
                // functie zelf iets filtert -- gewone voorraadweergaven
                // (Overzicht, productdetailpagina) tonen fysieke voorraad
                // van een niet-Actief artikel gewoon door, dat verandert hier
                // niet.
                'status' => $artikel['status'] ?? 'Actief',
            ];
        }
    }
    return $result;
}

/**
 * Flat lijst, één rij per ean+lot+vervaldatum+locatie met huidig > 0.
 * Equivalent van de oude Voorraad-Lot-sheet (met status = de kale
 * 30-dagen VERLOPEN/KRITIEK/OK-classificatie van de sheetformule,
 * gebruikt door getProductDetail()). Voor de rijkere 5-staten-
 * classificatie: gebruik classifyVervalStatus() op daysLeft.
 */
function voorraadLot(): array {
    $rows = db()->query(
        "SELECT ean, lot, vervaldatum, locatie,
                SUM(CASE WHEN transactietype IN (" . sqlInList(TRANSACTIETYPES_INKOMEND) . ") THEN hoeveelheid ELSE 0 END)
                - SUM(CASE WHEN transactietype IN (" . sqlInList(TRANSACTIETYPES_UITGAAND) . ") THEN hoeveelheid ELSE 0 END) AS huidig,
                MAX(productnaam) AS product,
                -- representatieve ruwe
                -- GS1-scanstring voor dit lot, voor het 'Bewerken'-linkje
                -- op de productdetailpagina (zie includes/lot_correcties.php).
                -- Voorkeur voor de Ontvangst-regel (de oorspronkelijke
                -- ontvangstscan is het meest betekenisvolle exemplaar);
                -- valt terug op eender welke andere regel met een
                -- barcode als dit lot nooit als Ontvangst geboekt is
                -- (bijv. alleen via Verplaatsing binnengekomen). Bewust twee
                -- platte MAX()-kolommen i.p.v. één COALESCE(MAX(CASE...),
                -- MAX(...))-expressie -- dat laatste bleek op een productieserver een
                -- HTTP 500 op o.a. api/product_list.php te veroorzaken (gemeld
                -- na v1.58), vermoedelijk een ONLY_FULL_GROUP_BY-verschil
                -- tussen MySQL/MariaDB-versies bij geneste aggregaten in
                -- COALESCE. Twee losse MAX()'en zijn ondubbelzinnige, overal
                -- ondersteunde SQL; de voorkeur-voor-Ontvangst-keuze gebeurt nu
                -- hieronder in PHP i.p.v. in de query.
                MAX(CASE WHEN transactietype = 'Ontvangst' THEN gs1_raw END) AS gs1_raw_ontvangst,
                MAX(gs1_raw) AS gs1_raw_fallback
         FROM transactielog
         GROUP BY ean, lot, vervaldatum, locatie
         HAVING huidig > 0"
    )->fetchAll();

    // (locatie-hernoemen cascade-bug):
    // de GROUP BY hierboven groepeert nog op de RUWE locatienaam, dus een
    // lot dat deels onder de oude en deels onder de nieuwe naam van
    // dezelfde locatie is geboekt zou hier als twee losse rijen
    // verschijnen (en de oude-naam-rij zou voor altijd de vervangen naam
    // blijven tonen). Voordat de rest van deze functie draait, worden
    // rijen met dezelfde ean+lot+vervaldatum+ACTUELE-locatienaam eerst
    // samengevoegd (huidig opgeteld, gs1_raw-voorkeur zoals in de SQL
    // hierboven maar dan in PHP over de samengevoegde rijen).
    $samengevoegd = [];
    foreach ($rows as $r) {
        $locatie = actueleNaam('locatie', $r['locatie']);
        $sleutel = $r['ean'] . '|' . $r['lot'] . '|' . $r['vervaldatum'] . '|' . $locatie;
        if (!isset($samengevoegd[$sleutel])) {
            $samengevoegd[$sleutel] = [
                'ean' => $r['ean'], 'lot' => $r['lot'], 'vervaldatum' => $r['vervaldatum'],
                'locatie' => $locatie, 'huidig' => 0, 'product' => null,
                'gs1_raw_ontvangst' => null, 'gs1_raw_fallback' => null,
            ];
        }
        $samengevoegd[$sleutel]['huidig'] += (int) $r['huidig'];
        if (!$samengevoegd[$sleutel]['product'] && $r['product']) $samengevoegd[$sleutel]['product'] = $r['product'];
        if (!$samengevoegd[$sleutel]['gs1_raw_ontvangst'] && $r['gs1_raw_ontvangst']) $samengevoegd[$sleutel]['gs1_raw_ontvangst'] = $r['gs1_raw_ontvangst'];
        if (!$samengevoegd[$sleutel]['gs1_raw_fallback'] && $r['gs1_raw_fallback']) $samengevoegd[$sleutel]['gs1_raw_fallback'] = $r['gs1_raw_fallback'];
    }
    $rows = array_values(array_filter($samengevoegd, fn($r) => $r['huidig'] > 0));

    $result = [];
    foreach ($rows as $r) {
        $vervaldatum = $r['vervaldatum'];
        $daysLeft = daysLeftUntil($vervaldatum);
        $status = 'OK';
        if ($vervaldatum !== null) {
            if ($daysLeft < 0) $status = 'VERLOPEN';
            elseif ($daysLeft <= 30) $status = 'KRITIEK';
        }
        $result[] = [
            'ean' => $r['ean'],
            'product' => $r['product'] ?: 'Onbekend',
            'lot' => $r['lot'],
            'vervaldatum' => $vervaldatum ? (new DateTime($vervaldatum))->format('d-m-Y') : null,
            'vervaldatum_sql' => $vervaldatum,
            'locatie' => $r['locatie'],
            'huidig' => (int) $r['huidig'],
            'status' => $status,
            'daysLeft' => $daysLeft,
            // Zie toelichting bij de query hierboven -- voorkeur
            // voor de Ontvangst-regel, anders eender welke andere regel met
            // een barcode.
            'gs1_raw' => $r['gs1_raw_ontvangst'] ?: $r['gs1_raw_fallback'],
        ];
    }
    return $result;
}

// =====================================================================
// DASHBOARD: OVERZICHT — poort van getOverzichtData()
// =====================================================================
function getOverzichtData(): array {
    $result = [];
    foreach (voorraadStatus() as $row) {
        $result[$row['locatie']][] = [
            'ean' => $row['ean'], 'naam' => $row['naam'], 'huidig' => $row['huidig'],
            'min' => $row['min'], 'max' => $row['max'], 'voorraadStatus' => $row['voorraadStatus'],
            'orderStatus' => $row['orderStatus'], 'kastlocatie' => $row['kastlocatie'],
            'prijs' => $row['prijs'], 'waarde' => $row['waarde'],
        ];
    }
    return $result;
}

// (De vroegere "Stock on Hand"-functie hier is vervallen -- het Overview-
// scherm (app.js::renderOverzicht) heeft nu een client-side "hide empty
// stock"-schakelaar over dezelfde getOverzichtData(), dus een apart
// server-eindpunt met bijna identieke logica was overbodig geworden.)

/**
 * Rondt een benodigd aantal stuks naar boven af op een heel aantal dozen
 * (sommige producten worden per doos
 * van bijv. 5 of 10 stuks bij de leverancier besteld, ook al wordt de
 * inhoud na ontvangst per los stuk verplaatst/verbruikt -- zie
 * includes/transacties.php::lookupArtikelen()). Puur een weergave-/
 * afrondingslaag bovenop het al berekende "stuks nodig"-aantal: de
 * orders-tabel zelf blijft altijd in stuks, ongeacht deze afronding --
 * bij ontvangst wordt de doos immers gewoon als zoveel stuks geboekt.
 *
 * Zonder doosverpakking (eenhedenPerDoos null, 0 of 1) blijft het aantal
 * ongewijzigd en is 'dozen' null, zodat de UI dan gewoon de kale
 * stuks-waarde toont zoals voorheen.
 *
 * @return array{stuks:int, dozen:?int}
 */
function rondAfOpDoos(int $stuksNodig, ?int $eenhedenPerDoos): array {
    if ($stuksNodig <= 0 || !$eenhedenPerDoos || $eenhedenPerDoos < 2) {
        return ['stuks' => $stuksNodig, 'dozen' => null];
    }
    $dozen = (int) ceil($stuksNodig / $eenhedenPerDoos);
    return ['stuks' => $dozen * $eenhedenPerDoos, 'dozen' => $dozen];
}

// =====================================================================
// DASHBOARD: BERGING / BESTELLIJST LOGISTIEK — poort van getBergingData().
// Sinds kamers geen eigen minimum meer
// hebben (ze worden gegarandeerd dezelfde dag tot hun maximum bijgevuld
// vanuit Berging, zie includes/minmax.php en artikelMinMax() hierboven),
// kan "is er ergens een tekort" niet langer over elke locatie heen
// gescand worden -- kamers zitten per definitie nooit "onder hun
// minimum" (dat bestaat niet meer voor ze). De enige locatie waar een
// reorder-drempel nog een echte betekenis heeft is Berging zelf: dat is
// de enige plek die op een externe leverancier wacht, en Bergings eigen
// 'min' is al berekend op basis van het systeembrede verbruik (inclusief
// wat er naar de kamers toe verplaatst wordt, zie
// includes/minmax.php::vraagPerEanLocatieWeek()). De trigger is dus nu
// simpelweg: zit Berging zelf onder haar eigen minimum?
// De bestelHOEVEELHEID blijft wel de optelsom van het volledige maximum
// over ALLE Automatisch-besteltype locaties (elke kamer + Berging zelf)
// min wat er in totaal al is en al onderweg is -- zodat een bestelling,
// als die geplaatst wordt, ook meteen genoeg is om de kamers weer vol te
// krijgen, niet alleen Bergings eigen plank.
// 'Handmatig'-besteltype artikelen horen hier niet bij (die lopen puur via
// de aparte "Te bestellen (Handmatig)"-lijst, getBestellersData()
// hieronder) -- deze lijst is uitsluitend voor wat Logistiek voor je
// bestelt.
// producent/artikelref erbij (uit artikelenMap(), niet in voorraadStatus()
// zelf) omdat dat is wat je nodig hebt om daadwerkelijk te bestellen.
// =====================================================================
function getBergingData(): array {
    $artikelen = artikelenMap();
    // Een artikel dat al in bestelling
    // staat hoeft niet nogmaals in zijn volle behoefte getoond te worden
    // -- alleen het restant boven wat al onderweg is (bijv. omdat er
    // tijdens de wachttijd extra is verbruikt) is nog relevant.
    $resterendOnderweg = openOrderResterendPerEan();
    $statusRijen = voorraadStatus();

    // Eerste doorloop: totaal max en totaal aanwezig per ean, opgeteld
    // over ELKE locatie (niet alleen Berging) -- alleen Automatisch-
    // besteltype, alleen Actieve artikelen. Los daarvan: zit Bergings
    // EIGEN rij onder haar eigen minimum (het enige echte reorder-signaal,
    // zie de toelichting hierboven).
    $totaalMaxPerEan = [];
    $totaalHuidigPerEan = [];
    $heeftTekortPerEan = [];
    foreach ($statusRijen as $row) {
        if (($row['status'] ?? 'Actief') !== 'Actief') continue;
        if (!str_contains((string) $row['besteltype'], 'Automatisch')) continue;
        $ean = $row['ean'];
        $totaalMaxPerEan[$ean] = ($totaalMaxPerEan[$ean] ?? 0) + $row['max'];
        $totaalHuidigPerEan[$ean] = ($totaalHuidigPerEan[$ean] ?? 0) + $row['huidig'];
        if (locatieSlotVoorNaam($row['locatie']) === 'berging' && $row['huidig'] < $row['min']) {
            $heeftTekortPerEan[$ean] = true;
        }
    }

    $result = [];
    foreach ($statusRijen as $row) {
        if (locatieSlotVoorNaam($row['locatie']) !== 'berging') continue;
        // Een niet-Actief artikel
        // (Niet leverbaar / Niet meer in gebruik) hoort niet op de
        // bestellijst -- daar kun je toch niets mee bij Logistiek.
        if (($row['status'] ?? 'Actief') !== 'Actief') continue;
        if (!str_contains((string) $row['besteltype'], 'Automatisch')) continue;
        // Berging zit niet onder haar eigen minimum -- niets te bestellen,
        // ook niet als het systeem-brede totaal toevallig niet 100% vol is.
        if (empty($heeftTekortPerEan[$row['ean']])) continue;

        $ean = $row['ean'];
        $artikel = $artikelen[$ean] ?? [];
        $totaalMax = $totaalMaxPerEan[$ean] ?? 0;
        $totaalHuidig = $totaalHuidigPerEan[$ean] ?? 0;
        $bruteNodig = max(0, $totaalMax - $totaalHuidig);
        $onderweg = $resterendOnderweg[$ean] ?? 0;
        $stuksResterend = max(0, $bruteNodig - $onderweg);
        // Volledig gedekt door wat al onderweg is -- niets meer te doen,
        // dus niet tonen (dat was voorheen wel het geval).
        if ($stuksResterend <= 0) continue;

        // Berging bestelt altijd bij de externe leverancier (zie
        // includes/minmax.php), dus 'nodig' wordt hier afgerond op hele
        // dozen als dit artikel een doosverpakking heeft (zie
        // includes/voorraad.php::rondAfOpDoos()).
        $afgerond = rondAfOpDoos($stuksResterend, $row['eenhedenPerDoos'] ?? null);
        $result[] = [
            // 'huidig'/'max' zijn bewust de som over ALLE locaties (zie
            // toelichting hierboven), niet Bergings eigen fysieke
            // Voorraad/max -- zodat je in één oogopslag
            // ziet hoeveel er in het hele systeem aanwezig/maximaal is, niet
            // alleen wat er toevallig in Berging zelf staat.
            'ean' => $ean, 'naam' => $row['naam'], 'huidig' => $totaalHuidig,
            'min' => $row['min'], 'max' => $totaalMax, 'nodig' => $afgerond['stuks'], 'dozenNodig' => $afgerond['dozen'],
            'voorraadStatus' => $row['voorraadStatus'], 'orderStatus' => $row['orderStatus'] ?: '-',
            'besteltype' => $row['besteltype'],
            'producent' => $artikel['producent'] ?? '',
            'artikelref' => $artikel['artikelref'] ?? '',
            // De Berging-bestellijst
            // toont voortaan een barcode van de interne logistiekcode
            // (voor een eigen schaplabel) i.p.v. de GS1-EAN -- die laatste
            // is de leverancierscode, niet iets wat op een intern
            // schaplabel hoort. Kan leeg zijn (optioneel veld); de
            // frontend toont dan een "geen code ingesteld"-melding i.p.v.
            // een barcode, zie renderBerging() in assets/js/app.js.
            'logistiekCode' => $artikel['logistiek_code'] ?? null,
        ];
    }
    return $result;
}

/**
 * Markeert de huidige Berging-bestellijst als besteld: één order per
 * artikel via upsertOrder() (includes/
 * orders.php), kanaal 'Berging-bestellijst' zodat je in de order-
 * geschiedenis kunt zien dat dit via deze lijst liep. Zodra Logistiek
 * levert, komt het gewoon binnen via de normale Ontvangst-scan (FIFO,
 * updateOrdersWithFIFO()) -- er is niets nieuws nodig aan de
 * ontvangstkant, dit hergebruikt het BESTAANDE orders-mechanisme.
 *
 * Bewust een SEPARATE actie van het printen/exporteren (zie renderBerging()
 * in assets/js/app.js) -- printen/bekijken mag geen bijwerkingen hebben,
 * je moet apart op "Markeer als besteld" klikken.
 *
 * GetBergingData() zelf trekt nu al af wat er al onderweg
 * is (openOrderResterendPerEan(), includes/orders.php) -- 'nodig' hier is
 * dus altijd al het RESTANT, nooit meer de volledige (bruto) behoefte.
 * Een item met een openstaande order kan dus best in de lijst staan (als
 * er tijdens de wachttijd extra is verbruikt) en dat restant moet dan
 * ook echt besteld worden; upsertOrder() maakt daar altijd een NIEUWE,
 * losse orderrij voor aan (zie de bijgewerkte docblock bij upsertOrder(),
 * na een gemelde bug: orders werden voorheen ten onrechte samengevoegd
 * met een al openstaande order voor hetzelfde EAN, waardoor niet meer te
 * zien was welk aantal op welk moment was besteld). Er is dus geen aparte
 * "sla over als al in bestelling"-check meer nodig, dat zou nu juist een
 * reëel restant negeren.
 */
function markeerBergingAlsBesteld(?string $gebruikerNaam = null): array {
    $lijst = getBergingData();
    $gemarkeerd = 0;
    $overgeslagen = 0;

    foreach ($lijst as $item) {
        if ($item['nodig'] <= 0) {
            $overgeslagen++;
            continue;
        }

        upsertOrder($item['ean'], (int) $item['nodig'], $gebruikerNaam ?? 'Onbekend', 'Berging-bestellijst', '');
        $gemarkeerd++;
    }

    if ($gebruikerNaam !== null && $gemarkeerd > 0) {
        logAudit($gebruikerNaam, 'berging_gemarkeerd_besteld', 'berging', '-', "aantal_artikelen=$gemarkeerd, overgeslagen=$overgeslagen");
    }

    return ['success' => true, 'aantalGemarkeerd' => $gemarkeerd, 'aantalOvergeslagen' => $overgeslagen];
}

// =====================================================================
// DASHBOARD: BIJVULLEN (voorheen "Boodschappenlijst") — poort van
// GetBoodschappenData(). Dit scherm
// heette Boodschappenlijst en toonde zowel interne bijvul-items
// (Automatisch besteltype, vanuit Berging) als externe bestel-items
// (Handmatig besteltype) door elkaar -- dat laatste dupliceerde het
// aparte "Te bestellen (Handmatig)"-scherm (getBestellersData()
// hieronder). Nu alleen nog de bijvul-kant, vandaar de naam "Bijvullen":
// een eenvoudige lijst van wat er DAADWERKELIJK vanuit Berging kan worden
// bijgevuld. Twee aanvullende filters t.o.v. voorheen:
//   - alleen Automatisch-besteltype items (Handmatig hoort nu uitsluitend
//     bij "Te bestellen");
//   - alleen items waar Berging ook echt voorraad van heeft -- staat er
//     niets in Berging, dan kan er ook niets bijgevuld worden, dus is het
//     tonen ervan hier alleen maar ruis (de gebruiker kan er toch niets
//     mee op dit scherm).
// =====================================================================
function getBoodschappenData(): array {
    $status = voorraadStatus();

    // bergingHuidig per EAN opzoeken
    $bergingPerEan = [];
    foreach ($status as $row) {
        if (locatieSlotVoorNaam($row['locatie']) === 'berging') $bergingPerEan[$row['ean']] = $row['huidig'];
    }

    $locatiesBestelling = actieveLocatieNamenBestelling();
    $result = [];
    foreach ($locatiesBestelling as $loc) $result[$loc] = [];

    foreach ($status as $row) {
        if (!in_array($row['locatie'], $locatiesBestelling, true)) continue;
        if ($row['huidig'] >= $row['min']) continue;
        // Zelfde uitsluiting als getBergingData() hierboven.
        if (($row['status'] ?? 'Actief') !== 'Actief') continue;
        // Alleen de bijvul-kant (intern, vanuit Berging) -- Handmatig
        // (extern besteld) hoort bij getBestellersData(), niet hier.
        if (!str_contains((string) $row['besteltype'], 'Automatisch')) continue;

        $bergingHuidig = $bergingPerEan[$row['ean']] ?? 0;
        // Niets in Berging = niets bij te vullen = niet tonen.
        if ($bergingHuidig <= 0) continue;

        $stuksNodig = $row['max'] - $row['huidig'];

        $result[$row['locatie']][] = [
            'ean' => $row['ean'], 'naam' => $row['naam'], 'huidig' => $row['huidig'],
            'hoeveel' => $stuksNodig, 'bergingHuidig' => $bergingHuidig,
        ];
    }
    return $result;
}

// =====================================================================
// DASHBOARD: VOORRAADWAARDE — poort van getVoorraadWaarde()
// =====================================================================
function getVoorraadWaarde(): array {
    $perLocatie = [];
    $perCategorie = [];
    $totaalAlles = 0.0;
    $artikelen = artikelenMap();

    foreach (voorraadStatus() as $row) {
        if ($row['huidig'] === 0) continue;
        $categorie = $artikelen[$row['ean']]['categorie'] ?? 'Onbekend';

        $perLocatie[$row['locatie']] ??= ['waarde' => 0, 'aantalStuks' => 0, 'aantalArtikelen' => 0];
        $perLocatie[$row['locatie']]['waarde'] += $row['waarde'];
        $perLocatie[$row['locatie']]['aantalStuks'] += $row['huidig'];
        $perLocatie[$row['locatie']]['aantalArtikelen'] += 1;

        $perCategorie[$categorie] ??= ['waarde' => 0, 'aantalStuks' => 0, 'aantalArtikelen' => 0];
        $perCategorie[$categorie]['waarde'] += $row['waarde'];
        $perCategorie[$categorie]['aantalStuks'] += $row['huidig'];
        $perCategorie[$categorie]['aantalArtikelen'] += 1;

        $totaalAlles += $row['waarde'];
    }

    return ['perLocatie' => $perLocatie, 'perCategorie' => $perCategorie, 'totaalAlles' => $totaalAlles];
}

// =====================================================================
// DASHBOARD: VERVALDATUM DETAIL — poort van getVervaldatumDetail()
// =====================================================================
function getVervaldatumDetail(): array {
    $result = [];
    foreach (voorraadLot() as $lot) {
        if ($lot['vervaldatum_sql'] === null) continue;
        $monthsLeft = round($lot['daysLeft'] / 30.44, 1);
        $result[] = [
            'ean' => $lot['ean'], 'product' => $lot['product'], 'lot' => $lot['lot'],
            'locatie' => $lot['locatie'], 'huidig' => $lot['huidig'], 'vervaldatum' => $lot['vervaldatum'],
            'daysLeft' => $lot['daysLeft'], 'monthsLeft' => $monthsLeft,
            'status' => classifyVervalStatus($lot['daysLeft']),
        ];
    }
    usort($result, fn($a, $b) => $a['daysLeft'] <=> $b['daysLeft']);
    return $result;
}

// =====================================================================
// DASHBOARD: LOT-TRACKING PER LOCATIE — poort van getLotTracking()
// =====================================================================
function getLotTracking(): array {
    $result = [];
    foreach (voorraadLot() as $lot) {
        $monthsLeft = $lot['vervaldatum_sql'] ? round($lot['daysLeft'] / 30.44, 1) : '?';
        $status = $lot['vervaldatum_sql'] ? classifyVervalStatus($lot['daysLeft']) : 'LANGDURIG';
        $result[$lot['locatie']][] = [
            'ean' => $lot['ean'], 'product' => $lot['product'], 'lot' => $lot['lot'],
            'vervaldatum' => $lot['vervaldatum'], 'huidig' => $lot['huidig'],
            'monthsLeft' => $monthsLeft, 'status' => $status,
        ];
    }
    return $result;
}

// =====================================================================
// DASHBOARD: BESTELLERS (handmatig besteltype) — poort van
// getBestellersData(). Staat hier i.p.v. in orders.php om een circulaire
// require tussen voorraad.php en orders.php te vermijden: deze functie
// heeft zowel voorraadStatus() als openOrderStatusMap() nodig, en
// orders.php heeft voorraad.php niet nodig.
//
// Zelfde herziening als getBergingData()
// hierboven, om dezelfde reden -- kamers hebben geen eigen minimum meer (ze
// worden gegarandeerd dezelfde dag tot hun maximum bijgevuld vanuit
// Berging), dus "per locatie onder minimum" bestaat daar niet meer. Voor
// Handmatig-besteltype artikelen die WEL in Berging liggen (Bergings eigen
// max > 0) is Bergings eigen rij dus de juiste plek om een reorder-drempel
// te toetsen: "zit Berging zelf onder haar eigen minimum?"
//
// Veel Handmatig-artikelen liggen
// helemaal NIET in Berging (min/max daar bewust 0 -- het product wordt
// rechtstreeks voor een kamer besteld, Berging is voor dit artikel geen
// tussenstation). Voor die artikelen bestaat er geen "dagelijks bijvullen
// vanuit Berging" om op te leunen, dus is Bergings eigen (0 < 0 = altijd
// false) trigger een dode letter en verdween het artikel onterecht van de
// bestellijst zodra de kamer volledig leeg raakte. Daarom geldt per EAN nu
// een tweede, aanvullende trigger: als Berging voor dit EAN geen eigen max
// heeft (dus geen voorraadpunt is), telt een kamer die onder haar eigen
// maximum zit direct als tekort -- er is immers geen Berging-buffer die het
// alsnog gladstrijkt.
// =====================================================================
function getBestellersData(): array {
    $artikelen = artikelenMap();
    // Een product dat al (deels)
    // onderweg is hoeft niet nogmaals in zijn volle behoefte getoond te
    // worden, alleen het restant boven wat al onderweg is (bijv. extra
    // verbruik tijdens de wachttijd) is nog relevant.
    $resterendOnderweg = openOrderResterendPerEan();
    $productMap = [];
    $heeftTekortPerEan = [];

    // Eerste, lichte doorloop: alleen om per EAN te bepalen of Berging
    // hiervoor daadwerkelijk een voorraadpunt is (eigen max > 0). Dit moet
    // vooraf bekend zijn omdat de volgorde van locaties in voorraadStatus()
    // niet gegarandeerd is (de Berging-rij kan na de kamer-rijen komen).
    $bergingMaxPerEan = [];
    foreach (voorraadStatus() as $row) {
        if (!str_contains((string) $row['besteltype'], 'Handmatig')) continue;
        if (($row['status'] ?? 'Actief') !== 'Actief') continue;
        if (locatieSlotVoorNaam($row['locatie']) === 'berging') {
            $ean = $row['ean'];
            $bergingMaxPerEan[$ean] = max($bergingMaxPerEan[$ean] ?? 0, (int) $row['max']);
        }
    }

    foreach (voorraadStatus() as $row) {
        if (!str_contains((string) $row['besteltype'], 'Handmatig')) continue;
        // Zelfde uitsluiting als getBergingData()/
        // getBoodschappenData().
        if (($row['status'] ?? 'Actief') !== 'Actief') continue;

        $ean = $row['ean'];
        $isBerging = locatieSlotVoorNaam($row['locatie']) === 'berging';
        $bergingIsVoorraadpunt = ($bergingMaxPerEan[$ean] ?? 0) > 0;

        if ($bergingIsVoorraadpunt) {
            // Berging houdt dit artikel echt op voorraad -- alleen Bergings
            // eigen tekort is de trigger, kamers worden er toch dagelijks
            // vanuit bijgevuld.
            if ($isBerging && $row['huidig'] < $row['min']) {
                $heeftTekortPerEan[$ean] = true;
            }
        } else {
            // Berging is voor dit artikel geen voorraadpunt (min/max 0 by
            // design) -- de kamer zelf is dan de enige echte voorraad, dus
            // een kamer onder haar eigen maximum is zelf al het tekort.
            if (!$isBerging && $row['huidig'] < $row['max']) {
                $heeftTekortPerEan[$ean] = true;
            }
        }

        if (!isset($productMap[$ean])) {
            $productMap[$ean] = [
                'ean' => $ean, 'naam' => $row['naam'], 'locaties' => [],
                'totaalNodig' => 0, 'bestelStatus' => $row['orderStatus'] ?? '',
                'interneRef' => $artikelen[$ean]['artikelref'] ?? '-',
                'eenhedenPerDoos' => $row['eenhedenPerDoos'] ?? null,
                'dozenNodig' => null,
            ];
        }
        $nodig = $row['max'] - $row['huidig'];
        // 'locaties' (per-locatie breakdown, getoond op het losse
        // Bestellers-scherm) toont alleen locaties met een echte behoefte
        // -- een 0 of negatieve waarde rendert daar toch al als "-" (zie
        // renderBestellers() in assets/js/app.js), dus dit heeft geen
        // effect op die tabel. Het voorkomt wel dat 'primaireLocatie'
        // (hieronder gebruikt door includes/voorraad_tabel.php om de
        // aggregaat-bestelknop op één rij te zetten) een toevallig
        // alfabetisch eerste, verder prima bevoorrade locatie kiest.
        if ($nodig > 0) $productMap[$ean]['locaties'][$row['locatie']] = $nodig;
        $productMap[$ean]['totaalNodig'] += $nodig;
    }

    // Alleen tonen bij een echt tekort: Bergings eigen minimum (als Berging
    // voor dit artikel een voorraadpunt is) of anders een kamer onder haar
    // Eigen maximum -- zonder dat is er niets te bestellen,
    // ook al is het systeembrede totaal toevallig niet 100% vol.
    $productMap = array_filter($productMap, fn($p) => !empty($heeftTekortPerEan[$p['ean']]));

    // Afronden op hele dozen gebeurt op het TOTAAL per
    // product, niet per locatie los -- anders zou de som van los
    // afgeronde per-locatie-aantallen hoger kunnen uitvallen dan nodig
    // (bijv. 3 + 3 stuks die allebei apart al naar een doos van 5 worden
    // afgerond, terwijl in totaal 6 stuks -- dus 2 dozen -- ook volstaat).
    // Eerst het restant t.o.v. wat al onderweg is bepalen,
    // dán pas op hele dozen afronden.
    foreach ($productMap as $ean => &$product) {
        $product['totaalNodig'] = max(0, $product['totaalNodig']);
        $onderweg = $resterendOnderweg[$ean] ?? 0;
        $product['totaalNodig'] = max(0, $product['totaalNodig'] - $onderweg);
        $afgerond = rondAfOpDoos($product['totaalNodig'], $product['eenhedenPerDoos']);
        $product['totaalNodig'] = $afgerond['stuks'];
        $product['dozenNodig'] = $afgerond['dozen'];
    }
    unset($product);

    // Volledig gedekt door wat al onderweg is -- niets meer te doen, dus
    // niet tonen.
    $productMap = array_filter($productMap, fn($p) => $p['totaalNodig'] > 0);

    return array_values($productMap);
}
