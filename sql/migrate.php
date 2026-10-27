<?php
/**
 * Lichtgewicht migratierunner (migratieplan hoofdstuk 9.3).
 *
 * De daadwerkelijke logica is verplaatst naar
 * includes/installer.php::voerMigratiesUit(), zodat install.php (de
 * browser-installer/updater in de hoofdmap) en dit command-line script
 * precies dezelfde code gebruiken -- dit bestand is nu alleen nog een
 * dunne CLI-wrapper eromheen.
 *
 * Gebruik (vanaf de command line op de server, of eenmalig via een
 * browser-aanroep die je daarna weer verwijdert):
 *   php sql/migrate.php
 *
 * Voor een verse installatie: gebruik liever install.php (browser-
 * wizard), die roept dit ook aan maar regelt daarnaast de databaseconfig
 * en het eerste account.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/installer.php';

$resultaat = voerMigratiesUit();
foreach ($resultaat['log'] as $regel) {
    echo $regel . PHP_EOL;
}

if (!$resultaat['success']) {
    fwrite(STDERR, 'Migratie mislukt: ' . $resultaat['error'] . PHP_EOL);
    exit(1);
}
