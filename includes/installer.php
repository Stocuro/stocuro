<?php
/**
 * Migratie 036, op verzoek van Harm: gedeelde migratierunner, gehaald uit
 * sql/migrate.php (dat bestand roept nu gewoon voerMigratiesUit() aan)
 * zodat install.php (de browser-installer/updater, zie install.php in de
 * hoofdmap) en de command-line variant precies dezelfde logica gebruiken
 * -- geen twee losse implementaties die uiteen kunnen groeien.
 *
 * declare(strict_types=1) is hier BEWUST weggelaten: dit bestand kan
 * draaien vóórdat de installatie compleet is (dus mogelijk tegen een
 * oudere/net andere PHP-configuratie dan de rest van de app aanneemt) --
 * strict_types zou hier alleen maar een extra faalkans toevoegen zonder
 * voordeel, aangezien alle aanroepen hieronder toch al met de juiste
 * types gebeuren.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Zorgt dat schema_migrations bestaat en geeft de PDO-verbinding terug --
 * gedeeld door migratiesInWachtrij() en voerMigratiesUit() zodat beide
 * altijd tegen dezelfde, gegarandeerd-bestaande tabel werken.
 */
function schemaMigratiesTabel(): PDO {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        migratie VARCHAR(255) NOT NULL UNIQUE,
        uitgevoerd_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $pdo;
}

/**
 * Geeft de bestandsnamen van migraties die nog NIET in schema_migrations
 * staan, in uitvoervolgorde. Gebruikt door install.php om vooraf te
 * weten of er iets te doen valt (en dus een voortgangspagina de moeite
 * waard is), zonder de migraties zelf al uit te voeren.
 *
 * @return string[]
 */
function migratiesInWachtrij(): array {
    $pdo = schemaMigratiesTabel();
    $stmt = $pdo->query('SELECT migratie FROM schema_migrations');
    $uitgevoerd = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $migratiesMap = __DIR__ . '/../sql/migrations';
    $bestanden = glob($migratiesMap . '/*.sql');
    sort($bestanden, SORT_STRING);

    $wachtrij = [];
    foreach ($bestanden as $pad) {
        $naam = basename($pad);
        if (!in_array($naam, $uitgevoerd, true)) {
            $wachtrij[] = $naam;
        }
    }
    return $wachtrij;
}

/**
 * Voert elk bestand in sql/migrations/*.sql uit dat nog niet in
 * schema_migrations staat (elke migratie registreert zichzelf aan het
 * einde van het eigen bestand, zie een willekeurig bestand in die map).
 * Idempotent: opnieuw aanroepen als alles al is uitgevoerd doet niets.
 *
 * Migratie 041, op verzoek van Harm ("tijdens Stocuro installeren zie ik
 * niet wat de status is van database bouwen") -- optionele $onLog-
 * callback, aangeroepen met elke logregel PRECIES op het moment dat hij
 * ontstaat (niet pas als de hele batch klaar is). install.php gebruikt
 * dit om regels live naar de browser te flushen zodat iemand die 40
 * migraties in één keer ziet draaien niet naar een blanco pagina zit te
 * staren. Puur additief: bestaande aanroepers (sql/migrate.php, en
 * install.php's eigen "alles al gedaan"-pad) geven gewoon niets mee en
 * merken niets van dit gedrag.
 *
 * @return array{success:bool, log:string[], error?:string}
 */
function voerMigratiesUit(?callable $onLog = null): array {
    $log = [];
    $schrijf = static function (string $regel) use (&$log, $onLog): void {
        $log[] = $regel;
        if ($onLog !== null) {
            $onLog($regel);
        }
    };
    try {
        $pdo = schemaMigratiesTabel();

        $stmt = $pdo->query('SELECT migratie FROM schema_migrations');
        $uitgevoerd = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $migratiesMap = __DIR__ . '/../sql/migrations';
        $bestanden = glob($migratiesMap . '/*.sql');
        sort($bestanden, SORT_STRING);

        if (!$bestanden) {
            $schrijf('Geen migratiebestanden gevonden in ' . $migratiesMap);
            return ['success' => true, 'log' => $log];
        }

        foreach ($bestanden as $pad) {
            $naam = basename($pad);
            if (in_array($naam, $uitgevoerd, true)) {
                $schrijf("Overslaan (al uitgevoerd): $naam");
                continue;
            }

            $schrijf("Uitvoeren: $naam");
            $sql = file_get_contents($pad);

            // Commentaarregels (regels die -- na eventuele voorloop-
            // witruimte beginnen) EERST volledig verwijderen, vóór het
            // opknippen in losse statements. Zonder deze stap raakte een
            // commentaarblok dat direct vóór een statement staat (zonder
            // tussenliggende puntkomma -- precies de opmaak van elk
            // migratiebestand hier) samengevoegd met dat statement, en
            // werd het hele blok inclusief de echte SQL overgeslagen
            // omdat het "begon met --". Dat leidde tot stilzwijgend
            // overgeslagen CREATE TABLE-statements.
            $regelsZonderCommentaar = array_filter(
                explode("\n", $sql),
                static fn(string $regel): bool => !str_starts_with(ltrim($regel), '--')
            );
            $sqlSchoon = implode("\n", $regelsZonderCommentaar);

            // PDO/MySQL voert niet altijd betrouwbaar meerdere statements
            // in één exec() uit (afhankelijk van serverconfiguratie).
            // Splits op ';' aan het einde van een regel en voer los uit --
            // geen enkel statement in dit schema bevat een ';' binnen een
            // stringwaarde, dus dit is veilig.
            $statements = array_filter(array_map('trim', explode(";\n", $sqlSchoon)));
            foreach ($statements as $statement) {
                $statement = rtrim(trim($statement), ';');
                if ($statement === '') continue;
                $pdo->exec($statement);
            }
            $schrijf("OK: $naam");
        }

        $schrijf('Klaar.');
        return ['success' => true, 'log' => $log];
    } catch (Throwable $e) {
        $schrijf('Migratie mislukt: ' . $e->getMessage());
        return ['success' => false, 'log' => $log, 'error' => $e->getMessage()];
    }
}

/**
 * Test een databaseverbinding met LOSSE, expliciet meegegeven gegevens
 * (dus NIET via db()/config/database.local.php, dat bestaat op dit
 * moment in install.php nog niet) -- gebruikt door install.php stap 1
 * vóórdat het configbestand daadwerkelijk weggeschreven wordt.
 *
 * @return array{success:bool, error?:string}
 */
function testDatabaseVerbinding(string $host, string $database, string $username, string $password, string $charset = 'utf8mb4'): array {
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $database, $charset);
        new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        return ['success' => true];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Schrijft config/database.local.php weg in exact hetzelfde formaat als
 * config/database.local.example.php -- db() (config/database.php) leest
 * dit bestand rechtstreeks in, dus na deze aanroep werkt de normale
 * app-brede db()-functie meteen, ook binnen dezelfde requestverwerking.
 */
function schrijfDatabaseConfig(string $host, string $database, string $username, string $password, string $charset = 'utf8mb4'): void {
    $inhoud = "<?php\n"
        . "/**\n"
        . " * Door install.php gegenereerd op " . date('Y-m-d H:i:s') . ".\n"
        . " * Bevat echte inloggegevens -- NIET in versiebeheer opnemen.\n"
        . " */\n\n"
        . "return [\n"
        . "    'host'     => " . var_export($host, true) . ",\n"
        . "    'database' => " . var_export($database, true) . ",\n"
        . "    'username' => " . var_export($username, true) . ",\n"
        . "    'password' => " . var_export($password, true) . ",\n"
        . "    'charset'  => " . var_export($charset, true) . ",\n"
        . "];\n";

    $pad = __DIR__ . '/../config/database.local.php';
    if (file_put_contents($pad, $inhoud) === false) {
        throw new RuntimeException('Kon config/database.local.php niet wegschrijven -- controleer schrijfrechten op de config/-map.');
    }
    @chmod($pad, 0640);
}
