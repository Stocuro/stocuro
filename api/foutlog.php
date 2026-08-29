<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/logging.php';

apiRequireLogin();
apiRequireScherm('foutlog');
apiTry(fn() => getFoutlog());
