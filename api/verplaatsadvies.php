<?php
/**
 * Verplaatsadvies (includes/verplaatsadvies.php) -- alleen-lezen: dit
 * scherm toont een advies, er is bewust geen "toepassen"-actie (een
 * verplaatsing gebeurt fysiek via het bestaande Verplaatsing-formulier,
 * zie de bestandskop van includes/verplaatsadvies.php). Zelfde
 * gastvriendelijke toegang als de andere dashboard-endpoints.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/verplaatsadvies.php';

apiRequireLogin();
apiRequireScherm('verplaatsadvies');
apiTry(fn() => getVerplaatsAdviesOverzicht());
