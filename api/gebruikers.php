<?php
/**
 * Gebruikersbeheer, alleen voor beheerders (migratieplan hoofdstuk
 * 9.2/9.4; "beheerder" is een los vinkje, is_beheerder,
 * geen functie meer -- zie includes/auth.php::requireBeheerder()).
 *
 * Dit scherm
 * is nu de ENIGE plek om een specialist/laborant/bekijker aan te maken
 * of te wijzigen -- de vroegere aparte Stamgegevens > Specialisten-lijst
 * is samengevoegd met Gebruikers, want iedereen die met het systeem
 * werkt logt zelf in. Een nieuw account krijgt direct een functie
 * (specialist/laborant/bekijker) en optioneel het is_beheerder-vinkje,
 * in plaats van eerst een naam in Stamgegevens en dan een los account te
 * moeten koppelen.
 *
 * GET  -> lijst gebruikers (zonder wachtwoord_hash)
 * POST -> { actie: 'aanmaken' (of weggelaten), naam, email, wachtwoord,
 *         rol? (specialist/laborant/bekijker), is_beheerder?, taal?,
 *         licentie_code, licentie_bevestigd_email }
 *         -- de
 *         laatste twee velden zijn VERPLICHT sinds die migratie -- een
 *         nieuw account wordt alleen aangemaakt als licentie_code een
 *         geldige, nog niet-gekoppelde activatiecode is en
 *         licentie_bevestigd_email overeenkomt met het besteladres
 *         waaronder die code is uitgegeven (zie includes/license.php::
 *         valideerActivatiecodeVoorRegistratie()/koppelLicentieCode()).
 *         Voorheen kon hier een onbeperkt aantal accounts zonder ENIGE
 *         licentie aangemaakt worden die toch nooit konden inloggen; dat
 *         gat is hiermee gedicht.
 *      -> { actie: 'gebruiker_bijwerken', id, rol, is_beheerder, actief, taal?, naam?, email? }
 *         -- wijzigt functie/beheerder-vlag/actief van een BESTAAND
 *         account in één keer (vervangt het oude, smallere
 *         'specialist_koppelen' -- dat bestond om een
 *         los account aan een Stamgegevens-naam te koppelen; nu functie
 *         en account één en dezelfde rij zijn, is een brede "wijzig dit
 *         account"-actie logischer). taal is optioneel: een beheerder
 *         kan hiermee de taal van een ANDER account wijzigen (voorheen
 *         kon alleen de gebruiker dit zelf, zie
 *         api/mijn_taal.php -- dat blijft gewoon bestaan).
 *         ("Naam, e-mail van gebruikers
 *         zijn niet aan te passen. Maak dit aanpasbaar."): naam en email
 *         zijn nu ook optioneel meegeefbaar. Bij een gewijzigde naam wordt
 *         eerst registreerHernoeming('specialist', oud, nieuw) aangeroepen
 *         (includes/hernoemingen.php) zodat transactielog.specialist-
 *         historie (Leaderboard, Verbruik per Procedure, PICC-rotatie) de
 *         omdoop kan volgen i.p.v. te splitsen -- zie ook includes/
 *         leaderboard.php en includes/artikelen.php voor de leeskant van
 *         Die fix. email heeft een UNIQUE-constraint; een
 *         dubbele waarde geeft een nette foutmelding i.p.v. een
 *         onafgevangen PDOException, zelfde patroon als
 *         updateLocatieExtra() in includes/locaties.php.
 *      -> { actie: 'foto_bijwerken', id, foto } -- foto is een data-URL
 *         ("data:image/png;base64,..."), zie includes/upload.php
 *      -> { actie: 'foto_verwijderen', id }
 *      -> { actie: 'wachtwoord_resetten', id, wachtwoord } -- beheerder
 *         zet een nieuw wachtwoord voor een ANDER account (geen "wachtwoord
 *         vergeten"-mailflow in deze app, zie toelichting hieronder bij
 *         die actie); zelfde minimumlengte-eis (8) als bij aanmaken.
 *
 * Taal is de starttaalvoorkeur voor het nieuwe account --
 * de gebruiker zelf kan dit later altijd zelf wijzigen (Sign out-menu,
 * zie api/mijn_taal.php), en sinds hierboven kan een beheerder dit ook
 * voor een ANDER account wijzigen via 'gebruiker_bijwerken'. Sindsdien
 * kan een beheerder via diezelfde actie ook naam/e-mail
 * bewerken (zie hierboven) -- dat kon voorheen niet.
 *
 * Rol 'specialist'/'laborant': volledige schrijfrechten
 * (scannen, bestellen, alles), zie includes/auth.php::
 * requireSchrijfToegang() -- alleen 'bekijker' is alleen-lezen, tenzij
 * ook is_beheerder=1. Een Laborant mag bij Scan Usage alleen procedures
 * uitvoeren die daarvoor zijn aangevinkt (in de praktijk nu alleen
 * PICC-lijn), zie includes/transacties.php::verwerkScan().
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/upload.php';
// Groepen (welke schermen/schrijftoegang) zijn een LOS
// veld naast rol hierboven -- rol behoudt zijn klinische betekenis
// (zie sql/migrations/024_groepen.sql), 'groepen' in de POST-body
// hieronder is puur het nieuwe permissiesysteem.
require_once __DIR__ . '/../includes/groepen.php';
// 'licentie_koppelen' hieronder + de
// licentiestatus die de GET-lijst per gebruiker meestuurt, zie
// includes/license.php voor de volledige toelichting.
require_once __DIR__ . '/../includes/license.php';
// RegistreerHernoeming() -- 'gebruiker_bijwerken' hieronder
// roept dit aan bij een naamswijziging, zie includes/hernoemingen.php.
require_once __DIR__ . '/../includes/hernoemingen.php';

$beheerder = apiRequireBeheerder();

const GEBRUIKER_ROLLEN = ['specialist', 'laborant', 'bekijker'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    $actie = $body['actie'] ?? 'aanmaken';

    if ($actie === 'foto_bijwerken') {
        apiTry(function () use ($body, $beheerder) {
            $id = (int) ($body['id'] ?? 0);
            $dataUrl = (string) ($body['foto'] ?? '');
            if ($id <= 0 || $dataUrl === '') {
                return ['success' => false, 'error' => 'Gebruiker en afbeelding zijn verplicht'];
            }
            $stmt = db()->prepare('SELECT foto FROM users WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $bestaand = $stmt->fetch();
            if (!$bestaand) return ['success' => false, 'error' => 'Gebruiker niet gevonden'];

            try {
                $pad = verwerkFotoUpload($dataUrl, 'gebruikers');
            } catch (RuntimeException $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
            db()->prepare('UPDATE users SET foto = :foto WHERE id = :id')->execute([':foto' => $pad, ':id' => $id]);
            // Pas ná de geslaagde UPDATE de oude foto opruimen -- zie
            // includes/upload.php voor waarom dit niet in
            // verwerkFotoUpload() zelf gebeurt.
            if ($bestaand['foto'] !== null && $bestaand['foto'] !== $pad) {
                verwijderFotoBestand($bestaand['foto']);
            }
            logAudit($beheerder['naam'], 'gebruiker_foto_bijgewerkt', 'gebruiker', (string) $id, null);
            return ['success' => true, 'foto' => $pad];
        });
    } elseif ($actie === 'foto_verwijderen') {
        apiTry(function () use ($body, $beheerder) {
            $id = (int) ($body['id'] ?? 0);
            $stmt = db()->prepare('SELECT foto FROM users WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $bestaand = $stmt->fetch();
            if (!$bestaand) return ['success' => false, 'error' => 'Gebruiker niet gevonden'];

            verwijderFotoBestand($bestaand['foto']);
            db()->prepare('UPDATE users SET foto = NULL WHERE id = :id')->execute([':id' => $id]);
            logAudit($beheerder['naam'], 'gebruiker_foto_verwijderd', 'gebruiker', (string) $id, null);
            return ['success' => true];
        });
    } elseif ($actie === 'gebruiker_bijwerken') {
        apiTry(function () use ($body, $beheerder) {
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) return ['success' => false, 'error' => 'Gebruiker is verplicht'];
            if (!in_array($body['rol'] ?? '', GEBRUIKER_ROLLEN, true)) {
                return ['success' => false, 'error' => 'Ongeldige functie'];
            }

            $rol = $body['rol'];
            $isBeheerder = !empty($body['is_beheerder']);
            $actief = !empty($body['actief']);
            // : een beheerder moet de taal van EEN ANDER
            // account kunnen wijzigen vanuit dit overzicht -- voorheen kon
            // alleen de gebruiker zelf dit aanpassen (Sign out-menu, zie
            // api/mijn_taal.php). 'taal' is optioneel en blijft ongewijzigd
            // als het ontbreekt of een onbekende waarde bevat (oudere
            // frontend-aanroep zonder dit veld mag de taal niet resetten).
            $taal = in_array($body['taal'] ?? '', ['en', 'nl'], true) ? $body['taal'] : null;

            // Naam/email zijn optioneel
            // -- ontbreken ze (oudere frontend-aanroep), dan blijven ze
            // ongewijzigd, net als taal hierboven. Leeg trimmen telt als
            // "niet meegegeven" i.p.v. een leeg veld op te slaan.
            $naam = isset($body['naam']) ? trim((string) $body['naam']) : null;
            if ($naam === '') $naam = null;
            $email = isset($body['email']) ? trim((string) $body['email']) : null;
            if ($email === '') $email = null;

            // Oude naam opzoeken VOORDAT die overschreven wordt, zodat de
            // hernoeming geregistreerd kan worden (zie includes/
            // hernoemingen.php) -- alleen relevant als naam daadwerkelijk
            // meegegeven is.
            $oudeNaam = null;
            if ($naam !== null) {
                $stmtOud = db()->prepare('SELECT naam FROM users WHERE id = :id');
                $stmtOud->execute([':id' => $id]);
                $oudeNaamRij = $stmtOud->fetchColumn();
                $oudeNaam = $oudeNaamRij !== false ? (string) $oudeNaamRij : null;
            }

            $velden = 'rol = :rol, is_beheerder = :is_beheerder, actief = :actief';
            $params = [':rol' => $rol, ':is_beheerder' => $isBeheerder ? 1 : 0, ':actief' => $actief ? 1 : 0, ':id' => $id];
            if ($taal !== null) { $velden .= ', taal = :taal'; $params[':taal'] = $taal; }
            if ($naam !== null) { $velden .= ', naam = :naam'; $params[':naam'] = $naam; }
            if ($email !== null) { $velden .= ', email = :email'; $params[':email'] = $email; }

            try {
                $stmt = db()->prepare("UPDATE users SET $velden WHERE id = :id");
                $stmt->execute($params);
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    return ['success' => false, 'error' => 'Er bestaat al een gebruiker met dit e-mailadres'];
                }
                throw $e;
            }
            if ($stmt->rowCount() === 0) {
                $check = db()->prepare('SELECT id FROM users WHERE id = :id');
                $check->execute([':id' => $id]);
                if (!$check->fetch()) return ['success' => false, 'error' => 'Gebruiker niet gevonden'];
            }

            // Pas NA een geslaagde UPDATE registreren, en
            // alleen als de naam ook echt veranderd is (registreerHernoeming()
            // is zelf al een no-op bij gelijke namen, maar zo wordt de
            // lookup van $oudeNaam hierboven ook overgeslagen als er niets
            // te registreren valt).
            if ($naam !== null && $oudeNaam !== null && $oudeNaam !== $naam) {
                registreerHernoeming('specialist', $oudeNaam, $naam);
            }

            // Groepslidmaatschap in dezelfde actie
            // bijwerken -- 'groepen' is optioneel (array van groep-id's);
            // ontbreekt het veld helemaal (oudere frontend-aanroep), dan
            // blijft het bestaande lidmaatschap ongemoeid i.p.v. het leeg
            // te trekken.
            if (isset($body['groepen']) && is_array($body['groepen'])) {
                setGebruikerGroepen($id, array_map('intval', $body['groepen']), $beheerder['naam']);
            }

            logAudit($beheerder['naam'], 'gebruiker_bijgewerkt', 'gebruiker', (string) $id, "rol=$rol, is_beheerder=" . ($isBeheerder ? '1' : '0') . ', actief=' . ($actief ? '1' : '0'));
            return ['success' => true];
        });
    } elseif ($actie === 'wachtwoord_resetten') {
        // : een beheerder moet het wachtwoord van een
        // ander account kunnen resetten (bijv. vergeten wachtwoord) --
        // er is geen "wachtwoord vergeten"-mailflow (bewust, zie
        // vendor-tools/README over hobby-schaal), dus dit is de enige weg.
        // Zelfde minimumlengte-eis als bij het aanmaken van een account.
        // Het nieuwe wachtwoord wordt NOOIT gelogd (ook niet in de audit
        // trail) -- alleen dat de actie heeft plaatsgevonden.
        apiTry(function () use ($body, $beheerder) {
            $id = (int) ($body['id'] ?? 0);
            $wachtwoord = (string) ($body['wachtwoord'] ?? '');
            if ($id <= 0 || strlen($wachtwoord) < 8) {
                return ['success' => false, 'error' => 'Gebruiker en een wachtwoord van minstens 8 tekens zijn verplicht'];
            }

            $stmt = db()->prepare('UPDATE users SET wachtwoord_hash = :hash WHERE id = :id');
            $stmt->execute([':hash' => password_hash($wachtwoord, PASSWORD_DEFAULT), ':id' => $id]);
            if ($stmt->rowCount() === 0) {
                $check = db()->prepare('SELECT id FROM users WHERE id = :id');
                $check->execute([':id' => $id]);
                if (!$check->fetch()) return ['success' => false, 'error' => 'Gebruiker niet gevonden'];
            }

            logAudit($beheerder['naam'], 'wachtwoord_gereset', 'gebruiker', (string) $id, null);
            return ['success' => true, 'message' => 'Wachtwoord gereset'];
        });
    } elseif ($actie === 'licentie_koppelen') {
        // Een beheerder plakt hier de
        // activatiecode die per e-mail is ontvangen na aankoop (zie
        // vendor-tools/) en koppelt 'm aan een specifieke gebruiker --
        // zie includes/license.php::koppelLicentieCode() voor de
        // volledige (offline) verificatie.
        //
        // Herzien: naast de code wordt ook het besteladres
        // (bevestigd_email) meegestuurd -- de beheerder typt dit zelf over
        // uit de aankoopmail, ter bevestiging dat deze code daadwerkelijk
        // uit die bestelling komt. Zie includes/license.php::
        // koppelLicentieCode() voor de vergelijking met payload['email'].
        apiTry(function () use ($body, $beheerder) {
            $id = (int) ($body['id'] ?? 0);
            $code = trim((string) ($body['code'] ?? ''));
            $bevestigdEmail = trim((string) ($body['bevestigd_email'] ?? ''));
            if ($id <= 0 || $code === '' || $bevestigdEmail === '') {
                return ['success' => false, 'error' => 'Gebruiker, activatiecode en besteladres zijn verplicht'];
            }
            $result = koppelLicentieCode($code, $id, $bevestigdEmail, $beheerder['naam']);
            if ($result['success']) {
                logAudit($beheerder['naam'], 'licentie_gekoppeld', 'gebruiker', (string) $id, $result['message'] ?? null);
            }
            return $result;
        });
    } else {
        $naam = trim($body['naam'] ?? '');
        $email = trim($body['email'] ?? '');
        $wachtwoord = (string) ($body['wachtwoord'] ?? '');
        $rol = in_array($body['rol'] ?? '', GEBRUIKER_ROLLEN, true) ? $body['rol'] : 'bekijker';
        $isBeheerder = !empty($body['is_beheerder']);
        $taal = in_array($body['taal'] ?? '', ['en', 'nl'], true) ? $body['taal'] : 'en';

        if ($naam === '' || $email === '' || strlen($wachtwoord) < 8) {
            http_response_code(400);
            jsonResponse(['success' => false, 'error' => 'Naam, e-mail en een wachtwoord van minstens 8 tekens zijn verplicht']);
        }

        // Licentiemodel: registratie blokkeren zonder geldige licentie --
        // een nieuw account kan hier niet meer aangemaakt worden zonder
        // een geldige, nog niet-gekoppelde activatiecode + het bijbehorende
        // besteladres -- zie includes/license.php::
        // valideerActivatiecodeVoorRegistratie() voor de volledige
        // toelichting op WAAROM (voorheen kon een beheerder onbeperkt
        // "dode" accounts aanmaken die toch nooit konden inloggen).
        $licentieCode = trim((string) ($body['licentie_code'] ?? ''));
        $licentieEmail = trim((string) ($body['licentie_bevestigd_email'] ?? ''));
        if ($licentieCode === '' || $licentieEmail === '') {
            http_response_code(400);
            jsonResponse(['success' => false, 'error' => 'Een geldige activatiecode en het bijbehorende besteladres zijn verplicht om een nieuw account te registreren.']);
        }
        $licentieCheck = valideerActivatiecodeVoorRegistratie($licentieCode, $licentieEmail);
        if (!$licentieCheck['geldig']) {
            http_response_code(400);
            jsonResponse(['success' => false, 'error' => $licentieCheck['reden']]);
        }

        $groepIds = isset($body['groepen']) && is_array($body['groepen']) ? array_map('intval', $body['groepen']) : [];

        apiTry(function () use ($naam, $email, $wachtwoord, $rol, $isBeheerder, $taal, $beheerder, $groepIds, $licentieCode, $licentieEmail) {
            $pdo = db();
            $pdo->prepare('INSERT INTO users (naam, email, wachtwoord_hash, rol, is_beheerder, taal) VALUES (:naam, :email, :hash, :rol, :is_beheerder, :taal)')
                ->execute([
                    ':naam' => $naam, ':email' => $email,
                    ':hash' => password_hash($wachtwoord, PASSWORD_DEFAULT), ':rol' => $rol,
                    ':is_beheerder' => $isBeheerder ? 1 : 0, ':taal' => $taal,
                ]);
            $nieuwId = (int) $pdo->lastInsertId();
            // Meteen bij aanmaken ook groepslidmaatschap
            // zetten, als er groepen zijn meegestuurd.
            if ($groepIds) setGebruikerGroepen($nieuwId, $groepIds, $beheerder['naam']);

            // De code hierboven is al
            // eenmaal voorgevalideerd, maar koppelLicentieCode() doet de
            // ECHTE (schrijvende) koppeling -- inclusief de dubbele-koppeling-
            // check op token_id, die nu met het net aangemaakte gebruiker-id
            // opnieuw plaatsvindt. Mislukt dit toch nog (bijv. een gelijktijdige
            // aanvraag met dezelfde code net iets eerder gekoppeld), dan wordt
            // het net aangemaakte account weer verwijderd -- nooit een "dood"
            // account achterlaten dat toch niet kan inloggen.
            $koppelResultaat = koppelLicentieCode($licentieCode, $nieuwId, $licentieEmail, $beheerder['naam']);
            if (!$koppelResultaat['success']) {
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $nieuwId]);
                return ['success' => false, 'error' => $koppelResultaat['error'] ?? 'Kon de activatiecode niet aan het nieuwe account koppelen.'];
            }

            logAudit($beheerder['naam'], 'gebruiker_aangemaakt', 'gebruiker', $email, "naam=$naam, rol=$rol, is_beheerder=" . ($isBeheerder ? '1' : '0'));
            logAudit($beheerder['naam'], 'licentie_gekoppeld', 'gebruiker', (string) $nieuwId, $koppelResultaat['message'] ?? null);
            return ['success' => true, 'message' => 'Gebruiker aangemaakt en licentie gekoppeld', 'id' => $nieuwId];
        });
    }
} else {
    apiTry(function () {
        $rows = db()->query('SELECT id, naam, email, rol, is_beheerder, taal, foto, actief, laatst_ingelogd, aangemaakt_op, licentie_vrij FROM users ORDER BY naam')->fetchAll();
        // Groepslidmaatschap per gebruiker meesturen, zodat
        // het Gebruikers-scherm meteen weet welke groep-checkboxes al aan
        // moeten staan bij het bewerken van een bestaand account.
        //
        // Licentiestatus per gebruiker LIVE herverifieerd
        // (niet de gecachete licentie_geblokkeerd-kolom uitgelezen) --
        // dit scherm is precies de plek waar een beheerder dit moet
        // kunnen zien, dus toont het altijd de actuele staat, inclusief
        // een net verlopen code die de betreffende gebruiker zelf nog
        // niet heeft "gemerkt" (bijv. nog ingelogde sessie).
        // Hoeveel CRYPTOGRAFISCH geldige lokale gratis-
        // tokens er momenteel bestaan (niet hetzelfde als het aantal
        // gebruikers met licentieStatus.vrij=true hieronder -- die is
        // altijd 0 of 1, want controleerLicentieGebruiker() past de
        // "laagste id wint"-regel al toe. Dit telt de ONDERLIGGENDE rijen,
        // dus dit IS de manier om >1 te kunnen zien). Zie
        // includes/license.php::telAantalGeldigeVrijeTokens().
        $aantalGeldigeVrij = telAantalGeldigeVrijeTokens();
        if ($aantalGeldigeVrij > 1) {
            // Rate-limited audit-signaal (niet bij elke keer dat dit
            // scherm geopend wordt opnieuw loggen) -- alleen als de
            // laatste 'licentie_anomalie'-regel meer dan een uur oud is.
            $laatsteMelding = db()->query(
                "SELECT tijdstempel FROM audit_log WHERE actie = 'licentie_anomalie' ORDER BY tijdstempel DESC LIMIT 1"
            )->fetchColumn();
            if (!$laatsteMelding || (time() - strtotime((string) $laatsteMelding)) > 3600) {
                logAudit('systeem', 'licentie_anomalie', null, null,
                    "Er zijn $aantalGeldigeVrij geldige lokale gratis-tokens gevonden, verwacht maximaal 1.");
            }
        }

        foreach ($rows as &$row) {
            $row['groepen'] = gebruikerGroepIds((int) $row['id']);
            $licentie = controleerLicentieGebruiker((int) $row['id']);
            $row['licentieStatus'] = [
                'ok' => $licentie['ok'],
                'vrij' => $licentie['vrij'],
                'geldigTot' => $licentie['geldigTot'],
                'reden' => $licentie['reden'],
            ];
            // Installatiebreed feit, op elke rij meegestuurd
            // (i.p.v. een apart top-level veld) omdat dit endpoint een
            // kale JSON-array teruggeeft, geen {data:[...]}-envelop --
            // renderGebruikers() (assets/js/app.js) leest 'm van de eerste
            // rij.
            $row['licentieAnomalieAantal'] = $aantalGeldigeVrij;
        }
        unset($row);
        return $rows;
    });
}
