<?php
/**
 * Recente transacties (laatste 7 dagen), gegroepeerd per scanactie
 * (batch_id) + bewerken/verwijderen van een hele batch in één keer, om
 * scanfouten recht te zetten. Alle ingelogde rollen mogen bewerken/
 * verwijderen behalve 'alleen-lezen' (en, sinds gast-modus, ook geen
 * gast) -- dit is een dagelijkse correctietaak voor het team, geen
 * beheerderstaak (in tegenstelling tot bijv. Stamgegevens/Locaties).
 * isTransactieBewerkbaar() in includes/transacties.php dwingt de
 * 7-dagen-grens server-side af, per regel, dus ook een handmatige
 * aanroep buiten de UI om kan geen oudere historie wijzigen.
 *
 * Werkt dit voortaan altijd op de hele scanactie
 * (alle regels die in één indiening zijn ontstaan), niet meer op één
 * losse regel: een Verplaatsing heeft zo z'n uit- en in-boeking samen
 * terug te draaien/aan te passen, en een Verbruik-scan met bijv. 14
 * gescande barcodes is in één keer te corrigeren i.p.v. 14 losse
 * Correcties. batch_id is ofwel een echte batch_id, ofwel
 * 'row-<id>' voor een rij van vóór die migratie (zie
 * getRecenteTransactiesGegroepeerd()) -- in dat geval bestaat de "batch"
 * gewoon uit die ene rij, dus dezelfde acties werken ook op oude data.
 *
 * GET  -> recente transacties, gegroepeerd per scanactie (laatste 7 dagen)
 * POST -> { actie: 'bewerken', batch_id, hoeveelheid?, locatie?,
 *           van_locatie?, naar_locatie?, specialist?, procedure_type?,
 *           opmerking? }
 *           (van_locatie/naar_locatie alleen relevant voor een
 *           Verplaatsing-batch; voor elk ander batchtype gewoon locatie)
 *      -> { actie: 'verwijderen', batch_id }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/transacties.php';

$user = apiRequireLogin();
apiRequireScherm('recente-transacties');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Was hier voorheen een losse ad-hoc alleen-lezen-check; nu de
    // gedeelde apiRequireWriteAccess(), die ook gast blokkeert.
    apiRequireWriteAccess();

    $body = readJsonBody();
    $actie = (string) ($body['actie'] ?? '');
    $batchId = (string) ($body['batch_id'] ?? '');

    if ($actie === 'verwijderen') {
        apiTry(fn() => verwijderTransactieBatch($batchId, $user['naam']));
    } else {
        apiTry(fn() => bewerkTransactieBatch($batchId, $body, $user['naam']));
    }
} else {
    apiTry(fn() => getRecenteTransactiesGegroepeerd());
}
