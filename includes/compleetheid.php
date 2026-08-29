<?php
/**
 * Compleetheidscheck productgegevens (takenlijst-item, op verzoek van
 * Harm): welke artikelen missen essentiële informatie? Geen aparte
 * tabel/vlaggen -- net als de rest van includes/taken.php wordt dit
 * live afgeleid uit de bestaande artikelen-/transactielog-data, dus er
 * is niets om handmatig bij te houden of te laten verlopen.
 *
 * Gecontroleerde velden, met per stuk de reden waarom dat "essentieel"
 * is voor de rest van de app:
 *   - producent      : nodig om te weten bij wie te bestellen/navragen.
 *   - foto           : helpt bij het herkennen op het schap tijdens scannen.
 *   - minMax         : zonder enige min/max ergens ingesteld verschijnt
 *                       dit artikel NOOIT in Berging/Boodschappenlijst,
 *                       ook niet als de voorraad op is.
 *   - kastlocatie     : helpt fysiek terugvinden op de juiste locatie.
 *   - houdbaarheid    : GEEN los veld (bewuste eerdere keuze, zie
 *                       includes/minmax.php::houdbaarheidMaandenPerEan())
 *                       -- "ontbreekt" betekent hier dat dit EAN nog nooit
 *                       een vervaldatum via een Ontvangst-scan heeft
 *                       gekregen, waardoor het min/max-advies geen
 *                       houdbaarheidsplafond kan toepassen.
 *   - prijs           : prijs = 0 breekt kosten-/besparingsrapportages
 *                       stilzwijgend (geen fout, gewoon een 0 in elk totaal).
 *   - categorie       : onzichtbaar in categorie-gebaseerde schermen.
 *   - doosInconsistent: alleen doos_ean OF alleen eenheden_per_doos
 *                       ingevuld (migratie 020) -- dan werkt de
 *                       doosherkenning bij het scannen stil niet zoals
 *                       bedoeld.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/** EANs die minstens één Ontvangst-scan met een vervaldatum hebben gehad. */
function eansMetHoudbaarheidData(): array {
    $rows = db()->query(
        "SELECT DISTINCT ean FROM transactielog WHERE transactietype = 'Ontvangst' AND vervaldatum IS NOT NULL"
    )->fetchAll();
    return array_flip(array_column($rows, 'ean'));
}

/**
 * Per ean: max-status en kastlocatie-status op actieve extra locaties
 * (migratie 007). Migratie 031, op verzoek van Harm: extra locaties hebben
 * geen minimum-kolom meer (ze gedragen zich als een kamer, zie
 * includes/locaties.php) -- "compleet" betekent hier dus alleen nog een
 * ingesteld maximum.
 */
function artikelLocatieExtraCompleetheid(): array {
    $rows = db()->query(
        "SELECT ale.ean, ale.max_aantal, ale.kastlocatie
         FROM artikel_locatie_extra ale
         JOIN locaties_extra le ON le.id = ale.locatie_extra_id
         WHERE le.actief = 1"
    )->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $ean = $r['ean'];
        if (!isset($result[$ean])) $result[$ean] = ['heeftMinMax' => false, 'heeftKastlocatie' => false];
        if ((int) $r['max_aantal'] > 0) $result[$ean]['heeftMinMax'] = true;
        if (trim((string) ($r['kastlocatie'] ?? '')) !== '') $result[$ean]['heeftKastlocatie'] = true;
    }
    return $result;
}

/**
 * @return array<int, array{ean:string, naam:string, ontbrekend:array<int,string>}>
 *         'ontbrekend' bevat een subset van:
 *         producent, foto, minMax, kastlocatie, houdbaarheid, prijs,
 *         categorie, doosInconsistent.
 */
function getOnvolledigeProducten(): array {
    // Migratie 031, op verzoek van Harm: min_ik/min_hybok/min_kar bestaan
    // niet meer (kamers hebben geen eigen minimum meer) -- "minMax"
    // hieronder checkt voor kamers dus alleen nog max, en voor Berging
    // (die wél een echt reorder-minimum heeft) blijft min meetellen.
    $artikelen = db()->query(
        "SELECT ean, productnaam, producent, foto_link, foto_data, categorie, prijs, doos_ean, eenheden_per_doos,
                max_ik, max_hybok, max_kar, min_berging, max_berging,
                locatie_ik, locatie_berging, locatie_hybok
         FROM artikelen ORDER BY productnaam"
    )->fetchAll();

    $houdbaarBekend = eansMetHoudbaarheidData();
    $extra = artikelLocatieExtraCompleetheid();

    $result = [];
    foreach ($artikelen as $a) {
        $ean = $a['ean'];
        $ontbrekend = [];

        if (trim((string) ($a['producent'] ?? '')) === '') $ontbrekend[] = 'producent';

        // Bugfix (op verzoek van Harm): controleerde voorheen alleen
        // foto_link (de oude, verwijderde Drive-link-invoer, zie migratie
        // 023/taak #258). Sinds de fotoUpload naar foto_data (MEDIUMBLOB,
        // migratie 023) is verhuisd, staat foto_link bij nieuw/bewerkte
        // producten altijd leeg -- ook als er wel degelijk een foto is
        // geupload. Daardoor werd elk product met alleen een foto_data
        // ten onrechte als "foto ontbreekt" op de Takenlijst gezet. Nu
        // pas "ontbrekend" als ALLEBEI leeg zijn.
        $heeftFotoData = !empty($a['foto_data']);
        $heeftFotoLink = trim((string) ($a['foto_link'] ?? '')) !== '';
        if (!$heeftFotoData && !$heeftFotoLink) $ontbrekend[] = 'foto';

        $heeftMinMaxVast = (int) $a['max_ik'] > 0 || (int) $a['max_hybok'] > 0 || (int) $a['max_kar'] > 0
            || (int) $a['min_berging'] > 0 || (int) $a['max_berging'] > 0;
        if (!$heeftMinMaxVast && empty($extra[$ean]['heeftMinMax'])) $ontbrekend[] = 'minMax';

        $heeftKastlocatieVast = trim((string) ($a['locatie_ik'] ?? '')) !== ''
            || trim((string) ($a['locatie_berging'] ?? '')) !== ''
            || trim((string) ($a['locatie_hybok'] ?? '')) !== '';
        if (!$heeftKastlocatieVast && empty($extra[$ean]['heeftKastlocatie'])) $ontbrekend[] = 'kastlocatie';

        if (!isset($houdbaarBekend[$ean])) $ontbrekend[] = 'houdbaarheid';
        if ((float) $a['prijs'] === 0.0) $ontbrekend[] = 'prijs';
        if (trim((string) ($a['categorie'] ?? '')) === '') $ontbrekend[] = 'categorie';

        // XOR: precies één van de twee ingevuld = inconsistent. Beide
        // leeg (geen doosverpakking) of beide gevuld (correct) is prima.
        $heeftDoosEan = !empty($a['doos_ean']);
        $heeftEenhedenPerDoos = !empty($a['eenheden_per_doos']);
        if ($heeftDoosEan !== $heeftEenhedenPerDoos) $ontbrekend[] = 'doosInconsistent';

        if (!empty($ontbrekend)) {
            $result[] = ['ean' => $ean, 'naam' => $a['productnaam'], 'ontbrekend' => $ontbrekend];
        }
    }

    return $result;
}
