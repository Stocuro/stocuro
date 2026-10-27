<?php
/**
 * Verwerking van scans, verplaatsingen en retouren. Poort van
 * onFormSubmit_Transactielog, onFormSubmit_Verplaatsing_Retournering,
 * handleVerplaatsing, handleRetournering, addToTransactionLog en
 * lookupArtikelen uit Code.gs.
 *
 * addToVoorraadStatus(), addToVoorraadLot_Simple() en
 * controleerVoorraadStatus() zijn vervallen: Voorraad-Status/
 * Voorraad-Lot zijn geen tabellen meer maar live berekeningen
 * (voorraad.php) -- er is dus niets meer om "bij te vullen" of te
 * controleren op ontbrekende rijen (migratieplan hoofdstuk 4.5).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ean.php';
require_once __DIR__ . '/gs1_parser.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/orders.php';
// HuidigeVoorraadPerLocatie() voor de "slimme ruimte"-
// omleiding bij Verbruik hieronder.
require_once __DIR__ . '/voorraad.php';
// LocatieNaamVoorSlot('berging') voor
// de "algemene voorraad"-omleiding bij Retourneren hieronder.
require_once __DIR__ . '/locaties.php';
// AddArtikel() voor
// maakArtikelenVoorOnbekendeRegels() hieronder.
require_once __DIR__ . '/artikelen.php';
// PasGs1CorrectieToe() in
// splitsBekendOnbekend() hieronder.
require_once __DIR__ . '/lot_correcties.php';

/**
 * $ean kan het stuk-EAN, het doos-EAN, OF
 * de handmatige interne logistiek_code van een artikel zijn -- sommige
 * producten (bijv. voerdraden, PICC-lijnen) hebben zowel een stuk- als
 * een doos-barcode, en een product kan daarnaast nog een eigen geprint
 * schaplabel (logistiek_code) hebben. Een stuk-EAN matcht met
 * hoeveelheid 1; een doos-EAN matcht met hoeveelheid =
 * artikelen.eenheden_per_doos; logistiek_code matcht ook met hoeveelheid
 * 1 (het is een productidentiteit, geen doos). Alle drie geven ALTIJD
 * het stuk-EAN terug (voorraad wordt nooit in "dozen" of onder de
 * logistiekcode bijgehouden) -- de aanroeper (verwerkScan()/
 * verplaatsing()/retournering() hieronder) hoeft dus niet te weten welke
 * van de drie gescand is, alleen hoeveel stuks het voorstelt.
 *
 * logistiek_code is bewust NIET genormaliseerd via normalizeEAN(): dat
 * pad verwacht een numerieke, 14-cijferige GS1-code, en een
 * logistiek_code is vrije alfanumerieke tekst (bijv. "STK-00042") --
 * alleen trimmen, exacte match.
 *
 * @return array{ean:string, naam:string, prijs:float, hoeveelheid:int}|null
 */
function lookupArtikelen(string $ean): ?array {
    $eanNorm = normalizeEAN($ean);

    $stmt = db()->prepare('SELECT ean, productnaam, prijs FROM artikelen WHERE ean = :ean');
    $stmt->execute([':ean' => $eanNorm]);
    $row = $stmt->fetch();
    if ($row) {
        return ['ean' => $row['ean'], 'naam' => $row['productnaam'], 'prijs' => (float) $row['prijs'], 'hoeveelheid' => 1];
    }

    // Niet gevonden als stuk-EAN -- proberen als doos-EAN.
    $stmt = db()->prepare('SELECT ean, productnaam, prijs, eenheden_per_doos FROM artikelen WHERE doos_ean = :ean');
    $stmt->execute([':ean' => $eanNorm]);
    $row = $stmt->fetch();
    if ($row) {
        // max(1, ...) als bescherming tegen een inconsistente/lege
        // eenheden_per_doos -- dan toch 1 stuk boeken i.p.v. 0.
        $hoeveelheid = max(1, (int) $row['eenheden_per_doos']);
        return ['ean' => $row['ean'], 'naam' => $row['productnaam'], 'prijs' => (float) $row['prijs'], 'hoeveelheid' => $hoeveelheid];
    }

    // Ook niet als doos-EAN -- proberen als interne logistiek_code
    //. Geen normalizeEAN(): exacte, getrimde tekstmatch.
    $codeTrimmed = trim($ean);
    if ($codeTrimmed !== '') {
        $stmt = db()->prepare('SELECT ean, productnaam, prijs FROM artikelen WHERE logistiek_code = :code');
        $stmt->execute([':code' => $codeTrimmed]);
        $row = $stmt->fetch();
        if ($row) {
            return ['ean' => $row['ean'], 'naam' => $row['productnaam'], 'prijs' => (float) $row['prijs'], 'hoeveelheid' => 1];
        }
    }

    logError('EAN_NOT_IN_ARTIKELEN', $ean, 'Product niet gevonden in artikelen');
    return null;
}

// =====================================================================
// ONBEKENDE SCANS : een barcode die
// bij geen van de drie paden in lookupArtikelen() een match oplevert,
// werd voorheen stilzwijgend in de tijdelijke $fouten-array van die ene
// aanroep gezet -- de scanner zag "regel overgeslagen" en daarna was de
// informatie weg, geen enkele beheerder kreeg dit ooit te zien. Nieuwe
// Gedrag, bewust BLOKKEREND : een indiening met
// onbekende regels wordt pas verwerkt als er per onbekende regel een
// korte omschrijving is meegegeven ("wat voor product is dit?"). Zonder
// omschrijving wordt er NIETS geboekt (ook niet de wel-herkende regels
// in dezelfde indiening) en komt de aanroeper terug met precies welke
// regels nog een omschrijving nodig hebben, zodat de frontend die
// kan tonen en de gebruiker opnieuw kan indienen met de tekst erbij.
// Gedeeld door verwerkScan()/verplaatsing()/retournering() hieronder en
// verwerkTelling() in telling.php.
// =====================================================================

/**
 * Splitst een blok scanregels in bekende (klaar om te boeken) en
 * onbekende artikelen. Bewust ná parseGS1Raw() óók de RUWE regel zelf
 * proberen als lookupArtikelen()-zoekterm wanneer er geen GS1-GTIN uit
 * Te halen viel: een geprint logistiek_code-label is geen
 * GS1-128-barcode maar gewone tekst, en zou anders nooit bij
 * lookupArtikelen() aankomen.
 *
 * @return array{bekend: array<int, array{line:string, parsed:array, artikel:array}>, onbekend: array<int, array{line:string, parsed:array}>}
 */
function splitsBekendOnbekend(array $lines): array {
    $bekend = [];
    $onbekend = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $parsed = parseGS1Raw($line);
        // Als deze exacte barcode al
        // eens handmatig gecorrigeerd is (zie includes/lot_correcties.php),
        // gebruik dan meteen de gecorrigeerde lot/vervaldatum i.p.v.
        // opnieuw de (bekend foute) parse van parseGS1Raw() hierboven.
        $parsed = pasGs1CorrectieToe($line, $parsed);
        $zoekterm = $parsed['ean'] !== '' ? $parsed['ean'] : $line;
        $artikel = lookupArtikelen($zoekterm);

        if ($artikel) {
            $bekend[] = ['line' => $line, 'parsed' => $parsed, 'artikel' => $artikel];
        } else {
            $onbekend[] = ['line' => $line, 'parsed' => $parsed];
        }
    }

    return ['bekend' => $bekend, 'onbekend' => $onbekend];
}

/**
 * $omschrijvingen: map van getrimde ruwe regel -> door de gebruiker
 * ingevulde omschrijving. Retourneert de ruwe regels uit $onbekend die
 * nog GEEN (niet-lege) omschrijving hebben -- lege array betekent: alles
 * heeft er een, de indiening mag doorgaan.
 */
function ontbrekendeOmschrijvingen(array $onbekend, array $omschrijvingen): array {
    $ontbrekend = [];
    foreach ($onbekend as $item) {
        $tekst = trim((string) ($omschrijvingen[$item['line']] ?? ''));
        if ($tekst === '') $ontbrekend[] = $item['line'];
    }
    return $ontbrekend;
}

/**
 * Schrijft elke onbekende regel weg naar onbekende_scans, met de
 * omschrijving die de gebruiker ervoor heeft ingevuld -- alleen aan te
 * roepen nadat ontbrekendeOmschrijvingen() een lege array teruggaf.
 * ean = de geparste GTIN indien beschikbaar, anders een best-effort
 * normalizeEAN() van de ruwe regel (altijd 14 tekens, consistent met de
 * rest van het schema) -- puur voor latere herkenning in het
 * Admin-scherm "Onbekende Producten", geen garantie dat dit een geldig
 * GS1-EAN is.
 */
function loggeOnbekendeScans(array $onbekend, array $omschrijvingen, ?string $locatie, string $transactieType, ?string $gebruikerNaam): void {
    if (empty($onbekend)) return;

    $stmt = db()->prepare(
        'INSERT INTO onbekende_scans (ean, gs1_raw, omschrijving, locatie, transactietype, gebruiker_naam)
         VALUES (:ean, :gs1_raw, :omschrijving, :locatie, :type, :gebruiker)'
    );
    foreach ($onbekend as $item) {
        $ean = $item['parsed']['ean'] !== '' ? $item['parsed']['ean'] : normalizeEAN($item['line']);
        $stmt->execute([
            ':ean' => $ean, ':gs1_raw' => $item['line'],
            ':omschrijving' => trim((string) ($omschrijvingen[$item['line']] ?? '')),
            ':locatie' => $locatie, ':type' => $transactieType, ':gebruiker' => $gebruikerNaam,
        ]);
    }
}

/**
 * "ik wil dat dit product direct wordt
 * aangemaakt met de tijdelijke naam die de laborant op dat moment
 * invult. Het systeem kan dan namelijk direct met dit product gaan
 * werken en tonen." Voorheen bleef een onbekende regel, ook ná het
 * invullen van een omschrijving, uitsluitend een logregel in
 * onbekende_scans -- het product zelf bestond nog nergens (niet in de
 * catalogus, niet in de voorraad) totdat een beheerder later handmatig
 * naar "Onbekende Producten" ging en het volledige aanmaakformulier
 * invulde. Nu wordt er, zodra ontbrekendeOmschrijvingen() leeg is (dus
 * elke onbekende regel een omschrijving heeft), METEEN een echt artikel
 * aangemaakt via addArtikel() -- met de omschrijving als tijdelijke
 * productnaam, verder alle standaardwaarden (prijs 0, besteltype
 * Automatisch, geen categorie) -- zodat de huidige indiening dit
 * product meteen als BEKEND kan boeken, precies zoals elk ander
 * artikel.
 *
 * loggeOnbekendeScans() hierboven blijft door de aanroeper ONVERANDERD
 * aangeroepen met de oorspronkelijke $onbekend-lijst: het product blijft
 * dus gewoon zichtbaar op het Admin-scherm "Onbekende Producten" (nu als
 * "nog af te ronden" i.p.v. "nog aan te maken"), totdat een beheerder
 * daar de echte naam/categorie/prijs/etc. invult -- zie
 * markeerOnbekendeScanVerwerkt() in api/product_edit.php.
 *
 * Idempotent: bestaat er al een artikel met dit EAN (bijv. twee regels
 * met dezelfde onbekende barcode in één indiening, of een eerdere scan
 * die 'm al aanmaakte), dan wordt dat artikel gewoon hergebruikt i.p.v.
 * een dubbele aanmaak-poging (die toch zou stuklopen op de UNIQUE-
 * constraint op artikelen.ean).
 *
 * @return array{nieuwBekend: array<int, array{line:string, parsed:array, artikel:array}>, nogOnbekend: array<int, array{line:string, parsed:array}>}
 *   nieuwBekend heeft dezelfde vorm als splitsBekendOnbekend()['bekend']
 *   en is bedoeld om daar meteen aan toegevoegd te worden (array_merge).
 *   nogOnbekend is in de praktijk vrijwel altijd leeg -- alleen gevuld als
 *   addArtikel() zelf onverhoopt faalt.
 */
function maakArtikelenVoorOnbekendeRegels(array $onbekend, array $omschrijvingen, ?string $gebruikerNaam): array {
    if (empty($onbekend)) return ['nieuwBekend' => [], 'nogOnbekend' => []];

    $nieuwBekend = [];
    $nogOnbekend = [];

    foreach ($onbekend as $item) {
        $ean = $item['parsed']['ean'] !== '' ? $item['parsed']['ean'] : normalizeEAN($item['line']);
        $omschrijving = trim((string) ($omschrijvingen[$item['line']] ?? ''));

        $stmt = db()->prepare('SELECT productnaam, prijs FROM artikelen WHERE ean = :ean');
        $stmt->execute([':ean' => $ean]);
        $bestaand = $stmt->fetch();

        if ($bestaand) {
            $naam = $bestaand['productnaam'];
            $prijs = (float) $bestaand['prijs'];
        } else {
            $result = addArtikel(['productnaam' => $omschrijving, 'ean' => $ean], $gebruikerNaam);
            if (!$result['success']) {
                // Zeer onwaarschijnlijk (addArtikel() heeft hier al een
                // niet-lege naam en EAN) -- als het toch misgaat, blijft de
                // regel gewoon "onbekend" zoals voorheen i.p.v. de hele
                // indiening te laten mislukken.
                $nogOnbekend[] = $item;
                continue;
            }
            $naam = $omschrijving;
            $prijs = 0.0;
        }

        $nieuwBekend[] = [
            'line' => $item['line'], 'parsed' => $item['parsed'],
            'artikel' => ['ean' => $ean, 'naam' => $naam, 'prijs' => $prijs, 'hoeveelheid' => 1],
        ];
    }

    return ['nieuwBekend' => $nieuwBekend, 'nogOnbekend' => $nogOnbekend];
}

/**
 * "de hele registratie rondom
 * eenmalige bestellingen ook rond maken" -- zodra iemand later een
 * eerder ontvangen eenmalige-bestelling-item gewoon via het BESTAANDE
 * Verbruik- of Verplaatsing+Retouren-scherm scant (géén nieuw veld, géén
 * aparte koppelstap: "als deze barcode overeen komt met de eenmalige
 * bestelling dan moet hij uit deze bestelling worden verwijderd"), moet
 * dat item op de bestelling zelf zichtbaar overgaan naar 'verbruikt' of
 * 'retour'. Matcht op EAN + exact hetzelfde lot (null-veilig via <=>,
 * MySQL/MariaDB's null-safe gelijkheidsoperator) tegen de OUDSTE nog
 * openstaande ('ontvangen') regel -- dat is een bewust lichte, niet-
 * verplichte koppeling: geen match (geen eenmalige bestelling met dat
 * EAN+lot openstaand) betekent gewoon een normale scan zoals altijd,
 * geen foutmelding, geen blokkade.
 *
 * $bekendeItems heeft dezelfde vorm als splitsBekendOnbekend()['bekend']
 * hierboven, MAAR dan met een extra sleutel 'transactielog_id' per regel
 * -- het id van de transactielog-rij die deze specifieke
 * scanregel heeft geboekt (zie verwerkScan()/retournering() hieronder).
 * $nieuweStatus is 'verbruikt' (vanuit verwerkScan() bij transactietype
 * 'Verbruik') of 'retour' (vanuit retournering()).
 *
 * De vastgelegde transactielog_id is wat verwijderTransactieRegel() straks
 * gebruikt om deze koppeling weer terug te draaien als de gebruiker die
 * transactie via Recente Transacties verwijdert -- zie
 * ontkoppelEenmaligeBestellingItem() hieronder.
 */
function koppelScanAanEenmaligeBestellingItems(array $bekendeItems, string $nieuweStatus, ?string $gebruikerNaam): void {
    if (empty($bekendeItems)) return;

    $vind = db()->prepare(
        "SELECT id, eenmalige_bestelling_id FROM eenmalige_bestelling_items
         WHERE ean = :ean AND status = 'ontvangen' AND lot <=> :lot
         ORDER BY aangemaakt_op ASC LIMIT 1"
    );
    $bijwerken = db()->prepare(
        'UPDATE eenmalige_bestelling_items
         SET status = :status, transactielog_id = :transactielog_id, afgehandeld_op = NOW(), afgehandeld_door = :door
         WHERE id = :id'
    );

    foreach ($bekendeItems as $item) {
        $lot = ($item['parsed']['lot'] ?? '') !== '' ? $item['parsed']['lot'] : null;
        $vind->execute([':ean' => $item['artikel']['ean'], ':lot' => $lot]);
        $rij = $vind->fetch();
        if ($rij) {
            $bijwerken->execute([
                ':status' => $nieuweStatus, ':transactielog_id' => $item['transactielog_id'] ?? null,
                ':door' => $gebruikerNaam, ':id' => (int) $rij['id'],
            ]);
            herbereekenEenmaligeBestellingStatusNaScan((int) $rij['eenmalige_bestelling_id']);
        }
    }
}

/**
 * "de hele registratie ook rond" --
 * na het bijwerken van een enkel item hierboven, zodra GEEN item van die
 * eenmalige bestelling meer op 'ontvangen' staat (alles is inmiddels
 * verbruikt of geretourneerd), mag de bestelling zelf ook als afgerond
 * gemarkeerd worden: uitsluitend 'verbruikt' -> status 'Gebruikt'; een
 * MIX van verbruikt/retour (of uitsluitend retour) -> status
 * 'Retourneren' ("het andere deel is geretourneerd... mag de hele status
 * worden afgerond naar Retourneren"). Rechtstreeks met SQL i.p.v. via
 * updateEenmaligeBestelling()/EENMALIGE_BESTELLING_STATUSSEN (includes/
 * eenmalige_bestellingen.php) om een circulaire require met dat bestand
 * (dat DIT bestand al required) te vermijden. De WHERE status NOT IN
 * (...)-guard voorkomt dat een al handmatig afgeronde bestelling
 * overschreven wordt, en dat dit bij een volgende matching regel opnieuw
 * (nutteloos) wordt weggeschreven.
 */
function herbereekenEenmaligeBestellingStatusNaScan(int $bestellingId): void {
    $stmt = db()->prepare('SELECT status FROM eenmalige_bestelling_items WHERE eenmalige_bestelling_id = :id');
    $stmt->execute([':id' => $bestellingId]);
    $statussen = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($statussen) || in_array('ontvangen', $statussen, true)) return;

    $nieuweStatus = in_array('retour', $statussen, true) ? 'Retourneren' : 'Gebruikt';
    db()->prepare(
        "UPDATE eenmalige_bestellingen SET status = :status WHERE id = :id AND status NOT IN ('Gebruikt', 'Retourneren')"
    )->execute([':status' => $nieuweStatus, ':id' => $bestellingId]);
}

/**
 * Genereert een nieuwe batch_id: één willekeurige hex-string per
 * Ingediende scanactie, zodat alle regels die uit die ene
 * indiening voortkomen (elke gescande barcode bij Ontvangst/Verbruik/
 * Vervallen Afboeken/Retournering, of het uit+in-paar per gescand
 * artikel bij Verplaatsing) achteraf als één geheel herkend en
 * gecorrigeerd kunnen worden -- zie getRecenteTransactiesGegroepeerd()
 * en bewerkTransactieBatch()/verwijderTransactieBatch() verderop.
 */
function nieuweBatchId(): string {
    return bin2hex(random_bytes(16));
}

/**
 * $prijsOpMoment: de artikelprijs op het moment van deze transactie,
 * vastgelegd als onveranderlijke snapshot. Rapportages
 * over verbruik in het verleden moeten deze kolom gebruiken, niet de
 * (mogelijk inmiddels gewijzigde) actuele artikelen.prijs.
 */
function insertTransactieRegel(
    string $tijdstempel, string $ean, ?string $lot, ?string $vervaldatumSql, string $locatie,
    string $type, int $hoeveelheid, ?float $prijsOpMoment, ?string $specialist, ?string $procedureType,
    ?string $opmerking, ?string $gs1Raw, string $productnaam, ?string $aangemaaktDoor = null, ?string $batchId = null
): int {
    db()->prepare(
        'INSERT INTO transactielog
            (tijdstempel, batch_id, ean, lot, vervaldatum, locatie, transactietype, hoeveelheid, prijs_op_moment, specialist, procedure_type, aangemaakt_door, opmerking, gs1_raw, productnaam)
         VALUES (:tijdstempel, :batch_id, :ean, :lot, :vervaldatum, :locatie, :type, :hoeveelheid, :prijs, :specialist, :procedure, :door, :opmerking, :gs1raw, :naam)'
    )->execute([
        ':tijdstempel' => $tijdstempel, ':batch_id' => $batchId, ':ean' => $ean, ':lot' => $lot, ':vervaldatum' => $vervaldatumSql,
        ':locatie' => $locatie, ':type' => $type, ':hoeveelheid' => $hoeveelheid, ':prijs' => $prijsOpMoment,
        ':specialist' => $specialist, ':procedure' => $procedureType, ':door' => $aangemaaktDoor, ':opmerking' => $opmerking,
        ':gs1raw' => $gs1Raw, ':naam' => $productnaam,
    ]);
    // Het teruggegeven id wordt gebruikt om (bij Verbruik/
    // Retournering) de koppeling met een eenmalige-bestelling-item vast te
    // leggen -- zie koppelScanAanEenmaligeBestellingItems() hieronder.
    return (int) db()->lastInsertId();
}

/**
 * "een voorraad van -1 kan niet. Stel
 * dit punt bereik je toch dan heeft dit te maken met een telfout in de
 * voorraad. Blijf dan gewoon op 0 maar registreer wel dat het product
 * verbruikt is." Roep dit aan VLAK NA elke uitgaande boeking (Verbruik,
 * Vervallen, Verplaatsing uit, Retournering) die de berekende voorraad
 * van een (ean, locatie) negatief zou kunnen laten worden -- de scan zelf
 * is dan al onvoorwaardelijk geboekt (nooit geweigerd/geblokkeerd, dat is
 * hier expliciet niet gewenst), dit controleert alleen wat
 * de RESULTERENDE voorraad nu is.
 *
 * Boekt zo nodig een compenserende correctie van hetzelfde transactietype
 * als een positieve afwijking bij een echte kastcontrole ('Telling:
 * overschot', zie verwerkTelling() in includes/telling.php) zodat de
 * weergegeven voorraad nooit onder 0 zakt. Bewust hergebruikt i.p.v. een
 * gloednieuw transactietype: beide betekenen feitelijk hetzelfde (de
 * administratie liep achter op de fysieke werkelijkheid) en dit type telt
 * al automatisch mee in TRANSACTIETYPES_INKOMEND (voorraadberekening),
 * Verbruikstrends ("aanpassingen") en de vertaalde badge-labels -- geen
 * enkele andere plek in de app hoeft hiervoor aangepast te worden. De
 * opmerking maakt het verschil met een echte, fysieke kastcontrole
 * duidelijk herkenbaar in Recente Transacties.
 *
 * Geen aparte lot/vervaldatum op de correctieregel: dit corrigeert het
 * EAN+locatie-totaal (wat de gebruiker als "de voorraad" ziet in
 * Overzicht/Voorraad), niet een specifiek lot -- lot-niveau-precisie is
 * hier niet het punt, een telfout van deze aard geeft toch al geen
 * betrouwbaar aanknopingspunt voor WELK lot precies fout stond.
 */
function voorkomNegatieveVoorraad(string $ean, string $locatie, string $artikelNaam, ?float $prijsOpMoment, ?string $gebruikerNaam = null): void {
    $perLocatie = huidigeVoorraadPerLocatie($ean);
    $huidig = $perLocatie[$locatie] ?? 0;
    if ($huidig >= 0) return;

    $tekort = abs($huidig);
    $opmerking = "Automatische correctie: deze boeking zou de voorraad van dit product op $locatie op $huidig hebben gebracht, wat niet kan. "
        . "Voorraad teruggezet naar 0 -- wijst op een telfout, controleer de kastvoorraad op deze locatie.";
    insertTransactieRegel(
        date('Y-m-d H:i:s'), $ean, null, null, $locatie, 'Telling: overschot', $tekort,
        $prijsOpMoment, null, null, $opmerking, null, $artikelNaam, $gebruikerNaam
    );
}

/**
 * Verwerkt een blok GS1-scans (meerdere regels, één per gescand
 * artikel -- zelfde patroon als het oude Google Forms-textveld) voor
 * Ontvangst of Verbruik. Poort van onFormSubmit_Transactielog().
 *
 * @param string $transactieType 'Ontvangst' of 'Verbruik'
 * @param string $locatie
 * @param string $gs1RawBlock meerdere GS1-regels, gescheiden door newlines
 * @param array $extra ['specialist'=>?, 'procedureType'=>?, 'opmerking'=>?, 'gebruikerNaam'=>?, 'omschrijvingen'=>?]
 * @return array{verwerkt:int, overgeslagen:int, fouten:array}|array{success:false, onbekend:true, onbekendeRegels:array, verwerkt:0}
 */
function verwerkScan(string $transactieType, string $locatie, string $gs1RawBlock, array $extra = []): array {
    $tijdstempel = date('Y-m-d H:i:s');
    $batchId = nieuweBatchId();
    $opmerking = $extra['opmerking'] ?? '';
    $specialist = $transactieType === 'Verbruik' ? ($extra['specialist'] ?? null) : null;
    $procedureType = $transactieType === 'Verbruik' ? ($extra['procedureType'] ?? null) : null;
    $gebruikerNaam = $extra['gebruikerNaam'] ?? null;
    $omschrijvingen = $extra['omschrijvingen'] ?? [];

    // PICC-regel : de ingelogde
    // gebruiker die dit formulier indient is NIET per se degene die de
    // procedure heeft uitgevoerd -- dat is losstaand het 'specialist'-veld
    // hierboven (wie de procedure heeft gedaan). Een Laborant mag alleen
    // als uitvoerder gekozen worden bij een procedure_type dat daarvoor is
    // aangevinkt (procedure_typen.laborant_toegestaan, in de praktijk nu
    // PICC-lijn); bij elke andere procedure moet de uitvoerder een
    // Specialist zijn. Dit wordt hier server-side afgedwongen (niet alleen
    // client-side, zie renderScanVerbruikForm()) omdat dit direct om
    // klinische verantwoordelijkheid gaat. Bij een overtreding wordt de
    // hele indiening geweigerd (geen enkele regel verwerkt), vóór er ook
    // maar één GS1-regel wordt geparsed. Als de procedure- of
    // uitvoerdersnaam niet herkend wordt (geen match in procedure_typen
    // resp. users), wordt niet geblokkeerd -- dat is geen bevoegd
    // ontworpen restrictie maar een onbekende invoer, en zou anders
    // legitieme scans onterecht kunnen blokkeren.
    if ($transactieType === 'Verbruik' && $specialist !== null && $specialist !== '' && $procedureType !== null && $procedureType !== '') {
        $procStmt = db()->prepare('SELECT laborant_toegestaan FROM procedure_typen WHERE naam = :naam');
        $procStmt->execute([':naam' => $procedureType]);
        $procedure = $procStmt->fetch();

        if ($procedure && !$procedure['laborant_toegestaan']) {
            $performerStmt = db()->prepare("SELECT rol FROM users WHERE naam = :naam AND rol IN ('specialist', 'laborant')");
            $performerStmt->execute([':naam' => $specialist]);
            $performer = $performerStmt->fetch();

            if ($performer && $performer['rol'] === 'laborant') {
                return ['success' => false, 'error' => 'Deze procedure mag alleen door een specialist worden uitgevoerd, niet door een laborant'];
            }
        }
    }

    // Licentiecontrole ("Licentiemodel: geen naam-
    // registratie zonder licentie"): materiaalverbruik mag niet op naam
    // van een specialist/laborant geboekt worden als dat account geen
    // geldige licentie heeft -- anders blijft die persoon onzichtbaar in
    // de verbruiksrapportage, wat het juist onaantrekkelijk maakt om
    // alsnog een licentie aan te schaffen. users.licentie_geblokkeerd is
    // een cache-kolom, bijgewerkt bij elke login/periodieke hercontrole
    // (zie includes/license.php::controleerLicentieGebruiker()) -- hier
    // wordt bewust niet opnieuw live geverifieerd, dat hoort puur bij
    // authenticatie zelf. Ook hier geldt (net als bij de PICC-regel
    // hierboven): een onherkende naam blokkeert niet, dat is geen
    // bevoegdheidsbeslissing maar onbekende invoer.
    if ($transactieType === 'Verbruik' && $specialist !== null && $specialist !== '') {
        $licStmt = db()->prepare("SELECT licentie_geblokkeerd FROM users WHERE naam = :naam AND rol IN ('specialist', 'laborant')");
        $licStmt->execute([':naam' => $specialist]);
        $licRij = $licStmt->fetch();
        if ($licRij && (int) $licRij['licentie_geblokkeerd'] === 1) {
            return ['success' => false, 'error' => "Voor '" . $specialist . "' ontbreekt een geldige licentie -- koppel eerst een activatiecode aan dit account voordat er materiaalverbruik op naam geboekt kan worden."];
        }
    }

    $lines = preg_split('/\r?\n/', trim($gs1RawBlock, "\" \t\n\r\0\x0B"));
    $split = splitsBekendOnbekend($lines);

    // Blokkerend : pas boeken als elke
    // onbekende regel een omschrijving heeft. Anders niets verwerken en
    // teruggeven welke regels dat nog missen.
    $ontbrekend = ontbrekendeOmschrijvingen($split['onbekend'], $omschrijvingen);
    if (!empty($ontbrekend)) {
        return ['success' => false, 'onbekend' => true, 'onbekendeRegels' => $ontbrekend, 'verwerkt' => 0];
    }

    // Elke onbekende regel heeft nu een
    // omschrijving -- maak er meteen een echt artikel van (zie
    // maakArtikelenVoorOnbekendeRegels() hierboven) zodat het in DEZE
    // indiening al meeboekt, i.p.v. pas na een latere handmatige
    // "aanmaken"-stap. $split['onbekend'] zelf blijft ongewijzigd (voor
    // loggeOnbekendeScans() verderop, zodat het product zichtbaar blijft
    // in "Onbekende Producten" om af te ronden).
    $aangemaakt = maakArtikelenVoorOnbekendeRegels($split['onbekend'], $omschrijvingen, $gebruikerNaam);
    $split['bekend'] = array_merge($split['bekend'], $aangemaakt['nieuwBekend']);

    $verwerkt = 0;
    $omgeleid = [];
    $verwerkteItems = []; // $item + transactielog_id per regel
    foreach ($split['bekend'] as $item) {
        $parsed = $item['parsed'];
        $artikel = $item['artikel'];
        $line = $item['line'];

        // "Slimme ruimte" bij
        // Verbruik Scannen. Sommige producten horen niet thuis in de
        // gescande ruimte zelf -- bijv. een uniek, eenmalig besteld
        // artikel dat rechtstreeks in Berging of op een kar staat, in
        // plaats van in de kamer waar het gebruikt wordt. Als dit
        // artikel GEEN voorraad heeft in de gescande ruimte, maar wél in
        // PRECIES ÉÉN andere ruimte, wordt het verbruik daar automatisch
        // uit afgeschreven -- ondubbelzinnig, dus veilig om automatisch
        // te doen. Staat het in nul of in meerdere andere ruimtes, dan
        // blijft het gewoon de gescande ruimte (zoals voorheen): bij nul
        // is er sowieso iets mis (nergens voorraad) en bij meerdere zou
        // gokken een verkeerde ruimte kunnen leegboeken.
        $effectieveLocatie = $locatie;
        if ($transactieType === 'Verbruik') {
            $perLocatie = huidigeVoorraadPerLocatie($artikel['ean']);
            $huidigHier = $perLocatie[$locatie] ?? 0;
            if ($huidigHier <= 0) {
                $andereMetVoorraad = [];
                foreach ($perLocatie as $loc => $aantal) {
                    if ($loc !== $locatie && $aantal > 0) $andereMetVoorraad[] = $loc;
                }
                if (count($andereMetVoorraad) === 1) {
                    $effectieveLocatie = $andereMetVoorraad[0];
                    $omgeleid[] = ['ean' => $artikel['ean'], 'naam' => $artikel['naam'], 'van' => $locatie, 'naar' => $effectieveLocatie];
                }
            }
        }
        // Opmerking krijgt een korte, doorzoekbare aantekening mee zodat
        // dit ook achteraf in Recente Transacties navolgbaar blijft, niet
        // alleen in de eenmalige melding die de gebruiker nu te zien
        // krijgt (zie renderScanVerbruikForm() in assets/js/app.js).
        $regelOpmerking = $opmerking;
        if ($effectieveLocatie !== $locatie) {
            $notitie = "(automatisch afgeschreven uit $effectieveLocatie i.p.v. $locatie -- daar geen voorraad van dit product)";
            $regelOpmerking = trim($opmerking !== '' ? "$opmerking $notitie" : $notitie);
        }

        // $artikel['hoeveelheid']: 1 bij een los stuk-EAN,
        // of eenheden_per_doos bij een herkende doos-EAN -- zie
        // lookupArtikelen(). Eén gescande barcode kan dus meerdere stuks
        // tegelijk boeken.
        $transactielogId = insertTransactieRegel(
            $tijdstempel, $artikel['ean'], $parsed['lot'], gs1ExpiryToSqlDate($parsed['expiry']), $effectieveLocatie,
            $transactieType, $artikel['hoeveelheid'], $artikel['prijs'], $specialist, $procedureType, $regelOpmerking, $line, $artikel['naam'], $gebruikerNaam, $batchId
        );
        $verwerkteItems[] = $item + ['transactielog_id' => $transactielogId];

        if ($transactieType === 'Ontvangst') {
            updateOrdersWithFIFO($artikel['ean'], $artikel['hoeveelheid']);
        } elseif ($transactieType === 'Verbruik' || $transactieType === 'Vervallen') {
            // Zie voorkomNegatieveVoorraad() hierboven --
            // de scan is hierboven al onvoorwaardelijk geboekt, dit
            // corrigeert alleen de resulterende voorraad als die door
            // deze boeking onder 0 zou zijn gezakt.
            voorkomNegatieveVoorraad($artikel['ean'], $effectieveLocatie, $artikel['naam'], $artikel['prijs'], $gebruikerNaam);
        }

        $verwerkt++;
    }

    loggeOnbekendeScans($split['onbekend'], $omschrijvingen, $locatie, $transactieType, $gebruikerNaam);

    // Alleen bij Verbruik (niet bij
    // Ontvangst of Vervallen) matchen tegen openstaande eenmalige-
    // bestelling-items -- zie koppelScanAanEenmaligeBestellingItems()
    // hierboven. $verwerkteItems (i.p.v. $split['bekend'])
    // draagt per regel het bijbehorende transactielog_id mee.
    if ($transactieType === 'Verbruik') {
        koppelScanAanEenmaligeBestellingItems($verwerkteItems, 'verbruikt', $gebruikerNaam);
    }

    return [
        // 'overgeslagen'/'fouten' tellen alleen nog regels
        // die ook na maakArtikelenVoorOnbekendeRegels() niet verwerkt
        // konden worden (in de praktijk vrijwel altijd 0) -- alle overige
        // onbekende regels zijn hierboven al meegeboekt als nieuw
        // aangemaakt artikel.
        'success' => true, 'verwerkt' => $verwerkt, 'overgeslagen' => count($aangemaakt['nogOnbekend']),
        'fouten' => array_column($aangemaakt['nogOnbekend'], 'line'),
        // Welke regels (indien van toepassing) automatisch
        // naar een andere ruimte zijn omgeleid -- de frontend toont dit
        // als een informatieve melding na het indienen.
        'omgeleid' => $omgeleid,
    ];
}

// =====================================================================
// RECENTE TRANSACTIES: overzicht + bewerken/verwijderen (scherm "Recent
// Transactions"). Alleen bedoeld om snel een vergissing recht te zetten
// (bijv. 20 regels per abuis vanuit de verkeerde locatie gescand) -- niet
// als algemene manier om de historie te herschrijven. Daarom hard
// begrensd tot de laatste TRANSACTIELOG_BEWERKBAAR_DAGEN dagen, zowel in
// de lijst zelf als (nogmaals, server-side) bij elke bewerk/verwijder-
// aanroep. Elke wijziging wordt vóór het doorvoeren gelogd in
// transactielog_wijzigingen, als audit-tabel i.p.v. een "verwijderd_op"-
// vlag op transactielog zelf -- zo blijft de oorspronkelijke rij intact
// en is elke wijziging los terug te vinden.
// =====================================================================
const TRANSACTIELOG_BEWERKBAAR_DAGEN = 7;

/**
 * Zelfde ruwe rijen als voorheen getRecenteTransacties(), maar gegroepeerd
 * per scanactie (batch_id, of -- voor oudere rijen zonder batch_id -- de
 * rij zelf) i.p.v. platte, losse regels: een foutieve
 * actie (bijv. 20 gescande barcodes in één Verbruik-indiening, of een
 * Verplaatsing met een uit- én in-boeking) moet in het scherm als één
 * geheel getoond en gecorrigeerd kunnen worden, niet regel voor regel.
 *
 * Elke groep krijgt, naast de samenvattende velden, een 'regels'-array
 * (alle onderliggende transactielog-rijen, alleen-lezen -- voor het
 * "bekijk regels"-uitklapper op het scherm) en een 'type' dat voor een
 * Verplaatsing altijd 'Verplaatsing' is (i.p.v. het per-rij onderscheid
 * 'Verplaatsing in'/'Verplaatsing uit', dat voor de samenvatting niet
 * relevant is -- de rij-specifieke waarde blijft wel gewoon aanwezig in
 * elk element van 'regels').
 */
function getRecenteTransactiesGegroepeerd(): array {
    $stmt = db()->prepare(
        'SELECT id, tijdstempel, batch_id, ean, productnaam, lot, vervaldatum, locatie, transactietype,
                hoeveelheid, specialist, procedure_type, opmerking, bewerkt_op, bewerkt_door, aangemaakt_door
         FROM transactielog
         WHERE tijdstempel >= (NOW() - INTERVAL :dagen DAY)
         ORDER BY tijdstempel DESC, id DESC
         LIMIT 1000'
    );
    $stmt->bindValue(':dagen', TRANSACTIELOG_BEWERKBAAR_DAGEN, PDO::PARAM_INT);
    $stmt->execute();
    $rijen = $stmt->fetchAll();

    $groepen = [];
    $volgorde = [];

    foreach ($rijen as $rij) {
        $key = $rij['batch_id'] !== null ? $rij['batch_id'] : ('row-' . $rij['id']);

        if (!isset($groepen[$key])) {
            $groepen[$key] = [
                'batch_id' => $key,
                'tijdstempel' => $rij['tijdstempel'],
                'type' => $rij['transactietype'],
                'specialist' => $rij['specialist'],
                'procedure_type' => $rij['procedure_type'],
                'opmerking' => $rij['opmerking'],
                'aangemaakt_door' => $rij['aangemaakt_door'],
                'bewerkt_op' => null,
                'bewerkt_door' => null,
                'locaties' => [],
                'van_locatie' => null,
                'naar_locatie' => null,
                'regels' => [],
                'bewerkbaar' => isTransactieBewerkbaar($rij),
            ];
            $volgorde[] = $key;
        }

        if ($rij['bewerkt_op'] !== null
            && ($groepen[$key]['bewerkt_op'] === null || $rij['bewerkt_op'] > $groepen[$key]['bewerkt_op'])) {
            $groepen[$key]['bewerkt_op'] = $rij['bewerkt_op'];
            $groepen[$key]['bewerkt_door'] = $rij['bewerkt_door'];
        }

        // (locatie-hernoemen cascade-bug):
        // actueleNaam() vertaalt de op transactiemoment opgeslagen naam
        // naar de huidige naam, puur voor weergave -- dit is geen
        // aggregatie/GROUP BY zoals in includes/voorraad.php, dus hier is
        // alleen relabelen nodig, geen merge.
        $locatie = actueleNaam('locatie', $rij['locatie']);
        if ($rij['transactietype'] === 'Verplaatsing uit') {
            $groepen[$key]['type'] = 'Verplaatsing';
            $groepen[$key]['van_locatie'] = $locatie;
        } elseif ($rij['transactietype'] === 'Verplaatsing in') {
            $groepen[$key]['type'] = 'Verplaatsing';
            $groepen[$key]['naar_locatie'] = $locatie;
        } elseif (!in_array($locatie, $groepen[$key]['locaties'], true)) {
            $groepen[$key]['locaties'][] = $locatie;
        }

        $groepen[$key]['regels'][] = [
            'id' => $rij['id'], 'ean' => $rij['ean'], 'productnaam' => $rij['productnaam'],
            'lot' => $rij['lot'], 'vervaldatum' => $rij['vervaldatum'], 'hoeveelheid' => $rij['hoeveelheid'],
            'transactietype' => $rij['transactietype'], 'locatie' => $locatie,
        ];
    }

    return array_map(function (string $key) use ($groepen): array {
        $g = $groepen[$key];
        $g['aantal_regels'] = count($g['regels']);
        $g['locatie'] = $g['locaties'][0] ?? null;
        unset($g['locaties']);
        return $g;
    }, $volgorde);
}

/**
 * Transactiehistorie groeperen per scanmoment: zelfde groepeer-aanpak
 * als getRecenteTransactiesGegroepeerd() hierboven (batch_id, of
 * 'row-<id>' voor oudere data zonder batch_id), maar dan geschikt voor de
 * "Transactiehistorie"-tabel op de productdetailpagina -- die is altijd
 * al voor ÉÉN ean, dus geen productnaam/bewerkbaar-vlag nodig (bewerken
 * gebeurt daar toch niet, alleen bekijken). $limit telt RUWE regels (zoals
 * voorheen), dus na groeperen kunnen er minder dan $limit rijen
 * overblijven als er scans met meerdere lots tussen zaten.
 */
function getTransactieHistorieVoorEanGeclusterd(string $ean, int $limit = 20): array {
    $stmt = db()->prepare(
        'SELECT id, tijdstempel, batch_id, lot, vervaldatum, locatie, transactietype,
                hoeveelheid, specialist, procedure_type, opmerking, aangemaakt_door
         FROM transactielog
         WHERE ean = :ean
         ORDER BY tijdstempel DESC, id DESC
         LIMIT ' . max(1, $limit)
    );
    $stmt->execute([':ean' => $ean]);
    $rijen = $stmt->fetchAll();

    $groepen = [];
    $volgorde = [];

    foreach ($rijen as $rij) {
        $key = $rij['batch_id'] !== null ? $rij['batch_id'] : ('row-' . $rij['id']);

        if (!isset($groepen[$key])) {
            $groepen[$key] = [
                'timestamp' => $rij['tijdstempel'],
                'type' => $rij['transactietype'],
                'specialist' => $rij['specialist'],
                'opmerking' => $rij['opmerking'],
                'gescandDoor' => $rij['aangemaakt_door'],
                'locaties' => [],
                'van_locatie' => null,
                'naar_locatie' => null,
                'regels' => [],
            ];
            $volgorde[] = $key;
        }

        // (locatie-hernoemen cascade-bug):
        // zie zelfde toelichting bij getRecenteTransactiesGegroepeerd()
        // hierboven -- pure weergave-relabeling, geen aggregatie hier.
        $locatie = actueleNaam('locatie', $rij['locatie']);
        if ($rij['transactietype'] === 'Verplaatsing uit') {
            $groepen[$key]['type'] = 'Verplaatsing';
            $groepen[$key]['van_locatie'] = $locatie;
        } elseif ($rij['transactietype'] === 'Verplaatsing in') {
            $groepen[$key]['type'] = 'Verplaatsing';
            $groepen[$key]['naar_locatie'] = $locatie;
        } elseif (!in_array($locatie, $groepen[$key]['locaties'], true)) {
            $groepen[$key]['locaties'][] = $locatie;
        }

        $groepen[$key]['regels'][] = [
            'lot' => $rij['lot'], 'vervaldatum' => $rij['vervaldatum'], 'hoeveelheid' => (int) $rij['hoeveelheid'],
            'transactietype' => $rij['transactietype'], 'locatie' => $locatie,
        ];
    }

    return array_map(function (string $key) use ($groepen): array {
        $g = $groepen[$key];
        $g['aantalRegels'] = count($g['regels']);
        $g['hoeveelheid'] = $g['aantalRegels'] === 1 ? $g['regels'][0]['hoeveelheid'] : null;
        $g['locatie'] = $g['type'] === 'Verplaatsing'
            ? (($g['van_locatie'] ?? '-') . ' → ' . ($g['naar_locatie'] ?? '-'))
            : ($g['locaties'][0] ?? null);
        unset($g['locaties'], $g['van_locatie'], $g['naar_locatie']);
        return $g;
    }, $volgorde);
}

function isTransactieBewerkbaar(array $regel): bool {
    return strtotime((string) $regel['tijdstempel']) >= strtotime('-' . TRANSACTIELOG_BEWERKBAAR_DAGEN . ' days');
}

function logTransactieWijziging(int $transactielogId, string $actie, string $gebruikerNaam, array $oud, ?array $nieuw): void {
    db()->prepare(
        'INSERT INTO transactielog_wijzigingen (transactielog_id, actie, gebruiker_naam, oude_waarden, nieuwe_waarden)
         VALUES (:id, :actie, :naam, :oud, :nieuw)'
    )->execute([
        ':id' => $transactielogId,
        ':actie' => $actie,
        ':naam' => $gebruikerNaam,
        ':oud' => json_encode($oud, JSON_UNESCAPED_UNICODE),
        ':nieuw' => $nieuw !== null ? json_encode($nieuw, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function vindTransactieRegel(int $id): array {
    $stmt = db()->prepare('SELECT * FROM transactielog WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $regel = $stmt->fetch();
    if (!$regel) throw new RuntimeException('Transactie niet gevonden');
    return $regel;
}

/**
 * $updates: ['hoeveelheid'=>?, 'locatie'=>?, 'specialist'=>?,
 * 'procedure_type'=>?, 'opmerking'=>?] -- ontbrekende sleutels laten het
 * bestaande veld ongewijzigd. ean/lot/vervaldatum/transactietype zijn
 * bewust NIET bewerkbaar: bij een verkeerd gescand product/type is
 * verwijderen + opnieuw scannen betrouwbaarder dan "omzetten".
 */
function bewerkTransactieRegel(int $id, array $updates, string $gebruikerNaam): array {
    $regel = vindTransactieRegel($id);
    if (!isTransactieBewerkbaar($regel)) {
        throw new RuntimeException('Deze transactie is ouder dan ' . TRANSACTIELOG_BEWERKBAAR_DAGEN . ' dagen en kan niet meer bewerkt worden');
    }

    $hoeveelheid = isset($updates['hoeveelheid']) && $updates['hoeveelheid'] !== ''
        ? max(1, (int) $updates['hoeveelheid']) : (int) $regel['hoeveelheid'];
    $locatie = isset($updates['locatie']) && $updates['locatie'] !== '' ? (string) $updates['locatie'] : $regel['locatie'];
    $specialist = array_key_exists('specialist', $updates) ? ($updates['specialist'] !== '' ? $updates['specialist'] : null) : $regel['specialist'];
    $procedureType = array_key_exists('procedure_type', $updates) ? ($updates['procedure_type'] !== '' ? $updates['procedure_type'] : null) : $regel['procedure_type'];
    $opmerking = array_key_exists('opmerking', $updates) ? ($updates['opmerking'] !== '' ? $updates['opmerking'] : null) : $regel['opmerking'];

    db()->prepare(
        'UPDATE transactielog
         SET hoeveelheid = :hoeveelheid, locatie = :locatie, specialist = :specialist,
             procedure_type = :procedure, opmerking = :opmerking, bewerkt_op = NOW(), bewerkt_door = :bewerker
         WHERE id = :id'
    )->execute([
        ':hoeveelheid' => $hoeveelheid, ':locatie' => $locatie, ':specialist' => $specialist,
        ':procedure' => $procedureType, ':opmerking' => $opmerking, ':bewerker' => $gebruikerNaam, ':id' => $id,
    ]);

    $nieuw = array_merge($regel, [
        'hoeveelheid' => $hoeveelheid, 'locatie' => $locatie, 'specialist' => $specialist,
        'procedure_type' => $procedureType, 'opmerking' => $opmerking,
    ]);
    logTransactieWijziging($id, 'bewerkt', $gebruikerNaam, $regel, $nieuw);

    return ['success' => true];
}

/**
 * ("Recent Transactions... dit moet wel
 * de juiste effecten uitvoeren in bijvoorbeeld de voorraden. Gaat dit wel
 * goed?" -> "ja graag, alles moet wel kloppen natuurlijk"): draait de
 * koppeling van koppelScanAanEenmaligeBestellingItems() terug zodra de
 * transactielog-regel die de koppeling maakte, hier verwijderd wordt.
 *
 * De voorraad zelf herstelt altijd vanzelf correct (die wordt live
 * herberekend uit transactielog, zie includes/voorraad.php) -- dit gaat
 * specifiek over het item op de eenmalige bestelling, dat anders ten
 * onrechte op 'verbruikt'/'retour' zou blijven staan terwijl de
 * onderliggende transactie niet meer bestaat. Zet het item terug naar
 * 'ontvangen' (weer openstaand), en zet de bestelling zelf terug naar
 * 'Ontvangen' als die ondertussen automatisch was afgerond
 * (herbereekenEenmaligeBestellingStatusNaScan() hierboven) op basis van
 * precies dit item.
 *
 * Ean/lot/transactietype van de transactie zijn bewust niet bewerkbaar
 * (zie docblock bewerkTransactieRegel() hierboven), dus alleen VERWIJDEREN
 * kan deze koppeling ooit ongeldig maken -- bewerken (hoeveelheid/locatie/
 * specialist/opmerking) raakt de koppeling nooit, daar hoeft dus niets
 * voor te gebeuren.
 */
function ontkoppelEenmaligeBestellingItem(int $transactielogId, string $gebruikerNaam): void {
    $stmt = db()->prepare(
        'SELECT id, eenmalige_bestelling_id FROM eenmalige_bestelling_items WHERE transactielog_id = :id'
    );
    $stmt->execute([':id' => $transactielogId]);
    $item = $stmt->fetch();
    if (!$item) return;

    db()->prepare(
        "UPDATE eenmalige_bestelling_items
         SET status = 'ontvangen', transactielog_id = NULL, afgehandeld_op = NULL, afgehandeld_door = NULL
         WHERE id = :id"
    )->execute([':id' => (int) $item['id']]);

    // Alleen terugzetten als de bestelling nog op de automatisch gezette
    // eindstatus staat -- een intussen handmatig aangepaste bestelling
    // (volledige edit, zie includes/eenmalige_bestellingen.php) wordt hier
    // niet overschreven, dat zou een andere, bewuste keuze van de
    // gebruiker teniet kunnen doen.
    db()->prepare(
        "UPDATE eenmalige_bestellingen SET status = 'Ontvangen'
         WHERE id = :id AND status IN ('Gebruikt', 'Retourneren')"
    )->execute([':id' => (int) $item['eenmalige_bestelling_id']]);
}

function verwijderTransactieRegel(int $id, string $gebruikerNaam): array {
    $regel = vindTransactieRegel($id);
    if (!isTransactieBewerkbaar($regel)) {
        throw new RuntimeException('Deze transactie is ouder dan ' . TRANSACTIELOG_BEWERKBAAR_DAGEN . ' dagen en kan niet meer verwijderd worden');
    }

    logTransactieWijziging($id, 'verwijderd', $gebruikerNaam, $regel, null);
    ontkoppelEenmaligeBestellingItem($id, $gebruikerNaam);
    db()->prepare('DELETE FROM transactielog WHERE id = :id')->execute([':id' => $id]);

    return ['success' => true];
}

/**
 * Alle rijen die tot dezelfde scanactie behoren, oplopend op id (dus in
 * de volgorde waarin ze oorspronkelijk zijn ingevoegd -- voor een
 * Verplaatsing dus per gescand artikel steeds eerst de uit-, dan de
 * In-regel). $batchKey is ofwel een echte batch_id, ofwel
 * 'row-<id>' voor een rij van vóór die migratie (zie
 * getRecenteTransactiesGegroepeerd()) -- in dat laatste geval bestaat de
 * "batch" uit precies die ene rij.
 */
function vindTransactieRegelsVoorBatch(string $batchKey): array {
    if (str_starts_with($batchKey, 'row-')) {
        $stmt = db()->prepare('SELECT * FROM transactielog WHERE id = :id');
        $stmt->execute([':id' => (int) substr($batchKey, 4)]);
    } else {
        $stmt = db()->prepare('SELECT * FROM transactielog WHERE batch_id = :batch_id ORDER BY id ASC');
        $stmt->execute([':batch_id' => $batchKey]);
    }
    return $stmt->fetchAll();
}

/**
 * Bewerkt in één keer alle regels van één scanactie: bijv. een hele
 * Verbruik-indiening met 14 gescande barcodes had
 * de verkeerde locatie, en die moet in één actie recht te zetten zijn,
 * niet 14 keer los.
 *
 * $updates heeft dezelfde vorm als bewerkTransactieRegel(), met twee
 * uitzonderingen specifiek voor een Verplaatsing-batch (die altijd twee
 * kanten heeft, elk met een eigen transactietype): 'van_locatie' wordt
 * toegepast op elke 'Verplaatsing uit'-regel, 'naar_locatie' op elke
 * 'Verplaatsing in'-regel. Voor elk ander batchtype geldt gewoon
 * 'locatie', identiek voor alle regels.
 *
 * Eén DB-transactie: bewerkTransactieRegel() controleert en logt elke
 * regel individueel (zodat transactielog_wijzigingen zijn bestaande,
 * rijke per-regel audit trail behoudt), maar de hele batch wordt hier
 * als één geheel toegepast -- of alle regels worden bijgewerkt, of geen
 * enkele (bijv. als een regel inmiddels buiten het bewerkbare venster
 * valt).
 */
function bewerkTransactieBatch(string $batchKey, array $updates, string $gebruikerNaam): array {
    $regels = vindTransactieRegelsVoorBatch($batchKey);
    if (!$regels) throw new RuntimeException('Transactie niet gevonden');

    foreach ($regels as $regel) {
        if (!isTransactieBewerkbaar($regel)) {
            throw new RuntimeException('Deze transactie is ouder dan ' . TRANSACTIELOG_BEWERKBAAR_DAGEN . ' dagen en kan niet meer bewerkt worden');
        }
    }

    db()->beginTransaction();
    try {
        foreach ($regels as $regel) {
            $regelUpdates = $updates;
            if ($regel['transactietype'] === 'Verplaatsing uit' && array_key_exists('van_locatie', $updates)) {
                $regelUpdates['locatie'] = $updates['van_locatie'];
            } elseif ($regel['transactietype'] === 'Verplaatsing in' && array_key_exists('naar_locatie', $updates)) {
                $regelUpdates['locatie'] = $updates['naar_locatie'];
            }
            unset($regelUpdates['van_locatie'], $regelUpdates['naar_locatie']);
            bewerkTransactieRegel((int) $regel['id'], $regelUpdates, $gebruikerNaam);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    return ['success' => true, 'aantal' => count($regels)];
}

/**
 * Verwijdert in één keer alle regels van één scanactie -- de "hele actie
 * ongedaan maken" die nodig is voor bijv. een foutieve Verplaatsing
 * (uit- én in-boeking samen terugdraaien) of Verbruik-scan (alle
 * gescande producten in die ene indiening samen). Zelfde
 * alles-of-niets-transactie als bewerkTransactieBatch().
 */
function verwijderTransactieBatch(string $batchKey, string $gebruikerNaam): array {
    $regels = vindTransactieRegelsVoorBatch($batchKey);
    if (!$regels) throw new RuntimeException('Transactie niet gevonden');

    foreach ($regels as $regel) {
        if (!isTransactieBewerkbaar($regel)) {
            throw new RuntimeException('Deze transactie is ouder dan ' . TRANSACTIELOG_BEWERKBAAR_DAGEN . ' dagen en kan niet meer verwijderd worden');
        }
    }

    db()->beginTransaction();
    try {
        foreach ($regels as $regel) {
            verwijderTransactieRegel((int) $regel['id'], $gebruikerNaam);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    return ['success' => true, 'aantal' => count($regels)];
}

/**
 * Verplaatsing tussen twee locaties: twee transactieregels per gescand
 * artikel (uit + in). Poort van handleVerplaatsing().
 */
function verplaatsing(string $gs1RawBlock, string $vanLocatie, string $naarLocatie, string $reden, string $opmerking, ?string $gebruikerNaam = null, array $omschrijvingen = []): array {
    $tijdstempel = date('Y-m-d H:i:s');
    $batchId = nieuweBatchId();
    $lines = preg_split('/\r?\n/', trim($gs1RawBlock, "\" \t\n\r\0\x0B"));
    $split = splitsBekendOnbekend($lines);

    $ontbrekend = ontbrekendeOmschrijvingen($split['onbekend'], $omschrijvingen);
    if (!empty($ontbrekend)) {
        return ['success' => false, 'onbekend' => true, 'onbekendeRegels' => $ontbrekend, 'verwerkt' => 0];
    }

    // Zie toelichting in verwerkScan() hierboven.
    $aangemaakt = maakArtikelenVoorOnbekendeRegels($split['onbekend'], $omschrijvingen, $gebruikerNaam);
    $split['bekend'] = array_merge($split['bekend'], $aangemaakt['nieuwBekend']);

    $verwerkt = 0;
    foreach ($split['bekend'] as $item) {
        $parsed = $item['parsed'];
        $artikel = $item['artikel'];
        $line = $item['line'];

        $vervaldatumSql = gs1ExpiryToSqlDate($parsed['expiry']);
        $opmerkingUit = "Verplaatst naar $naarLocatie - $reden. $opmerking";
        $opmerkingIn = "Verplaatst van $vanLocatie - $reden. $opmerking";

        // $artikel['hoeveelheid']: dekt zowel een hele,
        // ongeopende doos die in één keer verplaatst wordt (scan de
        // doos-EAN, hoeveelheid = eenheden_per_doos) als losse, al
        // uitgepakte stuks die één voor één gescand worden (elke regel
        // hoeveelheid 1) -- zie lookupArtikelen().
        insertTransactieRegel($tijdstempel, $artikel['ean'], $parsed['lot'], $vervaldatumSql, $vanLocatie, 'Verplaatsing uit', $artikel['hoeveelheid'], $artikel['prijs'], null, null, $opmerkingUit, $line, $artikel['naam'], $gebruikerNaam, $batchId);
        insertTransactieRegel($tijdstempel, $artikel['ean'], $parsed['lot'], $vervaldatumSql, $naarLocatie, 'Verplaatsing in', $artikel['hoeveelheid'], $artikel['prijs'], null, null, $opmerkingIn, $line, $artikel['naam'], $gebruikerNaam, $batchId);
        // Zie voorkomNegatieveVoorraad() -- alleen de 'uit'-
        // kant (vanLocatie) kan door deze boeking negatief worden, de
        // 'in'-kant (naarLocatie) krijgt er juist voorraad bij.
        voorkomNegatieveVoorraad($artikel['ean'], $vanLocatie, $artikel['naam'], $artikel['prijs'], $gebruikerNaam);

        $verwerkt++;
    }

    loggeOnbekendeScans($split['onbekend'], $omschrijvingen, $vanLocatie, 'Verplaatsing', $gebruikerNaam);

    return ['success' => true, 'verwerkt' => $verwerkt, 'fouten' => array_column($aangemaakt['nogOnbekend'], 'line')];
}

/**
 * Retournering: één transactieregel per gescand artikel. Poort van
 * handleRetournering().
 *
 * "slimme ruimte"-omleiding, zelfde
 * Gedachte als bij Verbruik Scannen maar dan specifiek
 * gericht op de ALGEMENE voorraad (Berging) in plaats van "precies één
 * andere ruimte": staat het gescande product niet in de gekozen
 * 'van'-locatie, maar wel in Berging, dan wordt de retour daar
 * automatisch vandaan geboekt. Dat is het meest voorkomende geval --
 * retourmateriaal van bijv. een eenmalige bestelling ligt vaak al in
 * Berging (zie de echte Ontvangst-boeking daarvandaan),
 * niet in de kliniekruimte die hier toevallig geselecteerd is.
 */
function retournering(string $gs1RawBlock, string $vanLocatie, string $redenRetour, string $opmerking, ?string $gebruikerNaam = null, array $omschrijvingen = []): array {
    $tijdstempel = date('Y-m-d H:i:s');
    $batchId = nieuweBatchId();
    $lines = preg_split('/\r?\n/', trim($gs1RawBlock, "\" \t\n\r\0\x0B"));
    $split = splitsBekendOnbekend($lines);

    $ontbrekend = ontbrekendeOmschrijvingen($split['onbekend'], $omschrijvingen);
    if (!empty($ontbrekend)) {
        return ['success' => false, 'onbekend' => true, 'onbekendeRegels' => $ontbrekend, 'verwerkt' => 0];
    }

    // Zie toelichting in verwerkScan() hierboven.
    $aangemaakt = maakArtikelenVoorOnbekendeRegels($split['onbekend'], $omschrijvingen, $gebruikerNaam);
    $split['bekend'] = array_merge($split['bekend'], $aangemaakt['nieuwBekend']);

    $bergingNaam = locatieNaamVoorSlot('berging');
    $verwerkt = 0;
    $omgeleid = [];
    $verwerkteItems = []; // $item + transactielog_id per regel
    foreach ($split['bekend'] as $item) {
        $parsed = $item['parsed'];
        $artikel = $item['artikel'];
        $line = $item['line'];

        $effectieveLocatie = $vanLocatie;
        if ($bergingNaam !== null && $bergingNaam !== $vanLocatie) {
            $perLocatie = huidigeVoorraadPerLocatie($artikel['ean']);
            $huidigHier = $perLocatie[$vanLocatie] ?? 0;
            if ($huidigHier <= 0 && ($perLocatie[$bergingNaam] ?? 0) > 0) {
                $effectieveLocatie = $bergingNaam;
                $omgeleid[] = ['ean' => $artikel['ean'], 'naam' => $artikel['naam'], 'van' => $vanLocatie, 'naar' => $effectieveLocatie];
            }
        }

        $regelOpmerking = "Retour - $redenRetour. $opmerking";
        if ($effectieveLocatie !== $vanLocatie) {
            $regelOpmerking = trim($regelOpmerking . " (automatisch geretourneerd vanuit $effectieveLocatie i.p.v. $vanLocatie -- daar geen voorraad van dit product)");
        }

        $transactielogId = insertTransactieRegel(
            $tijdstempel, $artikel['ean'], $parsed['lot'], gs1ExpiryToSqlDate($parsed['expiry']), $effectieveLocatie,
            'Retournering', $artikel['hoeveelheid'], $artikel['prijs'], null, null, $regelOpmerking, $line, $artikel['naam'], $gebruikerNaam, $batchId
        );
        $verwerkteItems[] = $item + ['transactielog_id' => $transactielogId];
        // Zie voorkomNegatieveVoorraad() hierboven.
        voorkomNegatieveVoorraad($artikel['ean'], $effectieveLocatie, $artikel['naam'], $artikel['prijs'], $gebruikerNaam);
        $verwerkt++;
    }

    loggeOnbekendeScans($split['onbekend'], $omschrijvingen, $vanLocatie, 'Retournering', $gebruikerNaam);

    // Dit scherm ("Verplaatsing +
    // Retouren") is precies waarmee eerder ontvangen eenmalige-
    // bestelling-items alsnog als geretourneerd herkend worden -- zie
    // koppelScanAanEenmaligeBestellingItems() hierboven. $verwerkteItems
    // (i.p.v. $split['bekend']) draagt per regel het
    // bijbehorende transactielog_id mee.
    koppelScanAanEenmaligeBestellingItems($verwerkteItems, 'retour', $gebruikerNaam);

    return ['success' => true, 'verwerkt' => $verwerkt, 'fouten' => array_column($aangemaakt['nogOnbekend'], 'line'), 'omgeleid' => $omgeleid];
}
