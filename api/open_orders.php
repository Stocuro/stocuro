<?php
/**
 * GET (geen actie) -> alle openstaande reguliere orders, voor het Open
 *                     Orders-scherm ('open-orders'-schermrecht).
 * GET ?mijn=1       -> alleen de
 *                      openstaande orders waar de ingelogde gebruiker zelf
 *                      besteller van is, voor de Home-widget "Mijn
 *                      Bestellingen" ('mijn-bestellingen'-schermrecht,
 *                      zelfde poort als api/eenmalige_bestelling.php's
 *                      eigen ?mijn=1) -- zie
 *                      includes/orders.php::getEigenOpenOrders().
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/orders.php';

$user = apiRequireLogin();

if (($_GET['mijn'] ?? '') === '1') {
    apiRequireScherm('mijn-bestellingen');
    apiTry(fn() => getEigenOpenOrders($user['naam']));
} else {
    apiRequireScherm('open-orders');
    apiTry(fn() => getOpenOrders());
}
