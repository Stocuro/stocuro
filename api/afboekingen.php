<?php
/**
 * Historisch overzicht: vervallen (over datum afgeboekte) voorraad en
 * tekorten uit kastcontrole (niet teruggevonden bij tellen). Zie
 * includes/dashboards.php::getAfboekingenOverzicht(). Alleen lezen, net
 * als de andere dashboards (trends.php e.d.) -- geen rolbeperking buiten
 * ingelogd zijn.
 *
 * ?periode=... (1m/3m/6m/1j/2j/totaal, zie
 * AFBOEKINGEN_PERIODES) filtert op tijdstempel -- ontbreekt de parameter,
 * dan blijft het gedrag zoals voorheen (volledige historie).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/dashboards.php';

apiRequireLogin();
apiRequireScherm('afboekingen');
$periode = (string) ($_GET['periode'] ?? 'totaal');
apiTry(fn() => getAfboekingenOverzicht($periode));
