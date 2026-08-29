-- =====================================================================
-- 006_transactielog_bewerkbaar.sql
-- Voegt bewerk/verwijder-mogelijkheid toe aan recente transacties
-- (scherm "Recent Transactions"). Rechtstreekse UPDATE/DELETE op
-- transactielog zelf (niet zacht verwijderen via een vlag), omdat
-- transactielog de enige bron van waarheid is voor actuele
-- voorraadaantallen -- een vlag zou overal waar voorraad wordt
-- berekend een extra "WHERE verwijderd_op IS NULL" vereisen, met veel
-- kans om een plek te missen. In plaats daarvan: elke wijziging/
-- verwijdering wordt gelogd in transactielog_wijzigingen (audit trail),
-- vóórdat de wijziging wordt doorgevoerd.
-- =====================================================================

ALTER TABLE transactielog
  ADD COLUMN bewerkt_op DATETIME NULL AFTER aangemaakt_op,
  ADD COLUMN bewerkt_door VARCHAR(100) NULL AFTER bewerkt_op;

CREATE TABLE IF NOT EXISTS transactielog_wijzigingen (
  id INT PRIMARY KEY AUTO_INCREMENT,
  transactielog_id INT NOT NULL,
  actie ENUM('bewerkt','verwijderd') NOT NULL,
  gebruiker_naam VARCHAR(100) NULL,
  oude_waarden JSON NOT NULL,
  nieuwe_waarden JSON NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_transactielog_id (transactielog_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (migratie) VALUES ('006_transactielog_bewerkbaar.sql');
