<?php
/**
 * Migratie 028, bijgewerkt in migratie 030: nieuwste producten + tijdelijke
 * vervangers. Was samen met Team en Contactpersonen op één endpoint, nu
 * een eigen endpoint met een eigen scherm-recht -- zie
 * includes/nieuws_dashboard.php en api/team_overzicht.php /
 * api/contactpersonen.php voor de andere twee.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/nieuws_dashboard.php';

apiRequireLogin();
apiRequireScherm('nieuws');
apiTry(fn() => getNieuwsAlleen());
