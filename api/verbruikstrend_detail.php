<?php
/**
 * Migratie 026: product-drilldown achter één cel van het
 * Verbruikstrends-tabblad. Zie includes/dashboards.php::getVerbruiksTrendDetail()
 * voor de reeks/sub-parameters. Alleen lezen, zelfde toegang als het
 * trends-scherm zelf (api/trends.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/dashboards.php';

apiRequireLogin();
apiRequireScherm('trends');
$maand = (string) ($_GET['maand'] ?? '');
$reeks = (string) ($_GET['reeks'] ?? '');
$sub = (string) ($_GET['sub'] ?? '');
apiTry(fn() => getVerbruiksTrendDetail($maand, $reeks, $sub));
