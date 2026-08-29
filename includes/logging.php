<?php
/**
 * Foutlog. Poort van logError()/getFoutlog() uit Code.gs/DashboardAll.gs.
 * Schrijft naar de foutlog-tabel i.p.v. een sheet-tabblad.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function logError(string $errorType, string $barcode, string $message): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO foutlog (fouttype, barcode, bericht) VALUES (:type, :barcode, :bericht)'
        );
        $stmt->execute([
            ':type' => $errorType,
            ':barcode' => $barcode,
            ':bericht' => $message,
        ]);
    } catch (Throwable $e) {
        // Foutlogging mag zelf nooit een aanvraag laten crashen.
        error_log('logError mislukt: ' . $e->getMessage());
    }
}

/**
 * Laatste 100 fouten, nieuwste eerst. Poort van getFoutlog().
 */
function getFoutlog(): array {
    $rows = db()->query(
        'SELECT tijdstip, fouttype AS type, barcode, bericht
         FROM foutlog ORDER BY tijdstip DESC, id DESC LIMIT 100'
    )->fetchAll();

    return array_map(function (array $r): array {
        return [
            'datum' => (new DateTime($r['tijdstip']))->format('d-m-Y'),
            'tijd' => (new DateTime($r['tijdstip']))->format('H:i:s'),
            'type' => $r['type'],
            'barcode' => $r['barcode'],
            'bericht' => $r['bericht'],
        ];
    }, $rows);
}
