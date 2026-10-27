<?php
/**
 * "Contactpersonen"-onderdeel dat eerst
 * Op het gecombineerde Nieuws-dashboard stond, nu een eigen
 * scherm met een eigen scherm-recht ('contactpersonen'). Alleen lezen,
 * zie includes/nieuws_dashboard.php voor getContactpersonenOverzicht()
 * zelf (ongewijzigd).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/nieuws_dashboard.php';

apiRequireLogin();
apiRequireScherm('contactpersonen');
apiTry(fn() => getContactpersonenOverzicht());
