<?php
/**
 * Migratie 030, op verzoek van Harm: "Contactpersonen"-onderdeel dat eerst
 * op het gecombineerde Nieuws-dashboard stond (migratie 028), nu een eigen
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
