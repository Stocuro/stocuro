<?php
/**
 * Migratie 039, op verzoek van Harm: "Wanneer ik locaties hernoem dan
 * wordt dit niet automatisch in de lots in stock en transactie historie
 * locaties aangepast."
 *
 * Achtergrond: transactielog.locatie en transactielog.specialist slaan
 * de naam op als vrije tekst op het moment van de transactie, niet als
 * foreign key -- BEWUST, zie de bestandskop van includes/locaties.php en
 * includes/producenten.php: dat voorkomt dat het verwijderen/deactiveren
 * van een locatie, gebruiker of producent ooit historische transacties
 * zou "orphanen". Maar niets hield bij dat een naam is VERANDERD, dus een
 * hernoeming liet elke bestaande transactielog-rij voor altijd de oude
 * naam tonen -- en voorraadberekeningen die per locatie groeperen
 * (transactieNettoPerLocatie(), voorraadLot() in includes/voorraad.php)
 * behandelden de oude en nieuwe naam als twee losse, ongerelateerde
 * locaties, waardoor voorraad die onder de oude naam was geboekt na een
 * rename leek te "verdwijnen".
 *
 * Deze twee functies lossen dat op zonder de bestaande architectuur
 * (naam-als-vrije-tekst in event-tabellen) overhoop te halen: elke
 * rename wordt hier als een RIJ toegevoegd (nooit bewerkt/verwijderd, dus
 * ook een naam die later weer wordt teruggedraaid blijft via de keten
 * oplosbaar), en actueleNaam() volgt die keten op leesmoment naar de
 * meest recente naam.
 *
 * 'soort' onderscheidt twee onafhankelijke naamruimtes in dezelfde tabel:
 * 'locatie' (locatie_instellingen/locaties_extra) en 'specialist'
 * (users.naam, zie api/gebruikers.php -- nodig zodra een gebruikersnaam
 * bewerkbaar wordt, anders zou dat exact dezelfde bug introduceren voor
 * Leaderboard/Verbruik per Procedure, die ook op transactielog.specialist
 * groeperen).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Registreert dat $oud is hernoemd naar $nieuw, binnen naamruimte $soort.
 * Geen-op als de naam feitelijk niet veranderd is, of als $oud leeg is
 * (nieuw aangemaakte locatie/gebruiker -- er is dan niets om vanaf te
 * hernoemen).
 */
function registreerHernoeming(string $soort, string $oud, string $nieuw): void {
    $oud = trim($oud);
    $nieuw = trim($nieuw);
    if ($oud === '' || $nieuw === '' || $oud === $nieuw) return;

    db()->prepare('INSERT INTO naam_hernoemingen (soort, oude_naam, nieuwe_naam) VALUES (:soort, :oud, :nieuw)')
        ->execute([':soort' => $soort, ':oud' => $oud, ':nieuw' => $nieuw]);
}

/**
 * Lost $naam op naar zijn meest recente naam binnen naamruimte $soort,
 * door de hernoem-keten te volgen (oud->nieuw->nieuwer->...). Statische
 * cache per (soort), één keer per request opgebouwd -- dit wordt
 * potentieel per transactielog-rij aangeroepen (voorraadLot() e.d.), dus
 * geen aparte query per aanroep.
 *
 * Een hard maximum van 20 hops beschermt tegen een (in theorie onmogelijke,
 * maar niet database-afgedwongen) cirkel van hernoemingen die anders een
 * oneindige lus zou geven.
 */
function actueleNaam(string $soort, string $naam): string {
    static $kettingen = [];

    if (!isset($kettingen[$soort])) {
        $keten = [];
        $stmt = db()->prepare('SELECT oude_naam, nieuwe_naam FROM naam_hernoemingen WHERE soort = :soort ORDER BY id ASC');
        $stmt->execute([':soort' => $soort]);
        foreach ($stmt->fetchAll() as $r) {
            $keten[$r['oude_naam']] = $r['nieuwe_naam'];
        }
        $kettingen[$soort] = $keten;
    }

    $keten = $kettingen[$soort];
    $huidig = $naam;
    $hops = 0;
    while (isset($keten[$huidig]) && $hops < 20) {
        $huidig = $keten[$huidig];
        $hops++;
    }
    return $huidig;
}
