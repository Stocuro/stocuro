<?php
/**
 * Migratie 036, op verzoek van Harm: browser-installatiewizard, zodat een
 * klant het gedistribueerde ZIP-bestand kan uitpakken op de eigen server
 * en zonder command-line-toegang (FTP/cPanel volstaat) een werkende
 * installatie krijgt. Geen internetverbinding nodig -- alles hieronder
 * werkt volledig lokaal tegen de eigen database.
 *
 * Vier fasen, bepaald door de HUIDIGE STAAT (geen fragiele sessie-vlaggen
 * die een pagina-ververs/afgebroken stap in de war zouden kunnen sturen):
 *   A. config/database.local.php ontbreekt of verbindt niet -> DB-formulier.
 *   B. verbinding werkt, maar er staan nog niet-uitgevoerde migraties
 *      klaar -> voert die automatisch uit (idempotent, zie
 *      includes/installer.php::voerMigratiesUit()).
 *   C. migraties klaar, maar er bestaat nog geen enkele gebruiker ->
 *      formulier voor het EERSTE (gratis, vrije) account.
 *   D. er bestaat al minstens één gebruiker -> installatie is voltooid:
 *      install.lock wordt (indien nog niet aanwezig) weggeschreven en dit
 *      bestand weigert vanaf dan zichzelf verder uit te voeren voor
 *      iedereen die niet als beheerder is ingelogd -- de ENIGE actie die
 *      daarna nog mogelijk is, is een ingelogde beheerder die handmatig
 *      "nieuwe migraties uitvoeren" klikt (het updatepad voor toekomstige
 *      versies: nieuwe bestanden uploaden inclusief een nieuw install.php,
 *      en deze pagina opnieuw openen voert dan alleen de NIEUWE
 *      sql/migrations/*.sql-bestanden uit, zonder opnieuw naar
 *      databasegegevens te vragen of een account aan te maken).
 *
 * Op verzoek van Harm (keep it simple): platte HTML-formulieren met een
 * volledige pagina-herlaad per stap, geen JavaScript nodig om te kunnen
 * installeren -- robuuster voor een wizard die draait vóórdat er ook maar
 * iets anders werkt.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/installer.php';
// Migratie 037: maakLokaalVrijToken()/zorgVoorInstantieGeheim()/
// telAantalGeldigeVrijeTokens() -- zie includes/license.php voor de
// volledige toelichting op het HMAC-gebaseerde gratis-token.
require_once __DIR__ . '/includes/license.php';

const INSTALL_LOCK_PATH = __DIR__ . '/install.lock';
const DB_CONFIG_PATH = __DIR__ . '/config/database.local.php';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function paginaOmheen(string $titel, string $inhoud): never {
    $css = @filemtime(__DIR__ . '/assets/css/style.css') ?: time();
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($titel) . ' — Stocuro installatie</title>'
        . '<link rel="stylesheet" href="assets/css/style.css?v=' . $css . '">'
        . '<style>.install-wrap{max-width:640px;margin:48px auto;padding:0 16px;}'
        . '.install-log{background:#111;color:#ddd;padding:12px;border-radius:6px;'
        . 'font-family:monospace;font-size:13px;white-space:pre-wrap;max-height:320px;overflow:auto;}'
        . '.install-step{color:#888;font-size:13px;margin-bottom:4px;}</style>'
        . '</head><body><div class="install-wrap">' . $inhoud . '</div></body></html>';
    exit;
}

// ---------------------------------------------------------------------
// Fase D-check gebeurt HELEMAAL vooraan: zodra er een lock-bestand is,
// is de rest van dit script (DB-formulier, gebruiker aanmaken) voor
// iedereen op slot, ongeacht wat er verder in de request staat.
// ---------------------------------------------------------------------
if (file_exists(INSTALL_LOCK_PATH)) {
    require_once __DIR__ . '/includes/auth.php';
    $ingelogd = currentUser();

    if (!$ingelogd || empty($ingelogd['is_beheerder'])) {
        paginaOmheen('Al geïnstalleerd', '<h1>Stocuro is al geïnstalleerd</h1>'
            . '<p>Deze installatiewizard is al eerder succesvol afgerond en staat nu op slot.</p>'
            . '<p>Wil je na een update controleren op nieuwe migraties? Log dan eerst in als beheerder.</p>'
            . '<p><a class="btn-save" style="display:inline-block;text-decoration:none;" href="login.php">Naar inloggen →</a></p>');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['actie'] ?? '') === 'migraties_uitvoeren') {
        $resultaat = voerMigratiesUit();
        paginaOmheen('Migraties uitgevoerd', '<h1>Migraties uitgevoerd</h1>'
            . '<div class="install-log">' . h(implode("\n", $resultaat['log'])) . '</div>'
            . ($resultaat['success']
                ? '<p style="margin-top:16px;">✅ Gelukt.</p>'
                : '<p style="margin-top:16px;color:#b00;">❌ Er ging iets mis: ' . h($resultaat['error']) . '</p>')
            . '<p><a href="app.php">← Terug naar de app</a></p>');
    }

    paginaOmheen('Updatepad', '<h1>Stocuro is al geïnstalleerd</h1>'
        . '<p>Ingelogd als beheerder: ' . h($ingelogd['naam']) . '.</p>'
        . '<p>Heb je zojuist een nieuwere versie geüpload (inclusief dit install.php-bestand)? '
        . 'Klik hieronder om alleen de NIEUWE migraties uit te voeren -- er wordt niets opnieuw '
        . 'geconfigureerd en er wordt geen nieuw account aangemaakt.</p>'
        . '<form method="post"><input type="hidden" name="actie" value="migraties_uitvoeren">'
        . '<button type="submit" class="btn-save">Nieuwe migraties uitvoeren</button></form>'
        . '<p style="margin-top:24px;"><a href="app.php">← Terug naar de app</a></p>');
}

// ---------------------------------------------------------------------
// Fase A: nog geen (werkende) databaseconfiguratie.
// ---------------------------------------------------------------------
$dbFout = null;
$dbWerkt = false;
if (file_exists(DB_CONFIG_PATH)) {
    try {
        // Ontkoppeld van db()'s statische cache: dit is bewust een verse
        // testverbinding met wat er nu in het bestand staat, niet
        // hergebruik van een eventueel al gefaalde connectie.
        $cfg = require DB_CONFIG_PATH;
        $test = testDatabaseVerbinding($cfg['host'], $cfg['database'], $cfg['username'], $cfg['password'], $cfg['charset'] ?? 'utf8mb4');
        $dbWerkt = $test['success'];
        if (!$dbWerkt) $dbFout = $test['error'];
    } catch (Throwable $e) {
        $dbFout = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['actie'] ?? '') === 'db_instellen') {
    $host = trim((string) ($_POST['host'] ?? ''));
    $database = trim((string) ($_POST['database'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $test = testDatabaseVerbinding($host, $database, $username, $password);
    if (!$test['success']) {
        $dbFout = $test['error'];
        $dbWerkt = false;
    } else {
        schrijfDatabaseConfig($host, $database, $username, $password);
        $dbWerkt = true;
        $dbFout = null;
    }
}

if (!$dbWerkt) {
    $vorigeWaarden = [
        'host' => $_POST['host'] ?? 'localhost',
        'database' => $_POST['database'] ?? '',
        'username' => $_POST['username'] ?? '',
    ];
    paginaOmheen('Database instellen', '<h1>Stocuro installeren</h1>'
        . '<p class="install-step">Stap 1 van 3 — databaseverbinding</p>'
        . ($dbFout ? '<div class="login-error" style="display:block;">' . h($dbFout) . '</div>' : '')
        . '<form method="post">'
        . '<input type="hidden" name="actie" value="db_instellen">'
        . '<div class="form-group"><label>Databasehost</label>'
        . '<input type="text" name="host" value="' . h($vorigeWaarden['host']) . '" required></div>'
        . '<div class="form-group"><label>Databasenaam</label>'
        . '<input type="text" name="database" value="' . h($vorigeWaarden['database']) . '" required></div>'
        . '<div class="form-group"><label>Gebruikersnaam</label>'
        . '<input type="text" name="username" value="' . h($vorigeWaarden['username']) . '" required></div>'
        . '<div class="form-group"><label>Wachtwoord</label>'
        . '<input type="password" name="password"></div>'
        . '<button type="submit" class="btn-save" style="width:100%;">Verbinding testen &amp; opslaan</button>'
        . '</form>'
        . '<p style="margin-top:16px;color:#888;font-size:13px;">Deze gegevens komen normaal van je hostingpaneel '
        . '(bijv. cPanel &gt; MySQL-databases). Er wordt geen internetverbinding gebruikt -- alleen een lokale '
        . 'verbinding met deze database.</p>');
}

// ---------------------------------------------------------------------
// Fase B: verbinding werkt -> migraties uitvoeren (idempotent, dus altijd
// veilig om gewoon opnieuw aan te roepen).
//
// Migratie 041, op verzoek van Harm ("tijdens Stocuro installeren zie ik
// niet wat de status is van database bouwen") -- staat er daadwerkelijk
// iets te doen, dan tonen we een live-bijwerkende voortgangspagina (elke
// regel wordt geflusht naar de browser zodra hij ontstaat, via
// voerMigratiesUit()'s $onLog-callback) in plaats van een blanco scherm
// tot alles klaar is. Bewust GEEN automatische meta-refresh na afloop --
// bij een echte mislukking (zoals de 040-migratiebug die dit meldpunt
// veroorzaakte) zou dat de mislukte migratie eindeloos opnieuw laten
// proberen; in plaats daarvan een handmatige "Direct doorgaan →"-link.
//
// Is er niets te doen (het normale geval: elke paginaherlading tijdens
// fase C/D roept deze fase ook gewoon aan), dan blijft het gedrag exact
// zoals voorheen -- stil en snel, geen voortgangspagina.
// ---------------------------------------------------------------------
$migratieWachtrij = migratiesInWachtrij();
if ($migratieWachtrij) {
    $css = @filemtime(__DIR__ . '/assets/css/style.css') ?: time();
    $aantal = count($migratieWachtrij);
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Database bouwen — Stocuro installatie</title>'
        . '<link rel="stylesheet" href="assets/css/style.css?v=' . $css . '">'
        . '<style>.install-wrap{max-width:640px;margin:48px auto;padding:0 16px;}'
        . '.install-log{background:#111;color:#ddd;padding:12px;border-radius:6px;'
        . 'font-family:monospace;font-size:13px;white-space:pre-wrap;max-height:320px;overflow:auto;}'
        . '.install-step{color:#888;font-size:13px;margin-bottom:4px;}</style>'
        . '</head><body><div class="install-wrap">'
        . '<h1>Database bouwen</h1>'
        . '<p class="install-step">Stap 2 van 3 — database vullen ('
        . $aantal . ' migratie' . ($aantal === 1 ? '' : 's') . ' te gaan)</p>'
        . '<div class="install-log" id="migratielog">';
    // Vult een paar KB op zodat browsers/proxies die kleine responses
    // bufferen alsnog meteen beginnen te renderen i.p.v. te wachten tot
    // de hele pagina binnen is.
    echo '<!-- ' . str_repeat(' ', 1024) . " -->\n";
    if (ob_get_level() > 0) { @ob_flush(); }
    flush();

    $migratieResultaat = voerMigratiesUit(static function (string $regel): void {
        echo h($regel) . "\n";
        if (ob_get_level() > 0) { @ob_flush(); }
        flush();
    });

    echo '</div>';
    if ($migratieResultaat['success']) {
        echo '<p style="margin-top:16px;"><a href="install.php">Direct doorgaan →</a></p>';
    } else {
        echo '<p style="margin-top:16px;color:#b00;">Controleer de foutmelding hierboven en herlaad deze pagina om '
            . 'het opnieuw te proberen.</p>';
    }
    echo '</div></body></html>';
    exit;
}

$migratieResultaat = voerMigratiesUit();
if (!$migratieResultaat['success']) {
    paginaOmheen('Migraties mislukt', '<h1>Migraties mislukt</h1>'
        . '<p class="install-step">Stap 2 van 3 — database vullen</p>'
        . '<div class="install-log">' . h(implode("\n", $migratieResultaat['log'])) . '</div>'
        . '<p style="margin-top:16px;color:#b00;">Controleer de foutmelding hierboven en herlaad deze pagina om '
        . 'het opnieuw te proberen.</p>');
}

// ---------------------------------------------------------------------
// Fase C: nog geen enkele gebruiker -> eerste (gratis, vrije) account.
// ---------------------------------------------------------------------
$pdo = db();
$aantalGebruikers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($aantalGebruikers === 0) {
    $formFout = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['actie'] ?? '') === 'eerste_gebruiker') {
        $naam = trim((string) ($_POST['naam'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $wachtwoord = (string) ($_POST['wachtwoord'] ?? '');
        $taal = ($_POST['taal'] ?? 'nl') === 'en' ? 'en' : 'nl';
        $akkoordVoorwaarden = ($_POST['akkoord_voorwaarden'] ?? '') === '1';

        if ($naam === '' || $email === '' || strlen($wachtwoord) < 8) {
            $formFout = 'Naam, e-mail en een wachtwoord van minstens 8 tekens zijn verplicht.';
        } elseif (!$akkoordVoorwaarden) {
            $formFout = 'U dient akkoord te gaan met de Algemene Voorwaarden om de installatie te voltooien.';
        } else {
            // Migratie 019: is_beheerder=1 -- er moet altijd minstens één
            // beheerder-account zijn om verder iets te kunnen instellen.
            // Migratie 037: licentie_vrij hieronder is alleen nog de
            // leesbare CACHE-kolom (start op 0, wordt na het aanmaken van
            // het echte token vlak hieronder op 1 gezet) -- de bron van
            // waarheid is vanaf nu de license_activations-rij, niet meer
            // deze kale boolean zelf. Zie includes/license.php.
            $pdo->prepare(
                'INSERT INTO users (naam, email, wachtwoord_hash, rol, is_beheerder, licentie_vrij, taal)
                 VALUES (:naam, :email, :hash, :rol, 1, 0, :taal)'
            )->execute([
                ':naam' => $naam, ':email' => $email,
                ':hash' => password_hash($wachtwoord, PASSWORD_DEFAULT),
                ':rol' => 'specialist', ':taal' => $taal,
            ]);
            $nieuwId = (int) $pdo->lastInsertId();

            // Migratie 037: het ENE gratis token van deze installatie --
            // een echt HMAC-ondertekend token (includes/license.php::
            // maakLokaalVrijToken()), geen kale boolean meer.
            $token = maakLokaalVrijToken($nieuwId);
            $tokenPayload = json_decode((string) base64UrlDecode(explode('.', $token)[0]), true);
            $pdo->prepare(
                'INSERT INTO license_activations (token, token_id, type, gebonden_gebruiker_id, geldig_vanaf, geldig_tot, toegevoegd_door)
                 VALUES (:token, :tid, :type, :uid, :vanaf, :tot, :door)'
            )->execute([
                ':token' => $token, ':tid' => $tokenPayload['id'], ':type' => 'vrij_lokaal', ':uid' => $nieuwId,
                ':vanaf' => $tokenPayload['issued'], ':tot' => $tokenPayload['expires'], ':door' => 'install.php',
            ]);
            $pdo->prepare('UPDATE users SET licentie_vrij = 1 WHERE id = :id')->execute([':id' => $nieuwId]);

            @file_put_contents(INSTALL_LOCK_PATH, 'Geïnstalleerd op ' . date('Y-m-d H:i:s') . "\n");
            paginaOmheen('Installatie voltooid', '<h1>✅ Installatie voltooid</h1>'
                . '<p>Account <strong>' . h($email) . '</strong> is aangemaakt als vrije, gratis gebruiker '
                . '-- geen activatiecode nodig, geen verval.</p>'
                . '<p>Extra gebruikers toevoegen? Dat kan altijd, maar vereist per extra account een '
                . 'activatiecode (Beheer → Gebruikers).</p>'
                . '<p style="margin:20px 0;padding:12px;background:#fff3cd;border-radius:6px;">'
                . '⚠️ Verwijder of hernoem <code>install.php</code> nu voor extra zekerheid -- deze pagina staat '
                . 'weliswaar al op slot (install.lock), maar dat is defense-in-depth, geen vervanging.</p>'
                . '<p><a class="btn-save" style="display:inline-block;text-decoration:none;" href="login.php">Naar inloggen →</a></p>');
        }
    }

    paginaOmheen('Eerste account', '<h1>Stocuro installeren</h1>'
        . '<p class="install-step">Stap 3 van 3 — eerste (gratis) account</p>'
        . ($formFout ? '<div class="login-error" style="display:block;">' . h($formFout) . '</div>' : '')
        . '<form method="post">'
        . '<input type="hidden" name="actie" value="eerste_gebruiker">'
        . '<div class="form-group"><label>Naam</label>'
        . '<input type="text" name="naam" value="' . h((string) ($_POST['naam'] ?? '')) . '" required></div>'
        . '<div class="form-group"><label>E-mailadres</label>'
        . '<input type="email" name="email" value="' . h((string) ($_POST['email'] ?? '')) . '" required></div>'
        . '<div class="form-group"><label>Wachtwoord (minstens 8 tekens)</label>'
        . '<input type="password" name="wachtwoord" required></div>'
        . '<div class="form-group"><label>Taal</label>'
        . '<select name="taal"><option value="nl" selected>Nederlands</option><option value="en">English</option></select></div>'
        . '<div class="form-group" style="display:flex;align-items:flex-start;gap:8px;">'
        . '<input type="checkbox" id="akkoord_voorwaarden" name="akkoord_voorwaarden" value="1" required '
        . 'style="width:auto;margin-top:4px;"' . (($_POST['akkoord_voorwaarden'] ?? '') === '1' ? ' checked' : '') . '>'
        . '<label for="akkoord_voorwaarden" style="font-weight:normal;">Ik ga akkoord met de '
        . '<a href="https://stocuro.com/terms.html" target="_blank" rel="noopener">Algemene Voorwaarden, het Privacybeleid '
        . 'en de garantie-uitsluiting</a> van Stocuro.</label>'
        . '</div>'
        . '<button type="submit" class="btn-save" style="width:100%;">Account aanmaken &amp; installatie afronden</button>'
        . '</form>'
        . '<p style="margin-top:16px;color:#888;font-size:13px;">Dit wordt een beheerder-account met alle rechten, '
        . 'en de enige gebruiker die altijd zonder activatiecode kan blijven inloggen.</p>');
}

// Zou niet meer bereikt moeten worden (fase D hierboven vangt dit af
// zodra install.lock bestaat) -- de scenario's die hier wél terechtkomen:
//   1. iemand heeft install.lock handmatig verwijderd terwijl er al
//      gebruikers waren -- zet 'm gewoon terug, niets anders aan de hand.
//   2. een bestaande, PRE-licentiemodel installatie (van vóór migratie
//      036) is voor het eerst geüpgraded: nog geen enkel geldig
//      vrij_lokaal-token, en zonder ingrijpen zou NIEMAND meer kunnen
//      inloggen. Migratie 037 herziet dit verder: de kandidaat krijgt nu
//      een ECHT HMAC-token (niet meer alleen een kale licentie_vrij=1),
//      en als een EERDERE (migratie-036-only) upgrade al een kale
//      licentie_vrij=1 had gezet, wordt die persoon met voorrang gekozen
//      -- zo blijft de eerder gekozen "gratis gebruiker" ook na deze
//      upgrade dezelfde persoon.
$grandfatherMelding = '';
$aantalGeldigeVrij = telAantalGeldigeVrijeTokens();
if ($aantalGeldigeVrij === 0) {
    zorgVoorInstantieGeheim();

    $kandidaat = $pdo->query(
        'SELECT id, naam, email FROM users WHERE licentie_vrij = 1 ORDER BY id ASC LIMIT 1'
    )->fetch();
    if (!$kandidaat) {
        $kandidaat = $pdo->query(
            'SELECT id, naam, email FROM users WHERE is_beheerder = 1 ORDER BY id ASC LIMIT 1'
        )->fetch();
    }

    if ($kandidaat) {
        $token = maakLokaalVrijToken((int) $kandidaat['id']);
        $tokenPayload = json_decode((string) base64UrlDecode(explode('.', $token)[0]), true);
        $pdo->prepare(
            'INSERT INTO license_activations (token, token_id, type, gebonden_gebruiker_id, geldig_vanaf, geldig_tot, toegevoegd_door)
             VALUES (:token, :tid, :type, :uid, :vanaf, :tot, :door)'
        )->execute([
            ':token' => $token, ':tid' => $tokenPayload['id'], ':type' => 'vrij_lokaal', ':uid' => $kandidaat['id'],
            ':vanaf' => $tokenPayload['issued'], ':tot' => $tokenPayload['expires'], ':door' => 'install.php (upgrade)',
        ]);
        // Alle kale licentie_vrij-vlaggen opschonen -- die kolom is nu
        // uitsluitend nog een leesbare cache (controleerLicentieGebruiker()
        // zet 'm bij de eerstvolgende controle sowieso opnieuw correct),
        // dit voorkomt alleen verwarrende weergave tot die controle draait.
        $pdo->exec('UPDATE users SET licentie_vrij = 0');
        $pdo->prepare('UPDATE users SET licentie_vrij = 1, licentie_geblokkeerd = 0 WHERE id = :id')
            ->execute([':id' => $kandidaat['id']]);

        $grandfatherMelding = '<p style="margin:20px 0;padding:12px;background:#fff3cd;border-radius:6px;">'
            . 'Upgrade herkend: <strong>' . h($kandidaat['naam'])
            . '</strong> (' . h($kandidaat['email']) . ') is zojuist ingesteld als de vrije, gratis gebruiker van '
            . 'deze installatie, nu met een echt lokaal ondertekend token (migratie 037) in plaats van alleen een '
            . 'vinkje. Overige bestaande accounts hebben, net als bij een nieuwe installatie, een eigen '
            . 'activatiecode nodig om te kunnen blijven inloggen (Beheer → Gebruikers → code koppelen).</p>';
    }
} elseif ($aantalGeldigeVrij > 1) {
    // Zou via de normale applicatiepaden niet moeten kunnen (alleen dit
    // bestand maakt vrij_lokaal-tokens aan, en alleen als er nog geen
    // geldige was) -- kan alleen als iemand met het lokale geheim uit
    // config/instance.local.php zelf een extra rij heeft toegevoegd.
    // Alleen zichtbaar maken, niets automatisch "repareren" -- welke van
    // de twee de rechtmatige gratis gebruiker is, is een
    // beheerdersbeslissing (includes/license.php::isRechtmatigeVrijeGebruiker()
    // kiest zelf altijd het laagste account-id, dus de app functioneert
    // ondertussen gewoon correct).
    $grandfatherMelding = '<p style="margin:20px 0;padding:12px;background:#f8d7da;border-radius:6px;">'
        . '⚠️ Er zijn meerdere geldige lokale gratis-tokens gevonden (' . $aantalGeldigeVrij . '). Alleen de '
        . 'gebruiker met het laagste account-id telt mee als de rechtmatige gratis gebruiker -- controleer '
        . 'license_activations handmatig als dit onverwacht is.</p>';
}

@file_put_contents(INSTALL_LOCK_PATH, 'Geïnstalleerd (hersteld) op ' . date('Y-m-d H:i:s') . "\n");
paginaOmheen('Al geïnstalleerd', '<h1>Stocuro is al geïnstalleerd</h1>'
    . '<p>Er bestaat al minstens één gebruiker. install.lock stond er niet (meer), maar is nu opnieuw gezet.</p>'
    . $grandfatherMelding
    . '<p><a class="btn-save" style="display:inline-block;text-decoration:none;" href="login.php">Naar inloggen →</a></p>');
