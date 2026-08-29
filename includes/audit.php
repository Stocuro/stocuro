<?php
/**
 * Generieke audit trail (migratie 010) voor beheersacties die geen eigen
 * transactielog-achtige tabel hebben: orders, eenmalige bestellingen,
 * productbewerkingen/-toevoegingen, gebruikers-/locatie-/stamgegevens-
 * beheer. Scanacties (Ontvangst/Verbruik/Verplaatsing/Retournering/
 * Telling/Vervallen) horen hier NIET in -- die krijgen hun eigen
 * gebruiker via transactielog.aangemaakt_door (zie includes/transacties.php
 * en includes/telling.php), want die tabel is al de rijke, doorzoekbare
 * bron van waarheid. Transactielog-bewerkingen/-verwijderingen hebben ook
 * al hun eigen, rijkere audit trail (transactielog_wijzigingen, migratie
 * 006, met oude/nieuwe waarden) -- ook die hoeven hier niet nogmaals in.
 *
 * Bewust een platte gebruiker_naam-snapshot i.p.v. een FK naar users.id,
 * consistent met bewerkt_door/prijs_op_moment elders in deze codebase.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function logAudit(string $gebruikerNaam, string $actie, ?string $entiteitType = null, ?string $entiteitId = null, ?string $details = null): void {
    db()->prepare(
        'INSERT INTO audit_log (gebruiker_naam, actie, entiteit_type, entiteit_id, details)
         VALUES (:naam, :actie, :type, :id, :details)'
    )->execute([
        ':naam' => $gebruikerNaam,
        ':actie' => $actie,
        ':type' => $entiteitType,
        ':id' => $entiteitId,
        ':details' => $details,
    ]);
}

/**
 * Laatste N audit-regels, nieuwste eerst -- voor een eventueel
 * beheerscherm later. Nu vooral bedoeld om vanuit de database zelf te
 * kunnen controleren dat het wegschrijven werkt.
 */
function getAuditLog(int $limiet = 200): array {
    $stmt = db()->prepare('SELECT * FROM audit_log ORDER BY tijdstempel DESC, id DESC LIMIT :limiet');
    $stmt->bindValue(':limiet', $limiet, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
