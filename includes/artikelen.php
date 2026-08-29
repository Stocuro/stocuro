<?php
/**
 * Artikelen: productstamdata. Poort van de artikel-gerelateerde
 * functies in DashboardAll.gs (getArtikelEditData, updateArtikelVeld,
 * getProductDetail, getProductList, getFotoDataUri) en van
 * onFormSubmit_Artikelen/onEdit in Code.gs (hier: addArtikel()).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ean.php';
require_once __DIR__ . '/gs1_parser.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/voorraad.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/producenten.php';
require_once __DIR__ . '/upload.php';
// Migratie 037, op verzoek van Harm: markeerOnbekendeScanVerwerkt() in
// updateArtikelVeld() hieronder -- zodra een tijdelijk aangemaakt
// artikel (zie maakArtikelenVoorOnbekendeRegels() in transacties.php)
// daadwerkelijk bewerkt wordt, verdwijnt het van "Onbekende Producten".
require_once __DIR__ . '/onbekende_scans.php';

// Algemene, bewerkbare velden op de detailpagina. Poort van ARTIKEL_EDIT_FIELDS.
// Migratie 028, op verzoek van Harm: 'foto_link' (Drive-link/directe URL)
// hier weggehaald -- alles loopt nu via de foto-upload (foto_data/
// foto_mime, migratie 023), dus een los invulbaar linkveld is verwarrend
// naast die upload-knop. Bestaande artikelen die nog een foto_link hebben
// (van vóór migratie 023) blijven intact in de database en werken nog
// gewoon als fallback in getProductDetail()/getFotoDataUri() -- dit
// verwijdert alleen de mogelijkheid om het veld handmatig te BEWERKEN.
//
// Op verzoek van Harm ("ik wil daadwerkelijk alles kunnen
// bewerken"): categorie, gs1_raw (de ruwe GS1-scan/"barcode") en de drie
// kastlocatie-velden (locatie_ik/_hybok/_berging) waren eerder bewust
// uitgesloten van dit bewerkscherm -- die uitsluiting is nu losgelaten.
// lot/vervaldatum blijven WEL buiten dit bestand: die horen niet bij het
// artikel zelf maar bij een individuele transactielog-regel (voorraad per
// partij), en worden dus nooit hier bewerkt maar via de scanformulieren of
// Recente Transacties (includes/transacties.php).
//
// EAN zelf staat BEWUST niet in deze lijst: dat is de kolom waarop dit
// artikel wordt opgezocht (WHERE ean = :ean) én een waarde die letterlijk
// staat opgeslagen in transactielog/orders/eenmalige_bestellingen/
// artikel_locatie_extra/minmax_advies_genegeerd -- een simpele
// tekstveld-overschrijving zou al die historische rijen laten "verweesd"
// achterblijven. Zie wijzigArtikelEan() hieronder voor de aparte,
// transactionele route die dat wél goed afhandelt (met cascade + een
// uniekheidscheck), aangeroepen via een eigen mini-formulier op het
// bewerkscherm i.p.v. het generieke commonFields-mechanisme hier.
/**
 * Alle al bestaande, niet-lege kastlocatie-waardes (schapaanduiding
 * binnen een kamer, bv. "Plank A3") -- op verzoek van Harm: dit blijft
 * bewust vrije tekst (geen aparte master-tabel, zie migratie 029), maar
 * de UI biedt 'm nu als dropdown-met-"nieuwe toevoegen" aan (zelfde
 * patroon als het Producent-veld), zodat bestaande schapaanduidingen
 * hergebruikt worden i.p.v. steeds opnieuw net iets anders getypt te
 * worden ("Plank A3" vs "plank a3" vs "Rek A3").
 *
 * Doorzoekt alle drie de vaste-kamer-kolommen op artikelen plus de
 * kastlocatie-kolom van artikel_locatie_extra, dus de suggestielijst is
 * altijd wat er al écht in gebruik is, kamer-onafhankelijk (een
 * schapaanduiding als "Lade 2" komt in meerdere kamers voor, dus geen zin
 * om deze lijst per kamer te splitsen).
 */
function getKastlocatieSuggesties(): array {
    $waardes = [];
    foreach (['locatie_ik', 'locatie_hybok', 'locatie_berging'] as $kolom) {
        $stmt = db()->query("SELECT DISTINCT $kolom AS v FROM artikelen WHERE $kolom IS NOT NULL AND $kolom <> ''");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $waardes[$v] = true;
        }
    }
    $stmt = db()->query("SELECT DISTINCT kastlocatie AS v FROM artikel_locatie_extra WHERE kastlocatie IS NOT NULL AND kastlocatie <> ''");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) {
        $waardes[$v] = true;
    }
    $lijst = array_keys($waardes);
    sort($lijst, SORT_NATURAL | SORT_FLAG_CASE);
    return $lijst;
}

const ARTIKEL_EDIT_FIELDS = [
    'productnaam' => 'Productnaam',
    // Op verzoek van Harm: vrij bewerkbaar, puur referentie -- lookupArtikelen()
    // (includes/transacties.php) matcht scans op ean/doos_ean/
    // logistiek_code, NOOIT op gs1_raw, dus wijzigen hiervan heeft geen
    // invloed op het herkennen van toekomstige scans.
    'gs1_raw' => 'GS1-barcode (ruwe scan)',
    'categorie' => 'Categorie',
    'besteltype' => 'Besteltype',
    // Migratie 031, op verzoek van Harm: kamers (IK/Hybride OK/Kar) hebben
    // geen minimum-kolom meer -- ze worden gegarandeerd dezelfde dag tot
    // hun maximum bijgevuld vanuit Berging, dus een reorder-minimum heeft
    // daar nooit een functie gehad. Alleen Berging (de enige locatie die op
    // een externe leverancier wacht) behoudt min+max.
    'max_ik' => 'Max IK',
    'max_hybok' => 'Max Hybride OK',
    'max_kar' => 'Max Kar',
    'min_berging' => 'Min Berging', 'max_berging' => 'Max Berging',
    // Op verzoek van Harm: kastlocatie (schapaanduiding) per kamer -- eerder alleen
    // in te vullen via het migratiescript, nooit handmatig op dit scherm.
    'locatie_ik' => 'Kastlocatie', 'locatie_hybok' => 'Kastlocatie', 'locatie_berging' => 'Kastlocatie',
    'artikelref' => 'Artikelnummer', 'producent' => 'Producent', 'prijs' => 'Prijs per stuk (EUR)',
    // Migratie 021, op verzoek van Harm: interne code die het ziekenhuis
    // zelf toekent (bijv. voor een eigen geprint schaplabel), volledig
    // handmatig -- géén automatische generatie. Losstaand van artikelref
    // hierboven (dat is het nummer van de FABRIKANT). Wordt als derde
    // fallback herkend bij het scannen, zie includes/transacties.php::
    // lookupArtikelen().
    'logistiek_code' => 'Interne logistiekcode (optioneel, voor een eigen schaplabel)',
    // Migratie 020, op verzoek van Harm: sommige producten (voerdraden,
    // sheaths, katheters, PICC-lijnen) worden per stuk verbruikt/
    // verplaatst maar door de leverancier per doos geleverd, met een
    // eigen doos-EAN naast het stuk-EAN. Beide leeg laten = geen
    // doosverpakking voor dit product. Zie includes/transacties.php::
    // lookupArtikelen() voor hoe een gescande doos-EAN wordt herkend en
    // includes/voorraad.php::rondAfOpDoos() voor de afronding bij het
    // bestel-advies.
    'doos_ean' => 'Doos-EAN (optioneel, alleen als dit product ook per doos geleverd wordt)',
    'eenheden_per_doos' => 'Aantal stuks per doos',
];

/**
 * Server-side download van de productfoto als base64 data-URI. Poort
 * van getFotoDataUri(): geen DriveApp/Drive-scope nodig, alleen dat het
 * bestand op "Iedereen met de link" staat -- zelfde aanpak, nu met
 * cURL i.p.v. UrlFetchApp.
 */
function getFotoDataUri(?string $url): string {
    if (!$url) return '';
    $trimmed = trim($url);
    if (!$trimmed) return '';

    $fileId = null;
    if (preg_match('#/file/d/([-\w]{20,})#', $trimmed, $m)) {
        $fileId = $m[1];
    } elseif (preg_match('#[?&]id=([-\w]{20,})#', $trimmed, $m)) {
        $fileId = $m[1];
    }

    if (!$fileId) {
        // Geen Drive-ID herkend -- ga uit van een al-directe externe URL.
        return $trimmed;
    }

    $downloadUrl = 'https://drive.google.com/uc?export=download&id=' . $fileId;
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg';
    curl_close($ch);

    if ($code !== 200 || $body === false) {
        logError('FOTO_LAAD_ERROR', $fileId, 'HTTP ' . $code . ' bij ophalen foto');
        return '';
    }

    return 'data:' . $contentType . ';base64,' . base64_encode($body);
}

/** Rangorde om de "ergste" voorraadstatus over meerdere locaties te
 * bepalen voor Product Zoeken -- zelfde volgorde/redenering als
 * beschikbaarheidStatusRang() in includes/beschikbaarheid.php, hier
 * lokaal gedupliceerd omdat dat bestand niet gerequired wordt vanuit
 * artikelen.php (zou een overbodige extra afhankelijkheid zijn voor
 * één klein rangordefunctietje). */
function productZoekenStatusRang(string $status): int {
    return match ($status) {
        'LEEG' => 3,
        'LAAG' => 2,
        'OK' => 1,
        'HOOG' => 0,
        default => 0,
    };
}

/**
 * Migratie 031_product_zoeken, op verzoek van Harm: Product Zoeken toonde
 * alleen EAN + naam -- geen voorraad, geen bestelstatus, geen categorie/
 * vervaldatum/waarde/producent, dus je moest voor die info altijd doorklikken
 * naar de productdetailpagina. Nu bevat elke rij dat direct, samengesteld uit
 * dezelfde bestaande berekeningen als elders (voorraadStatus()/voorraadLot()
 * in includes/voorraad.php) -- geen nieuwe voorraadlogica.
 */
function getProductList(): array {
    $artikelen = artikelenMap();

    // Per ean aggregeren over alle locaties: totaal stuks/waarde, een
    // per-locatie detaillijst, de "ergste" status, en de bestelstatus
    // (die is al per-ean, niet per-locatie -- zie openOrderStatusMap()
    // in includes/orders.php).
    $perEan = [];
    foreach (voorraadStatus() as $row) {
        $ean = $row['ean'];
        $perEan[$ean] ??= ['totaalStuks' => 0, 'totaalWaarde' => 0.0, 'locaties' => [], 'statusRang' => -1, 'status' => 'OK', 'orderStatus' => ''];
        $perEan[$ean]['totaalStuks'] += $row['huidig'];
        $perEan[$ean]['totaalWaarde'] += $row['waarde'];
        $perEan[$ean]['locaties'][] = ['locatie' => $row['locatie'], 'huidig' => $row['huidig']];
        $rang = productZoekenStatusRang($row['voorraadStatus']);
        if ($rang > $perEan[$ean]['statusRang']) {
            $perEan[$ean]['statusRang'] = $rang;
            $perEan[$ean]['status'] = $row['voorraadStatus'];
        }
        if (!empty($row['orderStatus'])) $perEan[$ean]['orderStatus'] = $row['orderStatus'];
    }

    // Eerstvolgende vervaldatum per ean (zelfde logica als op meerdere
    // andere plekken, o.a. includes/beschikbaarheid.php).
    $eerstvolgendeVervaldatum = [];
    foreach (voorraadLot() as $lot) {
        if ($lot['vervaldatum_sql'] === null) continue;
        $ean = $lot['ean'];
        if (!isset($eerstvolgendeVervaldatum[$ean]) || $lot['vervaldatum_sql'] < $eerstvolgendeVervaldatum[$ean]['sql']) {
            $eerstvolgendeVervaldatum[$ean] = ['sql' => $lot['vervaldatum_sql'], 'weergave' => $lot['vervaldatum']];
        }
    }

    $result = [];
    foreach ($artikelen as $eanRaw => $artikel) {
        // (string)-cast: zelfde reden als elders, zie includes/voorraad.php::voorraadStatus().
        $ean = (string) $eanRaw;
        if ($ean === '00000000000000') continue; // dummy/onbekend-placeholder, geen echt artikel

        $agg = $perEan[$ean] ?? ['totaalStuks' => 0, 'totaalWaarde' => 0.0, 'locaties' => [], 'status' => 'OK', 'orderStatus' => ''];
        $result[] = [
            'ean' => $ean,
            'naam' => $artikel['productnaam'],
            'categorie' => $artikel['categorie'] ?: '',
            'producent' => $artikel['producent'] ?: '',
            // Migratie 025: levensstatus van het artikel zelf (Actief/Niet
            // leverbaar/Niet meer in gebruik) -- puur informatief, deze
            // lijst blijft altijd alle artikelen tonen.
            'status' => $artikel['status'] ?? 'Actief',
            'voorraadStatus' => $agg['status'],
            'totaalStuks' => $agg['totaalStuks'],
            'totaalWaarde' => $agg['totaalWaarde'],
            'prijs' => (float) $artikel['prijs'],
            'locaties' => $agg['locaties'],
            'orderStatus' => $agg['orderStatus'],
            'eerstvolgendeVervaldatum' => $eerstvolgendeVervaldatum[$ean]['weergave'] ?? null,
            // Migratie 040_productfotos_uitbreiden, op verzoek van Harm
            // ("productfoto's uitbreiden waar mogelijk"): Product Zoeken
            // toont voortaan ook een duimnagelfoto per rij, zelfde
            // foto_data/foto_mime-encoding (migratie 023) als de
            // productdetailpagina. artikelenMap() haalt de hele artikelen-
            // rij al op (SELECT *), dus geen extra query nodig.
            'foto' => !empty($artikel['foto_data'])
                ? 'data:' . ($artikel['foto_mime'] ?: 'image/jpeg') . ';base64,' . base64_encode($artikel['foto_data'])
                : null,
        ];
    }

    usort($result, fn($a, $b) => strcmp($a['naam'], $b['naam']));
    return $result;
}

function getArtikelEditData(string $ean): array {
    $stmt = db()->prepare('SELECT * FROM artikelen WHERE ean = :ean');
    $stmt->execute([':ean' => normalizeEAN($ean)]);
    $artikel = $stmt->fetch();
    if (!$artikel) return ['error' => 'Product niet gevonden met EAN: ' . normalizeEAN($ean)];

    $commonFields = [];
    foreach (ARTIKEL_EDIT_FIELDS as $key => $label) {
        $commonFields[] = ['key' => $key, 'label' => $label, 'value' => $artikel[$key] ?? ''];
    }

    // Extra (door de gebruiker toegevoegde) locaties, migratie 007: zelfde
    // generieke commonFields-mechanisme als hierboven, met een
    // gesynthetiseerde sleutel extra_max_{id}. updateArtikelVeld() herkent
    // dat patroon en routeert naar artikel_locatie_extra i.p.v. een kolom
    // op artikelen (die kolom bestaat niet voor extra locaties). Hierdoor
    // hoeft het bewerk-formulier zelf (renderProductEdit in app.js) niets
    // te weten van extra locaties -- het rendert gewoon elk veld dat hier
    // in commonFields staat.
    // Migratie 031, op verzoek van Harm: geen extra_min_{id} meer -- extra
    // locaties gedragen zich als een kamer (zie includes/locaties.php),
    // dus alleen nog een maximum, geen minimum.
    foreach (getArtikelLocatieExtraOverzicht($artikel['ean']) as $loc) {
        $commonFields[] = ['key' => 'extra_max_' . $loc['locatie_extra_id'], 'label' => 'Max ' . $loc['locatie_naam'], 'value' => (string) $loc['max_aantal']];
    }

    $specs = $artikel['specs'] ? json_decode($artikel['specs'], true) : [];
    $specFields = [];
    foreach (specLabels($artikel['categorie'] ?? '') as $key => $label) {
        $specFields[] = ['key' => $key, 'label' => $label, 'value' => $specs[$key] ?? ''];
    }

    // Migratie 025, op verzoek van Harm: status ('Actief'/'Niet leverbaar'/
    // 'Niet meer in gebruik') + optionele vervanger-koppeling, alleen
    // betekenisvol bij 'Niet leverbaar'. mogelijkeVervangers is de lijst
    // waaruit renderProductEdit() de vervanger-select vult -- zichzelf en
    // reeds gedeactiveerde artikelen ('Niet meer in gebruik') worden hier
    // uitgesloten, want een uitgefaseerd artikel als vervanger aanwijzen
    // voor iets anders zou zelf weer een vervanger nodig hebben.
    $vervangendArtikelId = $artikel['vervangend_artikel_id'] !== null ? (int) $artikel['vervangend_artikel_id'] : null;
    $vervangendArtikelNaam = null;
    $vervangendArtikelEan = null;
    if ($vervangendArtikelId !== null) {
        $stmt = db()->prepare('SELECT productnaam, ean FROM artikelen WHERE id = :id');
        $stmt->execute([':id' => $vervangendArtikelId]);
        $vervanger = $stmt->fetch();
        if ($vervanger) {
            $vervangendArtikelNaam = $vervanger['productnaam'];
            $vervangendArtikelEan = $vervanger['ean'];
        }
    }
    $mogelijkeVervangers = array_values(array_filter(
        array_map(fn($r) => ['id' => (int) $r['id'], 'ean' => $r['ean'], 'naam' => $r['productnaam']],
            db()->query("SELECT id, ean, productnaam FROM artikelen WHERE status != 'Niet meer in gebruik' ORDER BY productnaam")->fetchAll()),
        fn($r) => $r['id'] !== (int) $artikel['id']
    ));

    return [
        'ean' => $artikel['ean'], 'naam' => $artikel['productnaam'],
        'categorie' => $artikel['categorie'] ?? '',
        'commonFields' => $commonFields, 'specFields' => $specFields,
        'status' => $artikel['status'] ?? 'Actief',
        'vervangendArtikelId' => $vervangendArtikelId,
        'vervangendArtikelNaam' => $vervangendArtikelNaam,
        'vervangendArtikelEan' => $vervangendArtikelEan,
        'mogelijkeVervangers' => $mogelijkeVervangers,
        // Migratie 023: in-database productfoto, apart van de
        // foto_link-tekstveld in commonFields hierboven -- de frontend
        // toont dit als voorbeeld met een eigen upload/verwijder-knop
        // (zelfde patroon als users/producent_contacten, maar dan
        // opgeslagen als blob i.p.v. bestandspad).
        'fotoData' => !empty($artikel['foto_data'])
            ? 'data:' . ($artikel['foto_mime'] ?: 'image/jpeg') . ';base64,' . base64_encode($artikel['foto_data'])
            : null,
    ];
}

const ARTIKEL_STATUSSEN = ['Actief', 'Niet leverbaar', 'Niet meer in gebruik'];

/**
 * Wijzigt de status van een artikel (migratie 025, op verzoek van Harm),
 * met optionele vervanger-koppeling. vervangendArtikelId wordt alleen
 * bewaard als de nieuwe status 'Niet leverbaar' is -- bij elke andere
 * status wordt het veld hard teruggezet naar NULL, zodat een oude
 * vervanger-koppeling niet blijft hangen nadat een artikel weer Actief is
 * gezet of juist blijvend Niet meer in gebruik is geworden (in beide
 * gevallen is de koppeling niet meer betekenisvol).
 */
function setArtikelStatus(string $ean, string $status, ?int $vervangendArtikelId, ?string $gebruikerNaam = null): array {
    if (!in_array($status, ARTIKEL_STATUSSEN, true)) {
        return ['success' => false, 'error' => 'Ongeldige status'];
    }

    try {
        $eanNorm = normalizeEAN($ean);
        $stmt = db()->prepare('SELECT id FROM artikelen WHERE ean = :ean');
        $stmt->execute([':ean' => $eanNorm]);
        $artikel = $stmt->fetch();
        if (!$artikel) return ['success' => false, 'error' => 'Product niet gevonden met EAN: ' . $eanNorm];
        $id = (int) $artikel['id'];

        $vervanger = null;
        if ($status === 'Niet leverbaar' && $vervangendArtikelId !== null && $vervangendArtikelId > 0) {
            if ($vervangendArtikelId === $id) {
                return ['success' => false, 'error' => 'Een artikel kan niet zijn eigen vervanger zijn'];
            }
            $stmt = db()->prepare('SELECT id FROM artikelen WHERE id = :id');
            $stmt->execute([':id' => $vervangendArtikelId]);
            if (!$stmt->fetch()) return ['success' => false, 'error' => 'Vervangend artikel niet gevonden'];
            $vervanger = $vervangendArtikelId;
        }

        db()->prepare('UPDATE artikelen SET status = :status, vervangend_artikel_id = :vervanger WHERE id = :id')
            ->execute([':status' => $status, ':vervanger' => $vervanger, ':id' => $id]);

        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'artikel_status_gewijzigd', 'artikel', $eanNorm, "status=$status" . ($vervanger ? ", vervanger_id=$vervanger" : ''));
        }

        return ['success' => true, 'message' => 'Status bijgewerkt'];
    } catch (Throwable $e) {
        logError('ARTIKEL_STATUS_ERROR', $ean, $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Koppelt een net aangemaakt artikel als vervanger aan een ander,
 * bestaand artikel, en zet dat originele artikel meteen op 'Niet
 * leverbaar'. Gebruikt door api/artikel_toevoegen.php wanneer een nieuw
 * artikel specifiek is aangemaakt als vervanger (de "Nieuw artikel
 * aanmaken als vervanger"-knop op het bewerkscherm, zie
 * renderProductEdit()/showArtikelToevoegen() in assets/js/app.js) --
 * scheelt de frontend een aparte tweede opslag-stap na het aanmaken.
 */
function koppelAlsVervanger(string $origineelEan, string $nieuwEan, ?string $gebruikerNaam = null): array {
    $stmt = db()->prepare('SELECT id FROM artikelen WHERE ean = :ean');
    $stmt->execute([':ean' => normalizeEAN($nieuwEan)]);
    $nieuwId = $stmt->fetchColumn();
    if (!$nieuwId) return ['success' => false, 'error' => 'Nieuw aangemaakt artikel niet teruggevonden'];

    return setArtikelStatus($origineelEan, 'Niet leverbaar', (int) $nieuwId, $gebruikerNaam);
}

// Tabellen die de EAN van een artikel als PLATTE WAARDE bevatten (geen FK
// naar artikelen.id) en dus mee moeten wanneer een EAN wijzigt -- zie
// wijzigArtikelEan() hieronder. onbekende_scans hoort hier bewust NIET
// bij: die rijen gaan over scans die nog NERGENS bij een artikel horen
// (per definitie een ander, nog onbekend EAN), dus die hebben niets te
// maken met het wijzigen van de EAN van een artikel dat al wel bestaat.
const ARTIKEL_EAN_CASCADE_TABELLEN = [
    'transactielog', 'orders', 'eenmalige_bestellingen',
    'artikel_locatie_extra', 'minmax_advies_genegeerd',
];

/**
 * Wijzigt de EAN van een bestaand artikel (migratie 029, op verzoek van
 * Harm: "ik wil daadwerkelijk alles kunnen bewerken, ook EAN/barcode").
 *
 * Dit is BEWUST geen gewoon veld in ARTIKEL_EDIT_FIELDS/updateArtikelVeld()
 * hierboven: de EAN is niet alleen een kolom op artikelen, het is ook de
 * sleutel waarop transactielog (de enige bron van waarheid voor
 * voorraadaantallen, zie includes/voorraad.php), orders,
 * eenmalige_bestellingen, artikel_locatie_extra en
 * minmax_advies_genegeerd hun rijen aan DIT artikel koppelen -- allemaal
 * als platte tekstwaarde, geen foreign key naar artikelen.id. Een simpele
 * "UPDATE artikelen SET ean = ..." zou al die bestaande rijen wees maken
 * (ze zouden ineens bij geen enkel artikel meer horen), en daarmee in de
 * praktijk de hele voorraadhistorie van dit product laten verdwijnen.
 *
 * Daarom: één DB-transactie die de nieuwe EAN naar artikelen EN naar elke
 * tabel in ARTIKEL_EAN_CASCADE_TABELLEN hierboven wegschrijft, of anders
 * volledig terugdraait. Een uniekheidscheck vooraf voorkomt dat twee
 * artikelen straks dezelfde EAN delen (artikelen.ean is UNIQUE, maar die
 * DB-constraint alleen zou hier midden in de transactie pas af breken,
 * ná de cascade-updates al gedeeltelijk te hebben uitgevoerd).
 */
function wijzigArtikelEan(string $huidigeEan, string $nieuweEanRuw, ?string $gebruikerNaam = null): array {
    try {
        $pdo = db();
        $huidigeEanNorm = normalizeEAN($huidigeEan);
        $nieuweEanNorm = normalizeEAN($nieuweEanRuw);

        if ($nieuweEanNorm === $huidigeEanNorm) {
            return ['success' => true, 'message' => 'EAN ongewijzigd', 'ean' => $nieuweEanNorm];
        }

        $stmt = $pdo->prepare('SELECT id FROM artikelen WHERE ean = :ean');
        $stmt->execute([':ean' => $huidigeEanNorm]);
        $artikel = $stmt->fetch();
        if (!$artikel) return ['success' => false, 'error' => 'Product niet gevonden met EAN: ' . $huidigeEanNorm];

        $stmt = $pdo->prepare('SELECT id FROM artikelen WHERE ean = :ean');
        $stmt->execute([':ean' => $nieuweEanNorm]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Deze EAN is al in gebruik bij een ander artikel: ' . $nieuweEanNorm];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE artikelen SET ean = :nieuw WHERE ean = :oud')
                ->execute([':nieuw' => $nieuweEanNorm, ':oud' => $huidigeEanNorm]);

            foreach (ARTIKEL_EAN_CASCADE_TABELLEN as $tabel) {
                $pdo->prepare("UPDATE $tabel SET ean = :nieuw WHERE ean = :oud")
                    ->execute([':nieuw' => $nieuweEanNorm, ':oud' => $huidigeEanNorm]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'artikel_ean_gewijzigd', 'artikel', $nieuweEanNorm, "was=$huidigeEanNorm");
        }

        return ['success' => true, 'message' => 'EAN gewijzigd', 'ean' => $nieuweEanNorm];
    } catch (Throwable $e) {
        logError('ARTIKEL_EAN_WIJZIGEN_ERROR', $huidigeEan, $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Slaat een productfoto rechtstreeks in de database op (migratie 023, op
 * uitdrukkelijk verzoek van Harm: "deze foto moet gewoon in de database
 * worden opgeslagen" -- een bewust ander patroon dan de op-schijf-opslag
 * voor gebruikers/producent-contactpersonen, zie includes/upload.php).
 * Hergebruikt decodeFotoDataUrl() voor dezelfde MIME/echte-afbeelding-
 * validatie als die op-schijf-route -- maar met PRODUCT_FOTO_MAX_BYTES
 * i.p.v. de kleinere avatar-grens (FOTO_MAX_BYTES), op uitdrukkelijk
 * verzoek van Harm: productfoto's mogen geen kunstmatige maximale
 * grootte hebben (zie includes/upload.php).
 */
function uploadArtikelFoto(string $ean, string $dataUrl, ?string $gebruikerNaam = null): array {
    try {
        $eanNorm = normalizeEAN($ean);
        $stmt = db()->prepare('SELECT id FROM artikelen WHERE ean = :ean');
        $stmt->execute([':ean' => $eanNorm]);
        $artikel = $stmt->fetch();
        if (!$artikel) return ['success' => false, 'error' => 'Product niet gevonden met EAN: ' . $eanNorm];

        $decoded = decodeFotoDataUrl($dataUrl, PRODUCT_FOTO_MAX_BYTES);

        db()->prepare('UPDATE artikelen SET foto_data = :data, foto_mime = :mime WHERE id = :id')
            ->execute([':data' => $decoded['bytes'], ':mime' => $decoded['mime'], ':id' => $artikel['id']]);

        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'product_foto_bijgewerkt', 'artikel', $eanNorm, '');
        }

        return ['success' => true, 'message' => 'Foto opgeslagen'];
    } catch (Throwable $e) {
        logError('PRODUCT_FOTO_ERROR', $ean, $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/** Verwijdert de in-database productfoto (migratie 023). Laat foto_link ongemoeid. */
function verwijderArtikelFoto(string $ean, ?string $gebruikerNaam = null): array {
    try {
        $eanNorm = normalizeEAN($ean);
        $stmt = db()->prepare('SELECT id FROM artikelen WHERE ean = :ean');
        $stmt->execute([':ean' => $eanNorm]);
        $artikel = $stmt->fetch();
        if (!$artikel) return ['success' => false, 'error' => 'Product niet gevonden met EAN: ' . $eanNorm];

        db()->prepare('UPDATE artikelen SET foto_data = NULL, foto_mime = NULL WHERE id = :id')
            ->execute([':id' => $artikel['id']]);

        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'product_foto_verwijderd', 'artikel', $eanNorm, '');
        }

        return ['success' => true, 'message' => 'Foto verwijderd'];
    } catch (Throwable $e) {
        logError('PRODUCT_FOTO_ERROR', $ean, $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * $updates: assoc array key -> nieuwe waarde, uit JSON-body. Alleen
 * sleutels uit de whitelist (ARTIKEL_EDIT_FIELDS + categorie-specifieke
 * velden van dit artikel) worden weggeschreven; onbekende sleutels
 * worden genegeerd i.p.v. een fout te geven. Poort van updateArtikelVeld().
 */
function updateArtikelVeld(string $ean, array $updates, ?string $gebruikerNaam = null): array {
    try {
        $pdo = db();
        $eanNorm = normalizeEAN($ean);

        $stmt = $pdo->prepare('SELECT id, categorie, specs FROM artikelen WHERE ean = :ean');
        $stmt->execute([':ean' => $eanNorm]);
        $artikel = $stmt->fetch();
        if (!$artikel) return ['success' => false, 'error' => 'Product niet gevonden met EAN: ' . $eanNorm];

        $specKeys = array_keys(specLabels($artikel['categorie'] ?? ''));
        $specs = $artikel['specs'] ? json_decode($artikel['specs'], true) : [];

        $setClauses = [];
        $params = [':id' => $artikel['id']];
        $specsChanged = false;
        $written = 0;
        // locatieExtraId => max -- verzameld tijdens de lus, pas na de lus
        // in artikel_locatie_extra weggeschreven. Migratie 031, op verzoek
        // van Harm: geen 'min' meer -- extra locaties gedragen zich als een
        // kamer (zie includes/locaties.php), alleen nog een maximum.
        $extraLocatieUpdates = [];

        foreach ($updates as $key => $value) {
            // doos_ean (migratie 020) moet op dezelfde manier genormaliseerd
            // worden als artikelen.ean zelf (zie ean.php), anders matcht een
            // gescande doos-barcode 'm straks niet in lookupArtikelen().
            // Leeg = geen doosverpakking (kolom terug naar NULL).
            if ($key === 'doos_ean') {
                $value = ($value !== null && trim((string) $value) !== '') ? normalizeEAN((string) $value) : null;
            } elseif ($key === 'eenheden_per_doos') {
                // < 2 is geen zinvolle doosgrootte ("doos van 1" is gewoon
                // het stuk zelf) -- dan liever NULL dan een misleidend getal.
                $value = ($value !== null && trim((string) $value) !== '' && (int) $value >= 2) ? (int) $value : null;
            } elseif ($key === 'logistiek_code') {
                // Leeg -> NULL (niet lege string): de UNIQUE-kolom staat wel
                // meerdere NULLs toe, maar niet meerdere lege strings.
                $value = ($value !== null && trim((string) $value) !== '') ? trim((string) $value) : null;
            }
            if (isset(ARTIKEL_EDIT_FIELDS[$key])) {
                $setClauses[] = "$key = :$key";
                $params[":$key"] = $value;
                $written++;
            } elseif (in_array($key, $specKeys, true)) {
                $specs[$key] = $value;
                $specsChanged = true;
                $written++;
            } elseif (preg_match('/^extra_max_(\d+)$/', (string) $key, $m)) {
                $extraLocatieUpdates[(int) $m[1]] = max(0, (int) $value);
                $written++;
            }
        }

        if ($written === 0) return ['success' => false, 'error' => 'Geen geldige velden om op te slaan'];

        if ($specsChanged) {
            $setClauses[] = 'specs = :specs';
            $params[':specs'] = json_encode($specs, JSON_UNESCAPED_UNICODE);
        }

        if (!empty($setClauses)) {
            $sql = 'UPDATE artikelen SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
        }

        foreach ($extraLocatieUpdates as $locatieExtraId => $max) {
            $bestaandStmt = $pdo->prepare(
                'SELECT kastlocatie FROM artikel_locatie_extra WHERE ean = :ean AND locatie_extra_id = :loc'
            );
            $bestaandStmt->execute([':ean' => $eanNorm, ':loc' => $locatieExtraId]);
            $bestaand = $bestaandStmt->fetch();

            setArtikelLocatieExtra($eanNorm, $locatieExtraId, $max, $bestaand['kastlocatie'] ?? null);
        }

        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'product_bewerkt', 'artikel', $eanNorm, implode(',', array_keys($updates)));
        }

        // Bugfix, op verzoek van Harm: zelfde "direct in Producenten-lijst"
        // fix als addArtikel() hierboven, nu ook voor het bewerkscherm --
        // zie includes/producenten.php::vindOfMaakProducent().
        if (array_key_exists('producent', $updates)) {
            vindOfMaakProducent($updates['producent'], $gebruikerNaam);
        }

        // Migratie 037, op verzoek van Harm: dit artikel kan een tijdelijk
        // aangemaakt product zijn (zie maakArtikelenVoorOnbekendeRegels()
        // in includes/transacties.php, aangemaakt met de omschrijving die
        // een laborant tijdens het scannen invulde) -- een beheerder die
        // het hier daadwerkelijk bewerkt (juiste naam/categorie/prijs/etc.)
        // rondt dat af, dus verdwijnt het van "Onbekende Producten". No-op
        // (0 rijen bijgewerkt) als dit een gewoon, al compleet artikel was.
        markeerOnbekendeScanVerwerkt($eanNorm);

        return ['success' => true, 'message' => $written . ' veld(en) opgeslagen'];
    } catch (Throwable $e) {
        logError('PRODUCT_EDIT_ERROR', $ean, $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Migratie 044_productpagina_verbruiksgrafiek, op verzoek van Harm
 * ("Productpagina: grafiek historisch verbruik"): verbruik (transactietype
 * 'Verbruik') van dit ene EAN, gegroepeerd per kalendermaand -- zelfde
 * PHP-side DateTime->format('Y-m')-bucketing als getVerbruiksTrends()
 * (includes/dashboards.php, de bron van het bestaande "Verbruik & Trends"-
 * dashboard), maar dan voor 1 product i.p.v. alle producten samen.
 *
 * In tegenstelling tot getVerbruiksTrends() (dat alleen maanden toont
 * waarin ÜBERHAUPT iets gebeurde) geeft deze functie ALTIJD een
 * aaneengesloten reeks van de laatste $maanden kalendermaanden terug,
 * inclusief maanden zonder verbruik (hoeveelheid 0) -- voor een grafiek
 * met een gelijkmatige tijdas is dat belangrijker dan lege maanden
 * overslaan (anders zou de x-as van de grafiek onregelmatig ogen).
 *
 * @return list<array{maand:string, hoeveelheid:int}> oudste maand eerst.
 */
function getProductVerbruikPerMaand(string $ean, int $maanden = 12): array {
    $stmt = db()->prepare(
        "SELECT tijdstempel, hoeveelheid FROM transactielog
         WHERE ean = :ean AND transactietype = 'Verbruik'"
    );
    $stmt->execute([':ean' => $ean]);

    $perMaand = [];
    foreach ($stmt->fetchAll() as $r) {
        $maand = (new DateTime($r['tijdstempel']))->format('Y-m');
        $perMaand[$maand] = ($perMaand[$maand] ?? 0) + (int) $r['hoeveelheid'];
    }

    $resultaat = [];
    $eersteVanDezeMaand = new DateTime('first day of this month');
    for ($i = $maanden - 1; $i >= 0; $i--) {
        $key = (clone $eersteVanDezeMaand)->modify("-$i months")->format('Y-m');
        $resultaat[] = ['maand' => $key, 'hoeveelheid' => $perMaand[$key] ?? 0];
    }
    return $resultaat;
}

/**
 * Volledig productdetail: stamdata, voorraad per locatie, loten,
 * laatste 20 transacties, verbruik per specialist. Poort van
 * getProductDetail().
 */
function getProductDetail(string $ean): array {
    $eanNorm = normalizeEAN($ean);
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM artikelen WHERE ean = :ean');
    $stmt->execute([':ean' => $eanNorm]);
    $artikel = $stmt->fetch();
    if (!$artikel) return ['error' => 'Product niet gevonden met EAN: ' . $eanNorm];

    $prijs = (float) $artikel['prijs'];
    $orderStatusMap = openOrderStatusMap();

    // Migratie 025, op verzoek van Harm: status + vervanger-info voor de
    // banner bovenaan de productpagina (zie renderProductDetail() in
    // assets/js/app.js). De vervanger wordt hier alleen als naam+EAN
    // (voor de doorklik-link) meegegeven -- het BEWERKEN van de status/
    // vervanger zelf gebeurt op het edit-scherm (getArtikelEditData()
    // hierboven, met de volledige keuzelijst).
    $vervangendArtikelNaam = null;
    $vervangendArtikelEan = null;
    if (!empty($artikel['vervangend_artikel_id'])) {
        $stmt = $pdo->prepare('SELECT productnaam, ean FROM artikelen WHERE id = :id');
        $stmt->execute([':id' => (int) $artikel['vervangend_artikel_id']]);
        $vervanger = $stmt->fetch();
        if ($vervanger) {
            $vervangendArtikelNaam = $vervanger['productnaam'];
            $vervangendArtikelEan = $vervanger['ean'];
        }
    }

    $result = [
        'ean' => $eanNorm, 'naam' => $artikel['productnaam'],
        'status' => $artikel['status'] ?? 'Actief',
        'vervangendArtikelNaam' => $vervangendArtikelNaam,
        'vervangendArtikelEan' => $vervangendArtikelEan,
        // Migratie 020: alleen gezet als dit product ook per doos wordt
        // geleverd, zie ARTIKEL_EDIT_FIELDS hierboven.
        'doosEan' => $artikel['doos_ean'] ?? null,
        'eenhedenPerDoos' => !empty($artikel['eenheden_per_doos']) ? (int) $artikel['eenheden_per_doos'] : null,
        // Migratie 023: als er een in-database opgeslagen foto is (op
        // uitdrukkelijk verzoek van Harm, i.p.v. alleen een link), krijgt
        // die voorrang boven foto_link. foto_link blijft de fallback voor
        // producten die alleen nog een Drive-link hebben.
        'foto' => !empty($artikel['foto_data'])
            ? 'data:' . ($artikel['foto_mime'] ?: 'image/jpeg') . ';base64,' . base64_encode($artikel['foto_data'])
            : getFotoDataUri($artikel['foto_link']),
        'categorie' => $artikel['categorie'] ?? '',
        'besteltype' => $artikel['besteltype'],
        'producent' => $artikel['producent'] ?? '',
        // vindProducentVoorNaam() (includes/producenten.php, migratie 012):
        // koppelt op naam (case-insensitief) aan een producent met
        // contactgegevens, als die er is -- artikelen.producent zelf blijft
        // gewoon vrije tekst, dit is puur een optionele verrijking voor de
        // "contactgegevens bekijken"-link op de productpagina.
        'producentInfo' => vindProducentVoorNaam($artikel['producent'] ?? null),
        'artikelRef' => $artikel['artikelref'] ?? '',
        'logistiekCode' => $artikel['logistiek_code'] ?? '',
        'gs1Raw' => $artikel['gs1_raw'] ?? '',
        'prijs' => $prijs,
        'orderStatus' => $orderStatusMap[$eanNorm] ?? '',
        'categorieSpecs' => getCategorieSpecs($artikel['categorie'] ?? '', $artikel['specs']),
        'voorraad_per_locatie' => [],
        'totaalWaarde' => 0.0,
        'loten' => [],
        'transacties' => [],
        'verbruik_stats' => [],
        'bestelhistorie' => [],
    ];

    // Migratie 026, op verzoek van Harm: het Min/Max Advies-overzicht zelf
    // verbergt no-op- en recent genegeerde voorstellen (beheersbaarheid),
    // maar hier op de productdetailpagina wil hij het voorstel per locatie
    // ALTIJD blijven zien, ongeacht dat -- vandaar $inclusiefAlles=true.
    // Eén lookup per locatie i.p.v. per rij opnieuw filteren.
    $minmaxAdviesPerLocatie = [];
    foreach (getMinMaxAdviesOverzicht($eanNorm, true) as $advies) {
        $minmaxAdviesPerLocatie[$advies['locatie']] = $advies;
    }

    foreach (voorraadStatus() as $row) {
        if ($row['ean'] !== $eanNorm) continue;
        $result['totaalWaarde'] += $row['waarde'];
        $advies = $minmaxAdviesPerLocatie[$row['locatie']] ?? null;
        $result['voorraad_per_locatie'][] = [
            'locatie' => $row['locatie'], 'huidig' => $row['huidig'], 'min' => $row['min'],
            'max' => $row['max'], 'status' => $row['voorraadStatus'],
            'kastlocatie' => $row['kastlocatie'], 'waarde' => $row['waarde'],
            // Migratie 031, op verzoek van Harm: kamers hebben geen eigen
            // minimum meer (altijd 0, geen aparte kolom) -- de frontend
            // gebruikt deze vlag om 'min'/'adviesMin' voor kamers als "n.v.t."
            // te tonen i.p.v. een misleidende 0.
            'isBerging' => locatieSlotVoorNaam($row['locatie']) === 'berging',
            'minmaxAdvies' => $advies === null ? null : [
                'status' => $advies['status'],
                'adviesMin' => $advies['adviesMin'] ?? null,
                'adviesMax' => $advies['adviesMax'] ?? null,
            ],
        ];
    }

    foreach (voorraadLot() as $lot) {
        if ($lot['ean'] !== $eanNorm) continue;
        $result['loten'][] = [
            'lot' => $lot['lot'], 'vervaldatum' => $lot['vervaldatum'], 'locatie' => $lot['locatie'],
            'huidig' => $lot['huidig'], 'status' => $lot['status'],
            // Migratie 035, op verzoek van Harm: barcode zichtbaar maken +
            // exacte (SQL-vorm) vervaldatum voor het "Bewerken"-formulier
            // op de productdetailpagina (zie includes/lot_correcties.php
            // en renderProductDetail() in assets/js/app.js).
            'vervaldatumSql' => $lot['vervaldatum_sql'],
            'gs1Raw' => $lot['gs1_raw'],
        ];
    }

    // Migratie 043_loten_herindelen, op verzoek van Harm ("loten op
    // voorraad herindelen per lot"): voorraadLot() heeft zelf GEEN
    // ORDER BY (zie de toelichting daar -- puur een GROUP BY-aggregatie),
    // dus als hetzelfde lotnummer op meerdere locaties tegelijk voorkomt
    // (bijv. 20 stuks net verplaatst van Berging naar een kamer, dus
    // tijdelijk nog op beide), stonden die rijen hier door elkaar i.p.v.
    // gegroepeerd. Nu eerst op lotnummer (natuurlijke sortering, zodat
    // "Lot 2" niet na "Lot 10" komt), dan op locatie -- zo staan alle
    // rijen van hetzelfde lot altijd bij elkaar, ongeacht in welke
    // volgorde de database ze toevallig teruggaf.
    usort($result['loten'], function (array $a, array $b): int {
        $lotVergelijking = strnatcasecmp((string) ($a['lot'] ?? ''), (string) ($b['lot'] ?? ''));
        if ($lotVergelijking !== 0) return $lotVergelijking;
        return strnatcasecmp((string) $a['locatie'], (string) $b['locatie']);
    });

    // aangemaakt_door (migratie 010): naam van de ingelogde gebruiker die
    // de scan deed -- apart van 'specialist' (voor wie de procedure was),
    // zie includes/transacties.php::verwerkScan(). Op verzoek van Harm nu
    // ook zichtbaar op de productpagina, niet alleen in Recent Transactions.
    // Migratie 042_transactiehistorie_scanmoment, op verzoek van Harm
    // ("transactiehistorie groeperen per scanmoment"): stond hier voorheen
    // als platte lijst van losse transactielog-regels -- een scan die
    // meerdere lots in één actie boekte (bijv. Verbruik of Verplaatsing van
    // 3 verschillende batchcodes tegelijk) toonde dus 3 losse rijen voor
    // wat feitelijk één scanmoment was. getTransactieHistorieVoorEanGeclusterd()
    // (includes/transacties.php) groepeert die nu per batch_id, zelfde
    // aanpak als getTransactiesRondOrderGeclusterd() (Order detail) en
    // getRecenteTransactiesGegroepeerd() (Recente Transacties).
    $result['transacties'] = getTransactieHistorieVoorEanGeclusterd($eanNorm, 20);

    // Op verzoek van Harm: totalen per specialist, niet meer uitgesplitst
    // per procedure (dat uitgesplitste overzicht staat al op het
    // Verbruik per Procedure-dashboard; hier is juist "hoeveel heeft deze
    // specialist in totaal van dit product verbruikt" de vraag).
    $stmt = $pdo->prepare(
        "SELECT specialist, SUM(hoeveelheid) AS total
         FROM transactielog WHERE ean = :ean AND transactietype = 'Verbruik'
         GROUP BY specialist
         ORDER BY total DESC"
    );
    $stmt->execute([':ean' => $eanNorm]);
    // Migratie 039, op verzoek van Harm (voorbereiding gebruikersnaam
    // bewerkbaar maken, zie includes/hernoemingen.php): de GROUP BY
    // hierboven groepeert nog op de RUWE, ten tijde van elke transactie
    // opgeslagen specialistnaam -- na een naamswijziging zou dit overzicht
    // anders in twee losse rijen splitsen. actueleNaam() lost dat op naar
    // de huidige naam en de totalen worden hier samengevoegd (zelfde
    // patroon als getMeesteProcedures() in includes/leaderboard.php).
    $verbruikPerSpecialist = [];
    foreach ($stmt->fetchAll() as $v) {
        $naam = actueleNaam('specialist', $v['specialist']);
        $verbruikPerSpecialist[$naam] = ($verbruikPerSpecialist[$naam] ?? 0) + (int) $v['total'];
    }
    arsort($verbruikPerSpecialist);
    foreach ($verbruikPerSpecialist as $naam => $total) {
        $result['verbruik_stats'][] = ['specialist' => $naam, 'total' => $total];
    }

    // Migratie 044_productpagina_verbruiksgrafiek, op verzoek van Harm
    // ("Productpagina: grafiek historisch verbruik"): maandelijks verbruik
    // van dit ene product, voor de nieuwe grafiek op de productdetailpagina
    // -- zie getProductVerbruikPerMaand() hieronder.
    $result['verbruikPerMaand'] = getProductVerbruikPerMaand($eanNorm);

    // Bestelhistorie (op verzoek van Harm): ALLE orders ooit voor dit EAN,
    // niet alleen de openstaande (zoals includes/orders.php::getOpenOrders()
    // dat voor het Open Orders-dashboard doet) -- hier is juist de
    // volledige geschiedenis nuttig, inclusief al lang geleden volledig
    // ontvangen orders.
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE ean = :ean ORDER BY tijdstempel DESC');
    $stmt->execute([':ean' => $eanNorm]);
    foreach ($stmt->fetchAll() as $o) {
        $besteld = (int) $o['hoeveelheid_besteld'];
        $ontvangen = (int) $o['hoeveelheid_ontvangen'];
        $result['bestelhistorie'][] = [
            // Op verzoek van Harm: id meesturen zodat elke rij op de
            // productpagina doorklikbaar is naar de bestaande order-
            // detailpagina (showOrderDetail(), zie assets/js/app.js) --
            // voorheen alleen platte tekst, dus geen manier om de volledige
            // orderdetails (transacties, opmerking-volledig, etc.) in te
            // zien zonder eerst zelf naar Bestellingen te navigeren en de
            // juiste rij terug te zoeken.
            'id' => (int) $o['id'],
            'datum' => $o['tijdstempel'], 'besteld' => $besteld, 'ontvangen' => $ontvangen,
            'nog' => $besteld - $ontvangen, 'status' => orderStatus($besteld, $ontvangen),
            'besteller' => $o['besteller'] ?: '', 'kanaal' => $o['kanaal'] ?: '', 'opmerking' => $o['opmerking'] ?: '',
        ];
    }

    return $result;
}

/**
 * Nieuw artikel toevoegen. Poort van onFormSubmit_Artikelen +
 * onEdit(Artikelen kolom C) uit Code.gs: GS1 wordt hier geparsed op
 * het moment van opslaan i.p.v. bij een los celbewerkings-triggermoment
 * (migratieplan hoofdstuk 6).
 *
 * $data verwacht: productnaam, categorie, besteltype, gs1_raw (optioneel,
 * voor EAN) OF ean rechtstreeks, plus optioneel de overige
 * ARTIKEL_EDIT_FIELDS-sleutels en categorie-specifieke specs-sleutels.
 *
 * Bugfix op verzoek van Harm: GS1 AI(240) ("interne artikelreferentie")
 * werd hier eerder automatisch overgenomen in artikelref ("Artikelnummer"),
 * en had daarbij zelfs voorrang boven een handmatig ingevuld artikelref.
 * Dat klopt niet met hoe Harm dit veld gebruikt: het is bij hem geen
 * fabrikantcode uit de barcode, maar een eigen nummer dat hij zelf
 * toekent/invult, dus mag nooit automatisch (of stilzwijgend overschreven)
 * uit een scan komen. parseGS1Raw() blijft AI(240) nog steeds parsen (voor
 * eventueel toekomstig gebruik elders), maar de waarde wordt hier niet meer
 * gebruikt voor artikelref.
 */
function addArtikel(array $data, ?string $gebruikerNaam = null): array {
    $productnaam = trim($data['productnaam'] ?? '');
    if ($productnaam === '') return ['success' => false, 'error' => 'Productnaam is verplicht'];

    $ean = '';
    if (!empty($data['gs1_raw'])) {
        $parsed = parseGS1Raw($data['gs1_raw']);
        $ean = $parsed['ean'];
    }
    if (!$ean && !empty($data['ean'])) {
        $ean = normalizeEAN($data['ean']);
    }
    if (!$ean) return ['success' => false, 'error' => 'Geen geldige EAN (via GS1-scan of handmatig) opgegeven'];
    $ean = normalizeEAN($ean);

    $categorie = $data['categorie'] ?? '';
    $specKeys = array_keys(specLabels($categorie));
    $specs = [];
    foreach ($specKeys as $key) {
        if (isset($data[$key]) && $data[$key] !== '') $specs[$key] = $data[$key];
    }

    // Doosverpakking (migratie 020, optioneel): alleen invullen als dit
    // product ook per doos geleverd wordt -- zie ARTIKEL_EDIT_FIELDS
    // hierboven voor de volledige toelichting. doos_ean genormaliseerd op
    // dezelfde manier als het stuk-EAN zelf; eenheden_per_doos < 2 telt
    // als "geen doosverpakking" (NULL).
    // Migratie 026 (op verzoek van Harm): de doosverpakking is ook meestal
    // een GS1-barcode -- dus mag ook via een eigen GS1-scan (doos_gs1_raw)
    // worden ingevoerd, met dezelfde GS1-voorrang-boven-handmatig-EAN-
    // logica als het stuk-EAN hierboven. Geen van beide dwingt de ander
    // af: leeg laten (geen doosverpakking) blijft mogelijk, en een
    // handmatig ingevoerde doos-EAN blijft werken als er geen doos-GS1
    // gescand is.
    $doosEan = null;
    if (!empty($data['doos_gs1_raw'])) {
        $doosParsed = parseGS1Raw($data['doos_gs1_raw']);
        if ($doosParsed['ean'] !== '') $doosEan = normalizeEAN($doosParsed['ean']);
    }
    if ($doosEan === null && !empty($data['doos_ean'])) {
        $doosEan = normalizeEAN((string) $data['doos_ean']);
    }
    $eenhedenPerDoos = (!empty($data['eenheden_per_doos']) && (int) $data['eenheden_per_doos'] >= 2)
        ? (int) $data['eenheden_per_doos'] : null;
    // Migratie 021: handmatige interne code, geen normalisatie (vrije tekst).
    $logistiekCode = !empty($data['logistiek_code']) ? trim((string) $data['logistiek_code']) : null;

    try {
        // Migratie 031, op verzoek van Harm: min_ik/min_hybok/min_kar
        // bestaan niet meer (kamers hebben geen eigen minimum meer, zie
        // artikelMinMax() in includes/voorraad.php) -- alleen min_berging
        // blijft een echt reorder-minimum.
        db()->prepare(
            'INSERT INTO artikelen
                (ean, doos_ean, eenheden_per_doos, productnaam, gs1_raw, foto_link, categorie, besteltype,
                 max_ik, max_hybok, max_kar, min_berging, max_berging,
                 locatie_ik, locatie_berging, locatie_hybok, artikelref, logistiek_code, producent, prijs, specs)
             VALUES
                (:ean, :doos_ean, :eenheden_per_doos, :naam, :gs1_raw, :foto, :cat, :besteltype,
                 :max_ik, :max_hybok, :max_kar, :min_berging, :max_berging,
                 :loc_ik, :loc_berging, :loc_hybok, :artikelref, :logistiek_code, :producent, :prijs, :specs)'
        )->execute([
            ':ean' => $ean, ':doos_ean' => $doosEan, ':eenheden_per_doos' => $eenhedenPerDoos,
            ':naam' => $productnaam, ':gs1_raw' => $data['gs1_raw'] ?? null,
            ':foto' => $data['foto_link'] ?? null, ':cat' => $categorie,
            ':besteltype' => $data['besteltype'] ?? 'Automatisch',
            ':max_ik' => (int) ($data['max_ik'] ?? 0),
            ':max_hybok' => (int) ($data['max_hybok'] ?? 0),
            ':max_kar' => (int) ($data['max_kar'] ?? 0),
            ':min_berging' => (int) ($data['min_berging'] ?? 0), ':max_berging' => (int) ($data['max_berging'] ?? 0),
            ':loc_ik' => $data['locatie_ik'] ?? null, ':loc_berging' => $data['locatie_berging'] ?? null,
            ':loc_hybok' => $data['locatie_hybok'] ?? null,
            ':artikelref' => $data['artikelref'] ?? null,
            ':logistiek_code' => $logistiekCode,
            ':producent' => $data['producent'] ?? null, ':prijs' => (float) ($data['prijs'] ?? 0),
            ':specs' => $specs ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null,
        ]);

        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'product_toegevoegd', 'artikel', $ean, "naam=$productnaam");
        }

        // Bugfix, op verzoek van Harm: een nieuw getypte producentnaam
        // (Artikel Toevoegen -> Producent -> "+ Nieuwe producent
        // toevoegen...") moet direct in de Producenten-lijst verschijnen,
        // niet pas de eerstvolgende keer dat iemand toevallig hetzelfde
        // opnieuw typt. Zie includes/producenten.php::vindOfMaakProducent().
        vindOfMaakProducent($data['producent'] ?? null, $gebruikerNaam);

        return ['success' => true, 'ean' => $ean, 'message' => 'Artikel toegevoegd'];
    } catch (Throwable $e) {
        logError('ARTIKEL_TOEVOEGEN_ERROR', $ean, $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
