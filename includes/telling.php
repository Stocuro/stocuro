<?php
/**
 * Kastcontrole ("telling"): periodieke fysieke telling per locatie
 * (takenlijst-item, zie includes/taken.php). Zelfde bedieningspatroon als
 * de andere scanformulieren (scan_verbruik.php e.a.): één blok GS1-regels,
 * één per fysiek aanwezig exemplaar -- "de hele kast langs, alles
 * scannen" (letterlijk hoe dit bedoeld is), niet handmatig aantallen
 * intypen per product.
 *
 * Verwerking: de scan wordt getald per EAN+LOT+VERVALDATUM en vergeleken
 * met het systeemaantal van diezelfde lotgroep op die locatie
 * (voorraadLot(), gefilterd -- zie getTellingLotArtikelen()). Een
 * afwijking wordt geboekt als 'Telling: overschot' (meer geteld dan
 * systeem) of 'Telling: tekort' (minder geteld), MET het lot/de
 * vervaldatum van die specifieke groep erbij -- nooit een directe UPDATE
 * op transactielog, dezelfde ledger-first aanpak als de rest van de app.
 * Een lot dat systeemvoorraad had maar niet (in die hoeveelheid)
 * teruggescand is, wordt behandeld als "0 geteld" voor dat lot (dus een
 * tekort tot 0 VOOR DIE LOT/VERVALDATUM-COMBINATIE): "alles scannen"
 * betekent dat afwezigheid in de scan net zo veel informatie is als een
 * scan zelf.
 *
 * Migratie 040 (bugfix, op verzoek van Harm): dit boekte voorheen de
 * afwijking op EAN-niveau, met lot/vervaldatum op NULL. Dat klopte qua
 * TOTAAL voor die EAN+locatie (voorraadStatus() sommeert toch over alle
 * lots), maar liet de oorspronkelijke lot/vervaldatum-rij zelf onaangeroerd
 * in voorraadLot() staan -- een lot dat niet meer aanwezig bleek te zijn,
 * bleef dus gewoon zichtbaar als "nog op voorraad" op de productpagina,
 * Vervaldatums & Lotbeheer, Vervaldatum Focus en Verplaatsadvies, terwijl
 * de EAN-totalen er intussen wel weer correct uitzagen. Nu wordt de
 * afwijking geboekt tegen exact het lot/de vervaldatum die het betreft,
 * zodat een niet-teruggescand lot ook daadwerkelijk als "weg" verschijnt,
 * niet alleen in het totaal.
 *
 * Na verwerken wordt laatst_geteld_op voor die locatie op nu gezet
 * (includes/locaties.php::zetLaatstGeteld()), waarmee de taak in
 * taken.php verdwijnt tot de volgende TELLING_INTERVAL_MAANDEN.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/locaties.php';
require_once __DIR__ . '/voorraad.php';
require_once __DIR__ . '/transacties.php';
require_once __DIR__ . '/ean.php';
require_once __DIR__ . '/gs1_parser.php';

/**
 * Systeemaantal per artikel voor één locatie -- alleen artikelen die daar
 * horen (min/max>0) of al voorraad hebben, zoals voorraadStatus() ze
 * meeneemt.
 *
 * Migratie 043, op verzoek van Harm ("per kast per locatie, niet de hele
 * Berging in 1 keer"): optioneel ook filteren op kastlocatie -- STRIKT op
 * exacte match (artikelKastlocatie() in voorraad.php), een artikel zonder
 * geregistreerde kastlocatie telt hier NIET mee als "hoort in deze kast",
 * want we weten dan niet waar de rest van zijn voorraad staat en zouden
 * anders ten onrechte een tekort kunnen boeken voor iets dat prima ergens
 * anders in dezelfde locatie ligt.
 */
function getTellingArtikelen(string $locatie, ?string $kastlocatie = null): array {
    $artikelen = ($kastlocatie !== null && $kastlocatie !== '') ? artikelenMap() : null;
    $result = [];
    foreach (voorraadStatus() as $row) {
        if ($row['locatie'] !== $locatie) continue;
        if ($artikelen !== null) {
            $artikel = $artikelen[$row['ean']] ?? null;
            if ($artikel === null || artikelKastlocatie($artikel, $locatie) !== $kastlocatie) continue;
        }
        $result[] = ['ean' => $row['ean'], 'naam' => $row['naam'], 'systeemAantal' => $row['huidig']];
    }
    usort($result, fn($a, $b) => strcmp((string) $a['naam'], (string) $b['naam']));
    return $result;
}

/**
 * Migratie 040 (bugfix, op verzoek van Harm): systeemvoorraad per
 * ean+lot+vervaldatum voor één locatie -- hetzelfde brondata als
 * voorraadLot() (voorraad.php), alleen gefilterd op deze locatie. Nodig
 * omdat verwerkTelling() hieronder tekorten/overschotten voortaan PER LOT
 * boekt in plaats van alleen op EAN-niveau (zie toelichting daar).
 *
 * Migratie 043: zelfde optionele, strikte kastlocatie-filter als
 * getTellingArtikelen() hierboven -- zie die toelichting.
 */
function getTellingLotArtikelen(string $locatie, ?string $kastlocatie = null): array {
    $artikelen = ($kastlocatie !== null && $kastlocatie !== '') ? artikelenMap() : null;
    $result = [];
    foreach (voorraadLot() as $r) {
        if ($r['locatie'] !== $locatie) continue;
        if ($artikelen !== null) {
            $artikel = $artikelen[$r['ean']] ?? null;
            if ($artikel === null || artikelKastlocatie($artikel, $locatie) !== $kastlocatie) continue;
        }
        $result[] = $r;
    }
    return $result;
}

/** Zelfde sleutel-opbouw voor zowel systeem- als geteld-lotgroepen hieronder -- moet identiek zijn om overeenkomende lots te matchen. */
function tellingLotSleutel(string $ean, ?string $lot, ?string $vervaldatumSql): string {
    return $ean . '|' . ($lot ?? '') . '|' . ($vervaldatumSql ?? '');
}

/**
 * $gs1RawBlock: meerdere GS1-regels, gescheiden door newlines -- één regel
 * per fysiek geteld exemplaar (dus een product met 5 stuks aanwezig komt
 * 5x voor, net als bij verwerkScan()), TENZIJ een regel een doos-EAN is
 * (migratie 020): dan telt die ene regel voor eenheden_per_doos stuks
 * (lookupArtikelen() geeft de multiplier terug) -- een ongeopende doos die
 * tijdens de telling wordt aangetroffen hoeft dus niet uitgepakt te worden
 * om 'm mee te tellen.
 *
 * $omschrijvingen (migratie 022): map van getrimde ruwe regel ->
 * omschrijving, voor onbekende barcodes -- zie splitsBekendOnbekend()/
 * ontbrekendeOmschrijvingen()/loggeOnbekendeScans() in transacties.php.
 * Bewust blokkerend: zonder omschrijving wordt de hele telling niet
 * verwerkt (geen half bijgewerkte kastcontrole).
 *
 * $kastlocatie (migratie 043, op verzoek van Harm: "het is niet heel
 * waarschijnlijk dat ik de hele berging in 1 keer ga scannen -- dus per
 * kast per locatie"): optioneel, alleen gezet bij de vrijwillige
 * "opnieuw telling uitvoeren"-actie op het Takenbord (nooit bij de
 * periodieke, verlopen Kastcontrole-taak zelf -- die blijft altijd de
 * VOLLEDIGE locatie). Als dit gezet is:
 *   - de systeemvergelijking (zowel EAN- als lotniveau) wordt beperkt tot
 *     artikelen wiens kastlocatie voor deze locatie exact overeenkomt
 *     (getTellingArtikelen()/getTellingLotArtikelen() met kastlocatie-
 *     filter) -- de rest van de locatie (andere kasten) blijft volledig
 *     onaangeroerd;
 *   - een gescande regel wiens artikel een ANDERE, wél-ingevulde
 *     kastlocatie voor deze locatie heeft, wordt NIET meegeteld (het hoort
 *     kennelijk ergens anders) maar teruggegeven als $verkeerdeKast, zodat
 *     de laborant dat kan zien i.p.v. dat het stilzwijgend een foutieve
 *     correctie boekt. Een artikel ZONDER geregistreerde kastlocatie wordt
 *     wél meegeteld (kan overal gescand zijn, geen tegenstrijdige
 *     aanwijzing), maar telt aan de systeemkant niet mee als "hoort hier"
 *     (zie getTellingArtikelen() hierboven) -- het kan dus nooit als
 *     tekort geboekt worden vanuit een kast waarin het toevallig niet is
 *     teruggevonden;
 *   - laatst_geteld_op voor de locatie wordt NIET bijgewerkt (een
 *     kast-telling is per definitie geen volledige fysieke telling van de
 *     hele locatie, dus mag de periodieke Kastcontrole-taak niet laten
 *     verdwijnen).
 */
function verwerkTelling(string $locatie, string $gs1RawBlock, string $gebruikerNaam, array $omschrijvingen = [], ?string $kastlocatie = null): array {
    if (!in_array($locatie, actieveLocatieNamen(), true)) {
        throw new RuntimeException('Onbekende of inactieve locatie');
    }
    $kastlocatie = ($kastlocatie !== null && trim($kastlocatie) !== '') ? trim($kastlocatie) : null;

    $lines = preg_split('/\r?\n/', trim($gs1RawBlock, "\" \t\n\r\0\x0B"));
    $split = splitsBekendOnbekend($lines);

    $ontbrekend = ontbrekendeOmschrijvingen($split['onbekend'], $omschrijvingen);
    if (!empty($ontbrekend)) {
        return ['success' => false, 'onbekend' => true, 'onbekendeRegels' => $ontbrekend, 'verwerkt' => 0];
    }

    // Migratie 037, op verzoek van Harm: zie toelichting bij
    // maakArtikelenVoorOnbekendeRegels() in includes/transacties.php --
    // een onbekende regel met omschrijving wordt hier meteen een echt
    // artikel, dat dan ook meteen in DEZE telling meetelt.
    $aangemaakt = maakArtikelenVoorOnbekendeRegels($split['onbekend'], $omschrijvingen, $gebruikerNaam);
    $split['bekend'] = array_merge($split['bekend'], $aangemaakt['nieuwBekend']);

    // Migratie 043: bij een kast-scoped sessie, gescande regels die bij
    // een ANDERE geregistreerde kastlocatie horen eruit filteren vóórdat
    // $geteld/$geteldPerLot hieronder gebouwd worden -- zie de toelichting
    // bij $kastlocatie in de docblock hierboven.
    $verkeerdeKast = [];
    if ($kastlocatie !== null) {
        $artikelenVoorKastcheck = artikelenMap();
        $bekendInDezeKast = [];
        foreach ($split['bekend'] as $item) {
            $ean = $item['artikel']['ean'];
            $volledigArtikel = $artikelenVoorKastcheck[$ean] ?? null;
            $werkelijkeKast = $volledigArtikel !== null ? artikelKastlocatie($volledigArtikel, $locatie) : '';
            if ($werkelijkeKast === '' || $werkelijkeKast === $kastlocatie) {
                $bekendInDezeKast[] = $item;
            } else {
                $verkeerdeKast[] = ['line' => $item['line'], 'ean' => $ean, 'naam' => $item['artikel']['naam'], 'werkelijkeKast' => $werkelijkeKast];
            }
        }
        $split['bekend'] = $bekendInDezeKast;
    }

    $geteld = [];
    foreach ($split['bekend'] as $item) {
        $artikel = $item['artikel'];
        $geteld[$artikel['ean']] = ($geteld[$artikel['ean']] ?? 0) + $artikel['hoeveelheid'];
    }

    // Migratie 040: naast de EAN-som hierboven (nodig voor de
    // $regels-samenvatting die de frontend ongewijzigd terugkrijgt) ook
    // per ean+lot+vervaldatum tellen, met het lot/de vervaldatum van de
    // gescande regel zelf -- exact hetzelfde patroon als verwerkScan()
    // gebruikt bij een gewone Verbruik/Ontvangst-boeking. Dit is de
    // sleutel tot de bugfix: de correctieboeking hieronder gebeurt straks
    // per lotgroep, niet meer op EAN-niveau met lot=NULL.
    $geteldPerLot = [];
    foreach ($split['bekend'] as $item) {
        $artikel = $item['artikel'];
        $parsed = $item['parsed'];
        $lot = $parsed['lot'] ?? null;
        $vervaldatumSql = gs1ExpiryToSqlDate($parsed['expiry'] ?? null);
        $sleutel = tellingLotSleutel($artikel['ean'], $lot, $vervaldatumSql);
        if (!isset($geteldPerLot[$sleutel])) {
            $geteldPerLot[$sleutel] = ['ean' => $artikel['ean'], 'lot' => $lot, 'vervaldatumSql' => $vervaldatumSql, 'aantal' => 0];
        }
        $geteldPerLot[$sleutel]['aantal'] += $artikel['hoeveelheid'];
    }

    loggeOnbekendeScans($split['onbekend'], $omschrijvingen, $locatie, 'Telling', $gebruikerNaam);
    $fouten = array_column($aangemaakt['nogOnbekend'], 'line');

    $systeem = [];
    foreach (getTellingArtikelen($locatie, $kastlocatie) as $r) $systeem[$r['ean']] = $r['systeemAantal'];

    // Migratie 040: systeemvoorraad in dezelfde ean+lot+vervaldatum-vorm,
    // via de nieuwe getTellingLotArtikelen()/tellingLotSleutel() hierboven.
    // Let op: voorraadLot() geeft 'vervaldatum' terug als weergavestring
    // (d-m-Y) EN 'vervaldatum_sql' als de rauwe SQL-datum -- hier moet
    // vervaldatum_sql gebruikt worden, dezelfde vorm als
    // gs1ExpiryToSqlDate() hierboven produceert, anders matchen de
    // sleutels nooit.
    $systeemPerLot = [];
    foreach (getTellingLotArtikelen($locatie, $kastlocatie) as $r) {
        $sleutel = tellingLotSleutel($r['ean'], $r['lot'], $r['vervaldatum_sql']);
        $systeemPerLot[$sleutel] = ['ean' => $r['ean'], 'lot' => $r['lot'], 'vervaldatumSql' => $r['vervaldatum_sql'], 'aantal' => $r['huidig']];
    }

    // Elke EAN die óf systeemvoorraad had óf nu geteld is, moet
    // vergeleken worden -- niet alleen wat er gescand is (anders zou een
    // volledig ontbrekend artikel nooit als tekort geboekt worden).
    //
    // array_keys() hieronder geeft NIET betrouwbaar strings terug: PHP zet
    // array-sleutels die er als een geheel getal uitzien (en dat doet een
    // EAN, want die is altijd numeriek) stilzwijgend om naar int. Zonder
    // de (string)-cast hieronder krijgt insertTransactieRegel() dus een
    // int i.p.v. de verwachte string $ean binnen, wat met
    // declare(strict_types=1) een fatale TypeError geeft in plaats van
    // een stille coercion.
    $alleEans = array_unique(array_merge(array_keys($systeem), array_keys($geteld)));
    $artikelen = artikelenMap();
    $tijdstempel = date('Y-m-d H:i:s');
    $regels = [];
    $verwerkt = 0;

    // EAN-niveau samenvatting voor het bevestigingsscherm (systeem/geteld/
    // verschil per product) -- ongewijzigd qua vorm t.o.v. vóór migratie
    // 040, puur zodat de frontend niet hoeft te veranderen. De
    // daadwerkelijke boeking gebeurt hieronder per lotgroep.
    foreach ($alleEans as $eanRaw) {
        $ean = (string) $eanRaw;
        if (!isset($artikelen[$ean])) continue;
        $artikel = $artikelen[$ean];
        $systeemAantal = $systeem[$ean] ?? 0;
        $geteldAantal = $geteld[$ean] ?? 0;
        $diff = $geteldAantal - $systeemAantal;

        $regels[] = [
            'ean' => $ean, 'naam' => $artikel['productnaam'],
            'systeem' => $systeemAantal, 'geteld' => $geteldAantal, 'verschil' => $diff,
        ];
    }

    // Migratie 040 (de eigenlijke bugfix): de correctieboeking gebeurt nu
    // per ean+lot+vervaldatum-groep i.p.v. op EAN-niveau met lot=NULL --
    // elke sleutel die óf systeemvoorraad had óf nu geteld is, wordt
    // vergeleken en een afwijking wordt geboekt MET het lot/de vervaldatum
    // van precies die groep. Zo verdwijnt een niet-teruggescand lot ook
    // daadwerkelijk uit voorraadLot() (Vervaldatums & Lotbeheer,
    // Vervaldatum Focus, Verplaatsadvies, productdetail) in plaats van
    // alleen de EAN-som te corrigeren.
    $alleSleutels = array_unique(array_merge(array_keys($systeemPerLot), array_keys($geteldPerLot)));

    foreach ($alleSleutels as $sleutel) {
        $bron = $systeemPerLot[$sleutel] ?? $geteldPerLot[$sleutel];
        $ean = (string) $bron['ean'];
        if (!isset($artikelen[$ean])) continue;
        $artikel = $artikelen[$ean];

        $systeemAantal = $systeemPerLot[$sleutel]['aantal'] ?? 0;
        $geteldAantal = $geteldPerLot[$sleutel]['aantal'] ?? 0;
        $diff = $geteldAantal - $systeemAantal;
        if ($diff === 0) continue;

        $lot = $bron['lot'];
        $vervaldatumSql = $bron['vervaldatumSql'];
        $type = $diff > 0 ? 'Telling: overschot' : 'Telling: tekort';
        $lotToelichting = ($lot !== null || $vervaldatumSql !== null)
            ? ' (lot: ' . ($lot ?? '-') . ', vervaldatum: ' . ($vervaldatumSql ?? '-') . ')'
            : '';
        $opmerking = "Kastcontrole door $gebruikerNaam: systeem $systeemAantal, geteld $geteldAantal$lotToelichting.";
        insertTransactieRegel(
            $tijdstempel, $ean, $lot, $vervaldatumSql, $locatie, $type, abs($diff),
            (float) $artikel['prijs'], null, null, $opmerking, null, $artikel['productnaam'], $gebruikerNaam
        );
        $verwerkt++;
    }

    // Migratie 043: alleen bij een VOLLEDIGE (niet kast-scoped) telling
    // geldt dit als "de hele locatie is nu weer fysiek gecontroleerd" --
    // een kast-telling is per definitie maar een deel van de locatie, dus
    // mag de periodieke Kastcontrole-taak (includes/taken.php) daardoor
    // niet verdwijnen.
    if ($kastlocatie === null) {
        zetLaatstGeteld($locatie, $tijdstempel);
    }

    return [
        'success' => true, 'verwerkt' => $verwerkt, 'fouten' => $fouten, 'regels' => $regels,
        'kastlocatie' => $kastlocatie, 'verkeerdeKast' => $verkeerdeKast,
    ];
}
