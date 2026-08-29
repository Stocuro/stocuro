<?php
/**
 * Maakt alle voorraad-/gebruiksdata leeg, zonder het schema (tabellen,
 * kolommen, migraties) aan te raken -- voor twee situaties:
 *
 *  1. Een schone start voordat je `genereer_testdata.php` draait (voorkomt
 *     EAN-botsingen met eerder ingeladen demo- of eigen data).
 *  2. "Een lege database opleveren": alle fictieve/test-data eruit, vlak
 *     voordat het systeem naar een echte installatie gaat (of aan een
 *     opdrachtgever wordt overgedragen) zonder dat er iets van de
 *     testfase in blijft staan.
 *
 * Wat blijft staan (tenzij je --met-gebruikers meegeeft):
 *  - users: je zou anders mogelijk niet meer kunnen inloggen. Geef
 *    --met-gebruikers mee als je ECHT ook alle accounts kwijt wilt (voor
 *    situatie 2 hierboven, vlak vóór overdracht) -- maak daarna eerst een
 *    nieuw beheerdersaccount aan (zie README, sectie Installatie) voordat
 *    je weer kunt inloggen.
 *  - locatie_instellingen (de 4 vaste locatie-"slots"): dat is configuratie
 *    (welke namen actief zijn), geen data. Alleen laatst_geteld_op wordt
 *    gereset, zodat de kastcontrole-taken weer als "nog nooit geteld"
 *    verschijnen.
 *  - schema_migrations: nooit aanraken, dat is migratieboekhouding.
 *
 * Gebruik, als je SSH/command-line-toegang tot de server hebt:
 *   php migratie/leegmaken.php --ja
 *   php migratie/leegmaken.php --ja --met-gebruikers
 *
 * Gebruik, als je GEEN SSH hebt (gangbaar bij gedeelde hosting): gewoon
 * de URL in je browser openen, met dezelfde bevestiging als querystring
 * i.p.v. commandoregel-vlag:
 *   https://jouw-domein/migratie/leegmaken.php?ja=1
 *   https://jouw-domein/migratie/leegmaken.php?ja=1&met_gebruikers=1
 * Verwijder (of hernoem) dit bestand na gebruik als je het zo draait --
 * anders kan iedereen die de URL kent (of raadt) je hele database
 * leegtrekken. Zelfde advies als bij sql/migrate.php.
 *
 * De bevestiging (--ja / ?ja=1) is verplicht en is een bewuste "typ het
 * niet per ongeluk aan"-drempel: dit script is onomkeerbaar (geen
 * prullenbak, geen backup) op elke tabel die het leegmaakt.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function leegmaken_log(string $msg): void {
    // PHP_EOL is \n op de server; in een browser toont dat niets zichtbaars
    // (geen <br>), dus voeg ook een <br> toe zodat het leesbaar blijft als
    // dit via een URL wordt gedraaid i.p.v. de command line.
    echo $msg . (PHP_SAPI === 'cli' ? PHP_EOL : '<br>' . PHP_EOL);
}

/** STDERR bestaat alleen bij de command-line-SAPI -- fwrite(STDERR, ...) zou hier fataal zijn als dit via een URL draait. */
function leegmaken_fout(string $msg): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . PHP_EOL);
    } else {
        echo $msg . '<br>' . PHP_EOL;
    }
}

$args = $argv ?? [];
$bevestigd = in_array('--ja', $args, true) || ($_GET['ja'] ?? '') === '1';
if (!$bevestigd) {
    leegmaken_log('Dit maakt alle voorraad-/gebruiksdata leeg (artikelen, transacties, orders, specialisten, ...).');
    leegmaken_log('Gebruikersaccounts en locatienamen blijven bestaan, tenzij je ook --met-gebruikers/?met_gebruikers=1 meegeeft.');
    leegmaken_log('Dit is onomkeerbaar. Voer opnieuw uit met --ja (command line) of ?ja=1 (browser) om te bevestigen:');
    leegmaken_log('  php migratie/leegmaken.php --ja [--met-gebruikers]');
    leegmaken_log('  https://jouw-domein/migratie/leegmaken.php?ja=1[&met_gebruikers=1]');
    exit(1);
}
$ookGebruikers = in_array('--met-gebruikers', $args, true) || ($_GET['met_gebruikers'] ?? '') === '1';

// Geen enkele van deze tabellen heeft een FOREIGN KEY naar een andere
// tabel in deze lijst behalve artikel_locatie_extra -> locaties_extra
// (migratie 007) en producent_contacten -> producenten (migratie 012) --
// FOREIGN_KEY_CHECKS uitzetten voorkomt dat de volgorde hieronder
// daarvoor zou moeten uitmaken.
$tabellen = [
    'transactielog_wijzigingen', 'transactielog',
    // Migratie 035: hoort bij specifieke (nu verdwijnende) scans -- zie
    // toelichting in leegmaken.sql.
    'gs1_correcties',
    'orders', 'eenmalige_bestellingen',
    'artikel_locatie_extra', 'artikelen',
    'audit_log', 'foutlog',
    'specialisten', 'procedure_typen',
    'locaties_extra',
    'producent_contacten', 'producenten',
];

try {
    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    foreach ($tabellen as $tabel) {
        $pdo->exec("TRUNCATE TABLE `$tabel`");
        leegmaken_log("Leeggemaakt: $tabel");
    }

    $pdo->exec('UPDATE locatie_instellingen SET laatst_geteld_op = NULL');
    leegmaken_log('Gereset: locatie_instellingen.laatst_geteld_op (namen/volgorde blijven ongewijzigd)');

    if ($ookGebruikers) {
        $pdo->exec('TRUNCATE TABLE users');
        leegmaken_log('Leeggemaakt: users -- maak een nieuw beheerdersaccount aan (zie README, Installatie) voordat je weer kunt inloggen.');
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    leegmaken_log('Klaar. Database is leeg' . ($ookGebruikers ? ' (inclusief gebruikersaccounts).' : ', gebruikersaccounts en locatienamen zijn ongewijzigd.'));
} catch (Throwable $e) {
    leegmaken_fout('Leegmaken mislukt: ' . $e->getMessage());
    exit(1);
}
