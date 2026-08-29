<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/artikelen.php';
// Migratie 035, op verzoek van Harm: lot_corrigeren hieronder heeft zowel
// corrigeerLot() (includes/lot_correcties.php) als logTransactieWijziging()
// (includes/transacties.php, wordt daarbinnen aangeroepen) nodig -- geen
// van beide komt automatisch mee via artikelen.php hierboven.
require_once __DIR__ . '/../includes/transacties.php';
require_once __DIR__ . '/../includes/lot_correcties.php';

$user = apiRequireLogin();
apiRequireScherm('product-search');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // GET (lezen) is hierboven al gastvriendelijk via apiRequireLogin();
    // schrijven (dit blok) blijft alleen voor echt ingelogde, niet-
    // alleen-lezen accounts.
    apiRequireWriteAccess();
    $body = readJsonBody();
    $ean = $body['ean'] ?? '';
    // Migratie 023: foto_bijwerken/foto_verwijderen zijn evengoed het
    // bewerken van dit artikel als de reguliere veldenupdate hieronder,
    // dus bewust hetzelfde apiRequireWriteAccess()-niveau -- geen apart
    // beheerdersrecht, in tegenstelling tot de foto-acties op
    // api/gebruikers.php (dat scherm is helemaal beheerder-only).
    $actie = $body['actie'] ?? 'updates';
    if ($actie === 'foto_bijwerken') {
        apiTry(fn() => uploadArtikelFoto($ean, (string) ($body['foto'] ?? ''), $user['naam']));
    } elseif ($actie === 'foto_verwijderen') {
        apiTry(fn() => verwijderArtikelFoto($ean, $user['naam']));
    } elseif ($actie === 'status_bijwerken') {
        // Migratie 025, op verzoek van Harm: status ('Actief'/'Niet
        // leverbaar'/'Niet meer in gebruik') + optionele vervanger-
        // koppeling naar een BESTAAND artikel. Het "nieuw artikel
        // aanmaken als vervanger"-pad loopt niet hierlangs -- dat gaat via
        // api/artikel_toevoegen.php (vervangtVoorEan), zie
        // includes/artikelen.php::koppelAlsVervanger().
        $vervangendArtikelId = !empty($body['vervangend_artikel_id']) ? (int) $body['vervangend_artikel_id'] : null;
        apiTry(fn() => setArtikelStatus($ean, (string) ($body['status'] ?? 'Actief'), $vervangendArtikelId, $user['naam']));
    } elseif ($actie === 'lot_corrigeren') {
        // Migratie 035, op verzoek van Harm: lot/vervaldatum van een
        // specifiek, mogelijk verkeerd geparst lot rechtzetten -- zie
        // includes/lot_correcties.php::corrigeerLot() voor waarom dit
        // bewust een aparte, niet aan de 7-dagen-grens gebonden actie is.
        apiTry(fn() => corrigeerLot(
            $ean,
            $body['oudLot'] ?? null,
            $body['oudVervaldatum'] ?? null,
            $body['nieuwLot'] ?? null,
            $body['nieuweVervaldatum'] ?? null,
            $user['naam']
        ));
    } elseif ($actie === 'ean_wijzigen') {
        // Op verzoek van Harm: EAN is bewust géén gewoon veld
        // in de generieke 'updates'-route hieronder -- zie de toelichting bij
        // wijzigArtikelEan()/ARTIKEL_EAN_CASCADE_TABELLEN in
        // includes/artikelen.php voor waarom dat een aparte, transactionele
        // actie met cascade nodig heeft.
        apiTry(fn() => wijzigArtikelEan($ean, (string) ($body['nieuweEan'] ?? ''), $user['naam']));
    } else {
        $updates = $body['updates'] ?? [];
        apiTry(fn() => updateArtikelVeld($ean, is_array($updates) ? $updates : [], $user['naam']));
    }
} else {
    $ean = $_GET['ean'] ?? '';
    apiTry(fn() => getArtikelEditData($ean));
}
