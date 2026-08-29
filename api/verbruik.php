<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/dashboards.php';

apiRequireLogin();
apiRequireScherm('verbruik');
apiTry(fn() => getVerbruiksgebruik());
