<?php
/**
 * Orders: reguliere voorraadaanvulling. Poort van de order-logica in
 * Code.gs (upsertOrder, updateOrdersWithFIFO) en de open-orders-
 * dashboards in DashboardAll.gs.
 *
 * Bewust GEEN afhankelijkheid van voorraad.php hier (voorkomt een
 * circulaire require -- voorraad.php require't dit bestand wel, voor
 * openOrderStatusMap()).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ean.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/audit.php';
// ActueleNaam() -- getTransactiesRondOrder() hieronder toont
// transactielog.locatie, die na een locatie-hernoeming nog de oude naam kan
// bevatten. Zie includes/hernoemingen.php voor de achtergrond.
require_once __DIR__ . '/hernoemingen.php';

/**
 * Status van een order, berekend (niet opgeslagen) -- zelfde regel als
 * de oude F-kolomformule in Orders.
 */
function orderStatus(int $besteld, int $ontvangen): string {
    if ($ontvangen === 0) return 'In bestelling';
    if ($ontvangen < $besteld) return 'Gedeeltelijk ontvangen';
    return 'Volledig ontvangen';
}

/**
 * Handmatig of Automatisch besteld -- (Bestellingen-
 * overzicht moet dit per order duidelijk tonen). 'kanaal' is de enige
 * betrouwbare, al bestaande plek om dit aan af te lezen: de literal
 * 'Berging-bestellijst' wordt UITSLUITEND gezet door
 * markeerBergingAlsBesteld() (includes/voorraad.php), de enige plek in de
 * hele app die orders automatisch (in bulk, vanuit het Berging-
 * bestellijst-scherm) aanmaakt. Elke andere order -- via de handmatige
 * bestel-modal (submitHandmatigeBestellingen()) of een eventuele
 * handmatige correctie op de detailpagina -- is per definitie Handmatig.
 * Bewust GEEN gebruik van artikelen.besteltype hiervoor: dat is een
 * PRODUCT-instelling (hoort dit artikel normaliter automatisch of
 * handmatig besteld te worden), terwijl dit hier gaat om hoe DEZE
 * SPECIFIEKE order daadwerkelijk tot stand is gekomen -- die twee kunnen
 * uiteenlopen (bijv. een Handmatig-artikel dat via Berging alsnog een
 * keer in bulk wordt meebesteld, of andersom).
 */
function bestelMethode(?string $kanaal): string {
    return $kanaal === 'Berging-bestellijst' ? 'Automatisch' : 'Handmatig';
}

/**
 * EAN -> kale orderstatustekst, alleen voor openstaande orders (In
 * bestelling / Gedeeltelijk ontvangen). Poort van buildOrderStatusMap().
 * "Volledig ontvangen" wordt bewust NIET meegenomen, zelfde reden als
 * vroeger: die order is afgerond en zou een misleidend label opleveren.
 */
function openOrderStatusMap(): array {
    $rows = db()->query('SELECT ean, hoeveelheid_besteld, hoeveelheid_ontvangen FROM orders')->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $status = orderStatus((int) $r['hoeveelheid_besteld'], (int) $r['hoeveelheid_ontvangen']);
        if ($status === 'In bestelling' || $status === 'Gedeeltelijk ontvangen') {
            $map[$r['ean']] = $status;
        }
    }
    return $map;
}

/**
 * EAN -> som van (besteld - ontvangen) over openstaande orders (In
 * bestelling / Gedeeltelijk ontvangen). De bestel-adviesschermen
 * (Berging-bestellijst, Te bestellen
 * Handmatig) mogen een artikel dat al (deels) onderweg is niet nogmaals
 * in zijn volle behoefte voorstellen -- maar als er tijdens de wachttijd
 * op die levering méér is verbruikt dan er onderweg is, moet dat verschil
 * wél zichtbaar blijven (anders verdwijnt een product van de lijst
 * terwijl er ondertussen alweer een reëel tekort ontstaat). Poort van
 * getBergingData()/getBestellersData() in includes/voorraad.php, die
 * hiermee "resterend nodig" = max(0, brutoNodig - watOnderwegIs)
 * berekenen i.p.v. het bruto-benodigde aantal te tonen.
 */
function openOrderResterendPerEan(): array {
    $rows = db()->query('SELECT ean, hoeveelheid_besteld, hoeveelheid_ontvangen FROM orders')->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $besteld = (int) $r['hoeveelheid_besteld'];
        $ontvangen = (int) $r['hoeveelheid_ontvangen'];
        $status = orderStatus($besteld, $ontvangen);
        if ($status === 'In bestelling' || $status === 'Gedeeltelijk ontvangen') {
            $map[$r['ean']] = ($map[$r['ean']] ?? 0) + ($besteld - $ontvangen);
        }
    }
    return $map;
}

/**
 * Nieuwe order aanmaken -- poort van upsertOrder(). Wordt zowel
 * aangeroepen door de dashboard-modal (submitHandmatigeBestellingen) als
 * door het handmatige-bestelling-formulier, en door
 * markeerBergingAlsBesteld() (includes/voorraad.php).
 *
 * (na een gemelde bug): dit voegt NIET meer samen met
 * een eventuele al openstaande order voor hetzelfde EAN. Elke aanroep
 * maakt altijd een eigen, nieuwe rij aan met zijn eigen tijdstempel,
 * aantal, besteller, kanaal en opmerking -- zo blijft precies zichtbaar
 * WELKE order (op welk moment) WELK aantal betrof, in plaats van dat
 * losse bestelmomenten onzichtbaar in elkaar opgaan tot één opgehoogd
 * totaal. getOpenOrders()/openOrderResterendPerEan()/
 * updateOrdersWithFIFO() werkten hier al op in: die lopen sowieso over
 * ALLE orderrijen per EAN heen (som resp. FIFO-verdeling), dus meerdere
 * gelijktijdig openstaande orders voor hetzelfde product waren voor die
 * kant van het systeem al nooit een probleem -- alleen upsertOrder() zelf
 * verborg dat onderscheid voorheen ten onrechte.
 */
function upsertOrder(string $ean, int $aantal, string $besteller, string $kanaal, string $opmerking): array {
    $pdo = db();
    $eanNorm = normalizeEAN($ean);
    $aantal = max(1, $aantal);

    $stmt = $pdo->prepare('SELECT productnaam FROM artikelen WHERE ean = :ean');
    $stmt->execute([':ean' => $eanNorm]);
    $productnaam = $stmt->fetchColumn() ?: '';

    $pdo->prepare(
        'INSERT INTO orders (tijdstempel, ean, productnaam, hoeveelheid_besteld, hoeveelheid_ontvangen, besteller, kanaal, opmerking)
         VALUES (NOW(), :ean, :naam, :aantal, 0, :besteller, :kanaal, :opmerking)'
    )->execute([
        ':ean' => $eanNorm, ':naam' => $productnaam, ':aantal' => $aantal,
        ':besteller' => $besteller, ':kanaal' => $kanaal, ':opmerking' => $opmerking,
    ]);

    return ['success' => true, 'message' => 'Nieuwe order aangemaakt'];
}

function submitHandmatigeBestellingen(string $ean, int $aantal, string $besteller, string $kanaal, string $opmerking, ?string $gebruikerNaam = null): array {
    try {
        $result = upsertOrder($ean, $aantal, $besteller, $kanaal, $opmerking);
        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'bestelling_aangemaakt', 'artikel', $ean, "aantal=$aantal, besteller=$besteller, kanaal=$kanaal");
        }
        return $result;
    } catch (Throwable $e) {
        logError('BESTELLING_ERROR', 'N/A', $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * FIFO: verdeelt een ontvangen hoeveelheid over openstaande orders voor
 * dit EAN, oudste eerst. Poort van updateOrdersWithFIFO(). Draait
 * binnen een transactie met rijvergrendeling (SELECT ... FOR UPDATE) --
 * migratieplan hoofdstuk 8, "Gelijktijdige scans": voorkomt dat twee
 * gelijktijdige ontvangst-scans elkaars ophoging overschrijven, iets
 * wat Apps Script impliciet deed door schrijfacties te serialiseren.
 */
function updateOrdersWithFIFO(string $ean, int $hoeveelheid): int {
    $pdo = db();
    $eanNorm = normalizeEAN($ean);
    $remaining = $hoeveelheid;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT id, hoeveelheid_besteld, hoeveelheid_ontvangen FROM orders
             WHERE ean = :ean ORDER BY tijdstempel ASC FOR UPDATE"
        );
        $stmt->execute([':ean' => $eanNorm]);
        $orders = $stmt->fetchAll();

        foreach ($orders as $order) {
            if ($remaining <= 0) break;
            $status = orderStatus((int) $order['hoeveelheid_besteld'], (int) $order['hoeveelheid_ontvangen']);
            if ($status === 'Volledig ontvangen') continue;

            $nogNodig = (int) $order['hoeveelheid_besteld'] - (int) $order['hoeveelheid_ontvangen'];
            if ($nogNodig <= 0) continue;

            $toevoegen = min($remaining, $nogNodig);
            // laatst_ontvangen_op wordt hier, en ALLEEN hier, gezet -- dit is
            // het enige punt waar een echte fysieke ontvangst wordt geboekt.
            // Gebruikt door includes/minmax.php om de levertijd (besteld ->
            // ontvangen) te berekenen; bijgewerkt_op is daar niet voor
            // bruikbaar (die verandert ook bij bijv. upsertOrder() als er
            // extra wordt bijbesteld op een al openstaande order).
            //
            // Eerst_ontvangen_op ernaast --
            // bij een order die in meerdere deelleveringen binnenkomt bleef
            // tot nu toe alleen het LAATSTE ontvangstmoment bewaard, dus ging
            // het moment van de EERSTE deellevering verloren zodra een tweede
            // deellevering binnenkwam. COALESCE zorgt dat dit veld maar één
            // keer gezet wordt (alleen bij de eerste ontvangst, NULL -> NOW()),
            // en daarna nooit meer overschreven wordt door latere deelleveringen.
            $pdo->prepare(
                'UPDATE orders SET hoeveelheid_ontvangen = hoeveelheid_ontvangen + :t, laatst_ontvangen_op = NOW(), eerst_ontvangen_op = COALESCE(eerst_ontvangen_op, NOW()) WHERE id = :id'
            )->execute([':t' => $toevoegen, ':id' => $order['id']]);

            $remaining -= $toevoegen;
        }

        $pdo->commit();
        return $remaining;
    } catch (Throwable $e) {
        $pdo->rollBack();
        logError('FIFO_UPDATE_ERROR', 'N/A', $e->getMessage());
        return -1;
    }
}

/**
 * Alle orders met status In bestelling / Gedeeltelijk ontvangen, met
 * dagen open. Poort van getOpenOrders().
 */
function getOpenOrders(): array {
    $rows = db()->query('SELECT * FROM orders ORDER BY tijdstempel DESC')->fetchAll();
    $today = new DateTime('today');
    $result = [];

    foreach ($rows as $r) {
        $besteld = (int) $r['hoeveelheid_besteld'];
        $ontvangen = (int) $r['hoeveelheid_ontvangen'];
        $status = orderStatus($besteld, $ontvangen);
        if ($status !== 'In bestelling' && $status !== 'Gedeeltelijk ontvangen') continue;

        $besteldOp = new DateTime($r['tijdstempel']);
        $dagenOpen = (int) $today->diff($besteldOp)->format('%a');

        $result[] = [
            // 'id' toegevoegd (orders
            // moeten klikbaar worden naar een detail-/bewerkpagina, zie
            // getOrderDetail()/updateOrder() hieronder) -- voorheen gaf
            // deze functie geen id terug, want er was nog geen detailpagina
            // om naartoe te linken.
            'id' => (int) $r['id'],
            'ean' => $r['ean'], 'naam' => $r['productnaam'] ?: '',
            'datum' => $besteldOp->format('d-m-Y H:i'),
            'besteld' => $besteld, 'ontvangen' => $ontvangen, 'nog' => $besteld - $ontvangen,
            'status' => $status, 'besteller' => $r['besteller'] ?: '', 'kanaal' => $r['kanaal'] ?: '',
            // : per order duidelijk Handmatig/Automatisch
            // tonen in het Bestellingen-overzicht, zie bestelMethode() hierboven.
            'besteltype' => bestelMethode($r['kanaal']),
            'opmerking' => $r['opmerking'] ?: '', 'dagenOpen' => $dagenOpen,
        ];
    }

    usort($result, fn($a, $b) => $b['dagenOpen'] <=> $a['dagenOpen']);
    return $result;
}

/**
 * ("ik zie niet op het homescherm al
 * mijn bestellingen terug"): de Home-widget "Mijn Bestellingen" toonde tot
 * nu toe UITSLUITEND eenmalige (patiëntgebonden) bestellingen (zie
 * getEigenEenmaligeBestellingen(), includes/eenmalige_bestellingen.php) --
 * reguliere voorraad-orders (Automatisch/Handmatig besteld via de
 * bestel-modal of Berging-bestellijst) kwamen daar nooit in terug, ook al
 * was de ingelogde gebruiker gewoon de besteller. Poort van
 * getOpenOrders() hierboven (dezelfde open-status-filter: In bestelling/
 * Gedeeltelijk ontvangen -- een volledig ontvangen order is niet meer
 * "onderweg" en hoort dus niet in de widget), hier extra gefilterd op
 * besteller = de ingelogde gebruiker. Wordt in de widget samengevoegd met
 * getEigenEenmaligeBestellingen() (zie api/open_orders.php::?mijn=1 en
 * assets/js/app.js::laadHomeWidgets()).
 */
function getEigenOpenOrders(string $eigenNaam): array {
    return array_values(array_filter(
        getOpenOrders(),
        fn(array $r): bool => $r['besteller'] === $eigenNaam
    ));
}

/**
 * Eén order-rij met alle velden -- 
 * orders (Actiecentrum, Overzicht, Open Orders) moeten klikbaar worden
 * naar een detailweergave met bewerkmogelijkheid, net zoals eenmalige
 * bestellingen die al hebben (zie includes/eenmalige_bestellingen.php).
 */
function getOrderDetail(int $id): array {
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();
    if (!$r) return ['error' => 'Order niet gevonden'];

    $besteld = (int) $r['hoeveelheid_besteld'];
    $ontvangen = (int) $r['hoeveelheid_ontvangen'];
    $besteldOp = new DateTime($r['tijdstempel']);

    return [
        'id' => (int) $r['id'], 'ean' => $r['ean'], 'naam' => $r['productnaam'] ?: '',
        'tijdstempel' => $r['tijdstempel'], 'datum' => $besteldOp->format('d-m-Y H:i'),
        'besteld' => $besteld, 'ontvangen' => $ontvangen, 'nog' => $besteld - $ontvangen,
        'status' => orderStatus($besteld, $ontvangen),
        'besteller' => $r['besteller'] ?: '', 'kanaal' => $r['kanaal'] ?: '', 'opmerking' => $r['opmerking'] ?: '',
        // Zie bestelMethode() hierboven.
        'besteltype' => bestelMethode($r['kanaal']),
        'laatstOntvangenOp' => $r['laatst_ontvangen_op'] ?? null,
        // Eerst_ontvangen_op ernaast op
        // het detailscherm, zie de toelichting bij updateOrdersWithFIFO()
        // hierboven voor waarom dit een apart veld is naast laatstOntvangenOp.
        'eerstOntvangenOp' => $r['eerst_ontvangen_op'] ?? null,
        // : "alle transacties rondom deze order" op de
        // detailpagina. transactielog heeft GEEN order_id-koppeling (een
        // Ontvangst-scan wordt via updateOrdersWithFIFO() over meerdere
        // gelijktijdig openstaande orders voor hetzelfde EAN kunnen
        // verdelen, dus een 1-op-1-link bestaat niet in dit schema) --
        // dit is daarom een tijdvenster-benadering: alle transacties voor
        // dit EAN vanaf het moment dat DEZE order werd geplaatst, tot aan
        // (of ze nu nog open is: tot NU) het moment waarop deze order voor
        // het laatst iets ontving. Bij meerdere gelijktijdig openstaande
        // orders voor hetzelfde product kunnen transacties dus in de
        // vensters van meer dan één order verschijnen -- zie ook de hint
        // hierover in de frontend (renderOrderDetail()).
        // Geclusterde variant
        // i.p.v. de platte lijst (zie getTransactiesRondOrderGeclusterd()
        // hieronder).
        'transacties' => getTransactiesRondOrderGeclusterd($r['ean'], $r['tijdstempel'], $r['laatst_ontvangen_op'] ?? null),
    ];
}

/**
 * Zie de toelichting bij getOrderDetail() hierboven. $eindeVenster is
 * laatst_ontvangen_op als de order al iets ontvangen heeft, anders NULL
 * (= "tot nu", de order staat nog open).
 */
function getTransactiesRondOrder(string $ean, string $besteldOp, ?string $eindeVenster): array {
    $sql = 'SELECT tijdstempel, batch_id, lot, vervaldatum, locatie, transactietype, hoeveelheid, specialist, procedure_type, opmerking, aangemaakt_door
            FROM transactielog
            WHERE ean = :ean AND tijdstempel >= :vanaf';
    $params = [':ean' => $ean, ':vanaf' => $besteldOp];
    if ($eindeVenster !== null) {
        $sql .= ' AND tijdstempel <= :tot';
        $params[':tot'] = $eindeVenster;
    }
    $sql .= ' ORDER BY tijdstempel DESC LIMIT 200';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $result = [];
    foreach ($stmt->fetchAll() as $t) {
        $result[] = [
            'timestamp' => $t['tijdstempel'], 'batchId' => $t['batch_id'], 'lot' => $t['lot'], 'vervaldatum' => $t['vervaldatum'],
            // (locatie-hernoemen cascade-bug):
            // ruwe locatienaam ten tijde van transactie -> huidige naam.
            'locatie' => actueleNaam('locatie', $t['locatie']), 'type' => $t['transactietype'], 'hoeveelheid' => (int) $t['hoeveelheid'],
            'specialist' => $t['specialist'], 'procedure' => $t['procedure_type'], 'opmerking' => $t['opmerking'],
            'gescandDoor' => $t['aangemaakt_door'],
        ];
    }
    return $result;
}

/**
 * 
 * "ontvangst-transacties clusteren" -- een order die met meerdere lots in
 * één scanactie is ontvangen (bijv. 3 verschillende batchcodes van
 * dezelfde levering) toonde hier voorheen 3 losse "Ontvangst"-rijen,
 * terwijl dat feitelijk één gebeurtenis was. getTransactiesRondOrder()
 * hierboven blijft ongewijzigd de platte lijst leveren (batch_id zit er nu
 * ook in) -- deze functie groepeert daarna alle 'Ontvangst'-rijen met
 * dezelfde batch_id tot één samengevatte regel (som van hoeveelheid, de
 * losse lots blijven opvraagbaar via 'regels' voor de "bekijk
 * lots"-uitklapper op de detailpagina, zelfde idee als
 * getRecenteTransactiesGegroepeerd()). Oudere rijen van vóór dit veld
 * bestond hebben geen batch_id (NULL) en blijven daarom, net als elk ander
 * transactietype (Verbruik/Verplaatsing/Retournering/Telling), gewoon een
 * eigen ongeclusterde regel.
 */
function getTransactiesRondOrderGeclusterd(string $ean, string $besteldOp, ?string $eindeVenster): array {
    $ruw = getTransactiesRondOrder($ean, $besteldOp, $eindeVenster);

    $result = [];
    $groepIndexPerBatch = [];
    foreach ($ruw as $t) {
        if ($t['type'] === 'Ontvangst' && $t['batchId'] !== null) {
            if (!isset($groepIndexPerBatch[$t['batchId']])) {
                $groepIndexPerBatch[$t['batchId']] = count($result);
                $result[] = [
                    'timestamp' => $t['timestamp'], 'type' => 'Ontvangst', 'hoeveelheid' => 0,
                    'locatie' => $t['locatie'], 'specialist' => $t['specialist'],
                    'gescandDoor' => $t['gescandDoor'], 'opmerking' => $t['opmerking'],
                    'geclusterd' => true, 'regels' => [],
                ];
            }
            $idx = $groepIndexPerBatch[$t['batchId']];
            $result[$idx]['hoeveelheid'] += $t['hoeveelheid'];
            $result[$idx]['regels'][] = $t;
            continue;
        }
        $t['geclusterd'] = false;
        $t['regels'] = [$t];
        $result[] = $t;
    }
    return $result;
}

/**
 * Meest recente order voor een EAN die nog niet volledig ontvangen is --
 * gebruikt om vanuit een kaal EAN (bijv. de orderstatus-
 * badge op Overzicht, die alleen een EAN kent, geen order-id) toch naar
 * de juiste detailpagina te kunnen linken. Null als er geen open order
 * voor dit EAN is (bijv. de order is inmiddels volledig ontvangen, of
 * er was er nooit een -- de aanroeper toont dan gewoon geen link).
 */
function getOpenOrderIdVoorEan(string $ean): ?int {
    $eanNorm = normalizeEAN($ean);
    $stmt = db()->prepare(
        'SELECT id, hoeveelheid_besteld, hoeveelheid_ontvangen FROM orders WHERE ean = :ean ORDER BY tijdstempel DESC'
    );
    $stmt->execute([':ean' => $eanNorm]);
    foreach ($stmt->fetchAll() as $r) {
        $status = orderStatus((int) $r['hoeveelheid_besteld'], (int) $r['hoeveelheid_ontvangen']);
        if ($status !== 'Volledig ontvangen') return (int) $r['id'];
    }
    return null;
}

/**
 * Volledige bewerking van een order (
 * "kan ik de inhoud zien en eventueel bewerken") -- zelfde "hele rij
 * opnieuw versturen"-conventie als updateEenmaligeBestelling(). Bewust
 * GEEN validatie dat ontvangen <= besteld (een beheerder corrigeert hier
 * soms precies zo'n eerder verkeerd geboekte situatie recht), maar wel
 * altijd niet-negatieve waarden.
 */
function updateOrder(int $id, array $data, ?string $gebruikerNaam = null): array {
    $stmt = db()->prepare('SELECT ean FROM orders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $ean = $stmt->fetchColumn();
    if ($ean === false) return ['success' => false, 'error' => 'Order niet gevonden'];

    $besteld = max(0, (int) ($data['besteld'] ?? 0));
    $ontvangen = max(0, (int) ($data['ontvangen'] ?? 0));

    db()->prepare(
        'UPDATE orders SET hoeveelheid_besteld = :besteld, hoeveelheid_ontvangen = :ontvangen,
            besteller = :besteller, kanaal = :kanaal, opmerking = :opmerking WHERE id = :id'
    )->execute([
        ':besteld' => $besteld, ':ontvangen' => $ontvangen,
        ':besteller' => trim((string) ($data['besteller'] ?? '')) ?: null,
        ':kanaal' => trim((string) ($data['kanaal'] ?? '')) ?: null,
        ':opmerking' => trim((string) ($data['opmerking'] ?? '')) ?: null,
        ':id' => $id,
    ]);

    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'order_bewerkt', 'order', (string) $id, "ean=$ean, besteld=$besteld, ontvangen=$ontvangen");
    }

    return ['success' => true];
}
