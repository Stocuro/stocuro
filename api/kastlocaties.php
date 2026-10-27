<?php
/**
 * Suggestielijst voor het kastlocatie-
 * dropdown-veld op Product Bewerken / Artikel Toevoegen (zie
 * includes/artikelen.php::getKastlocatieSuggesties()). Alleen-lezen, geen
 * eigen schrijf-actie -- nieuwe waardes ontstaan gewoon vanzelf zodra een
 * artikel met die kastlocatie wordt opgeslagen, net als bij Producent.
 *
 * Geen apiRequireScherm() (zelfde afweging als api/locaties.php): dit
 * wordt vanuit meerdere schermen aangeroepen (product-edit, artikel-
 * toevoegen) en is puur leesbare, ongevoelige data -- iedereen ingelogd
 * mag het opvragen.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/artikelen.php';

apiRequireLogin();
apiTry(fn() => getKastlocatieSuggesties());
