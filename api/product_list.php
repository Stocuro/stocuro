<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/artikelen.php';

apiRequireLogin();
apiRequireScherm('product-search');
apiTry(fn() => getProductList());
