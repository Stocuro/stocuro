<?php
/**
 * Lokaal, per-installatie geheim,
 * gebruikt om de "gratis gebruiker" van deze installatie een echt
 * cryptografisch token te geven (HMAC-SHA256) in plaats van een kaal
 * aan/uit-vinkje in de database (zie includes/license.php voor de
 * volledige toelichting op waarom -- dat vinkje bleek in de praktijk
 * met een simpele UPDATE-query te omzeilen).
 *
 * install.php GENEREERT dit bestand zelf, automatisch, de eerste keer
 * dat het draait -- dit voorbeeldbestand wordt NOOIT gebruikt om
 * daadwerkelijk in te loggen, het bestaat alleen zodat de map/vorm
 * zichtbaar is. Net als config/database.local.php mag het echte
 * config/instance.local.php NOOIT in versiebeheer of in een support-
 * ticket terechtkomen: wie dit geheim kent kan (in combinatie met
 * bestandstoegang tot deze server) een geldig gratis-token vervalsen.
 *
 * Bewust GEEN voor de hand liggende naam als "license.local.php" naast
 * license.php -- iets minder makkelijk te raden voor wie toevallig door
 * de config/-map browst, al is dat sowieso al afgeschermd door de
 * .htaccess ("Require all denied") die op deze map staat.
 */

declare(strict_types=1);

return [
    // 32 willekeurige bytes, base64. install.php genereert dit zelf met
    // random_bytes(32) -- dit voorbeeld is uiteraard geen echt geheim.
    'secret_b64' => 'VUL_DIT_NOOIT_HANDMATIG_IN__INSTALL_PHP_DOET_DIT_AUTOMATISCH',
];
