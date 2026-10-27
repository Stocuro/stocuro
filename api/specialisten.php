<?php
/**
 * Keuzelijst specialisten/laboranten. Sinds de rollenmodel-herziening is
 * dit GEEN aparte tabel meer die los van een inlogaccount
 * beheerd wordt -- elke specialist/laborant IS een gebruikersaccount
 * (rol 'specialist'/'laborant' op de users-tabel). Aanmaken, actief/
 * inactief zetten en het e-mailadres wijzigen gebeurt daarom voortaan
 * via het Gebruikers-scherm (api/gebruikers.php), niet meer hier.
 *
 * Iedereen die ingelogd is mag de lijst opvragen (nodig voor het Verbruik
 * Scannen-formulier en de Eenmalige Bestelling); dit endpoint is nu
 * GET-only.
 *
 * GET  -> lijst specialisten/laboranten (incl. inactieve, frontend
 *         filtert bij invulformulieren zelf op actief=1)
 * GET  ?legacy=1 -> namen uit de oude 'specialisten'-tabel die nog geen
 *         gekoppeld account hebben ("nog te migreren", zie
 *         includes/stamgegevens.php::getNietGemigreerdeSpecialisten()) --
 *         alleen voor de beheerder, getoond op het Gebruikers-scherm.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/stamgegevens.php';

apiRequireLogin();

if (($_GET['legacy'] ?? '') === '1') {
    apiRequireBeheerder();
    apiTry(fn() => getNietGemigreerdeSpecialisten());
} else {
    apiTry(fn() => getSpecialisten());
}
