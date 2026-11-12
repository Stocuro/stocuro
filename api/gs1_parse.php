<?php
/**
 * Kleine, puur lezende hulp-endpoint: parseert één ruwe GS1-scanregel en
 * geeft alleen lot/vervaldatum terug (EAN wordt bewust niet teruggegeven --
 * dit endpoint bestaat niet om een product op te zoeken, maar om op
 * meerdere schermen een "scan de barcode om lot/vervaldatum over te nemen"-
 * knop te kunnen bedienen zonder de hele scan-/boekingslogica opnieuw te
 * doorlopen): het ontbrekend-lotnummer-paneel bij Ontvangst/Verbruik (zie
 * includes/transacties.php::verwerkScan()) en het lot-correctiescherm op
 * de productdetailpagina (zie includes/lot_correcties.php). Geen
 * apiRequireScherm() -- dit is een gedeelde utility, geen eigen scherm, en
 * geeft zelf geen gevoelige data terug (puur een parse-resultaat).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/gs1_parser.php';
require_once __DIR__ . '/../includes/lot_correcties.php';

apiRequireLogin();

$raw = (string) ($_GET['gs1_raw'] ?? '');
apiTry(function () use ($raw) {
    $parsed = parseGS1Raw($raw);
    // Zelfde eerder-vastgelegde correctie toepassen als bij een echte scan
    // (zie includes/lot_correcties.php) -- is deze exacte barcode al eens
    // handmatig rechtgezet, dan geeft dit endpoint meteen de gecorrigeerde
    // waarden terug i.p.v. de kennelijk-foute parse.
    $parsed = pasGs1CorrectieToe($raw, $parsed);

    return [
        'success' => true,
        'lot' => $parsed['lot'],
        'vervaldatum_sql' => gs1ExpiryToSqlDate($parsed['expiry']),
    ];
});
