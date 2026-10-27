<?php
/**
 * Authenticatie (migratieplan hoofdstuk 9.1). PHP-sessies +
 * password_hash()/password_verify(), niets exotisch.
 *
 * requireLogin() en requireRole() worden aangeroepen aan het begin van
 * elk bestand in /api (behalve login.php zelf). Bij een ontbrekende of
 * ongeldige sessie stoppen ze de request met HTTP 401/403 en een JSON
 * foutmelding -- de aanroeper hoeft daar zelf niets voor te doen. Deze
 * twee functies blijven bewust STRIKT (nooit een gast doorlaten) -- ze
 * zijn de basis waar requireRole('beheerder') op leunt, dus een wijziging
 * hier zou per ongeluk ook alle beheerderscontroles verzwakken.
 *
 * Gast-modus (een bezoeker moet alles kunnen
 * BEKIJKEN -- dashboards, formulieren, beheerschermen -- zonder in te
 * loggen, voor demonstratiedoeleinden, maar nergens iets kunnen
 * INVOEREN of WIJZIGEN) zit in twee aparte, nieuwe functies hieronder
 * (currentUserOfGast()/requireLoginOfGast() voor lezen,
 * requireSchrijfToegang() voor schrijven) -- expliciet gescheiden van
 * requireLogin()/requireRole() zodat er geen enkel risico is dat een
 * beheerder-only endpoint per ongeluk gastvriendelijk wordt.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
// Groepen.php require't zelf alleen config/database.php
// (niet dit bestand), dus geen circulaire require -- gebruikerMagSchrijven-
// ViaGroep()/gebruikerSchermen() worden hieronder gebruikt.
require_once __DIR__ . '/groepen.php';
// ControleerLicentieGebruiker()/
// herlicentieControleNodig()/markeerLicentieGecontroleerd() worden
// hieronder gebruikt in currentUser()/attemptLogin() -- zie
// includes/license.php voor de volledige toelichting.
require_once __DIR__ . '/license.php';

// Losse rol, geen echt account: een bezoeker die niet is ingelogd. Nooit
// opgeslagen in de database of in $_SESSION -- puur een in-memory
// placeholder-array met dezelfde vorm als een echte $_SESSION['user'],
// zodat de rest van de app (frontend + apiRequireLogin()-aanroepers) er
// niet apart voor hoeft te controleren of er wel een gebruiker is.
const ROL_GAST = 'gast';

function startSessionIfNeeded(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Dit is de ENE plek waar elke
 * ingelogde gebruiker doorheen komt (requireLogin(), requireRole(),
 * requireBeheerder() en requireLoginOfGast()/currentUserOfGast() leunen
 * hier allemaal op), dus hier -- en alleen hier -- zit de periodieke
 * licentie-hercontrole tijdens gebruik. Bij een niet (meer) geldige
 * licentie wordt alleen de sessie-gebruiker verwijderd (niet de hele
 * PHP-sessie/cookie, zie doLogout() voor dat zwaardere pad) en de reden
 * bewaard voor requireLogin() hieronder om te tonen -- op een puur
 * lezend endpoint (via requireLoginOfGast()) valt de gebruiker hierdoor
 * vanzelf terug op de alleen-lezen gast-weergave, precies het "geblokkeerd
 * i.p.v. verwijderd" gedrag dat het licentiemodel vraagt: schrijven kan
 * dan niet meer (apiRequireWriteAccess() ziet een gast), inloggen geeft
 * bij de eerstvolgende poging de specifieke reden (attemptLogin()
 * hieronder controleert zelf óók, dus dit hoeft niet te wachten op een
 * verlopen sessie).
 */
function currentUser(): ?array {
    startSessionIfNeeded();
    $user = $_SESSION['user'] ?? null;
    if ($user === null) return null;

    if (herlicentieControleNodig()) {
        $resultaat = controleerLicentieGebruiker((int) $user['id']);
        markeerLicentieGecontroleerd();
        if (!$resultaat['ok']) {
            unset($_SESSION['user']);
            $_SESSION['licentie_blokkade_reden'] = $resultaat['reden'];
            return null;
        }
    }

    return $user;
}

function requireLogin(): array {
    $user = currentUser();
    if (!$user) {
        startSessionIfNeeded();
        $reden = $_SESSION['licentie_blokkade_reden'] ?? null;
        unset($_SESSION['licentie_blokkade_reden']);
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => $reden ? ('Licentie niet (meer) geldig: ' . $reden) : 'Niet ingelogd']);
        exit;
    }
    return $user;
}

function requireRole(string $rol): array {
    $user = requireLogin();
    if (($user['rol'] ?? '') !== $rol) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Onvoldoende rechten']);
        exit;
    }
    return $user;
}

/**
 * "Beheerder" is geen functie (rol) meer, maar een los
 * vinkje (users.is_beheerder) dat bovenop elke functie (specialist/
 * laborant/bekijker) gezet kan worden. Deze functie vervangt de oude
 * requireRole('beheerder')-aanroepen -- strikt (nooit een gast
 * doorlaten, net als requireRole()), maar controleert de vlag i.p.v. een
 * rol-string.
 */
function requireBeheerder(): array {
    $user = requireLogin();
    if (empty($user['is_beheerder'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Onvoldoende rechten']);
        exit;
    }
    return $user;
}

/**
 * Echte gebruiker als die is ingelogd, anders een synthetische
 * gast-gebruiker -- deze functie blokkeert NOOIT. Gebruikt door
 * apiRequireLogin() (zie includes/api_helpers.php), dus elk endpoint dat
 * alleen data LEEST wordt hierdoor automatisch bruikbaar zonder in te
 * loggen. Schrijfacties moeten daarnaast ALTIJD apiRequireWriteAccess()
 * (of, voor beheerder-only acties, apiRequireRole()) aanroepen -- dat is
 * de enige plek die daadwerkelijk blokkeert.
 */
function currentUserOfGast(): array {
    return currentUser() ?? [
        'id' => null,
        'naam' => 'Gast (demo)',
        'email' => null,
        'rol' => ROL_GAST,
        'is_beheerder' => false,
        'taal' => 'en',
    ];
}

function requireLoginOfGast(): array {
    return currentUserOfGast();
}

/**
 * De enige plek die daadwerkelijk "mag dit account iets opslaan?"
 * beantwoordt voor de brede categorie schrijfacties die geen apart
 * beheerdersrecht hebben (scannen, bestellen, artikelen toevoegen/
 * bewerken, min/max-advies toepassen, eenmalige bestellingen, ...).
 * Blokkeert zowel de gast-rol als de bestaande 'alleen-lezen'-rol --
 * dat laatste was voorheen alleen ad-hoc gecontroleerd in
 * api/telling.php en api/transactielog.php, met losse endpoints die
 * niets controleerden (zie migratieplan-aantekeningen bij gast-modus);
 * die endpoints roepen nu ook deze functie aan, dus dat gat is meteen
 * gedicht voor ELK 'alleen-lezen'-account, niet alleen voor gasten.
 * Beheerder-only acties (Stamgegevens, Locaties, Producenten,
 * Gebruikers) blijven apiRequireRole('beheerder') gebruiken, die al
 * strikt genoeg was en dus ongewijzigd blijft.
 *
 * Schrijftoegang komt
 * nu uit de groepen waar de gebruiker bij hoort (elke groep heeft een
 * eigen mag_schrijven-vinkje, includes/groepen.php::
 * gebruikerMagSchrijvenViaGroep()) i.p.v. de vroegere harde koppeling aan
 * rol==='bekijker'. is_beheerder blijft een universele overrule, precies
 * zoals voorheen. users.rol zelf is hier NIET meer relevant voor
 * schrijftoegang -- dat veld behoudt uitsluitend zijn klinische betekenis
 * (wie mag als uitvoerder van een procedure gekozen worden, zie
 * includes/transacties.php::verwerkScan() en sql/migrations/
 * 024_groepen.sql voor de volledige toelichting waarom dit twee losse
 * assen zijn). Bestaande accounts zijn bij de migratie zelf al gekoppeld
 * aan een startgroep die hun oude rol-gebaseerde schrijfrecht exact
 * overneemt, dus dit verandert niets aan het gedrag van bestaande
 * gebruikers.
 */
function requireSchrijfToegang(array $user): array {
    if (($user['rol'] ?? '') === ROL_GAST) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Dit is een alleen-lezen weergave (demo) -- log in met een schrijvend account om wijzigingen op te slaan.']);
        exit;
    }
    if (empty($user['is_beheerder']) && !gebruikerMagSchrijvenViaGroep((int) ($user['id'] ?? 0))) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Geen van je groepen heeft schrijftoegang -- vraag een beheerder om je groep aan te passen, of log in met een account dat wel mag schrijven.']);
        exit;
    }
    return $user;
}

/**
 * @return array{success:bool, error?:string}
 */
function attemptLogin(string $email, string $wachtwoord): array {
    $stmt = db()->prepare(
        'SELECT id, naam, email, wachtwoord_hash, rol, is_beheerder, actief, taal FROM users WHERE email = :email'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !$user['actief'] || !password_verify($wachtwoord, $user['wachtwoord_hash'])) {
        return ['success' => false, 'error' => 'Onjuiste inloggegevens'];
    }

    // Licentiecontrole bij ELKE login,
    // niet pas bij de eerstvolgende periodieke hercontrole -- zo krijgt
    // iemand meteen de specifieke reden te zien (geen code gekoppeld /
    // verlopen) i.p.v. pas na een sessie stilzwijgend uitgelogd te
    // worden. Wachtwoord is hierboven al geverifieerd (dit lekt dus geen
    // extra info aan iemand die het wachtwoord niet weet).
    $licentie = controleerLicentieGebruiker((int) $user['id']);
    if (!$licentie['ok']) {
        return ['success' => false, 'error' => 'Licentie niet geldig: ' . $licentie['reden'] . ' Neem contact op met je beheerder.'];
    }

    startSessionIfNeeded();
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'naam' => $user['naam'],
        'email' => $user['email'],
        'rol' => $user['rol'],
        // Los vinkje bovenop de functie (rol), zie
        // requireBeheerder()/requireSchrijfToegang() hierboven.
        'is_beheerder' => (bool) $user['is_beheerder'],
        'taal' => $user['taal'] ?: 'en',
    ];
    markeerLicentieGecontroleerd();

    db()->prepare('UPDATE users SET laatst_ingelogd = NOW() WHERE id = :id')
        ->execute([':id' => $user['id']]);

    return ['success' => true];
}

/**
 * Zelf-service taalvoorkeur: elke ingelogde gebruiker mag
 * zijn eigen taal wijzigen -- dit is nu een accountinstelling, geen
 * browser-lokale (localStorage) keuze meer op elke pagina van de
 * ingelogde app (zie assets/js/app.js voor het waarom). Werkt op de
 * huidige sessiegebruiker, dus geen aparte rolcontrole nodig: iedereen
 * mag zijn eigen voorkeur zetten.
 */
function setEigenTaal(string $taal): array {
    if (!in_array($taal, ['en', 'nl'], true)) {
        return ['success' => false, 'error' => 'Onbekende taal'];
    }

    $user = requireLogin();
    db()->prepare('UPDATE users SET taal = :taal WHERE id = :id')
        ->execute([':taal' => $taal, ':id' => $user['id']]);

    $_SESSION['user']['taal'] = $taal;
    return ['success' => true];
}

function doLogout(): void {
    startSessionIfNeeded();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
