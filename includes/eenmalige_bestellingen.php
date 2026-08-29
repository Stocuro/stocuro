<?php
/**
 * Eenmalige bestellingen: patiëntgebonden materiaalaanvragen, los van
 * de reguliere orders-tabel (migratieplan hoofdstuk 4.7). Bevat een
 * persoonsgegeven (patientnummer) -- elk endpoint dat dit bestand
 * gebruikt, moet requireLogin() aanroepen (zie hoofdstuk 8/9).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ean.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/gs1_parser.php';
// Migratie 032, op verzoek van Harm: het itemized-GS1-scannen hieronder
// (verwerkEenmaligeBestellingGs1Regels()) hergebruikt bewust dezelfde
// bekend/onbekend-splitsing en two-phase onbekende-scan-afhandeling als
// Ontvangst/Verbruik/Verplaatsing (splitsBekendOnbekend()/
// ontbrekendeOmschrijvingen()/loggeOnbekendeScans()/lookupArtikelen()),
// i.p.v. dat opnieuw uit te vinden.
require_once __DIR__ . '/transacties.php';
// Migratie 033, op verzoek van Harm: de "echte" Ontvangst-boeking
// hieronder (verwerkEenmaligeBestellingGs1Regels()) moet altijd op de
// Berging-locatie geboekt worden -- locatieNaamVoorSlot() zoekt de
// huidige (herbenoembare) naam van die vaste locatie-slot op.
require_once __DIR__ . '/locaties.php';

/**
 * Leest een optioneel gescand GS1-barcode-blok ('gs1_raw' in $data) en
 * zet die om naar ean/lot/vervaldatum/aantal -- op verzoek van Harm: bij
 * het AANMAKEN van een eenmalige bestelling is het product er nog niet,
 * dus wordt hier nog helemaal niets gescand (gs1_raw blijft leeg, dan
 * komt deze functie niet verder dan de lege fallback hieronder). Pas
 * zodra de bestelling binnenkomt (op de bewerkpagina) wordt de GS1-
 * barcode gescand -- dat levert in één keer ean + lot + vervaldatum op,
 * wat een handmatig ean-veld nooit deed. Er is bewust GEEN apart
 * handmatig EAN-veld meer in de UI (alleen GS1 wordt gescand); de
 * ean/lot/vervaldatum-sleutels in $data hieronder zijn puur een
 * terugval/doorgeefluik voor wanneer er GEEN nieuwe scan wordt
 * meegestuurd, zodat een eerder vastgelegde waarde niet per ongeluk
 * wordt overschreven met leeg bij een bewerking die niet over de
 * barcode zelf gaat (zie updateEenmaligeBestelling()).
 *
 * 'aantal' (nieuw, op verzoek van Harm): het aantal ontvangen stuks
 * wordt NIET meer los ingetypt, maar afgeleid uit het aantal gescande
 * GS1-regels zelf (één regel = één stuk) -- null wanneer er geen nieuwe
 * scan is meegestuurd, zodat de aanroeper weet dat de bestaande
 * hoeveelheid (de oorspronkelijk aangevraagde) ongemoeid moet blijven.
 *
 * Meerdere regels (migratie 018): bij een grotere hoeveelheid komt een
 * bestelling vaak in meerdere dozen/lots tegelijk binnen, dus mag
 * gs1_raw -- net als bij Ontvangst/Verbruik/Verplaatsing -- meerdere
 * barcodes bevatten (één per regel, zelfde preg_split-conventie als
 * includes/transacties.php). Het EAN komt van de eerste regel die er één
 * oplevert (één bestelling is één product, dus dat zou toch overal
 * hetzelfde EAN moeten zijn). Lotnummers worden allemaal verzameld en
 * kommagescheiden opgeslagen (lot is nu VARCHAR(255), zie migratie 018)
 * -- vervaldatum blijft een enkele DATE-kolom, dus daar wordt de
 * VROEGSTE van alle gescande regels in bewaard: dat is de datum die er
 * het meest toe doet om te weten (het eerste risico), niet een lijst.
 */
function gs1VoorEenmaligeBestelling(array $data): array {
    if (!empty($data['gs1_raw'])) {
        $lines = preg_split('/\r?\n/', trim((string) $data['gs1_raw'], "\" \t\n\r\0\x0B"));
        $ean = null;
        $lots = [];
        $vervaldatums = [];
        $aantalScans = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $parsed = parseGS1Raw($line);
            // Migratie 035: zelfde correctietoepassing als
            // splitsBekendOnbekend() (includes/transacties.php) -- deze
            // functie is los daarvan een eigen parseGS1Raw()-aanroep,
            // zie de toelichting bovenaan dit bestand.
            $parsed = pasGs1CorrectieToe($line, $parsed);
            if ($parsed['ean'] === '') continue;

            $aantalScans++;
            if ($ean === null) $ean = normalizeEAN($parsed['ean']);
            if ($parsed['lot'] !== null && !in_array($parsed['lot'], $lots, true)) $lots[] = $parsed['lot'];
            $sqlDatum = gs1ExpiryToSqlDate($parsed['expiry']);
            if ($sqlDatum !== null) $vervaldatums[] = $sqlDatum;
        }

        if ($ean !== null) {
            return [
                'ean' => $ean,
                'lot' => $lots ? implode(', ', $lots) : null,
                'vervaldatum' => $vervaldatums ? min($vervaldatums) : null,
                'aantal' => $aantalScans,
            ];
        }
    }
    return [
        'ean' => !empty($data['ean']) ? normalizeEAN($data['ean']) : null,
        'lot' => !empty($data['lot']) ? trim((string) $data['lot']) : null,
        'vervaldatum' => !empty($data['vervaldatum']) ? $data['vervaldatum'] : null,
        'aantal' => null,
    ];
}

// =====================================================================
// EENMALIGE_BESTELLING_ITEMS (migratie 032), op verzoek van Harm: EAN,
// Lot en Vervaldatum werden hierboven (gs1VoorEenmaligeBestelling())
// samengevoegd tot ÉÉN rij, terwijl een eenmalige bestelling best uit
// meerdere VERSCHILLENDE producten kan bestaan -- dat liet de gegevens
// van verschillende producten door elkaar lopen. Vanaf hier wordt elke
// gescande GS1-regel als eigen item bewaard (net als transactielog dat al
// deed voor gewone scans), inclusief herkenning of de EAN een bestaand
// artikel is. Onbekende EAN's volgen exact hetzelfde two-phase-patroon
// als Ontvangst/Verbruik/Verplaatsing (includes/transacties.php): pas
// opslaan zodra er een korte omschrijving bij is, en altijd ook naar
// onbekende_scans zodat de beheerder er via het bestaande "Onbekende
// Producten"-scherm een nieuw artikel van kan maken.
// =====================================================================

/**
 * Alle gescande regels voor één eenmalige bestelling, oudste eerst --
 * voor de itemized weergave op de bewerkpagina (i.p.v. de vlakke
 * ean/lot/vervaldatum-velden).
 */
function getEenmaligeBestellingItems(int $bestellingId): array {
    $stmt = db()->prepare(
        'SELECT id, ean, productnaam, lot, vervaldatum, onbekend, status, ontvangst_verwerkt,
                afgehandeld_op, afgehandeld_door, aangemaakt_op
         FROM eenmalige_bestelling_items WHERE eenmalige_bestelling_id = :id ORDER BY id ASC'
    );
    $stmt->execute([':id' => $bestellingId]);
    return array_map(function (array $r): array {
        return [
            'id' => (int) $r['id'], 'ean' => $r['ean'], 'productnaam' => $r['productnaam'],
            'lot' => $r['lot'], 'vervaldatum' => $r['vervaldatum'], 'onbekend' => (bool) $r['onbekend'],
            // Migratie 033, op verzoek van Harm: lifecycle van dit item --
            // 'ontvangen' (standaard) / 'verbruikt' / 'retour', bijgewerkt
            // door koppelScanAanEenmaligeBestellingItems() (includes/
            // transacties.php) zodra een latere Verbruik- of Retour-scan op
            // EAN+lot matcht. ontvangstVerwerkt: staat dit item al echt in
            // het normale voorraadsysteem (transactielog, Berging)?
            'status' => $r['status'], 'ontvangstVerwerkt' => (bool) $r['ontvangst_verwerkt'],
            'afgehandeldOp' => $r['afgehandeld_op'], 'afgehandeldDoor' => $r['afgehandeld_door'],
            'aangemaaktOp' => $r['aangemaakt_op'],
        ];
    }, $stmt->fetchAll());
}

/**
 * Ververst de vlakke ean/lot/vervaldatum/hoeveelheid-samenvatting op de
 * ouderrij vanuit ALLE items die er inmiddels voor bestaan (niet alleen
 * de zojuist toegevoegde regels) -- zo blijven bestaande weergaven die
 * deze vlakke velden gebruiken (Bestellingen-tabel, de mailto-knop op de
 * detailpagina) gewoon werken, ook als een bestelling in meerdere losse
 * scanmomenten binnenkomt. ean = het eerst toegevoegde item met een
 * HERKEND product (valt terug op het allereerste item als geen enkele
 * regel herkend is); lot = alle lots kommagescheiden; vervaldatum = de
 * vroegste van alle regels; hoeveelheid = totaal aantal regels.
 */
function herbereekenEenmaligeBestellingRollup(int $bestellingId): void {
    $items = getEenmaligeBestellingItems($bestellingId);
    if (empty($items)) return;

    $eersteBekend = null;
    foreach ($items as $item) {
        if (!$item['onbekend']) { $eersteBekend = $item; break; }
    }
    $ean = ($eersteBekend ?? $items[0])['ean'];

    $lots = [];
    $vervaldatums = [];
    foreach ($items as $item) {
        if ($item['lot'] !== null && $item['lot'] !== '' && !in_array($item['lot'], $lots, true)) $lots[] = $item['lot'];
        if ($item['vervaldatum'] !== null) $vervaldatums[] = $item['vervaldatum'];
    }

    db()->prepare(
        'UPDATE eenmalige_bestellingen SET ean = :ean, lot = :lot, vervaldatum = :vervaldatum, hoeveelheid = :hoeveelheid WHERE id = :id'
    )->execute([
        ':ean' => $ean, ':lot' => $lots ? implode(', ', $lots) : null,
        ':vervaldatum' => $vervaldatums ? min($vervaldatums) : null,
        ':hoeveelheid' => count($items), ':id' => $bestellingId,
    ]);
}

/**
 * Verwerkt een nieuw gescand GS1-blok voor een BESTAANDE eenmalige
 * bestelling: elke regel wordt een eigen item (zie hierboven), i.p.v. ze
 * samen te voegen tot één rij. Zelfde blokkerende two-phase-aanpak als
 * verwerkScan() (includes/transacties.php): zolang niet elke onbekende
 * regel een omschrijving heeft, wordt er NIETS opgeslagen (ook niet de
 * wel-herkende regels) en komt de aanroeper terug met welke regels dat
 * nog missen.
 *
 * Migratie 033, op verzoek van Harm: "het is namelijk ingescand en dan
 * weten we dat het op voorraad is" -- een HERKENDE regel boekt hier niet
 * meer alleen een itemrij, maar ook een echte Ontvangst-transactie in
 * het normale voorraadsysteem (transactielog, via insertTransactieRegel()
 * uit transacties.php), altijd op de Berging-locatie (de centrale
 * aanvulbron -- zie includes/locaties.php::locatieNaamVoorSlot()). Zo
 * telt dit stuk meteen mee in de gewone voorraadberekeningen (Voorraad-
 * dashboard, Min/Max Advies, etc.), niet alleen op de bestelling zelf.
 * Een ONBEKENDE regel kan nog niet geboekt worden (er bestaat nog geen
 * artikel om aan te boeken) -- dat item krijgt ontvangst_verwerkt = 0 en
 * wordt met terugwerkende kracht alsnog geboekt zodra een beheerder een
 * artikel voor die EAN aanmaakt, zie
 * verwerkAchterstalligeEenmaligeBestellingOntvangsten() hieronder.
 *
 * @return array{success:true, toegevoegd:int}|array{success:false, onbekend:true, onbekendeRegels:array, verwerkt:0}
 */
function verwerkEenmaligeBestellingGs1Regels(int $bestellingId, string $gs1RawBlock, array $omschrijvingen, ?string $gebruikerNaam = null): array {
    $lines = preg_split('/\r?\n/', trim($gs1RawBlock, "\" \t\n\r\0\x0B"));
    $split = splitsBekendOnbekend($lines);

    $ontbrekend = ontbrekendeOmschrijvingen($split['onbekend'], $omschrijvingen);
    if (!empty($ontbrekend)) {
        return ['success' => false, 'onbekend' => true, 'onbekendeRegels' => $ontbrekend, 'verwerkt' => 0];
    }

    $insert = db()->prepare(
        'INSERT INTO eenmalige_bestelling_items
            (eenmalige_bestelling_id, ean, productnaam, lot, vervaldatum, onbekend, ontvangst_verwerkt, gs1_raw, aangemaakt_door)
         VALUES (:bestelling_id, :ean, :naam, :lot, :vervaldatum, :onbekend, :ontvangst_verwerkt, :gs1_raw, :door)'
    );

    $tijdstempel = date('Y-m-d H:i:s');
    $batchId = nieuweBatchId();
    $bergingNaam = locatieNaamVoorSlot('berging') ?? 'Berging';

    foreach ($split['bekend'] as $item) {
        $parsed = $item['parsed'];
        $artikel = $item['artikel'];
        $vervaldatumSql = gs1ExpiryToSqlDate($parsed['expiry']);
        $insert->execute([
            ':bestelling_id' => $bestellingId, ':ean' => $artikel['ean'], ':naam' => $artikel['naam'],
            ':lot' => $parsed['lot'], ':vervaldatum' => $vervaldatumSql,
            ':onbekend' => 0, ':ontvangst_verwerkt' => 1, ':gs1_raw' => $item['line'], ':door' => $gebruikerNaam,
        ]);

        insertTransactieRegel(
            $tijdstempel, $artikel['ean'], $parsed['lot'], $vervaldatumSql, $bergingNaam,
            'Ontvangst', $artikel['hoeveelheid'], $artikel['prijs'], null, null,
            'Eenmalige bestelling #' . $bestellingId, $item['line'], $artikel['naam'], $gebruikerNaam, $batchId
        );
    }
    foreach ($split['onbekend'] as $item) {
        $parsed = $item['parsed'];
        $ean = $parsed['ean'] !== '' ? normalizeEAN($parsed['ean']) : normalizeEAN($item['line']);
        $insert->execute([
            ':bestelling_id' => $bestellingId, ':ean' => $ean, ':naam' => null,
            ':lot' => $parsed['lot'], ':vervaldatum' => gs1ExpiryToSqlDate($parsed['expiry']),
            ':onbekend' => 1, ':ontvangst_verwerkt' => 0, ':gs1_raw' => $item['line'], ':door' => $gebruikerNaam,
        ]);
    }

    // Zelfde admin-datakwaliteit-vangnet als elk ander scanformulier: elke
    // onbekende regel komt óók in onbekende_scans terecht, zodat de
    // beheerder 'm terugziet op het "Onbekende Producten"-scherm en er
    // desgewenst een nieuw artikel van kan maken.
    loggeOnbekendeScans($split['onbekend'], $omschrijvingen, null, 'Eenmalige bestelling', $gebruikerNaam);

    herbereekenEenmaligeBestellingRollup($bestellingId);

    return ['success' => true, 'toegevoegd' => count($split['bekend']) + count($split['onbekend'])];
}

/**
 * Migratie 033, op verzoek van Harm: tegenhanger van de "onbekend"-tak
 * hierboven. Zodra een beheerder alsnog een artikel aanmaakt voor een
 * EAN die eerder als onbekend in een (of meerdere) eenmalige
 * bestelling(en) was gescand -- zie showArtikelToevoegen()/
 * api/artikel_toevoegen.php -- moet dat item niet langer als "onbekend"
 * getoond worden, EN moet de destijds uitgestelde Ontvangst-boeking
 * (er bestond nog geen artikel om aan te boeken) alsnog gebeuren, één
 * transactie per openstaande regel, met terugwerkende kracht.
 *
 * Aan te roepen ALTIJD na een succesvolle addArtikel(), ongeacht waar
 * het artikel vandaan komt (Onbekende Producten-scherm of gewoon
 * handmatig Artikel Toevoegen met precies deze EAN) -- als er geen
 * enkele openstaande regel voor deze EAN bestaat, gebeurt er simpelweg
 * niets.
 *
 * Migratie 041, op verzoek van Harm: "koppel besteltype Eenmalig sowieso
 * aan producten die worden gescand/aangemaakt binnen Eenmalige
 * bestellingen". Als deze functie hieronder openstaande regels vindt,
 * bestaat dit artikel per definitie ALLEEN omdat het ooit binnen een
 * Eenmalige Bestelling is gescand (zie verwerkEenmaligeBestellingGs1Regels()
 * hierboven: een onbekende regel daar krijgt pas een echt artikel via de
 * Onbekende Producten-omweg) -- dus wordt besteltype hier altijd
 * geforceerd naar 'Eenmalig', ONGEACHT wat er in het aanmaakformulier
 * gekozen was. Een gewoon artikel dat al bestond en toevallig ook een
 * keer voor een eenmalige bestelling gebruikt is, komt hier NIET binnen
 * (dat had immers al een artikel, dus geen "onbekend"-regel om op te
 * wachten), dus blijft gewoon Automatisch/Handmatig zoals het al was.
 */
function verwerkAchterstalligeEenmaligeBestellingOntvangsten(string $ean, ?string $gebruikerNaam = null): void {
    $eanNorm = normalizeEAN($ean);
    $artikel = lookupArtikelen($eanNorm);
    if (!$artikel) return; // defensief: zou niet moeten gebeuren vlak na addArtikel()

    $stmt = db()->prepare(
        'SELECT id, lot, vervaldatum, gs1_raw, eenmalige_bestelling_id
         FROM eenmalige_bestelling_items
         WHERE ean = :ean AND onbekend = 1 AND ontvangst_verwerkt = 0'
    );
    $stmt->execute([':ean' => $eanNorm]);
    $rijen = $stmt->fetchAll();
    if (!$rijen) return;

    db()->prepare('UPDATE artikelen SET besteltype = \'Eenmalig\' WHERE ean = :ean')->execute([':ean' => $eanNorm]);

    $bergingNaam = locatieNaamVoorSlot('berging') ?? 'Berging';
    $tijdstempel = date('Y-m-d H:i:s');
    $batchId = nieuweBatchId();
    $update = db()->prepare(
        'UPDATE eenmalige_bestelling_items SET onbekend = 0, productnaam = :naam, ontvangst_verwerkt = 1 WHERE id = :id'
    );

    $bijgewerkteBestellingen = [];
    foreach ($rijen as $rij) {
        $update->execute([':naam' => $artikel['naam'], ':id' => $rij['id']]);

        insertTransactieRegel(
            $tijdstempel, $artikel['ean'], $rij['lot'], $rij['vervaldatum'], $bergingNaam,
            'Ontvangst', $artikel['hoeveelheid'], $artikel['prijs'], null, null,
            'Eenmalige bestelling #' . $rij['eenmalige_bestelling_id'] . ' (alsnog geboekt na aanmaken artikel)',
            $rij['gs1_raw'], $artikel['naam'], $gebruikerNaam, $batchId
        );

        $bijgewerkteBestellingen[(int) $rij['eenmalige_bestelling_id']] = true;
    }

    foreach (array_keys($bijgewerkteBestellingen) as $bestellingId) {
        herbereekenEenmaligeBestellingRollup($bestellingId);
    }
}

function addEenmaligeBestelling(array $data, ?string $gebruikerNaam = null): array {
    $patientnummer = trim($data['patientnummer'] ?? '');
    $omschrijving = trim($data['artikelomschrijving'] ?? '');
    if ($patientnummer === '') return ['success' => false, 'error' => 'Patiëntnummer is verplicht'];
    if ($omschrijving === '') return ['success' => false, 'error' => 'Artikelomschrijving is verplicht'];

    // Licentiecontrole (op verzoek van Harm, "Licentiemodel: geen naam-
    // registratie zonder licentie"): een eenmalige bestelling mag niet op
    // naam van een specialist/laborant zonder geldige licentie gezet
    // worden -- zelfde redenering en cache-kolom als bij
    // includes/transacties.php::verwerkScan(). Onbekende namen blokkeren
    // niet (geen bevoegdheidsbeslissing, gewoon onbekende invoer).
    $specialistNaam = trim((string) ($data['specialist'] ?? ''));
    if ($specialistNaam !== '') {
        $licStmt = db()->prepare("SELECT licentie_geblokkeerd FROM users WHERE naam = :naam AND rol IN ('specialist', 'laborant')");
        $licStmt->execute([':naam' => $specialistNaam]);
        $licRij = $licStmt->fetch();
        if ($licRij && (int) $licRij['licentie_geblokkeerd'] === 1) {
            return ['success' => false, 'error' => "Voor '" . $specialistNaam . "' ontbreekt een geldige licentie -- koppel eerst een activatiecode aan dit account voordat er op naam besteld kan worden."];
        }
    }

    $gs1 = gs1VoorEenmaligeBestelling($data);
    // 'aantal' is alleen gezet als er daadwerkelijk een GS1-scan is
    // meegestuurd (zie gs1VoorEenmaligeBestelling()) -- bij het aanmaken
    // is dat vrijwel nooit het geval (het product is er nog niet), dus
    // valt dit terug op de handmatig aangevraagde hoeveelheid.
    $hoeveelheid = $gs1['aantal'] !== null ? $gs1['aantal'] : max(1, (int) ($data['hoeveelheid'] ?? 1));

    db()->prepare(
        'INSERT INTO eenmalige_bestellingen
            (tijdstempel, patientnummer, artikelomschrijving, ean, lot, vervaldatum, hoeveelheid, procedure_type, specialist, besteller, opmerking, status)
         VALUES (NOW(), :patientnummer, :omschrijving, :ean, :lot, :vervaldatum, :hoeveelheid, :procedure, :specialist, :besteller, :opmerking, \'Aangevraagd\')'
    )->execute([
        ':patientnummer' => $patientnummer, ':omschrijving' => $omschrijving,
        ':ean' => $gs1['ean'], ':lot' => $gs1['lot'], ':vervaldatum' => $gs1['vervaldatum'],
        ':hoeveelheid' => $hoeveelheid,
        ':procedure' => $data['procedure_type'] ?? null, ':specialist' => $data['specialist'] ?? null,
        // Besteller is altijd de ingelogde gebruiker (op verzoek van
        // Harm) -- niet een vrij, door de client meegestuurd tekstveld.
        // $gebruikerNaam komt van apiRequireWriteAccess() in
        // api/eenmalige_bestelling.php, dus is er in de praktijk altijd;
        // de $data-terugval is alleen voor eventuele directe aanroepen
        // zonder ingelogde gebruiker (bijv. migratiescripts).
        ':besteller' => $gebruikerNaam ?? ($data['besteller'] ?? null), ':opmerking' => $data['opmerking'] ?? null,
    ]);

    $ean = $gs1['ean'];

    if ($gebruikerNaam !== null) {
        // Bewust GEEN patientnummer in de audit-details -- dat is een
        // persoonsgegeven (zie ook toelichting bovenin dit bestand), de
        // koppeling ligt al vast in eenmalige_bestellingen zelf.
        logAudit($gebruikerNaam, 'eenmalige_bestelling_aangemaakt', 'eenmalige_bestelling', $ean, "omschrijving=$omschrijving");
    }

    return ['success' => true, 'message' => 'Eenmalige bestelling aangevraagd'];
}

/**
 * Eén db-rij (eenmalige_bestellingen) naar het array-formaat dat alle
 * lees-functies hieronder teruggeven -- één plek voor de veldmapping
 * i.p.v. hem drie keer te herhalen (lijst/open-lijst/detail).
 */
function eenmaligeBestellingNaarArray(array $r): array {
    return [
        'id' => (int) $r['id'], 'tijdstempel' => $r['tijdstempel'],
        'patientnummer' => $r['patientnummer'], 'artikelomschrijving' => $r['artikelomschrijving'],
        'ean' => $r['ean'], 'lot' => $r['lot'], 'vervaldatum' => $r['vervaldatum'],
        'hoeveelheid' => (int) $r['hoeveelheid'],
        'procedure' => $r['procedure_type'], 'specialist' => $r['specialist'],
        'besteller' => $r['besteller'], 'opmerking' => $r['opmerking'], 'status' => $r['status'],
    ];
}

/**
 * Laatste 200 eenmalige bestellingen, nieuwste eerst.
 */
function getEenmaligeBestellingen(): array {
    $rows = db()->query(
        'SELECT * FROM eenmalige_bestellingen ORDER BY tijdstempel DESC LIMIT 200'
    )->fetchAll();

    return array_map('eenmaligeBestellingNaarArray', $rows);
}

/**
 * Eén eenmalige bestelling, met alle velden (incl. opmerking) -- voor de
 * detailpagina die je bereikt door in Open Orders op een regel te
 * klikken. Op verzoek van Harm: niet meer inline de status wijzigen
 * vanuit het overzicht zelf, maar eerst doorklikken naar de volledige
 * inhoud (ook de opmerkingen, die nergens in het overzicht zelf pasten)
 * en van daaruit bewerken.
 */
function getEenmaligeBestellingDetail(int $id): array {
    $stmt = db()->prepare('SELECT * FROM eenmalige_bestellingen WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();
    if (!$r) return ['error' => 'Eenmalige bestelling niet gevonden'];

    $result = eenmaligeBestellingNaarArray($r);

    // Contactadres voor de "Mail specialist"-knop (migratie 016, sinds
    // migratie 019 opgezocht in 'users' i.p.v. de opgeheven
    // 'specialisten'-tabel -- elke specialist/laborant IS nu een account
    // met een echt inlog-e-mailadres, dus is dit vrijwel altijd gevuld,
    // in plaats van het vroegere losse, vaak lege contactveld). Opgezocht
    // via de naam (geen FK, zie de toelichting bovenin dit bestand), en
    // alleen hier nodig (detailpagina), niet in de lijstweergaven.
    // Leeg/null als de specialist geen naam heeft of geen match heeft in
    // Gebruikers -- de frontend laat de mailto dan gewoon een leeg
    // "aan"-veld hebben.
    $result['specialistEmail'] = null;
    if (!empty($r['specialist'])) {
        $stmt = db()->prepare("SELECT email FROM users WHERE naam = :naam AND rol IN ('specialist', 'laborant')");
        $stmt->execute([':naam' => $r['specialist']]);
        $email = $stmt->fetchColumn();
        $result['specialistEmail'] = $email !== false && $email !== null && $email !== '' ? $email : null;
    }

    // Migratie 032, op verzoek van Harm: itemlijst (per gescande GS1-regel
    // een eigen EAN/product/lot/vervaldatum) voor de bewerkpagina, naast
    // de vlakke ean/lot/vervaldatum-samenvatting die hierboven al in
    // $result zit (blijft bestaan voor andere weergaven, zie
    // herbereekenEenmaligeBestellingRollup()).
    $result['items'] = getEenmaligeBestellingItems($id);

    return $result;
}

/**
 * Statussen die als "afgerond" gelden -- een eenmalige bestelling in een
 * van deze statussen hoort niet meer thuis in het Open Orders-overzicht,
 * exact dezelfde regel als "Volledig ontvangen" die een reguliere order
 * daar al uit laat verdwijnen (includes/orders.php::openOrderStatusMap()).
 * 'Gebruikt' = het artikel is in de procedure gebruikt (succesvol
 * afgerond); 'Retourneren' (migratie 013) = het artikel is NIET gebruikt
 * en gaat terug (ook afgerond, alleen niet als verbruik).
 */
const EENMALIGE_BESTELLING_AFGEROND = ['Gebruikt', 'Retourneren'];
const EENMALIGE_BESTELLING_STATUSSEN = ['Aangevraagd', 'Besteld', 'Ontvangen', 'Gebruikt', 'Retourneren'];

/**
 * Volledige bewerking (op verzoek van Harm: niet alleen de status, maar
 * "de eigenschappen" wijzigen vanaf de detailpagina) -- alle velden
 * inclusief status in één keer, geen los status-only endpoint meer.
 *
 * Migratie 032, op verzoek van Harm: gs1VoorEenmaligeBestelling() (die
 * alles samenvoegde tot één ean/lot/vervaldatum) wordt hier niet meer
 * gebruikt -- een nieuwe scan (gs1_raw) wordt nu, VÓÓR er iets anders op
 * deze rij wordt opgeslagen, itemized verwerkt door
 * verwerkEenmaligeBestellingGs1Regels() (nieuwe items + rollup van de
 * vlakke ean/lot/vervaldatum/hoeveelheid-velden). Bevat die scan
 * onbekende barcodes zonder omschrijving, dan wordt -- zelfde
 * alles-of-niets-aanpak als verwerkScan() -- ook de rest van deze
 * bewerking (patiëntnummer/omschrijving/status/etc.) NIET opgeslagen; de
 * aanroeper krijgt terug welke regels nog een omschrijving nodig hebben.
 * Zonder nieuwe scan (gs1_raw leeg) wordt dit hele blok overgeslagen en
 * verandert er niets aan de items/vlakke velden.
 */
function updateEenmaligeBestelling(int $id, array $data, ?string $gebruikerNaam = null): array {
    $patientnummer = trim($data['patientnummer'] ?? '');
    $omschrijving = trim($data['artikelomschrijving'] ?? '');
    $status = $data['status'] ?? '';
    if ($patientnummer === '') return ['success' => false, 'error' => 'Patiëntnummer is verplicht'];
    if ($omschrijving === '') return ['success' => false, 'error' => 'Artikelomschrijving is verplicht'];
    if (!in_array($status, EENMALIGE_BESTELLING_STATUSSEN, true)) {
        return ['success' => false, 'error' => 'Ongeldige status'];
    }

    $gs1Raw = trim((string) ($data['gs1_raw'] ?? ''));
    if ($gs1Raw !== '') {
        $itemResultaat = verwerkEenmaligeBestellingGs1Regels($id, $gs1Raw, $data['omschrijvingen'] ?? [], $gebruikerNaam);
        if (!empty($itemResultaat['onbekend'])) {
            return $itemResultaat;
        }
    }

    // Licentiecontrole (op verzoek van Harm), zelfde als addEenmaligeBestelling()
    // hierboven -- maar hier alleen afdwingen als het specialist-veld
    // daadwerkelijk WIJZIGT naar een andere naam zonder geldige licentie.
    // Een bestaande, al langer toegewezen specialist die inmiddels
    // geblokkeerd is blijft zo bewerkbaar op elk ander veld (status,
    // opmerking, ...) zonder dat deze update daarop struikelt -- alleen
    // het actief KIEZEN van een geblokkeerde naam wordt geweigerd.
    $specialistNaam = trim((string) ($data['specialist'] ?? ''));
    if ($specialistNaam !== '') {
        $huidigeRij = db()->prepare('SELECT specialist FROM eenmalige_bestellingen WHERE id = :id');
        $huidigeRij->execute([':id' => $id]);
        $huidigeSpecialist = $huidigeRij->fetchColumn();
        if ($specialistNaam !== $huidigeSpecialist) {
            $licStmt = db()->prepare("SELECT licentie_geblokkeerd FROM users WHERE naam = :naam AND rol IN ('specialist', 'laborant')");
            $licStmt->execute([':naam' => $specialistNaam]);
            $licRij = $licStmt->fetch();
            if ($licRij && (int) $licRij['licentie_geblokkeerd'] === 1) {
                return ['success' => false, 'error' => "Voor '" . $specialistNaam . "' ontbreekt een geldige licentie -- koppel eerst een activatiecode aan dit account voordat er op naam besteld kan worden."];
            }
        }
    }

    db()->prepare(
        'UPDATE eenmalige_bestellingen SET
            patientnummer = :patientnummer, artikelomschrijving = :omschrijving,
            procedure_type = :procedure, specialist = :specialist,
            besteller = :besteller, opmerking = :opmerking, status = :status
         WHERE id = :id'
    )->execute([
        ':patientnummer' => $patientnummer, ':omschrijving' => $omschrijving,
        ':procedure' => $data['procedure_type'] ?? null, ':specialist' => $data['specialist'] ?? null,
        ':besteller' => $data['besteller'] ?? null, ':opmerking' => $data['opmerking'] ?? null,
        ':status' => $status, ':id' => $id,
    ]);

    if ($gebruikerNaam !== null) {
        // Zelfde overweging als addEenmaligeBestelling(): bewust GEEN
        // patientnummer in de audit-details, dat is een persoonsgegeven.
        logAudit($gebruikerNaam, 'eenmalige_bestelling_bewerkt', 'eenmalige_bestelling', (string) $id, "status=$status");
    }

    return ['success' => true];
}

/**
 * Eenmalige bestellingen die nog "open" staan (niet Gebruikt/Retourneren),
 * met dagenOpen erbij -- voor het Open Orders-overzicht, naast (niet
 * i.p.v.) de reguliere orders uit includes/orders.php::getOpenOrders().
 * Op verzoek van Harm: eenmalige bestellingen waren nergens in een
 * overzicht met statusbeheer zichtbaar, alleen in een platte, alleen-
 * lezen lijst binnen het aanvraagformulier zelf.
 */
function getOpenEenmaligeBestellingen(): array {
    $placeholders = implode(',', array_fill(0, count(EENMALIGE_BESTELLING_AFGEROND), '?'));
    $stmt = db()->prepare("SELECT * FROM eenmalige_bestellingen WHERE status NOT IN ($placeholders) ORDER BY tijdstempel DESC");
    $stmt->execute(EENMALIGE_BESTELLING_AFGEROND);

    $today = new DateTime('today');
    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $besteldOp = new DateTime($r['tijdstempel']);
        $result[] = array_merge(eenmaligeBestellingNaarArray($r), [
            'datum' => $besteldOp->format('d-m-Y H:i'),
            'dagenOpen' => (int) $today->diff($besteldOp)->format('%a'),
        ]);
    }

    usort($result, fn($a, $b) => $b['dagenOpen'] <=> $a['dagenOpen']);
    return $result;
}

/**
 * Eigen eenmalige bestellingen (migratie 019, herzien in migratie 033, nu
 * weer verbreed in migratie 044 op verzoek van Harm: "de widget Mijn
 * Bestellingen moet alle bestellingen VOOR en DOOR de persoon die is
 * ingelogd laten zien"). Matcht dus weer op BEIDE rollen: BESTELLER (wie
 * de aanvraag heeft geplaatst) EN specialist (voor wie de aanvraag
 * klinisch is) -- migratie 033 liet specialist expres vallen omdat de
 * volledige Bestellingen-tabel/'mijn'-filterchip (bestellingenNormaliseer()
 * in assets/js/app.js) toen bewust naar besteller-only ging, maar dat is
 * een ander scherm met een andere, expliciet gekozen betekenis van "van
 * mij" -- deze functie hier voedt uitsluitend de Home-widget, en Harm wil
 * daar zowel zijn eigen aanvragen als de aanvragen die klinisch voor hem
 * bestemd zijn in terugzien. Besteller/specialist kunnen dus onafhankelijk
 * van elkaar matchen (OR, geen AND) en een rij die op allebei matcht
 * (besteller = specialist = deze gebruiker) komt vanzelfsprekend maar
 * één keer terug (geen UNION met dubbele rijen, gewoon één WHERE-conditie).
 */
function getEigenEenmaligeBestellingen(string $eigenNaam): array {
    $stmt = db()->prepare('SELECT * FROM eenmalige_bestellingen WHERE besteller = :naam OR specialist = :naam2 ORDER BY tijdstempel DESC LIMIT 200');
    $stmt->execute([':naam' => $eigenNaam, ':naam2' => $eigenNaam]);

    return array_map('eenmaligeBestellingNaarArray', $stmt->fetchAll());
}
