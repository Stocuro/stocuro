<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/artikelen.php';
// GetProductDetail() roept getMinMaxAdviesOverzicht() aan
// (min/max-voorstel op de productpagina) -- hier vereist i.p.v. vanuit
// artikelen.php zelf, want minmax.php require_once't op zijn beurt
// artikelen.php al (zelfde reden als de toelichting bij getBestellersData()
// in includes/voorraad.php: een circulaire require vermijden).
require_once __DIR__ . '/../includes/minmax.php';
// GetTransactieHistorieVoorEanGeclusterd()
// staat in includes/transacties.php -- apart hier vereist (zelfde reden als
// minmax.php hierboven) omdat transacties.php op zijn beurt artikelen.php
// require't, dus vanuit artikelen.php zelf zou dit een circulaire require zijn.
require_once __DIR__ . '/../includes/transacties.php';

apiRequireLogin();
apiRequireScherm('product-search');
$ean = $_GET['ean'] ?? '';
apiTry(fn() => getProductDetail($ean));
