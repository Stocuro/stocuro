<?php
/**
 * EAN-normalisatie. 1-op-1 poort van normalizeEAN() in het oude Code.gs.
 * Excel/Sheets sloeg numerieke barcodes soms als float op (trailing
 * '.0') -- die laag zit hier nog steeds, ook al levert MariaDB nu al
 * schone waarden.
 */

declare(strict_types=1);

/**
 * Na een gemelde bug: een handmatig EAN-/doos-EAN-
 * veld (naEan/naDoosEan op Artikel Toevoegen, en de gelijknamige velden op
 * het bewerkscherm) is bedoeld voor een KALE, 14-cijferige EAN -- maar een
 * barcodescanner onderscheidt geen velden. Komt er per ongeluk een
 * volledige GS1-barcode met AI-voorvoegsel (bv. "01" + 14 cijfers, of nog
 * meer erachter zoals batch/vervaldatum) in zo'n kaal-EAN-veld terecht,
 * dan gaf str_pad() dat eerder ONGEWIJZIGD (langer dan 14 tekens) door --
 * met een cryptische "Data too long for column"-databasefout als gevolg
 * zodra dat in een CHAR(14)-kolom (artikelen.ean/doos_ean) terechtkwam.
 *
 * Deze functie herkent en verwijdert dat AI-voorvoegsel nu zelf eerst
 * (dezelfde regel als AI 01 in parseGS1Raw(), includes/gs1_parser.php),
 * zodat een kale EAN en een volledige GS1-scan in hetzelfde veld allebei
 * tot dezelfde, geldige 14-cijferige EAN normaliseren. Blijft de waarde
 * ook ná die correctie te lang (dus geen EAN en ook geen herkenbare GS1-
 * GTIN -- bijvoorbeeld compleet andere tekst per ongeluk in het veld
 * geplakt), dan wordt dat expliciet geweigerd met een duidelijke
 * foutmelding, in plaats van de database straks te laten crashen op een
 * afkapping.
 */
function normalizeEAN(string|int|float|null $ean): string {
    $cleaned = trim((string) $ean);
    $cleaned = preg_replace('/\.0$/', '', $cleaned);
    $cleaned = preg_replace('/\s/', '', $cleaned);

    if (strlen($cleaned) > 14 && substr($cleaned, 0, 2) === '01' && ctype_digit(substr($cleaned, 2, 14))) {
        $cleaned = substr($cleaned, 2, 14);
    }

    $cleaned = str_pad($cleaned, 14, '0', STR_PAD_LEFT);

    if (strlen($cleaned) > 14) {
        throw new InvalidArgumentException("Geen geldige EAN: '$ean' is te lang, ook na opschonen -- controleer de scan/invoer");
    }

    return $cleaned;
}
