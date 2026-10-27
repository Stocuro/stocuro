<?php
/**
 * De samengevoegde Voorraad-megatabel, zie
 * includes/voorraad_tabel.php voor de volledige toelichting. Alleen lezen.
 * Hergebruikt het bestaande 'overzicht'-schermrecht -- er komt geen nieuw
 * apart schermrecht bij voor deze pagina, hij vervangt gewoon de
 * navigatie-ingang naar dezelfde onderliggende data.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/voorraad_tabel.php';

apiRequireLogin();
apiRequireScherm('overzicht');
apiTry(fn() => getVoorraadTabel());
