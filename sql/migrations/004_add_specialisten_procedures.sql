-- =====================================================================
-- 004_add_specialisten_procedures.sql
-- Vervangt de vrije-tekstvelden "specialist" en "procedure type" bij het
-- scannen door een selectie uit een beheerde lijst -- voorkomt tikfouten
-- en spreidingsvarianten (bijv. "PTA" vs "pta" vs "P.T.A."), en is de
-- basis voor eventuele latere toegangsbeperking per specialist.
--
-- Bewust twee losse, simpele tabellen (geen koppeling met users -- een
-- specialist heeft niet per se een inlogaccount, zie eerdere afweging
-- over afscherming per gebruiker). "actief" i.p.v. verwijderen: oudere
-- transactielog-rijen blijven gewoon vrije tekst en verwijzen nergens
-- naar een id, dus een naam kan gedeactiveerd (niet meer selecteerbaar)
-- worden zonder dat historische data breekt.
--
-- Beide tabellen starten leeg -- geen fictieve namen meeverzonnen, dit
-- vult Harm zelf via het nieuwe beheerscherm "Stamgegevens".
-- =====================================================================

CREATE TABLE IF NOT EXISTS specialisten (
  id INT PRIMARY KEY AUTO_INCREMENT,
  naam VARCHAR(100) NOT NULL UNIQUE,
  actief TINYINT(1) NOT NULL DEFAULT 1,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS procedure_typen (
  id INT PRIMARY KEY AUTO_INCREMENT,
  naam VARCHAR(150) NOT NULL UNIQUE,
  actief TINYINT(1) NOT NULL DEFAULT 1,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (migratie) VALUES ('004_add_specialisten_procedures.sql');
