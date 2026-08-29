<?php
/**
 * Verplaatsadvies: signaleert voorraad die op de HUIDIGE locatie dreigt
 * te vervallen omdat die locatie het product te langzaam verbruikt, en
 * beveelt de locatie aan waar hetzelfde product sneller wordt verbruikt
 * (dus waarschijnlijker op tijd wordt opgemaakt) -- op verzoek van Harm,
 * expliciet gekozen basis "overdatum-risico -> snellere locatie
 * (aanbevolen)" i.p.v. bijvoorbeeld een min/max-tekort op de doellocatie.
 *
 * Hergebruikt bewust dezelfde bouwstenen als includes/minmax.php voor de
 * "hoe snel verbruikt deze locatie dit product"-berekening
 * (vraagPerEanLocatieWeek/Dag, eersteActiviteitPerEanLocatie,
 * berekenMinMaxAdvies() puur voor het gemiddeldDagverbruik/status-veld --
 * de levertijd/houdbaarheid-parameters van die functie spelen geen rol in
 * dat deel van de berekening en worden hier met neutrale waarden
 * doorgegeven). Zo blijft "hoe snel gaat dit hier" overal in de app
 * dezelfde, navolgbare definitie (Verbruik + Verplaatsing uit, over
 * MINMAX_LOOKBACK_WEKEN, met MINMAX_MIN_WEKEN_HISTORIE als
 * betrouwbaarheidsdrempel) in plaats van een tweede, net-anders-berekende
 * "snelheid" ernaast.
 *
 * "Overdatum-risico" hergebruikt ook een bestaande, overal in de app
 * herkenbare drempel: classifyVervalStatus() === 'SPOEDGEBRUIK' (minder
 * dan 3 maanden houdbaar) -- dezelfde grens als de Vervaldatum-detail- en
 * Lot-tracking-schermen. Een net-andere eigen drempel zou verwarrend
 * zijn naast een status die overal al "SPOEDGEBRUIK" heet. Al-VERLOPEN
 * lots vallen bewust BUITEN dit advies: dat is een afboeking (Vervallen
 * Afboeken), geen verplaatsing.
 *
 * Bewust GEEN voorraadoptimalisatiemodel (zie ook includes/minmax.php's
 * bestandskop): alleen een simpele, uitlegbare vergelijking van
 * gemiddeld dagverbruik tussen locaties. Dit scherm toont alleen een
 * advies -- er is bewust geen "toepassen"-knop zoals bij Min/Max Advies:
 * een verplaatsing is een fysieke handeling (barcodes scannen via het
 * bestaande Verplaatsing-formulier), geen databasewaarde die je met één
 * klik kunt overnemen.
 *
 * 'Berging' (het hoofdmagazijn) komt NOOIT in aanmerking als aanbevolen
 * DOELlocatie -- op verzoek van Harm: Berging is de locatie waar alles
 * juist VANDAAN moet worden verbruikt (de kamers vullen zich intern bij
 * vanuit Berging), niet een plek om naartoe te verplaatsen. Bovendien is
 * Berging's gemeten "verbruik" (Verbruik + Verplaatsing uit, zie
 * includes/minmax.php) grotendeels Verplaatsing uit naar de kamers --
 * dat is doorvoer, geen echt klinisch verbruik, en zou Berging anders
 * ten onrechte als een "snelle verbruiker" kunnen laten lijken. Ligt het
 * risicovolle lot zelf IN Berging, dan blijft dat gewoon zichtbaar (met
 * de snelste KAMER als aanbevolen doel) -- alleen Berging zelf wordt
 * nooit voorgesteld als bestemming.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/voorraad.php';
require_once __DIR__ . '/locaties.php';
require_once __DIR__ . '/minmax.php';

/**
 * Gemiddeld dagverbruik + betrouwbaarheidsstatus voor één ean+locatie.
 * Hergebruikt berekenMinMaxAdvies() (includes/minmax.php) puur voor het
 * 'gemiddeldDagverbruik'/'status'-deel -- levertijd en houdbaarheid
 * worden met neutrale placeholder-waarden meegegeven, want die spelen
 * geen rol vóór het punt waarop die functie het gemiddelde dagverbruik
 * berekent (dat gebeurt onvoorwaardelijk, los van de berging/kamer-tak
 * die daarna volgt).
 */
function dagverbruikVoorEanLocatie(string $ean, string $locatie, array $eersteActiviteit, array $weekVraag, array $dagVraag): array {
    $advies = berekenMinMaxAdvies(
        $eersteActiviteit[$ean][$locatie] ?? null,
        $weekVraag[$ean][$locatie] ?? [],
        $dagVraag[$ean][$locatie] ?? [],
        ['dagen' => 0.0, 'bron' => 'n/a'],
        null,
        false
    );
    return [
        'status' => $advies['status'],
        'gemDag' => $advies['status'] === 'ok' ? $advies['gemiddeldDagverbruik'] : 0.0,
    ];
}

/**
 * "Overdatum-risico": SPOEDGEBRUIK (minder dan 3 maanden houdbaar) maar
 * nog niet verlopen -- zie de bestandskop hierboven voor waarom dit
 * bewust dezelfde drempel is als elders in de app.
 */
function isOverdatumRisico(?string $vervaldatumSql, ?int $daysLeft): bool {
    if ($vervaldatumSql === null || $daysLeft === null) return false;
    return classifyVervalStatus($daysLeft) === 'SPOEDGEBRUIK';
}

/**
 * Eén rij per lot dat op zijn huidige locatie een overdatum-risico loopt
 * (zie isOverdatumRisico()) EN op het huidige verbruikstempo daar niet op
 * tijd opgemaakt zou zijn -- met, indien gevonden, de actieve locatie die
 * hetzelfde product aantoonbaar sneller verbruikt.
 */
function getVerplaatsAdviesOverzicht(): array {
    $weekVraag = vraagPerEanLocatieWeek(MINMAX_LOOKBACK_WEKEN);
    $dagVraag = vraagPerEanLocatieDag(MINMAX_LOOKBACK_WEKEN);
    $eersteActiviteit = eersteActiviteitPerEanLocatie();
    $locaties = actieveLocatieNamen();

    // Cache per ean+locatie: hetzelfde product kan met meerdere lots op
    // dezelfde locatie staan, dan hoeft de vraag/status-opzoek maar één
    // keer per combinatie.
    $cache = [];
    $dagverbruik = function (string $ean, string $locatie) use (&$cache, $eersteActiviteit, $weekVraag, $dagVraag): array {
        if (!isset($cache[$ean][$locatie])) {
            $cache[$ean][$locatie] = dagverbruikVoorEanLocatie($ean, $locatie, $eersteActiviteit, $weekVraag, $dagVraag);
        }
        return $cache[$ean][$locatie];
    };

    $result = [];
    foreach (voorraadLot() as $lot) {
        if (!isOverdatumRisico($lot['vervaldatum_sql'], $lot['daysLeft'])) continue;

        $huidige = $dagverbruik($lot['ean'], $lot['locatie']);
        $daysLeft = $lot['daysLeft'];
        $projectedDagen = $huidige['gemDag'] > 0 ? $lot['huidig'] / $huidige['gemDag'] : null;

        // Ligt het (op basis van het huidige tempo) wél op schema om op
        // tijd opgemaakt te zijn? Dan is er geen verplaatsadvies nodig,
        // ook al is het lot zelf bijna verlopen.
        $ligtOpSchema = $projectedDagen !== null && $projectedDagen <= $daysLeft;
        if ($ligtOpSchema) continue;

        // Kandidaat-locaties: elke andere actieve KAMER (nooit Berging
        // zelf, zie de bestandskop hierboven) met een betrouwbaar
        // (status 'ok') gemeten dagverbruik dat STRIKT hoger ligt dan
        // het huidige (of, als het huidige tempo zelf onvoldoende_data
        // is om te vertrouwen, elke kamer met een betrouwbaar gemeten
        // tempo).
        $kandidaten = [];
        foreach ($locaties as $andereLocatie) {
            if ($andereLocatie === $lot['locatie']) continue;
            if (locatieSlotVoorNaam($andereLocatie) === 'berging') continue;
            $ander = $dagverbruik($lot['ean'], $andereLocatie);
            if ($ander['status'] !== 'ok') continue;
            if ($huidige['status'] === 'ok' && $ander['gemDag'] <= $huidige['gemDag']) continue;
            $kandidaten[] = ['locatie' => $andereLocatie, 'gemDag' => $ander['gemDag']];
        }
        usort($kandidaten, fn($a, $b) => $b['gemDag'] <=> $a['gemDag']);
        $aanbevolen = $kandidaten[0] ?? null;

        $result[] = [
            'ean' => $lot['ean'],
            'product' => $lot['product'],
            'lot' => $lot['lot'],
            'vervaldatum' => $lot['vervaldatum'],
            'daysLeft' => $daysLeft,
            'huidigeLocatie' => $lot['locatie'],
            'huidig' => $lot['huidig'],
            'huidigeSnelheidStatus' => $huidige['status'],
            'huidigDagverbruik' => round($huidige['gemDag'], 2),
            'aanbevolenLocatie' => $aanbevolen['locatie'] ?? null,
            'aanbevolenDagverbruik' => $aanbevolen !== null ? round($aanbevolen['gemDag'], 2) : null,
        ];
    }

    // Meest urgente (kortste daysLeft) bovenaan.
    usort($result, fn($a, $b) => $a['daysLeft'] <=> $b['daysLeft']);
    return $result;
}
