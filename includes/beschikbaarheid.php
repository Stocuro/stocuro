<?php
/**
 * Een arts die
 * tussen twee procedures snel wil weten "wat is er, hoeveel, waar, wat is
 * de status, en wat is het verbruik" moest daarvoor tot nu toe door
 * minstens drie schermen heen (Voorraad, Vervaldatums, Verbruik & Trends).
 * Dit bestand voegt dat samen tot één overzicht, per artikel:
 *   - totaal aantal stuks (over alle locaties samen) + waarde
 *   - status per locatie (voor de detailkolom) + een samengevat "ergste"
 *     status voor de hoofdkolom
 *   - eerstvolgende vervaldatum + of er loten binnen 6 maanden vervallen
 *   - verbruik in de laatste BESCHIKBAARHEID_VERBRUIK_DAGEN dagen
 * Alleen-lezen, hergebruikt volledig bestaande berekeningen
 * (voorraadStatus()/voorraadLot() in includes/voorraad.php) -- geen nieuwe
 * voorraadlogica, alleen een andere samenvatting ervan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/voorraad.php';

/**
 * Rangorde om de "ergste" status over meerdere locaties te bepalen --
 * LEEG is voor een arts het meest urgent (niets te gebruiken), LAAG
 * daarna, dan OK, met HOOG (te veel op voorraad) als laagste prioriteit:
 * dat is geen klinisch probleem, hooguit een inkoopkwestie.
 */
function beschikbaarheidStatusRang(string $status): int {
    return match ($status) {
        'LEEG' => 3,
        'LAAG' => 2,
        'OK' => 1,
        'HOOG' => 0,
        default => 0,
    };
}

/** Verbruik (transactietype 'Verbruik') per EAN in de laatste $dagen dagen. */
function verbruikLaatsteDagenPerEan(int $dagen): array {
    $rows = db()->query(
        "SELECT ean, SUM(hoeveelheid) AS totaal
         FROM transactielog
         WHERE transactietype = 'Verbruik'
           AND tijdstempel >= DATE_SUB(NOW(), INTERVAL $dagen DAY)
         GROUP BY ean"
    )->fetchAll();

    $result = [];
    foreach ($rows as $r) $result[(string) $r['ean']] = (int) $r['totaal'];
    return $result;
}

function getBeschikbaarheid(): array {
    $artikelen = artikelenMap();

    // Per ean aggregeren over alle locaties: totaal stuks/waarde, een
    // per-locatie detaillijst, en de "ergste" status.
    $perEan = [];
    foreach (voorraadStatus() as $row) {
        $ean = $row['ean'];
        $perEan[$ean] ??= ['totaalStuks' => 0, 'totaalWaarde' => 0.0, 'locaties' => [], 'statusRang' => -1, 'status' => 'OK'];
        $perEan[$ean]['totaalStuks'] += $row['huidig'];
        $perEan[$ean]['totaalWaarde'] += $row['waarde'];
        $perEan[$ean]['locaties'][] = ['locatie' => $row['locatie'], 'huidig' => $row['huidig'], 'status' => $row['voorraadStatus']];
        $rang = beschikbaarheidStatusRang($row['voorraadStatus']);
        if ($rang > $perEan[$ean]['statusRang']) {
            $perEan[$ean]['statusRang'] = $rang;
            $perEan[$ean]['status'] = $row['voorraadStatus'];
        }
    }

    // Eerstvolgende vervaldatum + aantal loten < 6 maanden, per ean (zelfde
    // logica als includes/dashboards.php::getCategorieOverzicht()).
    $eerstvolgendeVervaldatum = [];
    $loten6PerEan = [];
    foreach (voorraadLot() as $lot) {
        if ($lot['vervaldatum_sql'] === null) continue;
        $ean = $lot['ean'];
        if (!isset($eerstvolgendeVervaldatum[$ean]) || $lot['vervaldatum_sql'] < $eerstvolgendeVervaldatum[$ean]['sql']) {
            $eerstvolgendeVervaldatum[$ean] = ['sql' => $lot['vervaldatum_sql'], 'weergave' => $lot['vervaldatum']];
        }
        $monthsLeft = $lot['daysLeft'] / 30.44;
        if ($monthsLeft < 6) $loten6PerEan[$ean] = ($loten6PerEan[$ean] ?? 0) + 1;
    }

    $verbruikPerEan = verbruikLaatsteDagenPerEan(BESCHIKBAARHEID_VERBRUIK_DAGEN);

    $result = [];
    // (string)-cast: zelfde reden als op meerdere plekken elders (PHP zet
    // een numerieke array-sleutel als EAN stilzwijgend om naar int) -- zie
    // includes/voorraad.php::voorraadStatus() voor de volledige toelichting.
    foreach ($artikelen as $eanRaw => $artikel) {
        $ean = (string) $eanRaw;
        if ($ean === '00000000000000') continue; // dummy/onbekend-placeholder, geen echt artikel

        $agg = $perEan[$ean] ?? ['totaalStuks' => 0, 'totaalWaarde' => 0.0, 'locaties' => [], 'status' => 'OK'];
        $result[] = [
            'ean' => $ean,
            'naam' => $artikel['productnaam'],
            'categorie' => $artikel['categorie'] ?: '',
            'producent' => $artikel['producent'] ?: '',
            'status' => $artikel['status'] ?? 'Actief', // levensstatus artikel (Actief/Niet leverbaar/Niet meer in gebruik)
            'voorraadStatus' => $agg['status'], // status van de VOORRAAD (LEEG/LAAG/OK/HOOG)
            'totaalStuks' => $agg['totaalStuks'],
            'totaalWaarde' => $agg['totaalWaarde'],
            'prijs' => (float) $artikel['prijs'],
            'locaties' => $agg['locaties'],
            'eerstvolgendeVervaldatum' => $eerstvolgendeVervaldatum[$ean]['weergave'] ?? null,
            'loten6' => $loten6PerEan[$ean] ?? 0,
            'verbruikRecent' => $verbruikPerEan[$ean] ?? 0,
        ];
    }

    usort($result, fn($a, $b) => strcmp($a['naam'], $b['naam']));
    return $result;
}
