<?php
/**
 * Vaste waarden die in meerdere includes/api-bestanden terugkomen.
 *
 * Locatienamen staan hier NIET meer (voorheen LOCATIES/LOCATIES_BESTELLING) --
 * die zijn instelbaar via de database, zie
 * includes/locaties.php (actieveLocatieNamen()/actieveLocatieNamenBestelling()).
 */

declare(strict_types=1);

// Puur informatief label (audit/debug),
// meegestuurd in elk nieuw licentietoken (includes/license.php) zodat je
// achteraf kunt zien onder welke versie een token is uitgegeven. Telt
// BEWUST niet mee in de HMAC-sleutel zelf -- dat zou bij elke versiebump
// (en die worden voortaan bij elke wijziging opgehoogd, ook kleine) het bestaande
// gratis-token van de eigen installatie ongeldig maken.
//
// ("dit is dan officieel mijn eerste
// release"): versienummering reset naar 1.0.0 als markering van de eerste
// officiële, live release. Alle eerdere 1.x/2.x-nummers in README.md ##
// Features zijn de ontwikkelgeschiedenis van vóór deze release. Vanaf nu
// zijn nieuwe versies kleine, doorlopende aanpassingen (patch/minor-bumps
// op 1.0.0), zie README.md.
const APP_VERSION = '1.0.12';

const TRANSACTIETYPES_INKOMEND = ['Ontvangst', 'Verplaatsing in', 'Telling: overschot'];
const TRANSACTIETYPES_UITGAAND = ['Verbruik', 'Verplaatsing uit', 'Retournering', 'Telling: tekort', 'Vervallen'];

const ORDER_STATUS_OPEN = ['In bestelling', 'Gedeeltelijk ontvangen'];

// Hoe vaak een locatie fysiek geteld moet worden (kastcontrole, zie
// includes/taken.php) voor die taak weer als "verlopen" in de takenlijst
// verschijnt.
const TELLING_INTERVAL_MAANDEN = 3;

// -----------------------------------------------------------------------
// Min/Max Advies (zie includes/minmax.php): instelbare knoppen
// voor de vuistregel-berekening. Zie includes/minmax.php voor de volledige
// toelichting op de formule zelf.
// -----------------------------------------------------------------------
// Aanname voor levertijd als er nog geen betrouwbaar (>= 3 samples)
// gemiddelde uit eigen orderhistorie te berekenen is (per product, en
// anders globaal over alle producten) -- zie
// includes/minmax.php::getGemiddeldeLevertijdDagen().
const LEVERTIJD_DAGEN_DEFAULT = 14;

// Extra veiligheidsmarge boven op het verwachte verbruik tijdens de
// levertijd, uitgedrukt in weken gemiddeld verbruik. Geldt ALLEEN voor
// 'berging' -- de enige locatie die daadwerkelijk op een externe
// leverancier wacht (zie includes/minmax.php).
const MINMAX_BUFFER_WEKEN = 2;

// Hoeveel weken piekverbruik het voorgestelde max-niveau van 'berging'
// (bovenop min) moet kunnen opvangen. Geldt ALLEEN voor 'berging' -- zie
// hierboven.
const MINMAX_DEKKING_PIEK_WEKEN = 4;

// Dagvoorraad (in dagen gemiddeld verbruik) die op de kamers (elke locatie
// behalve 'berging') wordt aangehouden -- die worden dezelfde dag intern
// bijgevuld vanuit berging, dus hoeven geen externe levertijd te
// overbruggen. Zie includes/minmax.php.
const MINMAX_DAGVOORRAAD_DAGEN = 1;

// Minimum aantal weken aan historie (sinds de eerste transactie van dit
// product op deze locatie) voordat er überhaupt een advies wordt gegeven
// -- te weinig historie levert een onbetrouwbaar/toevallig getal op.
const MINMAX_MIN_WEKEN_HISTORIE = 8;

// Hoe ver terug verbruiksdata wordt meegenomen voor het advies.
const MINMAX_LOOKBACK_WEKEN = 52;

// Hoe lang een genegeerd advies
// (ean+locatie) verborgen blijft op het Min/Max Advies-overzicht voordat
// het daar -- als het dan nog steeds geldt -- opnieuw wordt aangeboden.
// Alleen dit overzichtsscherm respecteert dit; de productdetailpagina
// toont een min/max-voorstel altijd, genegeerd of niet (zie
// includes/minmax.php::getMinMaxAdviesOverzicht() resp.
// includes/artikelen.php::getProductDetail()).
const MINMAX_NEGEER_MAANDEN = 3;

// "recent verbruik"
// op het nieuwe Beschikbaarheid-scherm (includes/beschikbaarheid.php) --
// hoeveel dagen terug meetellen. 90 dagen (~1 kwartaal) geeft een arts een
// representatief beeld zonder dat een lang-vervlogen piek het beeld
// vertekent.
const BESCHIKBAARHEID_VERBRUIK_DAGEN = 90;
