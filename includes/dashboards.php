<?php
/**
 * Samengestelde dashboards die meerdere bronnen combineren (voorraad,
 * loten, orders, transactielog). Poort van getActiecentrumData(),
 * getVervaldatumFocus(), getCategorieOverzicht(), getVerbruiksTrends()
 * en getVerbruiksgebruik() uit DashboardAll.gs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ean.php';
require_once __DIR__ . '/voorraad.php';
require_once __DIR__ . '/orders.php';

// =====================================================================
// ACTIECENTRUM: alles wat aandacht vraagt op één pagina. Wat OK is
// staat er niet op. Poort van getActiecentrumData().
// =====================================================================
function getActiecentrumData(): array {
    $verlopen = [];
    $spoed = [];
    $artikelen = artikelenMap();

    foreach (voorraadLot() as $lot) {
        if ($lot['vervaldatum_sql'] === null) continue;
        $monthsLeft = $lot['daysLeft'] / 30.44;
        if ($lot['daysLeft'] >= 0 && $monthsLeft >= 3) continue;

        $prijs = (float) ($artikelen[$lot['ean']]['prijs'] ?? 0);
        $item = [
            'ean' => $lot['ean'], 'product' => $lot['product'], 'lot' => $lot['lot'],
            'locatie' => $lot['locatie'], 'huidig' => $lot['huidig'], 'vervaldatum' => $lot['vervaldatum'],
            'maanden' => round($monthsLeft, 1), 'waarde' => $lot['huidig'] * $prijs,
        ];
        if ($lot['daysLeft'] < 0) $verlopen[] = $item; else $spoed[] = $item;
    }
    usort($verlopen, fn($a, $b) => $a['maanden'] <=> $b['maanden']);
    usort($spoed, fn($a, $b) => $a['maanden'] <=> $b['maanden']);

    $leeg = [];
    $laag = [];
    foreach (voorraadStatus() as $row) {
        if ($row['huidig'] === 0) {
            $leeg[] = ['ean' => $row['ean'], 'naam' => $row['naam'], 'locatie' => $row['locatie'], 'huidig' => 0, 'min' => $row['min'], 'max' => $row['max'], 'orderStatus' => $row['orderStatus']];
        } elseif ($row['huidig'] < $row['min']) {
            $laag[] = ['ean' => $row['ean'], 'naam' => $row['naam'], 'locatie' => $row['locatie'], 'huidig' => $row['huidig'], 'min' => $row['min'], 'max' => $row['max'], 'orderStatus' => $row['orderStatus']];
        }
    }

    $orders = getOpenOrders();
    usort($orders, fn($a, $b) => ($b['dagenOpen'] ?? 0) <=> ($a['dagenOpen'] ?? 0));

    return ['verlopen' => $verlopen, 'spoed' => $spoed, 'leeg' => $leeg, 'laag' => $laag, 'orders' => $orders];
}

// =====================================================================
// VERVALDATUM FOCUS: alleen wat spannend wordt (< 12 mnd of verlopen),
// met euro-impact per lot en tegels. Poort van getVervaldatumFocus().
// =====================================================================
function getVervaldatumFocus(): array {
    $artikelen = artikelenMap();
    $items = [];
    $waardeVerlopen = 0.0; $waarde3 = 0.0; $waarde6 = 0.0; $waarde12 = 0.0;
    $aantalVerlopen = 0;

    foreach (voorraadLot() as $lot) {
        if ($lot['vervaldatum_sql'] === null) continue;
        $daysLeft = $lot['daysLeft'];
        $monthsLeft = $daysLeft / 30.44;
        if ($daysLeft >= 0 && $monthsLeft >= 12) continue;

        $prijs = (float) ($artikelen[$lot['ean']]['prijs'] ?? 0);
        $waarde = $lot['huidig'] * $prijs;

        $status = 'MONITOREN';
        if ($daysLeft < 0) { $status = 'VERLOPEN'; $waardeVerlopen += $waarde; $aantalVerlopen++; }
        elseif ($monthsLeft < 3) { $status = 'SPOEDGEBRUIK'; $waarde3 += $waarde; }
        elseif ($monthsLeft < 6) { $waarde6 += $waarde; }
        else { $waarde12 += $waarde; }

        $items[] = [
            'ean' => $lot['ean'], 'product' => $lot['product'], 'lot' => $lot['lot'],
            'locatie' => $lot['locatie'], 'huidig' => $lot['huidig'], 'vervaldatum' => $lot['vervaldatum'],
            'daysLeft' => $daysLeft, 'maanden' => round($monthsLeft, 1), 'waarde' => $waarde, 'status' => $status,
        ];
    }
    usort($items, fn($a, $b) => $a['daysLeft'] <=> $b['daysLeft']);

    return [
        'items' => $items,
        'tiles' => [
            'verlopen' => $waardeVerlopen, 'aantalVerlopen' => $aantalVerlopen,
            'binnen3' => $waarde3, 'binnen6' => $waarde3 + $waarde6, 'binnen12' => $waarde3 + $waarde6 + $waarde12,
        ],
    ];
}

// =====================================================================
// CATEGORIEEN: samenvatting per categorie + artikellijst voor drilldown.
// Poort van getCategorieOverzicht().
// =====================================================================
function getCategorieOverzicht(): array {
    $artikelen = artikelenMap();
    $orderStatusMap = openOrderStatusMap();

    $stuksPerEan = [];
    $onderMinPerEan = [];
    foreach (voorraadStatus() as $row) {
        $stuksPerEan[$row['ean']] = ($stuksPerEan[$row['ean']] ?? 0) + $row['huidig'];
        // Dit gebruikte een eigen
        // "$row['min'] > 0"-check, wat exact dezelfde bug had als de LAAG-
        // berekening in includes/voorraad.php::voorraadStatus() vóór deze
        // wijziging -- kamers hebben altijd min=0 (par-systeem),
        // dus een kamer die ver onder zijn vereiste dagvoorraad (max) zit
        // werd hier nooit als "onder min" meegeteld. Nu hergebruikt als
        // rechtstreekse afgeleide van $row['voorraadStatus'] (al gecorrigeerd
        // voor kamers t.o.v. max) i.p.v. een eigen, inmiddels afwijkende
        // kopie van diezelfde logica.
        if ($row['voorraadStatus'] === 'LAAG') $onderMinPerEan[$row['ean']] = true;
    }

    $lot6PerEan = [];
    foreach (voorraadLot() as $lot) {
        if ($lot['vervaldatum_sql'] === null) continue;
        $monthsLeft = $lot['daysLeft'] / 30.44;
        if ($monthsLeft < 6) $lot6PerEan[$lot['ean']] = ($lot6PerEan[$lot['ean']] ?? 0) + 1;
    }

    $summary = [];
    $artikelenPerCat = [];
    // (string)-cast: zelfde reden als includes/voorraad.php::voorraadStatus()
    // / includes/telling.php / includes/minmax.php -- PHP zet een numerieke
    // array-sleutel (EAN) stilzwijgend om naar int op deze foreach.
    foreach ($artikelen as $eanRaw => $artikel) {
        $ean = (string) $eanRaw;
        if ($ean === '00000000000000') continue;
        $cat = $artikel['categorie'] ?: 'Onbekend';
        $prijs = (float) $artikel['prijs'];
        $stuks = $stuksPerEan[$ean] ?? 0;
        $waarde = $stuks * $prijs;

        $summary[$cat] ??= ['aantalArtikelen' => 0, 'stuks' => 0, 'waarde' => 0, 'onderMin' => 0, 'loten6' => 0];
        $summary[$cat]['aantalArtikelen'] += 1;
        $summary[$cat]['stuks'] += $stuks;
        $summary[$cat]['waarde'] += $waarde;
        if (!empty($onderMinPerEan[$ean])) $summary[$cat]['onderMin'] += 1;
        $summary[$cat]['loten6'] += $lot6PerEan[$ean] ?? 0;

        $artikelenPerCat[$cat][] = [
            'ean' => $ean, 'naam' => $artikel['productnaam'], 'producent' => $artikel['producent'] ?? '',
            'prijs' => $prijs, 'stuks' => $stuks, 'waarde' => $waarde,
            'onderMin' => !empty($onderMinPerEan[$ean]), 'orderStatus' => $orderStatusMap[$ean] ?? '',
            // De "Loten < 6mnd"-kolom in
            // het categorie-overzicht moet klikbaar zijn naar precies de
            // producten die dit veroorzaken -- dus per artikel meesturen
            // i.p.v. alleen als categorie-totaal (zoals $summary hierboven).
            'loten6' => $lot6PerEan[$ean] ?? 0,
        ];
    }
    foreach ($artikelenPerCat as $cat => &$lijst) {
        usort($lijst, fn($a, $b) => $b['waarde'] <=> $a['waarde']);
    }
    unset($lijst);

    return ['summary' => $summary, 'artikelen' => $artikelenPerCat];
}

// =====================================================================
// VERBRUIK PER PROCEDURE. Poort van getVerbruiksgebruik().
//
// Doel : zichtbaar maken of materiaalgebruik afwijkt
// tussen specialisten die dezelfde procedure uitvoeren. Structuur:
// procedure (bovenste niveau) -> specialist (daaronder) -> wat die
// specialist gemiddeld PER PROCEDURE verbruikt, in totaal en per product.
//
// De crux van "gemiddeld per procedure" is: waaruit bestaat één
// procedure? transactielog heeft geen procedure-sessie-ID -- elke regel
// is één gescand product. Maar includes/transacties.php::verwerkScan()
// (het Scan Usage-formulier, waar meerdere GS1-scans in één indiening
// horen bij dezelfde specialist+procedure) gebruikt bewust ÉÉN gedeelde
// $tijdstempel voor alle regels uit die ene indiening. Dus: alle
// transactielog-regels met identieke (specialist, procedure_type,
// tijdstempel) zijn met zekerheid uit dezelfde formulierindiening
// afkomstig en representeren dus in de praktijk één procedure. Dat
// gebruiken we hier als groepeersleutel voor "één procedure-moment" --
// geen aanname, maar een direct gevolg van hoe scan_verbruik.php data
// wegschrijft.
//
// (Bij testdata uit migratie/genereer_testdata.php/.py komt dat
// tijdstempel-delen niet voor -- die simuleert losse individuele
// verbruiksregels, geen gebundelde formulierindieningen -- dus daar
// verschijnt elke regel als een eigen "procedure" van 1 product. Met
// echt gebruik via het Scan Usage-formulier groepeert dit wel correct.)
//
// Gemiddeldes worden gedeeld door het aantal PROCEDURES (niet door het
// aantal keer dat een product voorkwam) zodat een product dat niet bij
// elke procedure gebruikt wordt ook eerlijk meetelt als 0 voor de
// procedures waar het niet bij zat -- anders zou het gemiddelde een
// vertekend (te hoog) beeld geven t.o.v. een echt gemiddelde per
// procedure.
//
// Kostprijs gebruikt prijs_op_moment (de prijs die gold tén tijde van
// het verbruik) met terugval op de actuele artikelprijs
// voor oudere rijen zonder snapshot. Zo verandert de kostprijs van een
// procedure uit het verleden niet met terugwerkende kracht als de
// catalogusprijs later wijzigt.
// =====================================================================
function getVerbruiksgebruik(): array {
    $rows = db()->query(
        "SELECT t.batch_id, t.specialist, t.procedure_type, t.tijdstempel, t.ean, t.productnaam, t.hoeveelheid,
                COALESCE(t.prijs_op_moment, a.prijs, 0) AS prijs
         FROM transactielog t
         LEFT JOIN artikelen a ON a.ean = t.ean
         WHERE t.transactietype = 'Verbruik' AND t.specialist IS NOT NULL AND t.specialist <> ''
           AND t.procedure_type IS NOT NULL AND t.procedure_type <> ''"
    )->fetchAll();

    // Stap 1: groepeer regels tot procedure-momenten (één formulierindiening
    // van Scan Usage, mogelijk meerdere gescande producten, telt als één
    // Procedure). batch_id is hiervoor de betrouwbare
    // sleutel; voor data van vóór die migratie (batch_id IS NULL) valt dit
    // terug op specialist+procedure_type+tijdstempel, de enige sleutel die
    // toen beschikbaar was.
    $momenten = [];
    foreach ($rows as $r) {
        if (!$r['productnaam']) continue;
        $momentKey = $r['batch_id'] !== null ? $r['batch_id'] : ($r['specialist'] . '|' . $r['procedure_type'] . '|' . $r['tijdstempel']);
        $momenten[$momentKey] ??= ['procedure' => $r['procedure_type'], 'producten' => []];

        $pKey = $r['productnaam'];
        $momenten[$momentKey]['producten'][$pKey] ??= ['ean' => $r['ean'], 'product' => $pKey, 'aantal' => 0, 'kosten' => 0.0];
        $momenten[$momentKey]['producten'][$pKey]['aantal'] += (int) $r['hoeveelheid'];
        $momenten[$momentKey]['producten'][$pKey]['kosten'] += (float) $r['prijs'] * (int) $r['hoeveelheid'];
    }

    // Stap 2: aggregeer de procedure-momenten per procedure_type. Het
    // specialist-niveau dat hier eerder stond
    // (per-arts gemiddelden) is vervallen: dat voelt op termijn mogelijk
    // vervelend aan voor artsen, en dit scherm gaat over de procedure, niet
    // over wie 'm uitvoerde. specialist wordt hierboven alleen nog gebruikt
    // om de fallback-sleutel voor oude data uniek te houden. "Verbruik per
    // specialist"-inzicht staat nog wel op de productdetailpagina (in
    // totalen, niet per procedure).
    $result = [];
    foreach ($momenten as $m) {
        $procKey = $m['procedure'];
        $result[$procKey] ??= [
            'procedure' => $procKey, 'aantalProcedures' => 0,
            'totaalArtikelen' => 0, 'totaalKosten' => 0.0, 'producten' => [],
        ];
        $entry = &$result[$procKey];
        $entry['aantalProcedures'] += 1;

        foreach ($m['producten'] as $p) {
            $entry['totaalArtikelen'] += $p['aantal'];
            $entry['totaalKosten'] += $p['kosten'];
            $entry['producten'][$p['product']] ??= [
                'ean' => $p['ean'], 'product' => $p['product'],
                'totaal' => 0, 'kosten' => 0.0, 'aantalKeerGebruikt' => 0,
            ];
            $entry['producten'][$p['product']]['totaal'] += $p['aantal'];
            $entry['producten'][$p['product']]['kosten'] += $p['kosten'];
            $entry['producten'][$p['product']]['aantalKeerGebruikt'] += 1;
        }
        unset($entry);
    }

    // Stap 3: gemiddeldes per procedure berekenen, en producten binnen elke
    // procedure sorteren op gemiddeld aantal per procedure (hoog -> laag).
    foreach ($result as &$procEntry) {
        $n = $procEntry['aantalProcedures'];
        $procEntry['gemiddeldArtikelen'] = $n > 0 ? round($procEntry['totaalArtikelen'] / $n, 2) : 0.0;
        $procEntry['gemiddeldeKosten'] = $n > 0 ? round($procEntry['totaalKosten'] / $n, 2) : 0.0;
        foreach ($procEntry['producten'] as &$p) {
            $p['gemiddeldPerProcedure'] = $n > 0 ? round($p['totaal'] / $n, 2) : 0.0;
        }
        unset($p);
        uasort($procEntry['producten'], fn($a, $b) => $b['gemiddeldPerProcedure'] <=> $a['gemiddeldPerProcedure']);
    }
    unset($procEntry);

    ksort($result);
    return $result;
}

// =====================================================================
// AFBOEKINGEN: historisch overzicht van vervallen voorraad (over datum
// afgeboekt via het verbruikformulier, transactietype 'Vervallen') en
// tekorten uit kastcontrole (bij tellen niet teruggevonden, transactietype
// 'Telling: tekort', zie includes/telling.php). Beide zijn de "afgehandeld"-
// kant van de takenlijst (includes/taken.php): dat scherm toont wat er nog
// open staat, dit scherm toont wat er in het verleden is afgeboekt --
// zodat je (a) kunt terugzien hoeveel voorraad er over datum gaat, en (b)
// een indicatie hebt hoe nauwkeurig er gescand wordt (veel tekorten =
// vaak dingen niet teruggevonden bij een telling). Gebruikt
// prijs_op_moment (snapshot-prijs) voor de waarde, net als
// getVerbruiksgebruik() hierboven.
// =====================================================================
// Afboekingen moet beheersbaar blijven
// naarmate de transactielog groeit -- vandaar een periodefilter i.p.v.
// altijd de volledige historie. AFBOEKINGEN_PERIODES is de canonieke lijst
// van geldige periode-sleutels (ook gebruikt door de frontend om de
// keuzeknoppen te tekenen); 'totaal' betekent geen ondergrens.
const AFBOEKINGEN_PERIODES = ['1m', '3m', '6m', '1j', '2j', 'totaal'];

/**
 * Zet een periode-sleutel om in een ondergrens (DATETIME-string) voor
 * tijdstempel, of null voor 'totaal' (geen ondergrens). Onbekende
 * sleutels vallen terug op 'totaal' (nooit een lege lijst tonen door een
 * typefout in de sleutel).
 */
function afboekingenPeriodeCutoff(string $periode): ?string {
    $nu = new DateTime();
    switch ($periode) {
        case '1m': $nu->modify('-1 month'); return $nu->format('Y-m-d H:i:s');
        case '3m': $nu->modify('-3 months'); return $nu->format('Y-m-d H:i:s');
        case '6m': $nu->modify('-6 months'); return $nu->format('Y-m-d H:i:s');
        case '1j': $nu->modify('-1 year'); return $nu->format('Y-m-d H:i:s');
        case '2j': $nu->modify('-2 years'); return $nu->format('Y-m-d H:i:s');
        default: return null; // 'totaal' of onbekend
    }
}

function afboekingenPerType(string $type, string $periode = 'totaal'): array {
    $sinds = afboekingenPeriodeCutoff($periode);
    $sql = "SELECT tijdstempel, ean, productnaam, lot, vervaldatum, locatie, hoeveelheid,
                COALESCE(prijs_op_moment, 0) AS prijs, opmerking
         FROM transactielog
         WHERE transactietype = :type" . ($sinds !== null ? " AND tijdstempel >= :sinds" : "") . "
         ORDER BY tijdstempel DESC";
    $stmt = db()->prepare($sql);
    $params = [':type' => $type];
    if ($sinds !== null) $params[':sinds'] = $sinds;
    $stmt->execute($params);

    $regels = [];
    $totaalAantal = 0;
    $totaalWaarde = 0.0;
    foreach ($stmt->fetchAll() as $r) {
        $waarde = (float) $r['prijs'] * (int) $r['hoeveelheid'];
        $regels[] = [
            'tijdstempel' => $r['tijdstempel'], 'ean' => $r['ean'], 'productnaam' => $r['productnaam'],
            'lot' => $r['lot'], 'vervaldatum' => $r['vervaldatum'], 'locatie' => $r['locatie'],
            'hoeveelheid' => (int) $r['hoeveelheid'], 'waarde' => $waarde, 'opmerking' => $r['opmerking'],
        ];
        $totaalAantal += (int) $r['hoeveelheid'];
        $totaalWaarde += $waarde;
    }
    return ['regels' => $regels, 'totaalAantal' => $totaalAantal, 'totaalWaarde' => $totaalWaarde];
}

function getAfboekingenOverzicht(string $periode = 'totaal'): array {
    if (!in_array($periode, AFBOEKINGEN_PERIODES, true)) $periode = 'totaal';
    $vervallen = afboekingenPerType('Vervallen', $periode);
    $tekorten = afboekingenPerType('Telling: tekort', $periode);
    return ['vervallen' => $vervallen, 'tekorten' => $tekorten, 'periode' => $periode];
}

// =====================================================================
// VERBRUIKSTRENDS + SLOW MOVERS + AANPASSINGEN/AFBOEKINGEN/BESPARING OVER
// TIJD. Poort van getVerbruiksTrends(). uitgebreid met
// drie extra maandelijkse reeksen naast het bestaande verbruik-per-
// categorie-overzicht, zodat alles wat "over de tijd" gaat op één scherm
// staat i.p.v. verspreid:
//   - aanpassingen: netto bijgesteld via kastcontrole (Telling: overschot/
//     tekort, zie includes/telling.php) -- een indicatie hoe nauwkeurig
//     er scangewijs bijgehouden wordt.
//   - afboekingenTrend: waarde die als 'Vervallen' is afgeboekt (dezelfde
//     data als getAfboekingenOverzicht() hierboven, maar hier per maand
//     i.p.v. als platte lijst).
//   - besparing: materiaal dat ondanks een naderende vervaldatum tóch nog
//     gebruikt is i.p.v. te moeten worden afgeboekt. Concreet gedefinieerd
//     als: een 'Verbruik'-regel waarvan de vervaldatum
//     op het moment van scannen nog maar 1, 2 of 3 maand (30/60/90 dagen)
//     verwijderd was -- dat is materiaal dat een goede kans had om anders
//     als 'Vervallen' afgeboekt te worden. De euro-waarde daarvan (dezelfde
//     prijs_op_moment-snapshot als overal elders) is de geschatte
//     besparing. Bewust GEEN vergelijking met een aparte baseline/prognose
//     -- dat zou een aanname needen die niet hard te maken is; dit is een
//     concrete, natelbare deelverzameling van het bestaande verbruik.
// =====================================================================
function getVerbruiksTrends(): array {
    $artikelen = artikelenMap();

    $rows = db()->query(
        "SELECT tijdstempel, transactietype, ean, hoeveelheid, vervaldatum, COALESCE(prijs_op_moment, 0) AS prijs
         FROM transactielog
         WHERE transactietype IN ('Verbruik', 'Telling: overschot', 'Telling: tekort', 'Vervallen')"
    )->fetchAll();

    $verbruikTrends = [];
    $catSet = [];
    $laatsteVerbruik = [];
    $aanpassingenTrends = [];
    $afboekingenTrends = [];
    $besparingTrends = [];

    foreach ($rows as $r) {
        $d = new DateTime($r['tijdstempel']);
        $maand = $d->format('Y-m');
        $type = $r['transactietype'];
        $hoeveelheid = (int) $r['hoeveelheid'];
        $waarde = (float) $r['prijs'] * $hoeveelheid;

        if ($type === 'Verbruik') {
            $ean = $r['ean'];
            $cat = $artikelen[$ean]['categorie'] ?? 'Onbekend';
            $cat = $cat ?: 'Onbekend';
            $verbruikTrends[$maand][$cat] = ($verbruikTrends[$maand][$cat] ?? 0) + $hoeveelheid;
            $catSet[$cat] = true;
            if (!isset($laatsteVerbruik[$ean]) || $d > $laatsteVerbruik[$ean]) $laatsteVerbruik[$ean] = $d;

            if ($r['vervaldatum']) {
                $vervalDatum = new DateTime($r['vervaldatum']);
                $dagenTotVerval = (int) $d->diff($vervalDatum)->format('%r%a');
                if ($dagenTotVerval >= 0 && $dagenTotVerval <= 90) {
                    $bucket = $dagenTotVerval <= 30 ? 'tot30' : ($dagenTotVerval <= 60 ? 'tot60' : 'tot90');
                    $besparingTrends[$maand][$bucket]['aantal'] = ($besparingTrends[$maand][$bucket]['aantal'] ?? 0) + $hoeveelheid;
                    $besparingTrends[$maand][$bucket]['waarde'] = ($besparingTrends[$maand][$bucket]['waarde'] ?? 0) + $waarde;
                }
            }
        } elseif ($type === 'Telling: overschot' || $type === 'Telling: tekort') {
            $veld = $type === 'Telling: overschot' ? 'overschot' : 'tekort';
            $aanpassingenTrends[$maand][$veld . '_aantal'] = ($aanpassingenTrends[$maand][$veld . '_aantal'] ?? 0) + $hoeveelheid;
            $aanpassingenTrends[$maand][$veld . '_waarde'] = ($aanpassingenTrends[$maand][$veld . '_waarde'] ?? 0) + $waarde;
        } elseif ($type === 'Vervallen') {
            $afboekingenTrends[$maand]['aantal'] = ($afboekingenTrends[$maand]['aantal'] ?? 0) + $hoeveelheid;
            $afboekingenTrends[$maand]['waarde'] = ($afboekingenTrends[$maand]['waarde'] ?? 0) + $waarde;
        }
    }

    $alleMaanden = array_unique(array_merge(
        array_keys($verbruikTrends), array_keys($aanpassingenTrends),
        array_keys($afboekingenTrends), array_keys($besparingTrends)
    ));
    rsort($alleMaanden);
    $maandKeys = array_slice($alleMaanden, 0, 12);

    $categorieen = array_keys($catSet);
    sort($categorieen);

    $maanden = array_map(function ($key) use ($verbruikTrends) {
        $perCat = $verbruikTrends[$key] ?? [];
        return ['maand' => $key, 'perCat' => $perCat, 'totaal' => array_sum($perCat)];
    }, $maandKeys);

    $aanpassingen = array_map(function ($key) use ($aanpassingenTrends) {
        $a = $aanpassingenTrends[$key] ?? [];
        return [
            'maand' => $key,
            'overschotAantal' => $a['overschot_aantal'] ?? 0, 'overschotWaarde' => $a['overschot_waarde'] ?? 0.0,
            'tekortAantal' => $a['tekort_aantal'] ?? 0, 'tekortWaarde' => $a['tekort_waarde'] ?? 0.0,
        ];
    }, $maandKeys);

    $afboekingenTrend = array_map(function ($key) use ($afboekingenTrends) {
        $a = $afboekingenTrends[$key] ?? [];
        return ['maand' => $key, 'aantal' => $a['aantal'] ?? 0, 'waarde' => $a['waarde'] ?? 0.0];
    }, $maandKeys);

    $besparing = array_map(function ($key) use ($besparingTrends) {
        $b = $besparingTrends[$key] ?? [];
        $tot30 = $b['tot30'] ?? ['aantal' => 0, 'waarde' => 0.0];
        $tot60 = $b['tot60'] ?? ['aantal' => 0, 'waarde' => 0.0];
        $tot90 = $b['tot90'] ?? ['aantal' => 0, 'waarde' => 0.0];
        return [
            'maand' => $key,
            'tot30Aantal' => $tot30['aantal'], 'tot30Waarde' => $tot30['waarde'],
            'tot60Aantal' => $tot60['aantal'], 'tot60Waarde' => $tot60['waarde'],
            'tot90Aantal' => $tot90['aantal'], 'tot90Waarde' => $tot90['waarde'],
            'totaalAantal' => $tot30['aantal'] + $tot60['aantal'] + $tot90['aantal'],
            'totaalWaarde' => $tot30['waarde'] + $tot60['waarde'] + $tot90['waarde'],
        ];
    }, $maandKeys);

    // Slow movers: voorraad > 0, geen verbruik in laatste 90 dagen
    $stuksPerEan = [];
    foreach (voorraadStatus() as $row) {
        $stuksPerEan[$row['ean']] = ($stuksPerEan[$row['ean']] ?? 0) + $row['huidig'];
    }

    $today = new DateTime('today');
    $slowMovers = [];
    foreach ($stuksPerEan as $ean => $stuks) {
        if ($stuks === 0) continue;
        $last = $laatsteVerbruik[$ean] ?? null;
        $dagenGeleden = $last ? (int) $today->diff($last)->format('%a') : null;
        if ($last && $dagenGeleden <= 90) continue;
        if (!isset($artikelen[$ean])) continue;

        $artikel = $artikelen[$ean];
        $prijs = (float) $artikel['prijs'];
        $slowMovers[] = [
            'ean' => $ean, 'naam' => $artikel['productnaam'], 'categorie' => $artikel['categorie'] ?: 'Onbekend',
            'stuks' => $stuks, 'waarde' => $stuks * $prijs,
            'laatste' => $last ? $last->format('d-m-Y') : 'Nooit', 'dagenGeleden' => $dagenGeleden,
        ];
    }
    usort($slowMovers, fn($a, $b) => $b['waarde'] <=> $a['waarde']);

    return [
        'maanden' => $maanden, 'categorieen' => $categorieen, 'slowMovers' => $slowMovers,
        'aanpassingen' => $aanpassingen, 'afboekingenTrend' => $afboekingenTrend, 'besparing' => $besparing,
    ];
}

/**
 * Elk getal op het Verbruikstrends-tabblad
 * moet klikbaar zijn naar precies de producten erachter (bijv. "welke
 * ballonnen zijn in die maand gebruikt" i.p.v. alleen het totaal). Dit is de
 * detail-poort: zelfde brongegevens en dezelfde classificatielogica als
 * getVerbruiksTrends() hierboven (categorie-indeling, besparing-buckets),
 * maar nu voor één (maand, reeks[, sub]) gefilterd en per product
 * gegroepeerd, i.p.v. maandelijks geaggregeerd. Bewust een aparte,
 * on-demand query i.p.v. dit alvast in getVerbruiksTrends() meesturen --
 * dat zou de payload van dat scherm (12 maanden x alle categorieën x alle
 * reeksen) onnodig laten opzwellen terwijl de meeste cellen nooit worden
 * aangeklikt.
 *
 * @param string $reeks 'verbruik' | 'overschot' | 'tekort' | 'afboekingen' | 'besparing'
 * @param string $sub   Voor 'verbruik': categorienaam (leeg = alle categorieën
 *                       samen, voor de kolom "Totaal"). Voor 'besparing':
 *                       'tot30'/'tot60'/'tot90' (leeg = alle drie samen, voor
 *                       de kolom "Totaal"). Genegeerd voor de overige reeksen.
 */
function getVerbruiksTrendDetail(string $maand, string $reeks, string $sub = ''): array {
    $artikelen = artikelenMap();

    // Zelfde maandgrens-aanpak als tijdstempel LIKE '$maand-%' zou geven,
    // maar via >= / < zodat het een index op tijdstempel kan gebruiken.
    $vanaf = $maand . '-01 00:00:00';
    $totDt = new DateTime($vanaf);
    $totDt->modify('+1 month');
    $tot = $totDt->format('Y-m-d H:i:s');

    $stmt = db()->prepare(
        "SELECT tijdstempel, transactietype, ean, productnaam, hoeveelheid, vervaldatum, COALESCE(prijs_op_moment, 0) AS prijs
         FROM transactielog
         WHERE transactietype IN ('Verbruik', 'Telling: overschot', 'Telling: tekort', 'Vervallen')
           AND tijdstempel >= :vanaf AND tijdstempel < :tot"
    );
    $stmt->execute([':vanaf' => $vanaf, ':tot' => $tot]);

    $perEan = [];
    foreach ($stmt->fetchAll() as $r) {
        $type = $r['transactietype'];
        $ean = $r['ean'];
        $hoeveelheid = (int) $r['hoeveelheid'];
        $waarde = (float) $r['prijs'] * $hoeveelheid;
        $match = false;

        if ($reeks === 'verbruik' && $type === 'Verbruik') {
            $cat = $artikelen[$ean]['categorie'] ?? 'Onbekend';
            $cat = $cat ?: 'Onbekend';
            if ($sub === '' || $cat === $sub) $match = true;
        } elseif ($reeks === 'overschot' && $type === 'Telling: overschot') {
            $match = true;
        } elseif ($reeks === 'tekort' && $type === 'Telling: tekort') {
            $match = true;
        } elseif ($reeks === 'afboekingen' && $type === 'Vervallen') {
            $match = true;
        } elseif ($reeks === 'besparing' && $type === 'Verbruik' && $r['vervaldatum']) {
            // Zelfde bucket-berekening als in getVerbruiksTrends() hierboven.
            $d = new DateTime($r['tijdstempel']);
            $vervalDatum = new DateTime($r['vervaldatum']);
            $dagenTotVerval = (int) $d->diff($vervalDatum)->format('%r%a');
            if ($dagenTotVerval >= 0 && $dagenTotVerval <= 90) {
                $bucket = $dagenTotVerval <= 30 ? 'tot30' : ($dagenTotVerval <= 60 ? 'tot60' : 'tot90');
                if ($sub === '' || $bucket === $sub) $match = true;
            }
        }

        if (!$match) continue;
        // productnaam komt uit de transactielog zelf (snapshot op moment
        // van scannen) i.p.v. artikelenMap() -- zelfde reden als
        // afboekingenPerType() hierboven: blijft correct tonen ook als het
        // artikel later is verwijderd of hernoemd.
        $perEan[$ean] ??= ['ean' => $ean, 'naam' => $r['productnaam'], 'hoeveelheid' => 0, 'waarde' => 0.0];
        $perEan[$ean]['hoeveelheid'] += $hoeveelheid;
        $perEan[$ean]['waarde'] += $waarde;
    }

    $regels = array_values($perEan);
    usort($regels, fn($a, $b) => $b['hoeveelheid'] <=> $a['hoeveelheid']);

    return [
        'regels' => $regels,
        'totaalAantal' => array_sum(array_column($regels, 'hoeveelheid')),
        'totaalWaarde' => array_sum(array_column($regels, 'waarde')),
    ];
}
