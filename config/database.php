<?php
/**
 * PDO-verbinding. Eén functie db() die overal in includes/ en api/ wordt
 * hergebruikt (lazy singleton, geen nieuwe connectie per aanroep).
 */

declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $localConfigPath = __DIR__ . '/database.local.php';
    if (!file_exists($localConfigPath)) {
        throw new RuntimeException(
            'config/database.local.php ontbreekt. Kopieer database.local.example.php ' .
            'naar database.local.php en vul de echte gegevens in.'
        );
    }

    $cfg = require $localConfigPath;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['database'],
        $cfg['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
