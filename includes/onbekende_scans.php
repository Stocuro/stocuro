<?php
/**
 * Beheer van onbekende_scans: het scherm "Onbekende
 * Producten" onder Admin. De ruwe rijen worden weggeschreven door
 * loggeOnbekendeScans() in includes/transacties.php, hier alleen de
 * leeskant (gegroepeerd per EAN, voor het overzicht) en het afronden
 * (zodra een beheerder er daadwerkelijk een artikel van heeft gemaakt).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Open meldingen, gegroepeerd per EAN -- dezelfde onbekende barcode kan
 * meerdere keren gescand zijn voordat een beheerder het artikel afrondt
 * (elke poging staat als losse rij in onbekende_scans, puur een logboek).
 * Per groep: alle omschrijvingen (nieuwste eerst, voor context), het
 * aantal keer gezien, en de locaties waar het is opgedoken.
 *
 * Sinds
 * maakArtikelenVoorOnbekendeRegels() (includes/transacties.php) bestaat
 * er voor zo'n regel vrijwel altijd al een echt artikel (met de
 * omschrijving als tijdelijke naam) -- 'heeftArtikel' laat de frontend
 * weten of dat zo is, zodat de knop hier naar BEWERKEN van dat bestaande
 * artikel linkt i.p.v. naar het aanmaakformulier (dat zou stuklopen op de
 * UNIQUE-constraint op artikelen.ean). Alleen bij een oudere, nog niet
 * afgeronde melding van vóór deze migratie kan dit nog false zijn.
 */
function getOpenOnbekendeScans(): array {
    $rows = db()->query(
        "SELECT id, ean, gs1_raw, omschrijving, locatie, transactietype, gebruiker_naam, aangemaakt_op
         FROM onbekende_scans
         WHERE status = 'open'
         ORDER BY aangemaakt_op DESC"
    )->fetchAll();

    $groepen = [];
    $volgorde = [];

    foreach ($rows as $r) {
        $ean = $r['ean'];
        if (!isset($groepen[$ean])) {
            $groepen[$ean] = [
                'ean' => $ean,
                'omschrijvingen' => [],
                'locaties' => [],
                'aantalKeerGezien' => 0,
                'eerstGezien' => $r['aangemaakt_op'],
                'laatstGezien' => $r['aangemaakt_op'],
            ];
            $volgorde[] = $ean;
        }

        $g = &$groepen[$ean];
        $g['aantalKeerGezien']++;
        if ($r['omschrijving'] !== '' && !in_array($r['omschrijving'], $g['omschrijvingen'], true)) {
            $g['omschrijvingen'][] = $r['omschrijving'];
        }
        if ($r['locatie'] && !in_array($r['locatie'], $g['locaties'], true)) {
            $g['locaties'][] = $r['locatie'];
        }
        if ($r['aangemaakt_op'] < $g['eerstGezien']) $g['eerstGezien'] = $r['aangemaakt_op'];
        if ($r['aangemaakt_op'] > $g['laatstGezien']) $g['laatstGezien'] = $r['aangemaakt_op'];
        unset($g);
    }

    if (!empty($groepen)) {
        $eans = array_keys($groepen);
        $placeholders = implode(',', array_fill(0, count($eans), '?'));
        $stmt = db()->prepare("SELECT ean FROM artikelen WHERE ean IN ($placeholders)");
        $stmt->execute($eans);
        $bestaandeEans = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($groepen as $ean => &$g) {
            $g['heeftArtikel'] = isset($bestaandeEans[$ean]);
        }
        unset($g);
    }

    // Meest recent gezien eerst -- dat is waarschijnlijk waar op dit
    // moment de meeste behoefte aan is (net gebeurd, nog vers).
    usort($volgorde, fn($a, $b) => $groepen[$b]['laatstGezien'] <=> $groepen[$a]['laatstGezien']);

    return array_map(fn($ean) => $groepen[$ean], $volgorde);
}

/** Voor de Taken-badge: alleen het aantal unieke openstaande EANs. */
function aantalOpenOnbekendeScans(): int {
    return (int) db()->query(
        "SELECT COUNT(DISTINCT ean) FROM onbekende_scans WHERE status = 'open'"
    )->fetchColumn();
}

/**
 * Markeert alle open meldingen voor dit EAN als 'verwerkt' -- aangeroepen
 * zodra een beheerder het bijbehorende artikel daadwerkelijk heeft
 * aangemaakt (frontend roept dit aan direct na een succesvolle
 * addArtikel()-aanroep voor ditzelfde EAN, zie api/onbekende_scans.php).
 */
function markeerOnbekendeScanVerwerkt(string $ean): array {
    $stmt = db()->prepare(
        "UPDATE onbekende_scans SET status = 'verwerkt', verwerkt_op = NOW() WHERE ean = :ean AND status = 'open'"
    );
    $stmt->execute([':ean' => $ean]);
    return ['success' => true, 'aantal' => $stmt->rowCount()];
}
