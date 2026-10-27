<?php
/**
 * Min/Max Advies: geadviseerd minimum-/maximumniveau per artikel x locatie,
 * afgeleid uit historisch verbruik, piekverbruik, houdbaarheid en levertijd
 * -- om min/max-niveaus objectiever te kunnen bepalen
 * dan "uit de losse pols". Uitgangspunt, expliciet zo gevraagd: nooit meer
 * voorraad aanhouden dan nodig.
 *
 * TWEE aparte formules, afhankelijk van de rol van de locatie (bewust geen
 * voorraadoptimalisatiemodel met een gekozen service-level/
 * betrouwbaarheidsinterval; dat is voor een klinisch-kritische
 * interventiekamer lastiger te verantwoorden dan een simpele, navolgbare
 * regel per locatietype):
 *
 * - KAMERS (elke locatie behalve 'berging' -- de vaste slots ik/hybok/kar
 *   en eventuele extra locaties): deze worden intern
 *   bijgevuld vanuit berging (meestal dezelfde dag, zie includes/locaties.php
 *   voor de aanvul-rol van 'berging'), dus de externe leverancierslevertijd
 *   is hier niet relevant. Wat wel telt: een dagvoorraad op de plank.
 *     min = gemiddeld dagverbruik x MINMAX_DAGVOORRAAD_DAGEN
 *     max = piek-dagverbruik (het drukste dag ooit gezien in de lookback-periode)
 *
 * - BERGING: dit is de enige locatie die daadwerkelijk op een externe
 *   leverancier wacht (orders.php plaatst/ontvangt orders altijd voor
 *   berging), dus hier telt levertijd wel:
 *     min = gemiddeld weekverbruik x (levertijd_dagen / 7 + buffer_weken)
 *     max = min + piek_weekverbruik x dekking_piek_weken
 *
 * Beide, begrensd door een houdbaarheidsplafond (max_by_expiry), zodat het
 * advies nooit meer voorraad aanraadt dan er binnen de houdbaarheid van
 * het product ook daadwerkelijk verbruikt kan worden voordat het vervalt:
 *
 *   max_by_expiry ~ houdbaarheid_maanden x gemiddeld_maandverbruik
 *
 * "Verbruik"/"vraag" op een locatie wordt gemeten als 'Verbruik' +
 * 'Verplaatsing uit' samen, NIET alleen 'Verbruik': berging heeft vrijwel
 * nooit directe Verbruik-regels (dat is een kamer-gebeurtenis) -- de
 * daadwerkelijke "trek" op berging's voorraad loopt via Verplaatsing uit
 * naar de kamers. Zonder Verplaatsing uit mee te tellen zou berging's
 * gemeten verbruik altijd (bijna) nul zijn, en dus ook het advies.
 *
 * Levertijd (besteld -> ontvangen) wordt PER PRODUCT berekend uit
 * orders.tijdstempel -> orders.laatst_ontvangen_op (gezet door
 * includes/orders.php::updateOrdersWithFIFO() bij een ontvangst),
 * met een terugvalketen bij te weinig eigen orderhistorie: product-
 * gemiddelde (>= 3 volledig ontvangen orders) -> globaal gemiddelde over
 * alle producten (>= 3) -> vaste aanname (LEVERTIJD_DAGEN_DEFAULT,
 * config/constants.php). Precies zoals gevraagd: "14 dagen aanhouden
 * wanneer het niet bekend is, en anders uitrekenen wanneer een bestelling
 * is geplaatst, wanneer het ontvangen is en daar een recent gemiddelde
 * van berekenen".
 *
 * Houdbaarheid wordt NIET los ingevoerd -- die volgt uit bestaande
 * Ontvangst-transactieregels (elke ontvangst-scan legt via de GS1-parse
 * al een vervaldatum vast), als gemiddelde (vervaldatum - tijdstempel)
 * per EAN.
 *
 * Menselijke controle: dit bestand berekent en toont alleen een advies
 * (getMinMaxAdviesOverzicht()) -- niets wordt automatisch overgenomen.
 * Toepassen is een losse, expliciete actie per rij (applyMinMaxAdvies()),
 * die de bestaande updateArtikelVeld()-route hergebruikt: dezelfde
 * whitelist-routing (vast slot-kolom vs. artikel_locatie_extra) en
 * audit-logging als een handmatige bewerking op de productdetailpagina.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/voorraad.php';
require_once __DIR__ . '/locaties.php';
require_once __DIR__ . '/artikelen.php';

/**
 * Ruwe (dagen, aantal samples)-uitkomst uit orders, zonder terugvalketen
 * -- $ean = null voor het globale gemiddelde over alle producten. Alleen
 * volledig ontvangen orders (hoeveelheid_ontvangen >= hoeveelheid_besteld)
 * MET een gezette laatst_ontvangen_op (orders van vóór dat veld bestond
 * en die al volledig ontvangen waren, hebben het niet met
 * terugwerkende kracht gevuld gekregen en tellen terecht niet mee: daar
 * is geen betrouwbare levertijd van bekend).
 */
function berekenGemiddeldeLevertijdDagen(?string $ean, int $maxSamples = 20): ?array {
    $sql = "SELECT TIMESTAMPDIFF(HOUR, tijdstempel, laatst_ontvangen_op) / 24.0 AS dagen
            FROM orders
            WHERE laatst_ontvangen_op IS NOT NULL
              AND hoeveelheid_besteld > 0
              AND hoeveelheid_ontvangen >= hoeveelheid_besteld";
    $params = [];
    if ($ean !== null) {
        $sql .= ' AND ean = :ean';
        $params[':ean'] = $ean;
    }
    $sql .= ' ORDER BY laatst_ontvangen_op DESC LIMIT :limiet';

    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) $stmt->bindValue($key, $value);
    $stmt->bindValue(':limiet', $maxSamples, PDO::PARAM_INT);
    $stmt->execute();

    // Negatieve waarden (zou betekenen: ontvangen vóór besteld, een
    // datafout) worden uitgesloten i.p.v. het gemiddelde te verstoren.
    $dagenLijst = array_values(array_filter(
        array_map(fn($r) => (float) $r['dagen'], $stmt->fetchAll()),
        fn($d) => $d >= 0
    ));

    if (count($dagenLijst) === 0) return null;
    return ['dagen' => array_sum($dagenLijst) / count($dagenLijst), 'samples' => count($dagenLijst)];
}

/**
 * Terugvalketen: product-gemiddelde (>= 3 samples) -> globaal gemiddelde
 * (>= 3 samples, request-breed gecached) -> vaste aanname. 'bron' is
 * puur informatief, getoond in het advies-scherm zodat zichtbaar is hoe
 * betrouwbaar het gebruikte getal is.
 */
function getGemiddeldeLevertijdDagen(string $ean): array {
    static $globaalCache = null;
    $minSamples = 3;

    $perProduct = berekenGemiddeldeLevertijdDagen($ean);
    if ($perProduct !== null && $perProduct['samples'] >= $minSamples) {
        return ['dagen' => $perProduct['dagen'], 'bron' => 'product', 'samples' => $perProduct['samples']];
    }

    if ($globaalCache === null) {
        $globaalCache = berekenGemiddeldeLevertijdDagen(null) ?? ['dagen' => 0.0, 'samples' => 0];
    }
    if ($globaalCache['samples'] >= $minSamples) {
        return ['dagen' => $globaalCache['dagen'], 'bron' => 'globaal', 'samples' => $globaalCache['samples']];
    }

    return ['dagen' => (float) LEVERTIJD_DAGEN_DEFAULT, 'bron' => 'default', 'samples' => 0];
}

/**
 * Gemiddelde houdbaarheid in maanden per EAN, in één bulk-query (zelfde
 * aanpak als transactieNettoPerLocatie() in voorraad.php) -- afgeleid uit
 * bestaande Ontvangst-transactieregels, geen apart "houdbaarheid"-veld.
 */
function houdbaarheidMaandenPerEan(): array {
    $rows = db()->query(
        "SELECT ean, AVG(TIMESTAMPDIFF(DAY, tijdstempel, vervaldatum)) AS dagen
         FROM transactielog
         WHERE transactietype = 'Ontvangst' AND vervaldatum IS NOT NULL
         GROUP BY ean"
    )->fetchAll();

    $map = [];
    foreach ($rows as $r) $map[$r['ean']] = round(((float) $r['dagen']) / 30.44, 1);
    return $map;
}

/**
 * "Vraag" per ean+locatie+week binnen het lookback-venster, in één
 * bulk-query -- bij ~170 artikelen x meerdere locaties zou een aparte
 * query per combinatie al snel duizenden queries worden voor één
 * advies-overzicht.
 *
 * Telt 'Verbruik' EN 'Verplaatsing uit' mee (niet alleen 'Verbruik'): op
 * 'berging' is er vrijwel nooit een directe Verbruik-regel (dat gebeurt op
 * de kamers) -- de trek op berging's voorraad loopt via Verplaatsing uit
 * naar de kamers. Op de kamers zelf is Verplaatsing uit zeldzaam (dat is
 * juist de INKOMENDE aanvulling elders), dus deze bredere definitie is ook
 * daar onschadelijk en telt in beide gevallen de daadwerkelijke afname.
 */
function vraagPerEanLocatieWeek(int $lookbackWeken): array {
    $stmt = db()->prepare(
        "SELECT ean, locatie, YEARWEEK(tijdstempel, 3) AS week, SUM(hoeveelheid) AS aantal
         FROM transactielog
         WHERE transactietype IN ('Verbruik', 'Verplaatsing uit') AND tijdstempel >= (NOW() - INTERVAL :weken WEEK)
         GROUP BY ean, locatie, YEARWEEK(tijdstempel, 3)"
    );
    $stmt->bindValue(':weken', $lookbackWeken, PDO::PARAM_INT);
    $stmt->execute();

    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[$r['ean']][$r['locatie']][] = (int) $r['aantal'];
    }
    return $map;
}

/**
 * Zelfde "vraag"-definitie als vraagPerEanLocatieWeek(), maar per DAG
 * i.p.v. per week -- nodig voor de dagvoorraad-formule op de kamers (zie
 * berekenMinMaxAdvies()): daar telt het gemiddelde én het drukste dag
 * ooit gezien, niet een weekcijfer.
 */
function vraagPerEanLocatieDag(int $lookbackWeken): array {
    $stmt = db()->prepare(
        "SELECT ean, locatie, DATE(tijdstempel) AS dag, SUM(hoeveelheid) AS aantal
         FROM transactielog
         WHERE transactietype IN ('Verbruik', 'Verplaatsing uit') AND tijdstempel >= (NOW() - INTERVAL :weken WEEK)
         GROUP BY ean, locatie, DATE(tijdstempel)"
    );
    $stmt->bindValue(':weken', $lookbackWeken, PDO::PARAM_INT);
    $stmt->execute();

    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[$r['ean']][$r['locatie']][] = (int) $r['aantal'];
    }
    return $map;
}

/**
 * Eerste transactielog-tijdstempel per ean+locatie (ongeacht type) --
 * bepaalt hoeveel weken "historie" een combinatie daadwerkelijk heeft,
 * voor zowel de MINMAX_MIN_WEKEN_HISTORIE-drempel als de noemer van het
 * gemiddeld weekverbruik (zie berekenMinMaxAdvies()).
 */
function eersteActiviteitPerEanLocatie(): array {
    $rows = db()->query('SELECT ean, locatie, MIN(tijdstempel) AS eerste FROM transactielog GROUP BY ean, locatie')->fetchAll();
    $map = [];
    foreach ($rows as $r) $map[$r['ean']][$r['locatie']] = $r['eerste'];
    return $map;
}

/**
 * Eén advies-rij, gegeven de al opgezochte bouwstenen (zie
 * getMinMaxAdviesOverzicht() voor waar die vandaan komen). Status
 * 'onvoldoende_data' als er te weinig historie is om een zinvol getal te
 * geven -- expliciet zichtbaar i.p.v. een misleidend precies advies op
 * basis van een paar toevallige transacties.
 *
 * $isBerging bepaalt welke van de twee formules geldt (zie de
 * bestandskop hierboven voor de volledige toelichting):
 * - berging (true): levertijd-gebaseerd -- min = weekvraag x
 *   (levertijd_weken + buffer), max = min + piekweek x dekking.
 * - kamer (false): dagvoorraad-gebaseerd -- min = dagvraag x
 *   MINMAX_DAGVOORRAAD_DAGEN, max = piekdag x MINMAX_DAGVOORRAAD_DAGEN
 *   (nooit meer dan het drukste dat er ooit in één dag gevraagd is).
 *
 * "Gemiddelde vraag" (per week/dag) deelt door de daadwerkelijk gedekte
 * periode (sinds de EERSTE transactie voor dit ean+locatie, niet zomaar
 * het hele lookback-venster) -- anders zou een net geïntroduceerd product
 * ten onrechte een laag gemiddelde krijgen doordat er ook "lege" weken
 * van vóór de introductie meetellen.
 */
function berekenMinMaxAdvies(?string $eersteActiviteit, array $wekelijkseAantallen, array $dagelijkseAantallen, array $levertijd, ?float $houdbaarheidMaanden, bool $isBerging): array {
    if ($eersteActiviteit === null) {
        return ['status' => 'onvoldoende_data', 'wekenGedekt' => 0.0, 'minWekenHistorie' => MINMAX_MIN_WEKEN_HISTORIE];
    }

    $dagenSindsEerste = (time() - strtotime($eersteActiviteit)) / 86400;
    $wekenGedekt = min((float) MINMAX_LOOKBACK_WEKEN, max(1.0, $dagenSindsEerste / 7));

    if ($wekenGedekt < MINMAX_MIN_WEKEN_HISTORIE) {
        return ['status' => 'onvoldoende_data', 'wekenGedekt' => round($wekenGedekt, 1), 'minWekenHistorie' => MINMAX_MIN_WEKEN_HISTORIE];
    }

    $totaalVraag = array_sum($wekelijkseAantallen);
    $piekWeek = $wekelijkseAantallen === [] ? 0 : max($wekelijkseAantallen);
    $gemWeek = $wekenGedekt > 0 ? $totaalVraag / $wekenGedekt : 0.0;

    $piekDag = $dagelijkseAantallen === [] ? 0 : max($dagelijkseAantallen);
    $gemDag = $gemWeek / 7;

    if ($isBerging) {
        $adviesMinIntern = (int) ceil($gemWeek * ($levertijd['dagen'] / 7 + MINMAX_BUFFER_WEKEN));
        $adviesMax = $adviesMinIntern + (int) ceil($piekWeek * MINMAX_DEKKING_PIEK_WEKEN);
    } else {
        // Kamers hebben geen eigen
        // minimum meer (ze worden gegarandeerd dezelfde dag tot hun
        // maximum bijgevuld vanuit Berging) -- dit advies gaat voor kamers
        // dus alleen nog over het maximum (de par-waarde/capaciteit,
        // gebaseerd op het piekverbruik). $adviesMinIntern is hier puur een
        // interne ondergrens zodat adviesMax nooit kleiner uitvalt dan een
        // dagvoorraad zou vragen -- wordt NIET als 'adviesMin' teruggegeven.
        $adviesMinIntern = $gemDag > 0 ? max(1, (int) ceil($gemDag * MINMAX_DAGVOORRAAD_DAGEN)) : 0;
        $adviesMax = max($adviesMinIntern, (int) ceil($piekDag * MINMAX_DAGVOORRAAD_DAGEN));
    }

    $maxByExpiry = null;
    if ($houdbaarheidMaanden !== null && $houdbaarheidMaanden > 0) {
        $gemMaand = $gemWeek * (52 / 12);
        $maxByExpiry = (int) ceil($houdbaarheidMaanden * $gemMaand);
        if ($maxByExpiry > 0 && $adviesMax > $maxByExpiry) {
            $adviesMax = max($adviesMinIntern, $maxByExpiry);
        }
    }

    return [
        'status' => 'ok',
        'formule' => $isBerging ? 'levertijd' : 'dagvoorraad',
        // Alleen Berging heeft nog een echt minimum-advies --
        // voor kamers is dit null (geen kolom meer, zie hierboven), niet 0
        // (dat zou "advies is 0" betekenen, in plaats van "niet van
        // toepassing").
        'adviesMin' => $isBerging ? $adviesMinIntern : null,
        'adviesMax' => $adviesMax,
        'gemiddeldWeekverbruik' => round($gemWeek, 2),
        'piekWeekverbruik' => $piekWeek,
        'gemiddeldDagverbruik' => round($gemDag, 2),
        'piekDagverbruik' => $piekDag,
        'dagvoorraadDagen' => MINMAX_DAGVOORRAAD_DAGEN,
        'levertijdDagen' => round($levertijd['dagen'], 1),
        'levertijdBron' => $levertijd['bron'],
        'houdbaarheidMaanden' => $houdbaarheidMaanden,
        'maxByExpiry' => $maxByExpiry,
        'wekenGedekt' => round($wekenGedekt, 1),
    ];
}

/**
 * EAN+locatie -> wanneer genegeerd.
 * Alleen relevant voor het aggregaat-overzicht hieronder -- de
 * productdetailpagina negeert dit bewust (zie getProductDetail(),
 * includes/artikelen.php): een voorstel blijft daar altijd zichtbaar.
 */
function getGenegeerdeMinMaxAdviezen(): array {
    $rows = db()->query('SELECT ean, locatie, genegeerd_op FROM minmax_advies_genegeerd')->fetchAll();
    $map = [];
    foreach ($rows as $r) $map[$r['ean']][$r['locatie']] = $r['genegeerd_op'];
    return $map;
}

/**
 * "Negeer dit voorstel": verdwijnt van het Min/Max
 * Advies-overzicht tot MINMAX_NEGEER_MAANDEN later (config/constants.php)
 * -- daarna wordt het, als het dan nog steeds geldt, opnieuw aangeboden.
 * INSERT ... ON DUPLICATE KEY UPDATE zodat een tweede keer negeren de
 * teller gewoon opnieuw laat lopen vanaf nu.
 */
function negeerMinMaxAdvies(string $ean, string $locatie): array {
    db()->prepare(
        'INSERT INTO minmax_advies_genegeerd (ean, locatie, genegeerd_op) VALUES (:ean, :locatie, NOW())
         ON DUPLICATE KEY UPDATE genegeerd_op = NOW()'
    )->execute([':ean' => $ean, ':locatie' => $locatie]);
    return ['success' => true];
}

/**
 * Volledig overzicht: elke EAN x locatie-combinatie die op dit moment al
 * "hoort" bij die locatie (min>0 of max>0 al ingesteld -- zelfde
 * scope-regel als voorraadStatus()/getBergingData(): een advies geven
 * voor een combinatie die daar helemaal niet gebruikt wordt, is ruis),
 * met het huidige min/max erbij ter vergelijking.
 *
 * $alleenEan: scoopt tot één product -- gebruikt door
 * getProductDetail() (includes/artikelen.php) om het min/max-voorstel
 * voor DAT product op de productdetailpagina te tonen.
 *
 * $inclusiefAlles : het Min/Max
 * Advies-overzicht zelf moet twee dingen NIET meer tonen (beheersbaar
 * blijven): (1) rijen waar het advies exact gelijk is aan de huidige
 * min/max -- niets te wijzigen, en (2) rijen die recent genegeerd zijn
 * (zie hierboven). De productdetailpagina wil juist ALTIJD het voorstel
 * zien, ongeacht die twee filters -- vandaar deze vlag i.p.v. de filters
 * onvoorwaardelijk toe te passen.
 */
function getMinMaxAdviesOverzicht(?string $alleenEan = null, bool $inclusiefAlles = false): array {
    $artikelen = artikelenMap();
    $weekVraag = vraagPerEanLocatieWeek(MINMAX_LOOKBACK_WEKEN);
    $dagVraag = vraagPerEanLocatieDag(MINMAX_LOOKBACK_WEKEN);
    $eersteActiviteit = eersteActiviteitPerEanLocatie();
    $houdbaarheidPerEan = houdbaarheidMaandenPerEan();
    $genegeerd = $inclusiefAlles ? [] : getGenegeerdeMinMaxAdviezen();
    $result = [];

    // (string)-cast hier om dezelfde reden als in includes/telling.php: PHP
    // zet array-sleutels die er als een geheel getal uitzien (en dat doet
    // een EAN, want die is altijd numeriek) stilzwijgend om naar int. Zonder
    // de cast krijgt getGemiddeldeLevertijdDagen() hieronder een int i.p.v.
    // de verwachte string $ean binnen, wat met declare(strict_types=1) een
    // fatale TypeError geeft in plaats van een stille coercion.
    foreach ($artikelen as $eanRaw => $artikel) {
        $ean = (string) $eanRaw;
        if ($alleenEan !== null && $ean !== $alleenEan) continue;

        // Een artikel dat 'Niet
        // leverbaar' of 'Niet meer in gebruik' is, krijgt geen nieuw
        // besteladvies -- geen zin om te adviseren iets te herbestellen
        // wat je niet kunt of niet meer wilt bestellen. Bestaande
        // fysieke voorraad en de min/max-velden zelf blijven gewoon
        // bestaan, alleen dit advies-scherm slaat het artikel over. Op de
        // productdetailpagina ($inclusiefAlles) geldt deze uitsluiting
        // NIET -- die toont het voorstel altijd, ongeacht status.
        if (!$inclusiefAlles && ($artikel['status'] ?? 'Actief') !== 'Actief') continue;

        $levertijd = getGemiddeldeLevertijdDagen($ean);
        $houdbaarheidMaanden = $houdbaarheidPerEan[$ean] ?? null;

        foreach (actieveLocatieNamen() as $locatie) {
            [$huidigMin, $huidigMax] = artikelMinMax($artikel, $locatie);
            if ($huidigMin === 0 && $huidigMax === 0) continue;

            // Alleen 'berging' wacht daadwerkelijk op een externe
            // leverancier (orders.php); alle andere locaties -- de vaste
            // slots ik/hybok/kar én eventuele extra locaties (die geen
            // slot hebben, dus locatieSlotVoorNaam() geeft null) -- worden
            // dezelfde dag intern bijgevuld en krijgen de dagvoorraad-
            // formule. Zie de bestandskop hierboven.
            $isBerging = locatieSlotVoorNaam($locatie) === 'berging';

            $advies = berekenMinMaxAdvies(
                $eersteActiviteit[$ean][$locatie] ?? null,
                $weekVraag[$ean][$locatie] ?? [],
                $dagVraag[$ean][$locatie] ?? [],
                $levertijd,
                $houdbaarheidMaanden,
                $isBerging
            );

            if (!$inclusiefAlles) {
                // Filter 1: niets te wijzigen -- niet tonen. 
                // kamers hebben geen adviesMin/huidigMin meer (altijd null
                // resp. 0) -- daar alleen het max-advies vergelijken, anders
                // zou dit filter een kamerrij nooit als "niets te wijzigen"
                // herkennen (null === 0 is nooit waar).
                $adviesGelijk = $advies['status'] === 'ok' && ($isBerging
                    ? ($advies['adviesMin'] === $huidigMin && $advies['adviesMax'] === $huidigMax)
                    : ($advies['adviesMax'] === $huidigMax));
                if ($adviesGelijk) continue;
                // Filter 2: recent genegeerd -- niet tonen tot
                // MINMAX_NEGEER_MAANDEN later.
                $genegeerdOp = $genegeerd[$ean][$locatie] ?? null;
                if ($genegeerdOp !== null) {
                    $maandenSinds = (time() - strtotime($genegeerdOp)) / (86400 * 30.44);
                    if ($maandenSinds < MINMAX_NEGEER_MAANDEN) continue;
                }
            }

            $result[] = array_merge(
                [
                    'ean' => $ean, 'naam' => $artikel['productnaam'], 'locatie' => $locatie,
                    'huidigMin' => $huidigMin, 'huidigMax' => $huidigMax,
                    // Frontend gebruikt dit om de Min-kolom voor
                    // kamers als "n.v.t." te tonen i.p.v. een misleidende 0.
                    'isBerging' => $isBerging,
                ],
                $advies
            );
        }
    }

    usort($result, fn($a, $b) => strcmp((string) $a['naam'], (string) $b['naam']) ?: strcmp((string) $a['locatie'], (string) $b['locatie']));
    return $result;
}

/**
 * Past één advies-rij toe op het echte min/max-veld -- hergebruikt
 * updateArtikelVeld() (includes/artikelen.php) zodat exact dezelfde
 * routing (vast slot-kolom vs. artikel_locatie_extra, via dezelfde
 * ARTIKEL_EDIT_FIELDS-whitelist) en audit-logging geldt als een
 * handmatige bewerking op de productdetailpagina. Nooit automatisch
 * aangeroepen -- alleen op expliciet verzoek van een gebruiker
 * (api/minmax_advies.php, POST), zie de bestandskop hierboven.
 *
 * Kamers (elke locatie behalve
 * 'berging', inclusief extra locaties) hebben geen minimum-kolom meer --
 * $min wordt voor die locaties genegeerd (alleen max_$slot resp.
 * extra_max_$id wordt geschreven). Alleen Berging schrijft nog beide.
 */
function applyMinMaxAdvies(string $ean, string $locatie, int $min, int $max, ?string $gebruikerNaam = null): array {
    $min = max(0, $min);
    $max = max(0, $max);

    $slot = locatieSlotVoorNaam($locatie);
    if ($slot !== null) {
        if (!in_array($slot, ['ik', 'hybok', 'kar', 'berging'], true)) {
            return ['success' => false, 'error' => 'Onbekende locatie-slot'];
        }
        $velden = $slot === 'berging' ? ["min_$slot" => $min, "max_$slot" => $max] : ["max_$slot" => $max];
        return updateArtikelVeld($ean, $velden, $gebruikerNaam);
    }

    $locatieExtraId = locatieExtraIdVoorNaam($locatie);
    if ($locatieExtraId === null) {
        return ['success' => false, 'error' => 'Onbekende locatie: ' . $locatie];
    }
    return updateArtikelVeld($ean, ["extra_max_$locatieExtraId" => $max], $gebruikerNaam);
}
