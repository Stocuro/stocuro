<?php
/**
 * Migratie 030_groep_landingsscherm: samengesteld beschikbaarheids-
 * overzicht (bedoeld als landingsscherm voor de artsen-groep). Alleen
 * lezen, zie includes/beschikbaarheid.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/beschikbaarheid.php';

apiRequireLogin();
apiRequireScherm('beschikbaarheid');
apiTry(fn() => getBeschikbaarheid());
