<?php
/**
 * Leaderboard-statistieken: meeste scans per gebruiker,
 * meeste procedures per specialist/type, optioneel gefilterd op één
 * procedure_type. Alleen lezen, iedereen ingelogd mag dit opvragen --
 * geen gevoelige data (geen patiëntnummers e.d.), puur tellingen.
 *
 * GET -> { periode?: 'week'|'maand'|'kwartaal'|'jaar'|'totaal' (default 'maand'),
 *          procedure_type?: string }
 *
 * ?actie=picc_rotatie geeft
 * i.p.v. de periodetelling hierboven de rotatievolgorde terug ("wie is aan
 * de beurt") -- zelfde scherm-recht ('leaderboard'), want het is dezelfde
 * onderliggende transactielog-data, alleen anders gesorteerd. Zie
 * getPiccRotatie() in includes/leaderboard.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/leaderboard.php';

apiRequireLogin();
apiRequireScherm('leaderboard');

if (($_GET['actie'] ?? '') === 'picc_rotatie') {
    apiTry(fn() => getPiccRotatie());
}

$periode = (string) ($_GET['periode'] ?? 'maand');
$procedureType = isset($_GET['procedure_type']) && $_GET['procedure_type'] !== '' ? (string) $_GET['procedure_type'] : null;

apiTry(fn() => getLeaderboardData($periode, $procedureType));
