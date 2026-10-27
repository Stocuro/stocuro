<?php
/**
 * (onderdeel van
 * "Licentiemodel: registratie blokkeren zonder geldige licentie +
 * profielpagina"): zelf-service profielpagina -- elke ECHT ingelogde
 * gebruiker (bewust requireLogin() hieronder, niet de gastvriendelijke
 * apiRequireLogin()/requireLoginOfGast() -- een gast-"account" bestaat
 * niet echt: geen rij in users, geen wachtwoord, geen licentie om te
 * tonen) kan hier zijn eigen accountgegevens + licentiestatus bekijken,
 * en als enige plek in de app zijn EIGEN wachtwoord wijzigen (met het
 * huidige wachtwoord ter bevestiging -- in tegenstelling tot de
 * beheerder-actie 'wachtwoord_resetten' in api/gebruikers.php, die geen
 * huidig wachtwoord kent/hoeft te kennen omdat een ANDER account wordt
 * gereset).
 *
 * GET  -> eigen accountgegevens (naam/email/rol/taal/...) + licentieStatus
 * POST -> { actie: 'wachtwoord_wijzigen', huidig_wachtwoord, nieuw_wachtwoord }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/license.php';
require_once __DIR__ . '/../includes/audit.php';

// Bewust requireLogin() (STRIKT, zie includes/auth.php) i.p.v.
// apiRequireLogin() -- dit scherm is uitsluitend voor een echt account.
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    $actie = $body['actie'] ?? 'wachtwoord_wijzigen';

    if ($actie === 'wachtwoord_wijzigen') {
        apiTry(function () use ($body, $user) {
            $huidig = (string) ($body['huidig_wachtwoord'] ?? '');
            $nieuw = (string) ($body['nieuw_wachtwoord'] ?? '');
            if (strlen($nieuw) < 8) {
                return ['success' => false, 'error' => 'Het nieuwe wachtwoord moet minstens 8 tekens lang zijn'];
            }

            $stmt = db()->prepare('SELECT wachtwoord_hash FROM users WHERE id = :id');
            $stmt->execute([':id' => $user['id']]);
            $rij = $stmt->fetch();
            if (!$rij || !password_verify($huidig, $rij['wachtwoord_hash'])) {
                return ['success' => false, 'error' => 'Huidig wachtwoord is onjuist'];
            }

            db()->prepare('UPDATE users SET wachtwoord_hash = :hash WHERE id = :id')
                ->execute([':hash' => password_hash($nieuw, PASSWORD_DEFAULT), ':id' => $user['id']]);

            // Nieuwe wachtwoord wordt NOOIT gelogd (net als bij de
            // beheerder-reset in api/gebruikers.php) -- alleen dat de
            // actie heeft plaatsgevonden.
            logAudit($user['naam'], 'eigen_wachtwoord_gewijzigd', 'gebruiker', (string) $user['id'], null);
            return ['success' => true, 'message' => 'Wachtwoord gewijzigd'];
        });
    } else {
        http_response_code(400);
        jsonResponse(['success' => false, 'error' => 'Onbekende actie']);
    }
} else {
    apiTry(function () use ($user) {
        $stmt = db()->prepare('SELECT naam, email, rol, is_beheerder, taal, foto, aangemaakt_op, laatst_ingelogd FROM users WHERE id = :id');
        $stmt->execute([':id' => $user['id']]);
        $rij = $stmt->fetch();
        if (!$rij) return ['error' => 'Gebruiker niet gevonden'];

        // Zelfde LIVE herverificatie als het Gebruikers-scherm (beheerder-
        // only) -- zie includes/license.php::controleerLicentieGebruiker().
        // Hier voor het EIGEN account, geen beheerdersrecht nodig.
        $licentie = controleerLicentieGebruiker((int) $user['id']);
        $rij['licentieStatus'] = [
            'ok' => $licentie['ok'],
            'vrij' => $licentie['vrij'],
            'geldigTot' => $licentie['geldigTot'],
            'reden' => $licentie['reden'],
        ];
        return $rij;
    });
}
