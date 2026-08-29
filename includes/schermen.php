<?php
/**
 * Canonieke lijst van alle schermen in de app (migratie 024, groepen-
 * systeem). Eén plek die zowel de frontend (checkbox-matrix op het
 * Groepen-beheerscherm, menu-opbouw in showHome()) als de backend
 * (apiRequireScherm(), zie includes/api_helpers.php) gebruiken, zodat er
 * maar één canonieke bron van "welke schermen bestaan er" is.
 *
 * 'labelKey' verwijst naar een BESTAANDE t()-vertaalsleutel in
 * assets/js/app.js (het menuknop-label van dat scherm) -- er zijn dus
 * geen nieuwe vertalingen nodig om deze lijst overal met een leesbare
 * naam te tonen.
 *
 * 'beheerderOnly' => true betekent: dit scherm is NOOIT via een groep
 * toe te kennen, ongeacht groep_schermen -- alleen is_beheerder geeft er
 * toegang toe (zie gebruikerSchermen() in includes/groepen.php). Dit
 * geldt bewust alleen voor 'gebruikers' en 'groepen' zelf: rechten
 * uitdelen is te gevoelig om via een willekeurig aan te maken groep te
 * laten lopen (anders zou iemand een groep kunnen maken die zichzelf
 * toegang tot het Gebruikers- of Groepen-scherm geeft).
 */

declare(strict_types=1);

const SCHERMEN = [
    // --- Formulieren ---
    'scan-ontvangst'      => ['sectie' => 'formulieren', 'labelKey' => 'receiveStock'],
    'scan-verbruik'       => ['sectie' => 'formulieren', 'labelKey' => 'scanUsage'],
    'verplaatsing'        => ['sectie' => 'formulieren', 'labelKey' => 'transferReturns'],
    'eenmalige-bestelling' => ['sectie' => 'formulieren', 'labelKey' => 'oneOffOrder'],
    'artikel-toevoegen'   => ['sectie' => 'formulieren', 'labelKey' => 'addProduct'],
    'verval-afboeken'     => ['sectie' => 'formulieren', 'labelKey' => 'writeOffExpired'],

    // --- Dashboards ---
    'actiecentrum'        => ['sectie' => 'dashboards', 'labelKey' => 'actionCenter'],
    // Migratie 026 (op verzoek van Harm): "Tasks" was één scherm met vier
    // heel verschillende soorten taken door elkaar (databeheer-taken naast
    // fysiek vloerwerk). Nu twee losse scherm-keys/dashboards, zodat een
    // groep specifiek toegang tot het ene kan krijgen zonder het andere.
    'taken-beheerder'     => ['sectie' => 'dashboards', 'labelKey' => 'tasksAdmin'],
    'taken-laborant'      => ['sectie' => 'dashboards', 'labelKey' => 'tasksLab'],
    'recente-transacties' => ['sectie' => 'dashboards', 'labelKey' => 'recentTransactions'],
    'overzicht'           => ['sectie' => 'dashboards', 'labelKey' => 'overview'],
    // Migratie 026 (op verzoek van Harm): heette "Boodschappenlijst",
    // scherm-key blijft ongewijzigd (geen breaking change voor bestaande
    // groepsrechten), alleen het label wijzigt naar "Bijvullen".
    'boodschappenlijst'   => ['sectie' => 'dashboards', 'labelKey' => 'refillList'],
    'berging'             => ['sectie' => 'dashboards', 'labelKey' => 'storage'],
    'minmax-advies'       => ['sectie' => 'dashboards', 'labelKey' => 'minmaxAdvies'],
    'vervaldatums'        => ['sectie' => 'dashboards', 'labelKey' => 'expiryDatesDetail'],
    'lot-tracking'        => ['sectie' => 'dashboards', 'labelKey' => 'lotTracking'],
    'vervalfocus'         => ['sectie' => 'dashboards', 'labelKey' => 'expiryFocus'],
    'verplaatsadvies'     => ['sectie' => 'dashboards', 'labelKey' => 'transferAdvice'],
    // 'bestellers' is één scherm met twee menu-ingangen (Forms-knop
    // "Manual Order" + Ordering-knop "To Order", zie assets/js/app.js
    // showHome()/attachHomeHandlers() -- beide roepen showBestellers()
    // aan) -- daarom hier één scherm-key voor allebei.
    'bestellers'          => ['sectie' => 'dashboards', 'labelKey' => 'toOrder'],
    'open-orders'         => ['sectie' => 'dashboards', 'labelKey' => 'openOrders'],
    'afboekingen'         => ['sectie' => 'dashboards', 'labelKey' => 'writeOffs'],
    // Blijft DAARNAAST ook gated op rol specialist/laborant (ongewijzigd,
    // zie isSpecialistOfLaborant in showHome() en api/eenmalige_bestelling.
    // php ?mijn=1) -- een groep kan dit scherm dus wel AANBIEDEN, maar een
    // Bekijker-rol-gebruiker ziet 'm sowieso niet, wat er ook in de groep
    // staat. Dat is bewust: dit scherm toont eigen bestelstatus, niet iets
    // dat voor een willekeurige groep zinvol is.
    'mijn-bestellingen'   => ['sectie' => 'dashboards', 'labelKey' => 'mbTitle'],
    'verbruik'            => ['sectie' => 'dashboards', 'labelKey' => 'usage'],
    'voorraadwaarde'      => ['sectie' => 'dashboards', 'labelKey' => 'stockValue'],
    'categorien'          => ['sectie' => 'dashboards', 'labelKey' => 'categories'],
    'trends'              => ['sectie' => 'dashboards', 'labelKey' => 'usageTrends'],
    'leaderboard'         => ['sectie' => 'dashboards', 'labelKey' => 'leaderboard'],
    'product-search'      => ['sectie' => 'dashboards', 'labelKey' => 'searchProducts'],
    'producenten'         => ['sectie' => 'dashboards', 'labelKey' => 'producenten'],
    // Migratie 028, bijgewerkt in migratie 030 (op verzoek van Harm): was
    // één scherm 'nieuws' met drie onderdelen (nieuws/team/contact-
    // personen) samen. Nu drie losse schermen met elk een eigen recht --
    // 'team' hoort inhoudelijk bij "Team & Prestaties" (samen met
    // Leaderboard, zie TEAM_TABS), 'nieuws' en 'contactpersonen' blijven
    // losse schermen. Groepen die eerder 'nieuws' hadden aangevinkt zien
    // nu alleen nog de nieuws-producten -- 'team'/'contactpersonen' moeten
    // in Groepen-beheer opnieuw aangevinkt worden als gewenst.
    'nieuws'              => ['sectie' => 'dashboards', 'labelKey' => 'newsTitle'],
    'team'                => ['sectie' => 'dashboards', 'labelKey' => 'newsTeamTitle'],
    'contactpersonen'     => ['sectie' => 'dashboards', 'labelKey' => 'newsContactsTitle'],
    // Migratie 030_groep_landingsscherm, op verzoek van Harm: samengesteld
    // "wat is er, hoeveel, waar, status en verbruik"-overzicht, bedoeld als
    // landingsscherm voor de artsen-groep (zie groepen.landing_scherm) --
    // ze hoeven dan niet meer door Voorraad/Vervaldatums/Verbruik & Trends
    // heen te klikken voor één simpele beschikbaarheidsvraag. Toont ook
    // inkoopprijs/voorraadwaarde (expliciet zo gekozen door Harm), dus geen
    // aparte financiele-afscherming binnen dit scherm.
    'beschikbaarheid'     => ['sectie' => 'dashboards', 'labelKey' => 'beschikbaarheidTitle'],
    // Samenvoeging van bestaande kengetallen (voorraadwaarde, verbruiks-
    // trend/besparing, team-prestaties) tot één landingsscherm voor de
    // management-groep -- geen nieuwe backend-aggregatie, alleen bestaande
    // eindpunten parallel opgehaald en samengevat.
    'management-cockpit'  => ['sectie' => 'dashboards', 'labelKey' => 'managementCockpitTitle'],

    // --- Beheer (configureerbaar via een groep) ---
    // De schrijfacties BINNEN deze schermen (locatienaam wijzigen,
    // procedure-type toevoegen, ...) blijven ongewijzigd beheerder-only
    // (apiRequireBeheerder(), zie api/locaties.php / api/procedure_typen.
    // php) -- dit scherm-recht regelt alleen of een groep het scherm mag
    // BEKIJKEN (GET), niet of ze er ook in mogen schrijven.
    'stamgegevens'        => ['sectie' => 'beheer', 'labelKey' => 'masterData'],
    'locaties'            => ['sectie' => 'beheer', 'labelKey' => 'locations'],
    'foutlog'             => ['sectie' => 'beheer', 'labelKey' => 'errorLog'],
    'onbekende-producten' => ['sectie' => 'beheer', 'labelKey' => 'unknownProducts'],

    // --- Beheer (altijd beheerder-only, NIET via een groep toe te kennen) ---
    'gebruikers'          => ['sectie' => 'beheer', 'labelKey' => 'users', 'beheerderOnly' => true],
    'groepen'             => ['sectie' => 'beheer', 'labelKey' => 'groepenTitle', 'beheerderOnly' => true],
];

/** Scherm-keys die WEL via een groep toe te kennen zijn (voor de checkbox-matrix). */
function configureerbareSchermen(): array {
    return array_filter(SCHERMEN, fn($s) => empty($s['beheerderOnly']));
}
