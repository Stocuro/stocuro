<?php
/**
 * Patiëntgebonden materiaalaanvragen (migratieplan hoofdstuk 4.7/8/9).
 * Bevat een persoonsgegeven (patientnummer) -- apiRequireLogin() is
 * hier bewust geen "kan later", zie migratieplan hoofdstuk 8.
 *
 * Gast-modus : GET werkt ook zonder in te loggen
 * (net als elk ander leesendpoint, zie apiRequireLogin() in
 * includes/api_helpers.php), zodat Open Orders en het One-off Order-
 * overzicht ook voor een gast zichtbaar zijn. Maar patientnummer blijft
 * een persoonsgegeven ongeacht wie er kijkt -- dus voor een gast wordt
 * het gemaskeerd (maskeerPatientnummer() hieronder) in plaats van het
 * hele endpoint te blokkeren. Schrijven (POST) blijft wél volledig
 * geblokkeerd voor gast/bekijker via apiRequireWriteAccess().
 *
 * GET  ?id=N    -> volledige details van één aanvraag (incl. opmerking) --
 *                  voor de detailpagina die je vanuit Open Orders bereikt
 * GET  ?open=1  -> alleen open aanvragen (niet Gebruikt/Retourneren),
 *                  met dagenOpen -- voor het Open Orders-overzicht
 * GET  ?mijn=1  -> voor elke ingelogde gebruiker met het
 *                  'mijn-bestellingen'-scherm-recht -- de eigen aanvragen
 *                  (besteller = ingelogde gebruiker OF specialist =
 *                  ingelogde gebruiker) voor de Home-widget
 *                  "Mijn Bestellingen". Dit beperkte zich ooit tot
 *                  Besteller-only; later verbreed naar
 *                  "alle bestellingen VOOR en DOOR de persoon die is
 *                  ingelogd", dus specialist telt er ook weer bij -- zie
 *                  includes/eenmalige_bestellingen.php::
 *                  getEigenEenmaligeBestellingen().
 * GET  (anders) -> laatste 200 aanvragen, alle statussen
 * POST { actie: 'bewerken', id, patientnummer, artikelomschrijving,
 *      gs1_raw?, omschrijvingen?, procedure_type?, specialist?, besteller?,
 *      opmerking?, status } -> volledige bewerking vanaf de detailpagina,
 *      inclusief status (Aangevraagd/Besteld/Ontvangen/Gebruikt/
 *      Retourneren) -- geen apart status-only endpoint meer,
 *      zie includes/eenmalige_bestellingen.php::updateEenmaligeBestelling().
 *      Gs1_raw wordt itemized verwerkt
 *      (elke gescande regel een eigen EAN/lot/vervaldatum, i.p.v.
 *      samengevoegd) -- bevat gs1_raw regels met een onbekende EAN zonder
 *      omschrijving, dan slaat deze aanroep NIETS op (ook de andere
 *      velden niet) en komt de response terug als
 *      { success:false, onbekend:true, onbekendeRegels:[...] }, zelfde
 *      twee-fase-patroon als Ontvangst/Verbruik/Verplaatsing.
 * POST (geen actie, backwards compatible met het bestaande
 *      aanvraagformulier) -> nieuwe aanvraag: { patientnummer,
 *      artikelomschrijving, ean?, hoeveelheid?, procedure_type?,
 *      specialist?, besteller?, opmerking? }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/eenmalige_bestellingen.php';

$user = apiRequireLogin();
$isGast = ($user['rol'] ?? '') === ROL_GAST;

/** Vervangt patientnummer door een placeholder -- alleen voor gast-weergave. */
function maskeerPatientnummer(array $rij): array {
    $rij['patientnummer'] = '•••• (verborgen voor demo-bezoekers)';
    return $rij;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // GET (lezen) is hierboven al gastvriendelijk; schrijven blijft
    // alleen voor echt ingelogde, niet-alleen-lezen accounts.
    apiRequireWriteAccess();
    apiRequireScherm('eenmalige-bestelling');

    $body = readJsonBody();
    if (($body['actie'] ?? '') === 'bewerken') {
        apiTry(fn() => updateEenmaligeBestelling((int) ($body['id'] ?? 0), $body, $user['naam']));
    } else {
        apiTry(fn() => addEenmaligeBestelling($body, $user['naam']));
    }
} else {
    // Dit bestand bedient drie schermen (Eenmalige
    // Bestelling zelf, Open Orders en Mijn Bestellingen), dus welk
    // scherm-recht nodig is hangt af van de branch, niet van het bestand.
    if (($_GET['mijn'] ?? '') === '1') {
        // Niet langer beperkt tot een
        // specialist/laborant-account -- getEigenEenmaligeBestellingen()
        // matcht nu op besteller (wie de aanvraag plaatste), niet meer op
        // specialist (voor wie hij klinisch is), dus is dit voor elke
        // ingelogde gebruiker met het 'mijn-bestellingen'-scherm-recht
        // zinvol. apiRequireScherm() hieronder is de enige poort.
        apiRequireScherm('mijn-bestellingen');
        apiTry(fn() => getEigenEenmaligeBestellingen($user['naam']));
    } elseif (isset($_GET['id'])) {
        apiRequireScherm('eenmalige-bestelling');
        apiTry(function () use ($isGast) {
            $detail = getEenmaligeBestellingDetail((int) $_GET['id']);
            return ($isGast && !isset($detail['error'])) ? maskeerPatientnummer($detail) : $detail;
        });
    } else {
        $open = ($_GET['open'] ?? '') === '1';
        apiRequireScherm($open ? 'open-orders' : 'eenmalige-bestelling');
        apiTry(function () use ($open, $isGast) {
            $rows = $open ? getOpenEenmaligeBestellingen() : getEenmaligeBestellingen();
            return $isGast ? array_map('maskeerPatientnummer', $rows) : $rows;
        });
    }
}
