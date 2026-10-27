<?php
/**
 * Laborant-takenoverzicht (later uitgebreid met een derde categorie --
 * geen eigen schema-migratie nodig, puur
 * een extra afgeleide query): kastcontrole + vervallen voorraad +
 * ontbrekende batchgegevens, alle drie afgeleid, zie
 * includes/taken.php::getTakenLaborant(). Alleen lezen
 * -- de acties zelf (tellen / afboeken als vervallen / opnieuw scannen)
 * lopen via api/telling.php, api/scan_verbruik.php resp. de bestaande
 * Recente Transacties + scanformulieren. Daarom hier geen rolbeperking
 * buiten scherm-toegang: ook alleen-lezen mag zien wat er open staat.
 *
 * GET -> { kastcontrole: [...], vervallen: [...], ontbrekendeBatch: [...], totaal }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/taken.php';

apiRequireLogin();
apiRequireScherm('taken-laborant');
apiTry(fn() => getTakenLaborant());
