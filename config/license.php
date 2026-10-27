<?php
/**
 * Licentie-configuratie. In tegenstelling tot
 * config/database.local.php is DIT bestand veilig om mee te leveren en
 * in versiebeheer op te nemen: het bevat uitsluitend de PUBLIEKE Ed25519
 * sleutel waarmee activatiecodes geverifieerd worden, nooit de private
 * (ondertekenende) sleutel. Met alleen deze public key kan niemand een
 * geldige code VERZINNEN -- alleen ECHTHEID van een al bestaande code
 * controleren. De private key blijft uitsluitend bij de leverancier (zie
 * vendor-tools/), nooit in deze codebase.
 *
 * Zie includes/license.php voor de volledige verificatielogica.
 */

declare(strict_types=1);

return [
    // Ed25519 public key, ruwe 32 bytes, base64-gecodeerd.
    'public_key_b64' => 'e/VMeK488Ih6sjs8tlCCzm2Jnw6ziPZjaoZLq/ZtT9w=',

    // Hoeveel uur een succesvolle licentiecontrole in de sessie
    // vertrouwd wordt voordat includes/license.php 'm opnieuw
    // uitvoert (zie herverifieerLicentieSessie() in includes/license.php).
    // Laag genoeg om een net-verlopen/ingetrokken licentie binnen
    // redelijke tijd te laten gelden, hoog genoeg om niet bij elke
    // request de database/crypto te belasten.
    //
    // Verlaagd van 6 naar 1 -- een
    // lokale database, niet zwaar belast, dus de extra herverificaties
    // wegen niet op tegen sneller ontdekken/terugdraaien van handmatige
    // manipulatie (bijv. direct in license_activations of users geknoeid).
    'hercontrole_uren' => 1,
];
