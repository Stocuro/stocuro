<?php
/**
 * Na de persona-navigatie
 * Bleef de zijbalk toch een muur van
 * knoppen -- 16 losse dashboard-bestemmingen alleen al buiten Formulieren/
 * Beheer om. Onderliggende reden: bijna al die schermen zijn dezelfde
 * databron (artikel x locatie, met lot-detail erbij), alleen elke keer
 * anders gefilterd of gesorteerd. Dit bestand bouwt daarom ÉÉN samengestelde
 * rij per (ean, locatie) met alle kolommen erbij, zodat het Voorraad-scherm
 * (assets/js/app.js::renderVoorraadTabel()) dat met filterchips/kolom-
 * toggles kan tonen i.p.v. aparte pagina's.
 *
 * Dit bestand voegt GEEN nieuwe voorraadlogica toe -- het combineert
 * uitsluitend de bestaande, al geteste berekeningen:
 *   - voorraadStatus()/voorraadLot()      (includes/voorraad.php)
 *   - getMinMaxAdviesOverzicht()          (includes/minmax.php)
 *   - getVerplaatsAdviesOverzicht()       (includes/verplaatsadvies.php)
 *   - openOrderResterendPerEan()/rondAfOpDoos() (includes/orders.php resp.
 *     includes/voorraad.php)
 * Vervangt als databron: getOverzichtData(), getBoodschappenData(),
 * getBergingData(), getVoorraadWaarde(), getMinMaxAdviesOverzicht() (als
 * apart scherm), getVervaldatumDetail(), getLotTracking(), getBestellersData
 * (deels -- alleen de "moet ik bestellen"-vlag, niet de aparte pagina),
 * getBeschikbaarheid() en getActiecentrumData() (als apart scherm) -- die
 * bestaande functies zelf blijven ongewijzigd bestaan (niets verwijderd),
 * alleen de frontend-navigatie ernaartoe vervalt.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/voorraad.php';
require_once __DIR__ . '/minmax.php';
require_once __DIR__ . '/verplaatsadvies.php';
require_once __DIR__ . '/locaties.php';
// Voor verbruikLaatsteDagenPerEan() -- oorspronkelijk gebouwd voor het
// (inmiddels vervallen als apart scherm) Beschikbaarheid-scherm, hier
// hergebruikt als de "recent verbruik"-kolom van de megatabel.
require_once __DIR__ . '/beschikbaarheid.php';

function getVoorraadTabel(): array {
    $artikelen = artikelenMap();
    $basisRijen = voorraadStatus();

    // Eerstvolgende vervaldatum + aantal loten < 6 maanden, per (ean,
    // locatie) -- preciezer dan per ean alleen (hetzelfde product kan op de
    // ene locatie snel vervallen en op de andere niet).
    $vervalPerEanLocatie = [];
    $loten6PerEanLocatie = [];
    foreach (voorraadLot() as $lot) {
        if ($lot['vervaldatum_sql'] === null) continue;
        $ean = $lot['ean'];
        $loc = $lot['locatie'];
        if (!isset($vervalPerEanLocatie[$ean][$loc]) || $lot['vervaldatum_sql'] < $vervalPerEanLocatie[$ean][$loc]['sql']) {
            $vervalPerEanLocatie[$ean][$loc] = ['sql' => $lot['vervaldatum_sql'], 'weergave' => $lot['vervaldatum'], 'daysLeft' => $lot['daysLeft']];
        }
        $monthsLeft = $lot['daysLeft'] / 30.44;
        if ($monthsLeft < 6) $loten6PerEanLocatie[$ean][$loc] = ($loten6PerEanLocatie[$ean][$loc] ?? 0) + 1;
    }

    // Verplaatsadvies: per lot berekend, hier verdicht tot het meest
    // urgente (kleinste daysLeft) advies per (ean, huidige locatie) -- de
    // megatabel toont één rij per (ean, locatie), niet per lot.
    $verplaatsPerEanLocatie = [];
    foreach (getVerplaatsAdviesOverzicht() as $advies) {
        if ($advies['aanbevolenLocatie'] === null) continue;
        $ean = $advies['ean'];
        $loc = $advies['huidigeLocatie'];
        if (!isset($verplaatsPerEanLocatie[$ean][$loc]) || $advies['daysLeft'] < $verplaatsPerEanLocatie[$ean][$loc]['daysLeft']) {
            $verplaatsPerEanLocatie[$ean][$loc] = $advies;
        }
    }

    $verbruikPerEan = verbruikLaatsteDagenPerEan(BESCHIKBAARHEID_VERBRUIK_DAGEN);

    // inclusiefAlles=true: altijd een advies-waarde krijgen (ook voor niet-
    // actieve artikelen en zonder de "recent genegeerd"-filter) -- de
    // filtering zelf (wel/niet tonen als afwijkend, wel/niet genegeerd) doet
    // deze functie hieronder zelf, per rij, precies zoals
    // getMinMaxAdviesOverzicht() dat normaal ook doet.
    $minMaxPerEanLocatie = [];
    foreach (getMinMaxAdviesOverzicht(null, true) as $a) {
        $minMaxPerEanLocatie[$a['ean']][$a['locatie']] = $a;
    }
    $genegeerd = getGenegeerdeMinMaxAdviezen();

    // Huidige voorraad in Berging per ean -- nodig om te bepalen of een
    // kamerlocatie daadwerkelijk bijgevuld KAN worden (zelfde regel als
    // getBoodschappenData()).
    $bergingHuidigPerEan = [];
    foreach ($basisRijen as $row) {
        if (locatieSlotVoorNaam($row['locatie']) === 'berging') $bergingHuidigPerEan[$row['ean']] = $row['huidig'];
    }

    // (drie gemelde bugs):
    //   1. "Handmatig bestellen" moet een optelsom over alle locaties zijn,
    //      niet een los getal per locatie-rij -- hergebruikt de al langer
    //      bestaande, al juiste aggregatie in getBestellersData() (die
    //      groepeert per ean en rondt op hele dozen af over het TOTAAL, zie
    //      toelichting daar) in plaats van dat hier opnieuw te berekenen.
    //      Om te voorkomen dat dezelfde bestelknop op elke locatie-rij van
    //      hetzelfde artikel verschijnt (en dus dubbel besteld zou kunnen
    //      worden), wordt de aggregaat-waarde maar op ÉÉN rij per ean gezet
    //      -- de alfabetisch eerste locatie waar dit artikel een tekort
    //      heeft, zodat het altijd deterministisch is welke rij de knop
    //      toont.
    //   2. "Berging: moet besteld" moet ook een optelsom over alle locaties
    //      zijn (niet alleen Bergings eigen min/max-gat) en mag geen
    //      Handmatig-besteltype artikelen tonen -- hergebruikt de bijgewerkte
    //      getBergingData() (includes/voorraad.php), die precies dit al
    //      doet. Bewust dezelfde functie als "Markeer als besteld"
    //      (markeerBergingAlsBesteld()) gebruikt, zodat wat hier getoond
    //      wordt nooit kan afwijken van wat daadwerkelijk besteld wordt.
    $handmatigPerEan = [];
    foreach (getBestellersData() as $p) {
        $locatieNamen = array_keys($p['locaties']);
        sort($locatieNamen, SORT_STRING);
        // 'locaties' bevat sinds kort alleen kamers met een
        // echte behoefte (zie getBestellersData()) -- als geen enkele kamer
        // zelf krap zit maar Berging wél onder haar eigen minimum zit (de
        // trigger die dit product hier heeft laten verschijnen), zou
        // primaireLocatie anders leeg blijven en de bestelknop nergens
        // getoond worden. Val in dat geval terug op Bergings eigen rij --
        // die bestaat altijd, voor elk artikel.
        $primaireLocatie = $locatieNamen[0] ?? locatieNaamVoorSlot('berging');
        $handmatigPerEan[$p['ean']] = [
            'totaal' => $p['totaalNodig'],
            'dozen' => $p['dozenNodig'],
            'primaireLocatie' => $primaireLocatie,
        ];
    }
    // Bugfix: 'huidig'/'max' komen hier ook mee (naast
    // 'nodig'/'dozenNodig') -- getBergingData() (includes/voorraad.php)
    // levert die inmiddels al als optelsom over alle locaties, niet meer
    // als Bergings eigen fysieke voorraad/max. Hieronder alleen op de
    // Berging-rij van dit artikel gezet, ter vervanging van die rij's eigen
    // lokale huidig/max (zie verderop) zodat je in het overzicht in één
    // oogopslag Maximaal/Aanwezig over alle locaties samen ziet, i.p.v.
    // alleen wat er toevallig in Berging zelf staat.
    $bergingBestelPerEan = [];
    foreach (getBergingData() as $b) {
        $bergingBestelPerEan[$b['ean']] = [
            'nodig' => $b['nodig'], 'dozenNodig' => $b['dozenNodig'],
            'huidig' => $b['huidig'], 'max' => $b['max'],
        ];
    }

    $result = [];
    foreach ($basisRijen as $row) {
        $ean = $row['ean'];
        $loc = $row['locatie'];
        $artikel = $artikelen[$ean] ?? [];
        $isBerging = locatieSlotVoorNaam($loc) === 'berging';
        $isActief = ($row['status'] ?? 'Actief') === 'Actief';
        // Kamers hebben geen eigen
        // minimum meer (ze worden gegarandeerd dezelfde dag tot hun
        // maximum bijgevuld vanuit Berging) -- "heeft een gat" betekent
        // voor een kamer dus simpelweg "zit niet op het maximum", en voor
        // Berging (die wél een echt reorder-minimum heeft) blijft het
        // "zit onder het minimum".
        $heeftGat = $isBerging ? ($row['huidig'] < $row['min']) : ($row['huidig'] < $row['max']);

        $verval = $vervalPerEanLocatie[$ean][$loc] ?? null;
        $verplaats = $verplaatsPerEanLocatie[$ean][$loc] ?? null;

        // Min/max-advies: alleen "actief" tonen als het advies status 'ok'
        // heeft én afwijkt van de huidige instelling, en niet recent
        // genegeerd is -- zelfde twee filters als getMinMaxAdviesOverzicht().
        // Kamers hebben geen adviesMin/huidigMin meer (altijd
        // null resp. 0) -- daar alleen het max-advies vergelijken, anders
        // zou elke kamerrij hier altijd als "wijkt af" tonen (null !== 0).
        $advies = $minMaxPerEanLocatie[$ean][$loc] ?? null;
        $adviesWijktAf = $advies && $advies['status'] === 'ok' && (
            $isBerging
                ? ($advies['adviesMin'] !== $row['min'] || $advies['adviesMax'] !== $row['max'])
                : ($advies['adviesMax'] !== $row['max'])
        );
        $genegeerdOp = $genegeerd[$ean][$loc] ?? null;
        $adviesGenegeerd = false;
        if ($genegeerdOp !== null) {
            $maandenSinds = (time() - strtotime($genegeerdOp)) / (86400 * 30.44);
            $adviesGenegeerd = $maandenSinds < MINMAX_NEGEER_MAANDEN;
        }

        // "Moet bijgevuld" (voorheen Bijvullen/Boodschappenlijst): een
        // kamerlocatie (nooit Berging zelf) met een gat t.o.v. haar
        // maximum -- LOS van of Berging er zelf voorraad van heeft.
        // Bugfix: voorheen bestond alleen
        // "magBijvullen" (hieronder), dat ALLEEN waar is als Berging
        // voorraad heeft -- stond Berging op nul, dan verdween de rij
        // overal uit beeld, en een leeg filterresultaat werd door de
        // Bijvullen-pagina gelezen als "alles op peil", terwijl er juist
        // een fors tekort is. Nu apart bijgehouden zodat de frontend
        // "tekort, maar Berging is leeg" kan tonen in plaats van niets/
        // verkeerd "voldoende".
        //
        // Bugfix: NIET meer beperkt tot Automatisch-
        // besteltype. Een Handmatig besteld artikel kan óók in Berging
        // belanden (bijv. eenmalig extra besteld), en als het daar op
        // voorraad ligt terwijl een kamer een tekort heeft, hoort dat
        // artikel net zo goed als "kan bijgevuld worden" te tellen --
        // besteltype zegt iets over HOE een artikel besteld wordt, niet
        // over of het vanuit Berging naar een kamer verplaatst mag worden.
        $moetBijgevuld = !$isBerging && $isActief && $heeftGat;
        // "Kan NU daadwerkelijk bijgevuld worden" -- hetzelfde als voorheen
        // "magBijvullen": alleen waar als Berging ook echt voorraad heeft.
        $magBijvullen = $moetBijgevuld && ($bergingHuidigPerEan[$ean] ?? 0) > 0;

        // Berging-bestelbehoefte (voorheen aparte Berging-bestellijst):
        // optelsom van tekorten over ALLE locaties (zie getBergingData() in
        // includes/voorraad.php) -- niet alleen Bergings eigen
        // gat, en Handmatig-besteltype telt hier niet mee. Deze waarde komt
        // dus al kant-en-klaar aggregeerd binnen; hier alleen op de
        // Berging-rij van dit artikel zetten (er is er maar één per ean).
        $bergingAgg = $isBerging ? ($bergingBestelPerEan[$ean] ?? null) : null;
        $bergingNodig = $bergingAgg['nodig'] ?? null;
        $bergingDozenNodig = $bergingAgg['dozenNodig'] ?? null;
        $bergingKanBestellen = $bergingAgg !== null;

        // Handmatig te bestellen (voorheen "Bestellers"): optelsom over
        // ALLE locaties van dit artikel (zie getBestellersData() in
        // Includes/voorraad.php) -- Bugfix: dit was
        // voorheen een los getal per locatie-rij i.p.v. één totaal. Om een
        // dubbele/verspreide bestelknop voor hetzelfde artikel te vermijden
        // wordt de aggregaat-waarde alleen op de alfabetisch eerste locatie
        // met een tekort gezet; op de overige locatie-rijen van hetzelfde
        // artikel blijft dit null (die rijen tonen de eigen LAAG/LEEG-status
        // gewoon via 'voorraadStatus', maar geen eigen bestelknop).
        $handmatigAgg = $handmatigPerEan[$ean] ?? null;
        $handmatigNodig = ($handmatigAgg && $handmatigAgg['primaireLocatie'] === $loc) ? $handmatigAgg['totaal'] : null;
        $handmatigDozenNodig = ($handmatigAgg && $handmatigAgg['primaireLocatie'] === $loc) ? $handmatigAgg['dozen'] : null;

        // Bugfix: op de Berging-rij van een product met
        // een systeembrede bestelbehoefte (bergingKanBestellen) tonen
        // 'huidig'/'max' niet langer Bergings eigen fysieke voorraad/max,
        // maar de optelsom over alle locaties -- 'min' blijft wél Bergings
        // eigen minimum (dat is geen zinvol op te tellen getal). Geen
        // andere rij/kolom in de tabel wordt hierdoor geraakt: dit raakt
        // alleen deze ene rij, en alleen wanneer er daadwerkelijk iets te
        // bestellen is.
        $weergaveHuidig = $bergingAgg['huidig'] ?? $row['huidig'];
        $weergaveMax = $bergingAgg['max'] ?? $row['max'];

        $result[] = [
            'ean' => $ean,
            'naam' => $row['naam'],
            'categorie' => $artikel['categorie'] ?? '',
            'producent' => $artikel['producent'] ?? '',
            // Productfoto's waar mogelijk meegeven: $artikel komt uit
            // artikelenMap() (SELECT *, includes/voorraad.php), dus
            // foto_data/foto_mime zijn al in het geheugen -- alleen nog de
            // dataURL-encoding erbij (zelfde patroon als getProductDetail()/
            // getProductList()/getNieuwsteProducten()). Gebruikt door o.a.
            // de Bijvullen-lijst (assets/js/app.js::renderBijvullen()).
            'foto' => !empty($artikel['foto_data'])
                ? 'data:' . ($artikel['foto_mime'] ?: 'image/jpeg') . ';base64,' . base64_encode($artikel['foto_data'])
                : null,
            'locatie' => $loc,
            // Frontend gebruikt dit om de Min-kolom voor
            // kamers als "n.v.t." te tonen (kamers hebben geen minimum meer).
            'isBerging' => $isBerging,
            'huidig' => $weergaveHuidig,
            'min' => $row['min'],
            'max' => $weergaveMax,
            'voorraadStatus' => $row['voorraadStatus'],
            'status' => $row['status'],
            'prijs' => $row['prijs'],
            'waarde' => $row['waarde'],
            'kastlocatie' => $row['kastlocatie'],
            'besteltype' => $row['besteltype'],
            'orderStatus' => $row['orderStatus'],
            'eenhedenPerDoos' => $row['eenhedenPerDoos'],
            'eerstvolgendeVervaldatum' => $verval['weergave'] ?? null,
            // Bugfix: de frontend toonde hier altijd
            // hardcoded het label "MONITOREN" zodra er een lot < 6 maanden
            // was (loten6 > 0) -- maar "< 6 maanden" omvat ook al VERLOPEN
            // en SPOEDGEBRUIK loten (allebei ook < 6 maanden). Een al
            // verstreken vervaldatum kreeg zo ten onrechte het label
            // MONITOREN. Nu de ECHTE classificatie van de eerstvolgende
            // vervaldatum zelf meegeven, zie includes/voorraad.php::
            // classifyVervalStatus().
            'eerstvolgendeVervaldatumStatus' => isset($verval['daysLeft']) ? classifyVervalStatus($verval['daysLeft']) : null,
            'vervalDaysLeft' => $verval['daysLeft'] ?? null,
            'loten6' => $loten6PerEanLocatie[$ean][$loc] ?? 0,
            'verbruikRecent' => $verbruikPerEan[$ean] ?? 0,
            'minMaxAdvies' => ($adviesWijktAf && !$adviesGenegeerd)
                ? ['min' => $advies['adviesMin'], 'max' => $advies['adviesMax']] : null,
            'verplaatsAdvies' => $verplaats ? [
                'naar' => $verplaats['aanbevolenLocatie'],
                'lot' => $verplaats['lot'],
                'daysLeft' => $verplaats['daysLeft'],
            ] : null,
            'moetBijgevuld' => $moetBijgevuld,
            'magBijvullen' => $magBijvullen,
            'bijvullenHoeveel' => $moetBijgevuld ? ($row['max'] - $row['huidig']) : null,
            'bergingKanBestellen' => $bergingKanBestellen,
            'bergingNodig' => $bergingNodig,
            'bergingDozenNodig' => $bergingDozenNodig,
            'logistiekCode' => $artikel['logistiek_code'] ?? null,
            'artikelref' => $artikel['artikelref'] ?? null,
            'handmatigNodig' => $handmatigNodig,
            'handmatigDozenNodig' => $handmatigDozenNodig,
        ];
    }

    usort($result, fn($a, $b) => strcmp($a['naam'], $b['naam']) ?: strcmp($a['locatie'], $b['locatie']));
    return $result;
}
