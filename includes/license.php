<?php
/**
 * Licentiemodel voor zelf-hostbare
 * distributie aan andere afdelingen/ziekenhuizen. Kort samengevat:
 *
 *  - 1 gratis, vrije gebruiker per installatie -- altijd toegestaan, geen
 *    code nodig, geen verval.
 *  - Elke andere gebruiker heeft een eigen, ondertekende activatiecode
 *    nodig ("1 code = 1 gebruiker, 1 jaar"), gekoppeld via
 *    koppelLicentieCode() hieronder (zie api/gebruikers.php, actie
 *    'licentie_koppelen').
 *  - Verificatie is volledig OFFLINE: nooit een serverconnectie nodig,
 *    ook niet bij het aanmaken/koppelen van een code.
 *
 * Herzien na live testen op de eigen demo-omgeving: het gratis-vinkje
 * bleek met een simpele `UPDATE users SET
 * licentie_vrij=1` op onbeperkt veel accounts te zetten -- users.licentie_vrij
 * was een kale, niet-geverifieerde boolean. Sindsdien loopt de
 * "gratis gebruiker"-toekenning door HETZELFDE mechanisme als een echte
 * activatiecode (license_activations), alleen ondertekend met een LOKAAL,
 * per-installatie geheim (HMAC-SHA256, config/instance.local.php) in
 * plaats van de Ed25519 private key van de leverancier -- die laatste
 * verlaat nooit vendor-tools/, dus de klant-app kan onmogelijk zelf een
 * ECHTE (gekochte) code verzinnen, maar kan wel (via install.php, zie
 * daar) zijn eigen ENE gratis token uitgeven, precies zoals bedoeld.
 *
 * Er is dus nog maar EEN verificatiepad (verifieerToken() hieronder):
 * geen kale boolean naast een apart tokensysteem meer. users.licentie_vrij
 * en users.licentie_geblokkeerd blijven bestaan als leesbare CACHE voor
 * het Gebruikers-scherm (snelle weergave zonder de hele lijst opnieuw te
 * verifiëren), maar worden nooit blind vertrouwd -- elke controle
 * hieronder herverifieert altijd tegen license_activations, nooit tegen
 * die kolommen zelf.
 *
 * Twee dingen die BEWUST NIET in de HMAC-sleutel van het lokale token
 * verwerkt zitten, ondanks dat ze ter sprake kwamen:
 *  - Het versienummer (config/constants.php::APP_VERSION) staat wel
 *    (informatief, voor audit/debug) in de payload, maar NIET in de
 *    sleutel zelf -- anders zou een gewone versiebump (die gebeurt bij
 *    ELKE wijziging, ook kleine) het bestaande, legitieme gratis-token
 *    van de eigen installatie ongeldig maken. Zodra de echte HMAC-check
 *    er staat, voegt versiebinding sowieso niets toe tegen iemand zonder
 *    het geheim -- die kan toch niets vervalsen, welke versie er ook
 *    bijstaat.
 *  - Losse tabellen/kolommen als "verstopte puzzel" zonder geheim (ook
 *    overwogen): voegt geen echte cryptografische drempel toe, want de
 *    verificatielogica staat leesbaar in deze (niet source-encoded) PHP.
 *
 * Bewuste, gemaakte scope-keuzes (hobbyproject, geen
 * volledig productbedrijf): geen ionCube/source-encoding, geen sluitende
 * bescherming tegen iemand met VOLLEDIGE serverToegang (bestanden +
 * database) die het lokale geheim uit config/instance.local.php leest en
 * de HMAC-berekening handmatig naleeft -- dat blijft de geaccepteerde
 * grens. Wat dit mechanisme wel sluit: iemand met ALLEEN databasetoegang
 * (geen bestandstoegang, dus het geheim niet kan lezen) kan nu geen
 * enkele extra gratis of geldige gebruiker meer fabriceren.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

/** @return array{public_key_b64:string, hercontrole_uren:int} */
function licentieConfig(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/license.php';
    }
    return $cfg;
}

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** @return string|null null bij ongeldige base64url-invoer */
function base64UrlDecode(string $data): ?string {
    $pad = strlen($data) % 4;
    if ($pad > 0) $data .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

// -----------------------------------------------------------------------
// Lokaal, per-installatie geheim (HMAC-sleutel voor het
// gratis token). NOOIT in de zip, NOOIT in versiebeheer -- net als
// config/database.local.php wordt dit bestand pas op de klantserver zelf
// aangemaakt (zie zorgVoorInstantieGeheim() hieronder, aangeroepen vanuit
// install.php). Bewust een minder voor de hand liggende bestandsnaam dan
// bijv. "license.local.php" naast license.php -- iets minder makkelijk te
// vinden voor wie toevallig door config/ browst, al is die map sowieso al
// afgeschermd met een .htaccess ("Require all denied").
// -----------------------------------------------------------------------
const INSTANTIE_GEHEIM_PAD = __DIR__ . '/../config/instance.local.php';

/**
 * Leest het lokale geheim (ruwe bytes). Geeft null terug als het bestand
 * (nog) niet bestaat of geen geldige 32-byte sleutel bevat -- verificatie
 * van een vrij_lokaal-token faalt dan gewoon (fail closed), in plaats van
 * een fout te gooien die de hele licentiecontrole zou breken.
 */
function laadInstantieGeheim(): ?string {
    if (!file_exists(INSTANTIE_GEHEIM_PAD)) return null;
    try {
        $cfg = require INSTANTIE_GEHEIM_PAD;
        $geheim = base64_decode((string) ($cfg['secret_b64'] ?? ''), true);
        return ($geheim !== false && strlen($geheim) >= 32) ? $geheim : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Genereert config/instance.local.php als het nog niet bestaat (32
 * willekeurige bytes, random_bytes -- een echte cryptografische sleutel,
 * geen wachtwoord dat iemand moet onthouden). Aangeroepen vanuit
 * install.php, zowel bij een verse installatie als bij het upgraden van
 * een pre-migratie-037 installatie. Idempotent: bestaat het al, dan wordt
 * het NOOIT overschreven (dat zou elk al uitgegeven vrij_lokaal-token
 * direct ongeldig maken).
 *
 * @return string de (eventueel net aangemaakte) geheime bytes.
 */
function zorgVoorInstantieGeheim(): string {
    $bestaand = laadInstantieGeheim();
    if ($bestaand !== null) return $bestaand;

    $geheim = random_bytes(32);
    $inhoud = "<?php\n"
        . "/**\n"
        . " * Door install.php gegenereerd op " . date('Y-m-d H:i:s') . ".\n"
        . " * Lokaal, per-installatie geheim voor het gratis-gebruikerstoken\n"
        . " * (zie includes/license.php). NIET in versiebeheer opnemen, NIET delen.\n"
        . " */\n\n"
        . "return [\n"
        . "    'secret_b64' => " . var_export(base64_encode($geheim), true) . ",\n"
        . "];\n";

    if (file_put_contents(INSTANTIE_GEHEIM_PAD, $inhoud) === false) {
        throw new RuntimeException('Kon config/instance.local.php niet wegschrijven -- controleer schrijfrechten op de config/-map.');
    }
    @chmod(INSTANTIE_GEHEIM_PAD, 0640);
    return $geheim;
}

/**
 * Verifieert een ECHTE, door de leverancier ondertekende activatiecode
 * (Ed25519, publieke sleutel in config/license.php). Vereist sinds
 * kort ook een 'email'-veld in de payload (zie vendor-tools/
 * licentie_generator_core.php) -- oudere codes die zonder dat veld zijn
 * uitgegeven worden dus niet meer geaccepteerd; die
 * moeten opnieuw gegenereerd worden. Aanvaardbaar op dit moment (nog geen
 * echte klanten met eerder uitgegeven codes), en expliciet vermeld in de
 * changelog.
 *
 * @return array{geldig:bool, reden:?string, payload:?array}
 */
function verifieerVendorToken(string $token): array {
    $token = trim($token);
    $delen = explode('.', $token);
    if (count($delen) !== 2) {
        return ['geldig' => false, 'reden' => 'Onherkenbaar formaat -- dit lijkt geen Stocuro-activatiecode.', 'payload' => null];
    }
    [$payloadDeel, $sigDeel] = $delen;

    $payloadBytes = base64UrlDecode($payloadDeel);
    $sigBytes = base64UrlDecode($sigDeel);
    if ($payloadBytes === null || $sigBytes === null || strlen($sigBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return ['geldig' => false, 'reden' => 'Onherkenbaar formaat -- dit lijkt geen Stocuro-activatiecode.', 'payload' => null];
    }

    $publicKey = base64_decode(licentieConfig()['public_key_b64'], true);
    if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return ['geldig' => false, 'reden' => 'Licentieconfiguratie ontbreekt of is ongeldig (config/license.php).', 'payload' => null];
    }

    if (!sodium_crypto_sign_verify_detached($sigBytes, $payloadBytes, $publicKey)) {
        return ['geldig' => false, 'reden' => 'Ongeldige handtekening -- dit is geen echte, door Stocuro uitgegeven activatiecode.', 'payload' => null];
    }

    $payload = json_decode($payloadBytes, true);
    if (!is_array($payload) || empty($payload['id']) || empty($payload['expires']) || empty($payload['issued'])) {
        return ['geldig' => false, 'reden' => 'Code-inhoud onvolledig.', 'payload' => null];
    }
    // E-mailbinding -- zie koppelLicentieCode() voor de
    // controle bij koppelen, en controleerLicentieGebruiker() voor de
    // continue herverificatie bij elke periodieke check.
    if (empty($payload['email'])) {
        return ['geldig' => false, 'reden' => 'Deze code is uitgegeven vóór de e-mailbinding en is niet meer geldig -- vraag een nieuwe code aan.', 'payload' => null];
    }

    return ['geldig' => true, 'reden' => null, 'payload' => $payload];
}

/**
 * Verifieert het EEN lokale gratis-token van deze installatie (HMAC-
 * SHA256, config/instance.local.php). Structureel hetzelfde
 * token-formaat als een vendor-code (base64url(payload) . '.' .
 * base64url(mac)), maar met hash_hmac() i.p.v. sodium_crypto_sign_*,
 * exact zodat er verder in dit bestand geen tweede reeks call-sites
 * nodig is -- alleen verifieerToken() hieronder weet welk pad erbij hoort.
 *
 * @return array{geldig:bool, reden:?string, payload:?array}
 */
function verifieerLokaalToken(string $token): array {
    $token = trim($token);
    $delen = explode('.', $token);
    if (count($delen) !== 2) {
        return ['geldig' => false, 'reden' => 'Onherkenbaar formaat.', 'payload' => null];
    }
    [$payloadDeel, $macDeel] = $delen;

    $payloadBytes = base64UrlDecode($payloadDeel);
    $macBytes = base64UrlDecode($macDeel);
    if ($payloadBytes === null || $macBytes === null) {
        return ['geldig' => false, 'reden' => 'Onherkenbaar formaat.', 'payload' => null];
    }

    $geheim = laadInstantieGeheim();
    if ($geheim === null) {
        // Geen geheim (nog) op deze server -- kan geen enkel lokaal token
        // ooit geldig verklaren. Fail closed, nooit "geldig" teruggeven.
        return ['geldig' => false, 'reden' => 'Lokaal licentiegeheim ontbreekt op deze server (config/instance.local.php) -- draai install.php opnieuw.', 'payload' => null];
    }

    $verwachteMac = hash_hmac('sha256', $payloadBytes, $geheim, true);
    if (!hash_equals($verwachteMac, $macBytes)) {
        return ['geldig' => false, 'reden' => 'Ongeldige handtekening op het lokale token.', 'payload' => null];
    }

    $payload = json_decode($payloadBytes, true);
    if (!is_array($payload) || empty($payload['id']) || empty($payload['expires']) || ($payload['type'] ?? null) !== 'vrij_lokaal') {
        return ['geldig' => false, 'reden' => 'Token-inhoud onvolledig.', 'payload' => null];
    }

    return ['geldig' => true, 'reden' => null, 'payload' => $payload];
}

/**
 * EEN verificatiepad voor elke aanroeper (koppelLicentieCode(),
 * controleerLicentieGebruiker()) -- kijkt naar het (nog niet vertrouwde)
 * 'type'-veld in de payload om te bepalen welk cryptografisch pad erbij
 * hoort, en laat DAT pad de daadwerkelijke, harde verificatie doen. Puur
 * kijken naar een ongeverifieerd veld om te ROUTEREN is veilig -- er wordt
 * pas iets vertrouwd nadat de bijbehorende handtekening/HMAC daadwerkelijk
 * klopt; een vals 'type' claimen levert hoogstens de verkeerde (en dus
 * sowieso falende) controle op, nooit een omzeiling.
 *
 * @return array{geldig:bool, reden:?string, payload:?array}
 */
function verifieerToken(string $token): array {
    $delen = explode('.', trim($token));
    $ruwePayload = count($delen) === 2 ? json_decode((string) base64UrlDecode($delen[0]), true) : null;
    $type = is_array($ruwePayload) ? ($ruwePayload['type'] ?? null) : null;

    return $type === 'vrij_lokaal' ? verifieerLokaalToken($token) : verifieerVendorToken($token);
}

/**
 * Bouwt en ondertekent (HMAC, lokaal geheim) het ENE gratis-token van
 * deze installatie. Aangeroepen vanuit install.php -- nooit vanuit een
 * API-endpoint dat een klant zelf zou kunnen aanroepen, want dat zou de
 * "maximaal 1 gratis gebruiker"-regel omzeilbaar maken via herhaald
 * aanroepen. install.php bewaakt zelf dat dit maar één keer per
 * installatie gebeurt (zie daar).
 */
function maakLokaalVrijToken(int $gebruikerId): string {
    $geheim = zorgVoorInstantieGeheim();

    $payload = [
        'v' => 1,
        'type' => 'vrij_lokaal',
        'id' => bin2hex(random_bytes(8)),
        'issued' => (new DateTime('today'))->format('Y-m-d'),
        // Geen echt verval voor de gratis gebruiker -- ver in de toekomst
        // is functioneel "nooit", en simpeler dan een aparte "geen
        // vervaldatum"-tak door de rest van deze/andere functies.
        'expires' => '9999-12-31',
        // Puur informatief (audit/debug), telt NIET mee in de HMAC-sleutel
        // zelf -- zie de toelichting bovenaan dit bestand.
        'issuedVersion' => defined('APP_VERSION') ? APP_VERSION : null,
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $mac = hash_hmac('sha256', $payloadJson, $geheim, true);

    return base64UrlEncode($payloadJson) . '.' . base64UrlEncode($mac);
}

/**
 * Koppelt een activatiecode aan een specifieke gebruiker (beheerder-
 * actie, zie api/gebruikers.php::'licentie_koppelen'). Eén code kan maar
 * aan één gebruiker gekoppeld worden (token_id is UNIQUE) -- opnieuw
 * invoeren van dezelfde code bij dezelfde gebruiker is onschadelijk
 * (expliciete melding, geen dubbele rij); bij een ANDERE gebruiker wordt
 * het geweigerd.
 *
 * Alleen ECHTE vendor-codes (type 'extra_user') kunnen hier
 * gekoppeld worden -- een vrij_lokaal-token hoort hier nooit binnen te
 * komen (die maakt install.php zelf aan), dus wordt hard geweigerd, al
 * kan niemand er toch een geldige vervalsen zonder het lokale geheim.
 *
 * Herzien na live gebruik: de
 * e-mailbinding vergelijkt NIET (meer) met het e-mailadres van het
 * account waaraan gekoppeld wordt -- dat brak het normale aankooppatroon
 * (meerdere accounts in 1 WooCommerce-bestelling onder 1 besteladres,
 * later verdeeld over verschillende collega's-accounts met hun EIGEN
 * e-mailadres). In plaats daarvan moet $bevestigdEmail hieronder
 * overeenkomen met het BESTELADRES dat in de code staat (payload['email'],
 * gezet door vendor-tools/ op basis van de WooCommerce-order) -- een
 * eenmalige bevestiging bij het koppelen, geen doorlopende eis aan het
 * doelaccount. Zo blijft "1 bestelling, meerdere ontvangers" mogelijk,
 * terwijl iemand die een code toevallig ergens vindt zonder de
 * bijbehorende bevestigingsmail (die alleen de koper ontvangen heeft)
 * 'm nog steeds niet kan koppelen.
 */
function koppelLicentieCode(string $tokenRaw, int $gebruikerId, string $bevestigdEmail, ?string $toegevoegdDoor = null): array {
    $result = verifieerToken($tokenRaw);
    if (!$result['geldig']) {
        return ['success' => false, 'error' => $result['reden']];
    }
    $payload = $result['payload'];

    if (($payload['type'] ?? null) !== 'extra_user') {
        return ['success' => false, 'error' => 'Dit tokentype kan niet via "Code koppelen" gekoppeld worden.'];
    }

    // Bevestiging van het BESTELADRES, niet van
    // het e-mailadres van het doelaccount. Case-insensitief/getrimd.
    $codeEmail = strtolower(trim((string) $payload['email']));
    $ingevoerd = strtolower(trim($bevestigdEmail));
    if ($codeEmail === '' || $ingevoerd !== $codeEmail) {
        return ['success' => false, 'error' => 'Het ingevoerde e-mailadres komt niet overeen met het besteladres waaronder deze code is uitgegeven.'];
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, naam, email FROM users WHERE id = :id');
    $stmt->execute([':id' => $gebruikerId]);
    $gebruiker = $stmt->fetch();
    if (!$gebruiker) {
        return ['success' => false, 'error' => 'Gebruiker niet gevonden'];
    }

    $stmt = $pdo->prepare('SELECT gebonden_gebruiker_id FROM license_activations WHERE token_id = :tid');
    $stmt->execute([':tid' => $payload['id']]);
    $bestaand = $stmt->fetch();
    if ($bestaand) {
        if ((int) $bestaand['gebonden_gebruiker_id'] === $gebruikerId) {
            return ['success' => true, 'message' => 'Deze code was al aan ' . $gebruiker['naam'] . ' gekoppeld.'];
        }
        return ['success' => false, 'error' => 'Deze code is al gekoppeld aan een andere gebruiker.'];
    }

    $vandaag = (new DateTime('today'))->format('Y-m-d');
    if ($payload['expires'] < $vandaag) {
        return ['success' => false, 'error' => 'Deze code is al verlopen op ' . $payload['expires'] . ' -- vraag een nieuwe code aan.'];
    }

    $pdo->prepare(
        'INSERT INTO license_activations (token, token_id, type, gebonden_gebruiker_id, geldig_vanaf, geldig_tot, toegevoegd_door)
         VALUES (:token, :tid, :type, :uid, :vanaf, :tot, :door)'
    )->execute([
        ':token' => $tokenRaw, ':tid' => $payload['id'], ':type' => 'extra_user', ':uid' => $gebruikerId,
        ':vanaf' => $payload['issued'], ':tot' => $payload['expires'], ':door' => $toegevoegdDoor,
    ]);

    $pdo->prepare('UPDATE users SET licentie_geblokkeerd = 0, licentie_vrij = 0 WHERE id = :id')->execute([':id' => $gebruikerId]);

    return ['success' => true, 'message' => 'Code gekoppeld aan ' . $gebruiker['naam'] . ', geldig tot ' . $payload['expires'] . '.'];
}

/**
 * ("Licentiemodel:
 * registratie blokkeren zonder geldige licentie"): valideert een
 * activatiecode + bevestigd besteladres VOORDAT er een nieuw account wordt
 * aangemaakt (api/gebruikers.php, de 'aanmaken'-actie) -- dezelfde checks
 * als koppelLicentieCode() hierboven, behalve de koppeling aan een
 * gebruiker zelf (die bestaat op dit moment nog niet, het account wordt
 * pas na een geslaagde validatie hier aangemaakt). Zo faalt een
 * ongeldige/verlopen/al-gekoppelde code VOORDAT er een account in de
 * database staat, i.p.v. het gat dat hiervoor bestond: een compleet
 * onbeperkt aan te maken account zonder ENIGE licentiecontrole, dat er
 * dan wel staat maar nooit kan inloggen (zie controleerLicentieGebruiker()
 * hieronder) -- een bewuste keuze om die "dode accounts"-mogelijkheid
 * te dichten, niet alleen de login zelf te blokkeren.
 *
 * Na deze validatie roept api/gebruikers.php nog steeds koppelLicentieCode()
 * aan (met het echte, dan bekende gebruiker-id) om de daadwerkelijke
 * koppeling vast te leggen -- deze functie hier doet zelf GEEN schrijf-
 * actie, puur een voorcontrole.
 *
 * @return array{geldig:bool, reden:?string}
 */
function valideerActivatiecodeVoorRegistratie(string $tokenRaw, string $bevestigdEmail): array {
    $result = verifieerToken($tokenRaw);
    if (!$result['geldig']) {
        return ['geldig' => false, 'reden' => $result['reden']];
    }
    $payload = $result['payload'];

    if (($payload['type'] ?? null) !== 'extra_user') {
        return ['geldig' => false, 'reden' => 'Dit tokentype kan niet voor een nieuwe registratie gebruikt worden.'];
    }

    $codeEmail = strtolower(trim((string) $payload['email']));
    $ingevoerd = strtolower(trim($bevestigdEmail));
    if ($codeEmail === '' || $ingevoerd !== $codeEmail) {
        return ['geldig' => false, 'reden' => 'Het ingevoerde e-mailadres komt niet overeen met het besteladres waaronder deze code is uitgegeven.'];
    }

    $vandaag = (new DateTime('today'))->format('Y-m-d');
    if ($payload['expires'] < $vandaag) {
        return ['geldig' => false, 'reden' => 'Deze code is al verlopen op ' . $payload['expires'] . ' -- vraag een nieuwe code aan.'];
    }

    $stmt = db()->prepare('SELECT gebonden_gebruiker_id FROM license_activations WHERE token_id = :tid');
    $stmt->execute([':tid' => $payload['id']]);
    if ($stmt->fetch()) {
        return ['geldig' => false, 'reden' => 'Deze code is al gekoppeld aan een ander account.'];
    }

    return ['geldig' => true, 'reden' => null];
}

/**
 * Bepaalt of $gebruikerId de RECHTMATIGE ene gratis
 * gebruiker van deze installatie is -- niet zomaar "heeft een geldig
 * vrij_lokaal-token", want een direct in de database bijgezette extra
 * rij (die zonder het lokale geheim toch nooit een kloppende HMAC zou
 * hebben, maar defense-in-depth kost hier bijna niets) telt alleen mee
 * als het de rij met het LAAGSTE gebonden_gebruiker_id is. Gebruikt de
 * Nieuwe type-kolom om niet elke rij te hoeven decoderen
 * -- alleen de kandidaat-rij wordt daadwerkelijk cryptografisch
 * geverifieerd.
 */
function isRechtmatigeVrijeGebruiker(int $gebruikerId): bool {
    $laagste = db()->query(
        "SELECT gebonden_gebruiker_id FROM license_activations WHERE type = 'vrij_lokaal' ORDER BY gebonden_gebruiker_id ASC LIMIT 1"
    )->fetchColumn();
    return $laagste !== false && (int) $laagste === $gebruikerId;
}

/**
 * De daadwerkelijke "mag deze gebruiker gebruikmaken van de app?"-vraag.
 * Aangeroepen vanuit includes/auth.php bij elke login én periodiek
 * tijdens gebruik (zie herlicentieControleNodig() hieronder) -- NOOIT
 * alleen bij het aanmaken van een gebruiker, anders is een verlopen
 * licentie pas zichtbaar bij de volgende keer dat iemand een account
 * aanmaakt/bewerkt.
 *
 * Leunt niet meer op users.licentie_vrij (kale boolean) --
 * kijkt naar de daadwerkelijk gekoppelde license_activations-rij, ongeacht
 * of die een echte code of het lokale gratis-token is, en verifieert 'm
 * ELKE keer opnieuw (handtekening/HMAC + voor vendor-codes ook de
 * e-mailbinding). users.licentie_vrij/licentie_geblokkeerd worden aan het
 * einde alleen nog als leesbare CACHE bijgewerkt voor het Gebruikers-
 * scherm, nooit als bron van waarheid gelezen.
 *
 * @return array{ok:bool, reden:?string, vrij:bool, geldigTot:?string}
 */
function controleerLicentieGebruiker(int $gebruikerId): array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, email FROM users WHERE id = :id');
    $stmt->execute([':id' => $gebruikerId]);
    $gebruiker = $stmt->fetch();
    if (!$gebruiker) {
        return ['ok' => false, 'reden' => 'Gebruiker niet gevonden', 'vrij' => false, 'geldigTot' => null];
    }

    $stmt = $pdo->prepare('SELECT token, type, geldig_tot FROM license_activations WHERE gebonden_gebruiker_id = :id ORDER BY geldig_tot DESC LIMIT 1');
    $stmt->execute([':id' => $gebruikerId]);
    $activatie = $stmt->fetch();

    $vrijCache = 0; // wat er straks in users.licentie_vrij als cache komt

    if (!$activatie) {
        $pdo->prepare('UPDATE users SET licentie_geblokkeerd = 1, licentie_vrij = 0 WHERE id = :id')->execute([':id' => $gebruikerId]);
        return ['ok' => false, 'reden' => 'Geen activatiecode gekoppeld aan dit account.', 'vrij' => false, 'geldigTot' => null];
    }

    $verificatie = verifieerToken($activatie['token']);
    if (!$verificatie['geldig']) {
        $pdo->prepare('UPDATE users SET licentie_geblokkeerd = 1, licentie_vrij = 0 WHERE id = :id')->execute([':id' => $gebruikerId]);
        return ['ok' => false, 'reden' => 'Gekoppelde code is niet (meer) geldig.', 'vrij' => false, 'geldigTot' => $activatie['geldig_tot']];
    }
    $payload = $verificatie['payload'];

    $vandaag = (new DateTime('today'))->format('Y-m-d');
    if ($activatie['geldig_tot'] < $vandaag) {
        $pdo->prepare('UPDATE users SET licentie_geblokkeerd = 1, licentie_vrij = 0 WHERE id = :id')->execute([':id' => $gebruikerId]);
        return ['ok' => false, 'reden' => 'Licentie verlopen op ' . $activatie['geldig_tot'] . ' -- koppel een nieuwe activatiecode.', 'vrij' => false, 'geldigTot' => $activatie['geldig_tot']];
    }

    if (($payload['type'] ?? null) === 'vrij_lokaal') {
        // "Maximaal 1 gratis gebruiker": zelfs met een cryptografisch
        // geldig lokaal token telt alleen de rechtmatige (laagste id) mee
        // -- zie isRechtmatigeVrijeGebruiker().
        if (!isRechtmatigeVrijeGebruiker($gebruikerId)) {
            $pdo->prepare('UPDATE users SET licentie_geblokkeerd = 1, licentie_vrij = 0 WHERE id = :id')->execute([':id' => $gebruikerId]);
            return ['ok' => false, 'reden' => 'Geen activatiecode gekoppeld aan dit account.', 'vrij' => false, 'geldigTot' => null];
        }
        $vrijCache = 1;
    } else {
        // Herzien: de e-mailbinding wordt alleen nog EENMALIG
        // bevestigd bij het koppelen (zie koppelLicentieCode()) -- niet meer
        // doorlopend herverifieerd tegen het (huidige) e-mailadres van dit
        // account. Reden: 1 WooCommerce-bestelling kan meerdere accounts
        // bedienen, elk met een eigen e-mailadres dat niets te maken heeft
        // met het besteladres in de code. Er is dus geen persistente
        // "account-e-mail hoort bij code-e-mail"-eis meer om doorlopend op
        // te controleren; de bevestiging bij koppelen is de enige check.
        // Geldigheid/vervaldatum/handtekening worden hierboven nog steeds
        // wel bij elke hercontrole geverifieerd.
    }

    $pdo->prepare('UPDATE users SET licentie_geblokkeerd = 0, licentie_vrij = :vrij WHERE id = :id')
        ->execute([':vrij' => $vrijCache, ':id' => $gebruikerId]);
    return ['ok' => true, 'reden' => null, 'vrij' => (bool) $vrijCache, 'geldigTot' => $vrijCache ? null : $activatie['geldig_tot']];
}

/**
 * Periodieke hercontrole tijdens gebruik (i.p.v. bij elke losse request
 * de database + sodium-verificatie te belasten): true zodra er meer dan
 * config/license.php's 'hercontrole_uren' verstreken zijn sinds de
 * laatste geslaagde controle in DEZE sessie. Bij login (includes/auth.php::
 * attemptLogin()) wordt de sessie-tijdstempel altijd meteen gezet, dus de
 * eerste hercontrole tijdens een sessie vindt pas na die periode plaats.
 */
function herlicentieControleNodig(): bool {
    $laatst = $_SESSION['licentie_check_op'] ?? 0;
    $uren = licentieConfig()['hercontrole_uren'];
    return (time() - $laatst) > ($uren * 3600);
}

function markeerLicentieGecontroleerd(): void {
    $_SESSION['licentie_check_op'] = time();
}

/**
 * Telt hoeveel accounts MOMENTEEL als geldig gratis
 * doorkomen (dus na de "laagste id wint"-regel in
 * isRechtmatigeVrijeGebruiker() zou dit altijd 0 of 1 moeten zijn via de
 * normale applicatiepaden). >1 kan in theorie alleen als iemand met het
 * lokale geheim zelf een tweede geldige rij heeft toegevoegd -- gebruikt
 * door api/gebruikers.php om een audit-signaal + waarschuwing op het
 * Gebruikers-scherm te tonen (zie licentieAnomalieController() aldaar).
 */
function telAantalGeldigeVrijeTokens(): int {
    $rijen = db()->query("SELECT token FROM license_activations WHERE type = 'vrij_lokaal'")->fetchAll(PDO::FETCH_COLUMN);
    $geldig = 0;
    foreach ($rijen as $token) {
        if (verifieerLokaalToken((string) $token)['geldig']) $geldig++;
    }
    return $geldig;
}
