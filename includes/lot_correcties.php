<?php
/**
 * Migratie 035, op verzoek van Harm: "op de detailpagina van een
 * specifiek product is nu een overzicht van Loten te zien die zijn
 * ingescand. Soms wordt een barcode gescand die kennelijk niet helemaal
 * goed herkend wordt met EAN, Vervaldatum en Lotnummer. Ik wil per
 * lotnummer het specifieke lotnummer kunnen bewerken (...) Wordt er in de
 * toekomst hetzelfde item nog eens gescand dan pakt hij automatisch de
 * aangepaste gegevens."
 *
 * Twee helften:
 *  - corrigeerLot(): de eenmalige, door een gebruiker getriggerde actie
 *    vanaf de productdetailpagina ("Loten op Voorraad" -> Bewerken). Zet
 *    lot/vervaldatum voor een heel lot in één keer recht (alle
 *    onderliggende transactielog-regels, ongeacht locatie of ouderdom)
 *    EN onthoudt de correctie voor elke onderliggende ruwe GS1-barcode
 *    die dit lot ooit heeft opgeleverd.
 *  - pasGs1CorrectieToe(): de stille, automatische toepassing bij elke
 *    volgende scan van diezelfde barcode -- aangeroepen vanuit
 *    splitsBekendOnbekend() (includes/transacties.php, en daarmee gedeeld
 *    door verwerkScan()/verplaatsing()/retournering()/telling.php::
 *    verwerkTelling()) en gs1VoorEenmaligeBestelling()
 *    (includes/eenmalige_bestellingen.php).
 *
 * Bewust een APARTE, nieuwe correctieweg -- niet uitgebreid vanuit
 * bewerkTransactieRegel()/bewerkTransactieBatch() (includes/transacties.php):
 * die zijn ontworpen om een RECENTE vergissing in de laatste
 * TRANSACTIELOG_BEWERKBAAR_DAGEN (7) dagen terug te draaien, en laten
 * lot/vervaldatum daar bewust ongemoeid ("bij een verkeerd gescand
 * product/type is verwijderen + opnieuw scannen betrouwbaarder dan
 * omzetten"). Een structureel verkeerd geparste barcode is iets anders:
 * geen vergissing van een gebruiker maar een parseprobleem, dat net zo
 * goed een lot van maanden geleden kan raken en dat blijvend (voor elke
 * toekomstige scan van dezelfde barcode) opgelost moet worden -- vandaar
 * hier geen 7-dagen-grens en wel de gs1_correcties-registratie.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ean.php';

/**
 * Kijkt of er voor deze EXACTE ruwe scanregel al een eerdere correctie is
 * vastgelegd (gs1_correcties.gs1_raw, UNIQUE) en past die zo nodig toe op
 * het resultaat van parseGS1Raw(). Ean blijft altijd ongemoeid -- deze
 * correctie gaat alleen over lot/vervaldatum (zie de toelichting
 * bovenaan dit bestand voor waarom EAN hier bewust buiten schot blijft).
 *
 * Met een lichte in-memory cache: één scanindiening kan tientallen regels
 * bevatten, maar dezelfde barcode komt zelden vaker dan een handvol keer
 * voor in één indiening -- dit voorkomt onnodig herhaalde identieke
 * queries binnen diezelfde requestverwerking.
 *
 * @param array{ean:string, lot:?string, expiry:?string, interneRef:string} $parsed
 * @return array{ean:string, lot:?string, expiry:?string, interneRef:string}
 */
function pasGs1CorrectieToe(string $gs1Raw, array $parsed): array {
    static $cache = [];

    $key = trim($gs1Raw);
    if ($key === '') return $parsed;

    if (!array_key_exists($key, $cache)) {
        $stmt = db()->prepare('SELECT lot, vervaldatum FROM gs1_correcties WHERE gs1_raw = :raw');
        $stmt->execute([':raw' => $key]);
        $cache[$key] = $stmt->fetch() ?: null;
    }

    $correctie = $cache[$key];
    if ($correctie === null) return $parsed;

    $parsed['lot'] = $correctie['lot'];
    // parseGS1Raw() geeft expiry terug als 'd-m-Y'-string (zie
    // includes/gs1_parser.php); gs1_correcties.vervaldatum is een SQL DATE
    // ('Y-m-d'), dus hier terugvertalen zodat de aanroeper (die daarna
    // gewoon gs1ExpiryToSqlDate($parsed['expiry']) blijft aanroepen zoals
    // altijd) niets van dit verschil hoeft te weten.
    $parsed['expiry'] = $correctie['vervaldatum']
        ? (new DateTime($correctie['vervaldatum']))->format('d-m-Y')
        : null;

    return $parsed;
}

/**
 * Corrigeert één lot: alle transactielog-regels die ean+lot+vervaldatum
 * delen (over ALLE locaties -- een lot dat via Verplaatsing over meerdere
 * ruimtes is uitgesmeerd deelt dezelfde foute herkomst) worden bijgewerkt
 * naar het nieuwe lotnummer/vervaldatum, en voor elke onderliggende ruwe
 * GS1-barcode die dit lot ooit heeft opgeleverd wordt een blijvende
 * correctie vastgelegd (of bijgewerkt, als er al één bestond) in
 * gs1_correcties.
 *
 * $oudLot/$oudVervaldatum (SQL-vorm, 'Y-m-d' of null) identificeren WELK
 * lot bewerkt wordt -- exact zoals includes/voorraad.php::voorraadLot()
 * dat teruggeeft (ean, lot, vervaldatum, locatie), min de locatie.
 * $nieuwLot/$nieuweVervaldatum zijn de gecorrigeerde waarden (beide
 * mogen null/leeg zijn -- bijv. een lotnummer dat helemaal geen
 * batchcode had).
 */
function corrigeerLot(string $ean, ?string $oudLot, ?string $oudVervaldatum, ?string $nieuwLot, ?string $nieuweVervaldatum, string $gebruikerNaam): array {
    $eanNorm = normalizeEAN($ean);
    $nieuwLot = $nieuwLot !== null ? trim($nieuwLot) : null;
    if ($nieuwLot === '') $nieuwLot = null;
    $nieuweVervaldatum = $nieuweVervaldatum !== null && trim($nieuweVervaldatum) !== '' ? trim($nieuweVervaldatum) : null;
    $oudVervaldatum = $oudVervaldatum !== null && trim($oudVervaldatum) !== '' ? trim($oudVervaldatum) : null;
    $oudLot = $oudLot !== null && trim($oudLot) !== '' ? trim($oudLot) : null;

    if ($nieuwLot === $oudLot && $nieuweVervaldatum === $oudVervaldatum) {
        return ['success' => true, 'aantalRegels' => 0, 'aantalBarcodesOnthouden' => 0];
    }

    $pdo = db();

    // NULL-veilige match (<=>): lot en/of vervaldatum kunnen allebei leeg
    // zijn (bijv. een kastcontrole-correctie zonder batchgegevens).
    $stmt = $pdo->prepare(
        'SELECT id, lot, vervaldatum, gs1_raw FROM transactielog
         WHERE ean = :ean AND lot <=> :oudLot AND vervaldatum <=> :oudVervaldatum'
    );
    $stmt->execute([':ean' => $eanNorm, ':oudLot' => $oudLot, ':oudVervaldatum' => $oudVervaldatum]);
    $regels = $stmt->fetchAll();

    if (!$regels) {
        throw new RuntimeException('Geen transacties gevonden voor dit lot -- ververs de pagina, mogelijk is de voorraad ondertussen gewijzigd.');
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE transactielog SET lot = :nieuwLot, vervaldatum = :nieuweVervaldatum, bewerkt_op = NOW(), bewerkt_door = :door WHERE id = :id'
        );
        $rawWaarden = [];
        foreach ($regels as $regel) {
            logTransactieWijziging(
                (int) $regel['id'], 'lot_gecorrigeerd', $gebruikerNaam,
                ['lot' => $regel['lot'], 'vervaldatum' => $regel['vervaldatum']],
                ['lot' => $nieuwLot, 'vervaldatum' => $nieuweVervaldatum]
            );
            $update->execute([
                ':nieuwLot' => $nieuwLot, ':nieuweVervaldatum' => $nieuweVervaldatum,
                ':door' => $gebruikerNaam, ':id' => $regel['id'],
            ]);
            if (!empty($regel['gs1_raw'])) {
                $rawWaarden[trim($regel['gs1_raw'])] = true;
            }
        }

        // Voor elke onderliggende ruwe barcode die dit lot ooit heeft
        // opgeleverd: leg de correctie vast (of werk 'm bij als hij al
        // bestond) zodat een toekomstige scan van diezelfde barcode
        // automatisch de juiste waarden gebruikt -- zie
        // pasGs1CorrectieToe() hierboven.
        $upsert = $pdo->prepare(
            'INSERT INTO gs1_correcties (ean, gs1_raw, lot, vervaldatum, toegevoegd_door)
             VALUES (:ean, :gs1_raw, :lot, :vervaldatum, :door)
             ON DUPLICATE KEY UPDATE lot = VALUES(lot), vervaldatum = VALUES(vervaldatum),
                 toegevoegd_door = VALUES(toegevoegd_door), bijgewerkt_op = NOW()'
        );
        foreach (array_keys($rawWaarden) as $raw) {
            $upsert->execute([
                ':ean' => $eanNorm, ':gs1_raw' => $raw, ':lot' => $nieuwLot,
                ':vervaldatum' => $nieuweVervaldatum, ':door' => $gebruikerNaam,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['success' => true, 'aantalRegels' => count($regels), 'aantalBarcodesOnthouden' => count($rawWaarden)];
}
