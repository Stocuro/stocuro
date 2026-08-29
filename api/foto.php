<?php
/**
 * Nieuw t.o.v. de oorspronkelijke 18 endpoints uit het migratieplan:
 * getFotoDataUri() werd in de oude dashboard-JS ook los aangeroepen
 * (live foto-voorbeeld in het edit-formulier), dus die krijgt hier
 * ook een eigen endpoint.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/artikelen.php';

apiRequireLogin();
$url = $_GET['url'] ?? '';
apiTry(fn() => getFotoDataUri($url));
