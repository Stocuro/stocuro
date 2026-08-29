<?php
/**
 * Beheerder-takenoverzicht (migratie 026): onbekende scans + onvolledige
 * productgegevens, beide afgeleid, zie includes/taken.php::getTakenBeheerder().
 * Alleen lezen -- "afronden" gebeurt op de Admin-schermen "Onbekende
 * Producten" resp. de productpagina zelf.
 *
 * GET -> { onbekendeScans: [...], onvolledigeProducten: [...], totaal }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/taken.php';

apiRequireLogin();
apiRequireScherm('taken-beheerder');
apiTry(fn() => getTakenBeheerder());
