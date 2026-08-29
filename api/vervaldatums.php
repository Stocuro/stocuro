<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/voorraad.php';

apiRequireLogin();
apiRequireScherm('vervaldatums');
apiTry(fn() => getVervaldatumDetail());
