<?php
/**
 * GS1-barcodeparser. Oorspronkelijk een 1-op-1 poort van parseGS1Raw() in
 * het oude Code.gs (vaste volgorde 01=GTIN, 11=productiedatum wordt
 * overgeslagen, 17=vervaldatum, 10=lot, 21=serienummer als fallback voor
 * lot, 240=interne artikelref).
 *
 * Herbouwd (na een gemeld praktijkprobleem): er zijn
 * verschillende leveranciers/verpakkingsniveaus in omloop die dezelfde
 * gegevens in een andere volgorde aanbieden, of als twee losse fysieke
 * barcodes i.p.v. één combinatiecode, of als QR-code volgens de GS1
 * Digital Link-standaard (een URL) i.p.v. een kale AI-tekenreeks. Een
 * laborant moet dit nooit zelf hoeven te onderscheiden -- dus leest deze
 * parser nu:
 *   1. Elke combinatie van AI-groepen, in WILLEKEURIGE volgorde in de
 *      tekenreeks (i.p.v. de vaste 01->17->10-volgorde van vroeger), zodat
 *      het niet uitmaakt welke van twee losse barcodes het eerst gescand
 *      is.
 *   2. GS1 Digital Link-URL's (bv. https://id.gs1.org/01/.../17/.../10/...)
 *      worden eerst herkend en omgezet naar dezelfde kale AI-vorm, zodat
 *      daarna dezelfde tokenizer hieronder ze verwerkt -- een QR-code met
 *      dezelfde data levert dus exact hetzelfde resultaat op als een
 *      GS1-128/DataMatrix-barcode.
 *
 * Bekende beperking, en de fix daarvoor (n.a.v. een concrete meldingen-reeks
 * met echt gescande lotnummers die stelselmatig fout uitkwamen):
 * een barcodescanner stuurt in de praktijk meestal geen FNC1/GS-
 * scheidingsteken door tussen twee variabele-lengte velden, dus moet het
 * einde van een variabele waarde (lot/serienummer/interne ref) bij
 * benadering bepaald worden.
 *
 * De eerdere aanpak zocht naar ELKE herkenbare AI-prefix (01/11/17/10/21/240)
 * verderop in de waarde en kapte daar af. Dat bleek in de praktijk
 * doorslaggevend fout: een 2-cijferige AI-code komt toevallig heel vaak voor
 * als toevallige deelreeks in een numeriek lot-/serienummer (bv. een lot dat
 * ergens de tekens "21" of "10" bevat), en elke keer dat dat gebeurde werd
 * het lotnummer daar afgekapt tot een fragment van 1-2 tekens, gevolgd door
 * een compleet verzonnen "serienummer" uit de rest. Getest tegen 19 echte
 * scans (Astato/Terumo/Pier-katheter plus 16 losse voorbeelden) brak dit in
 * meer dan de helft van de gevallen.
 *
 * Nu wordt binnen een variabele waarde NIET meer actief gezocht naar de
 * 2-cijferige AI's (01/11/17/10/21) -- die worden simpelweg als onderdeel
 * van de waarde zelf beschouwd, tenzij de waarde het einde van de hele
 * string bereikt. Alleen de letterlijke 3-cijferige marker "240" (interne
 * fabrikantsreferentie) wordt nog actief gezocht als afkappunt, omdat dat
 * zeldzamer is (3 vaste cijfers i.p.v. 2) en in de praktijk wél een echt
 * tweede veld aankondigt (vaak alfanumeriek, bv. "...240BXA105902E").
 *
 * Update (n.a.v. een lijst van 170 echte GS1-scans die tegen deze
 * parser getest zijn): de hierboven genoemde
 * restbeperking (een lot dat toevallig "240" bevat) kwam in die lijst 1x
 * voor en is nu gemitigeerd -- zie het commentaar bij gs1LeesVariabeleWaarde()
 * hieronder. Dezelfde testronde bracht ook aan het licht dat AI(17) soms
 * legitiem DD=00 ("geen specifieke dag", per GS1 General Specifications)
 * bevat; dat wordt nu naar de laatste dag van de maand omgezet i.p.v. als
 * ongeldige datum "00-05-2028" opgeslagen te worden -- zie het commentaar
 * bij de AI(17)-afhandeling in parseGS1Raw(). Een volledig sluitende
 * oplossing voor het "240 zit toevallig in het lotnummer"-scenario bestaat
 * nog steeds alleen als de scanner zelf het GS1 FNC1/GS-scheidingsteken
 * doorstuurt (zie het advies over "USB Keyboard FN1 Substitution" op de
 * scanner) -- de mitigatie hieronder dekt alleen het geval waarin het
 * lotnummer zelf puur numeriek is, wat in de praktijk tot nu toe altijd zo
 * was bij deze foutklasse.
 */

declare(strict_types=1);

/** Vaste lengte per AI, of null voor variabele lengte (lot/serienummer/interne ref). */
const GS1_AI_LENGTHS = [
    '01' => 14,   // GTIN
    '11' => 6,    // Productiedatum (wordt gelezen en overgeslagen, niet teruggegeven)
    '17' => 6,    // Vervaldatum (YYMMDD)
    '10' => null, // Lot/batchnummer
    '21' => null, // Serienummer (fallback voor lot)
    '240' => null, // Interne artikelreferentie
];

/**
 * Herkent een GS1 Digital Link-URL (bv. https://id.gs1.org/01/xxxx/17/yyyy/
 * 10/zzzz, eventueel met een leverancier-eigen domein) en zet 'm om naar
 * dezelfde kale AI-tekenreeks-vorm die de rest van deze parser al begrijpt
 * (dus "01" . gtin . "17" . datum . "10" . lot, in de volgorde die de URL
 * zelf aanhoudt -- de tokenizer hieronder is toch al volgorde-onafhankelijk).
 * Geeft de ORIGINELE string terug als dit geen GS1 Digital Link-achtige URL
 * blijkt te zijn (dan probeert de aanroeper het gewoon als kale AI-string).
 *
 * Ondersteunt alleen numerieke AI's in het pad (/01/.../17/...), niet de
 * GS1 Digital Link "korte naam"-aliassen (zoals /gtin/ i.p.v. /01/) -- dat
 * is een uitbreiding voor als dat in de praktijk nodig blijkt.
 */
function gs1OntvouwDigitalLink(string $raw): string {
    if (!str_starts_with($raw, 'http://') && !str_starts_with($raw, 'https://')) {
        return $raw;
    }

    $path = parse_url($raw, PHP_URL_PATH);
    if (!$path) {
        return $raw;
    }

    $segmenten = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
    // Moet een even aantal segmenten zijn (AI, waarde, AI, waarde, ...) en
    // elk AI-segment moet een bekende AI zijn -- anders is dit geen
    // (ondersteunde) GS1 Digital Link en laten we de string ongemoeid.
    if (count($segmenten) < 2 || count($segmenten) % 2 !== 0) {
        return $raw;
    }

    $opnieuwOpgebouwd = '';
    for ($i = 0; $i < count($segmenten); $i += 2) {
        $ai = $segmenten[$i];
        $waarde = $segmenten[$i + 1];
        if (!array_key_exists($ai, GS1_AI_LENGTHS)) {
            return $raw; // Onbekend AI-segment in het pad -- geen GS1 Digital Link zoals wij die verwachten.
        }
        $opnieuwOpgebouwd .= $ai . rawurldecode($waarde);
    }

    return $opnieuwOpgebouwd;
}

/**
 * Leest een variabele-lengte AI-waarde (10/21/240) vanaf het begin van
 * $remaining. Neemt de volledige rest van de string, TENZIJ verderop een
 * letterlijke "240"-marker gevonden wordt (dan stopt de waarde daar) -- zie
 * de uitgebreide toelichting in de bestandsdocblock voor waarom er bewust
 * niet meer gezocht wordt naar de 2-cijferige AI's (01/11/17/10/21) binnen
 * een waarde.
 *
 * @return array{0:string,1:int} [waarde, aantal_gelezen_tekens]
 */
function gs1LeesVariabeleWaarde(string $remaining): array {
    $maxLen = min(40, strlen($remaining)); // ruime veiligheidsgrens tegen extreem lange/vervuilde invoer
    $zoekGrens = min(30, strlen($remaining));
    for ($i = 1; $i <= $zoekGrens; $i++) {
        if (substr($remaining, $i, 3) === '240') {
            // (n.a.v. een lijst van 170
            // echte GS1-scans waarin dit 1x misging): "240" komt soms
            // toevallig voor als losse deelreeks in een puur numeriek
            // lot-/serienummer (bv. lot "32405978" -> werd afgekapt tot
            // lot "3" + een compleet verzonnen interne referentie "5978").
            // In alle echte AI(240)-referenties die we tot nu toe gezien
            // hebben (bv. "BXA085902E") staat na de 240-marker altijd
            // minstens één letter. Een zuiver numerieke vervolgtekst
            // behandelen we daarom NIET meer als een 240-marker -- de
            // zoektocht loopt dan gewoon door naar een eventuele latere,
            // wel-alfanumerieke 240-marker, of neemt anders de volledige
            // waarde (oude fallback-gedrag).
            $mogelijkeRef = substr($remaining, $i + 3, 10);
            if ($mogelijkeRef !== '' && !ctype_digit($mogelijkeRef)) {
                return [substr($remaining, 0, $i), $i];
            }
        }
    }
    return [substr($remaining, 0, $maxLen), $maxLen];
}

/**
 * @return array{ean:string, lot:?string, expiry:?string, interneRef:string}
 *         expiry is een 'd-m-Y'-string (zelfde notatie als de oude
 *         Sheets-kolom), zodat de aanroeper zelf naar DATE kan casten.
 */
function parseGS1Raw(?string $rawString): array {
    if (!$rawString) {
        return ['ean' => '', 'lot' => null, 'expiry' => null, 'interneRef' => ''];
    }
    $rawString = trim($rawString);
    $rawString = gs1OntvouwDigitalLink($rawString);

    $ean = '';
    $expiry = null;
    $lot = null;
    $serienummer = null;
    $interneRef = '';
    $pos = 0;
    $len = strlen($rawString);

    // Volgorde-onafhankelijk : loopt door de hele
    // string heen en herkent elke AI-groep waar hij ook staat, i.p.v. een
    // vaste 01->11->17->10->21->240-volgorde te veronderstellen zoals de
    // oude parser deed. Dat maakt het niet meer uit of bijvoorbeeld de
    // batch-/vervaldatumcode vóór of ná de productcode gescand is.
    while ($pos < $len) {
        $ai3 = substr($rawString, $pos, 3);
        if ($ai3 === '240' && $interneRef === '') {
            $pos += 3;
            [$waarde, $gelezen] = gs1LeesVariabeleWaarde(substr($rawString, $pos));
            $interneRef = trim($waarde);
            $pos += $gelezen;
            continue;
        }

        $ai2 = substr($rawString, $pos, 2);
        if ($ai2 === '01' && $ean === '') {
            $pos += 2;
            $ean = substr($rawString, $pos, 14);
            $pos += 14;
            continue;
        }
        if ($ai2 === '11') {
            // Productiedatum -- bewust overgeslagen, niet teruggegeven.
            $pos += 2 + 6;
            continue;
        }
        if ($ai2 === '17' && $expiry === null) {
            $pos += 2;
            $dateStr = substr($rawString, $pos, 6);
            if (strlen($dateStr) === 6 && ctype_digit($dateStr)) {
                $year = 2000 + (int) substr($dateStr, 0, 2);
                $month = (int) substr($dateStr, 2, 2);
                $day = (int) substr($dateStr, 4, 2);
                // (n.a.v. dezelfde lijst
                // van 170 echte GS1-scans): de GS1 General Specifications
                // staan DD=00 in AI(17) toe als "geen specifieke dag" --
                // dat kwam in de praktijk ook echt voor. De spec-conforme
                // interpretatie is de laatste dag van die maand. Zonder
                // deze correctie werd '00-05-2028' als vervaldatum
                // opgeslagen: geen geldige kalenderdatum, en downstream
                // (daysLeftUntil() e.a.) verschoof PHP dat stilzwijgend
                // naar de laatste dag van de VORIGE maand -- fout resultaat
                // zonder dat ergens een fout zichtbaar werd.
                if ($month >= 1 && $month <= 12 && $day === 0) {
                    $day = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
                }
                // Extra vangnet: een overduidelijk ongeldige datum (bv.
                // maand 13, of dag 31 in een maand met 30 dagen) leveren we
                // niet meer op als "expiry" -- beter geen vervaldatum dan
                // een stilzwijgend verkeerde.
                if ($month >= 1 && $month <= 12 && checkdate($month, $day, $year)) {
                    $expiry = sprintf('%02d-%02d-%04d', $day, $month, $year);
                }
                $pos += 6;
            }
            continue;
        }
        if ($ai2 === '10' && $lot === null) {
            $pos += 2;
            [$waarde, $gelezen] = gs1LeesVariabeleWaarde(substr($rawString, $pos));
            $lot = trim($waarde);
            $pos += $gelezen;
            continue;
        }
        if ($ai2 === '21' && $serienummer === null) {
            $pos += 2;
            [$waarde, $gelezen] = gs1LeesVariabeleWaarde(substr($rawString, $pos));
            $serienummer = trim($waarde);
            $pos += $gelezen;
            continue;
        }

        // Geen herkenbare AI op deze positie -- één teken opschuiven i.p.v.
        // vast te lopen, zodat losse ruis (bv. een scheidingsteken dat de
        // scanner toch doorstuurt) de rest van de string niet blokkeert.
        $pos++;
    }

    // AI 10 heeft altijd voorrang op AI 21 als lotnummer, ongeacht in welke
    // volgorde ze in de string stonden (zelfde regel als de oude parser).
    if ($lot === null) {
        $lot = $serienummer;
    }

    return [
        'ean' => $ean ?: '',
        'lot' => $lot ?: null,
        'expiry' => $expiry ?: null,
        'interneRef' => $interneRef ?: '',
    ];
}

/**
 * Zet de 'd-m-Y'-expiry uit parseGS1Raw() om naar 'Y-m-d' voor MariaDB
 * DATE-kolommen. Losse functie i.p.v. inline overal herhalen.
 */
function gs1ExpiryToSqlDate(?string $expiry): ?string {
    if (!$expiry) return null;
    $parts = explode('-', $expiry);
    if (count($parts) !== 3) return null;
    [$d, $m, $y] = $parts;
    return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d);
}
